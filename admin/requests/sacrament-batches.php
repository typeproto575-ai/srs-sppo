<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Sacrament Groups';
$errors = [];
$success = null;
$flash = $_SESSION['sacrament_batches_flash'] ?? null;
unset($_SESSION['sacrament_batches_flash']);
if (is_array($flash)) {
    $success = is_string($flash['success'] ?? null) ? $flash['success'] : null;
    $errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
}

$sacramentLabels = [
    'first_communion' => 'First Holy Communion',
    'confirmation' => 'Confirmation',
];
$gradeOptions = sacrament_formation_grade_options();
$batchId = filter_var($_GET['batch_id'] ?? null, FILTER_VALIDATE_INT);
$batchId = $batchId && $batchId > 0 ? (int) $batchId : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } else {
        try {
            if ($action === 'create_batch') {
                $sacramentType = is_string($_POST['sacrament_type'] ?? null) ? $_POST['sacrament_type'] : '';
                $grade = is_string($_POST['school_grade'] ?? null) ? $_POST['school_grade'] : '';
                $schoolYear = is_string($_POST['school_year'] ?? null) ? trim($_POST['school_year']) : '';
                $groupLabel = is_string($_POST['group_label'] ?? null) ? trim($_POST['group_label']) : '';
                if (!isset($sacramentLabels[$sacramentType])) {
                    $errors[] = 'Choose First Holy Communion or Confirmation.';
                }
                if (mb_strlen($groupLabel, 'UTF-8') > 40) {
                    $errors[] = 'The group label must be 40 characters or fewer.';
                }
                if (($formationError = sacrament_formation_input_error('batch', $grade, $schoolYear)) !== null) {
                    $errors[] = $formationError;
                }

                if ($errors === []) {
                    $batchName = $gradeOptions[$grade] . ' ' . $sacramentLabels[$sacramentType]
                        . ($groupLabel !== '' ? ' - ' . $groupLabel : '')
                        . ' - School Year ' . $schoolYear;
                    $pdo->beginTransaction();
                    $insert = $pdo->prepare(
                        'INSERT INTO sacrament_batches (sacrament_type, grade_level, school_year, batch_name, created_by)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $insert->execute([
                        $sacramentType,
                        $grade,
                        $schoolYear,
                        $batchName,
                        (int) current_user()['id'],
                    ]);
                    $batchId = (int) $pdo->lastInsertId();
                    log_activity(
                        $pdo,
                        (int) current_user()['id'],
                        'sacrament_batch_created',
                        'sacrament_batches',
                        $batchId,
                        $batchName
                    );
                    $pdo->commit();
                    $_SESSION['sacrament_batches_flash'] = ['success' => 'Group created. Assign reviewed applications to it.'];
                    redirect_to('/admin/requests/sacrament-batches.php?' . http_build_query(['batch_id' => $batchId]) . '#group-management');
                    exit;
                }
            } elseif ($action === 'assign_applications') {
                $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
                $batchId = $batchId && $batchId > 0 ? (int) $batchId : 0;
                $submittedIds = $_POST['application_ids'] ?? [];
                if ($batchId < 1 || !is_array($submittedIds) || $submittedIds === []) {
                    $errors[] = 'Select at least one reviewed application.';
                }
                $applicationIds = [];
                if (is_array($submittedIds)) {
                    foreach ($submittedIds as $submittedId) {
                        $validatedId = filter_var($submittedId, FILTER_VALIDATE_INT);
                        if (!$validatedId || $validatedId < 1) {
                            $errors[] = 'One or more selected applications are invalid.';
                            break;
                        }
                        $applicationIds[] = (int) $validatedId;
                    }
                }
                $applicationIds = array_values(array_unique($applicationIds));
                if (count($applicationIds) > 100) {
                    $errors[] = 'Assign no more than 100 applications at a time.';
                }

                if ($errors === []) {
                    $pdo->beginTransaction();
                    $batchStmt = $pdo->prepare('SELECT * FROM sacrament_batches WHERE id = ? FOR UPDATE');
                    $batchStmt->execute([$batchId]);
                    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$batch) {
                        throw new RuntimeException('The selected group could not be found.');
                    }
                    $eventStmt = $pdo->prepare(
                        'SELECT event_date, start_time, end_time
                         FROM schedule_events
                         WHERE batch_id = ?
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $eventStmt->execute([$batchId]);
                    $batchEvent = $eventStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($batchEvent) {
                        throw new RuntimeException('This group already has a confirmed schedule. Create a new group or use the individual catch-up path for later applications.');
                    }
                    $selectApplication = $pdo->prepare(
                        'SELECT id, request_no, sacrament_type, status, batch_id, details
                         FROM sacrament_applications
                         WHERE id = ?
                         FOR UPDATE'
                    );
                    $assignApplication = $pdo->prepare(
                        "UPDATE sacrament_applications
                         SET batch_id = ?, status = ?
                         WHERE id = ?"
                    );
                    foreach ($applicationIds as $applicationId) {
                        $selectApplication->execute([$applicationId]);
                        $application = $selectApplication->fetch(PDO::FETCH_ASSOC);
                        $details = is_array($application)
                            ? json_decode((string) ($application['details'] ?? ''), true)
                            : null;
                        if (!$application
                            || $application['sacrament_type'] !== $batch['sacrament_type']
                            || $application['status'] !== 'approved'
                            || $application['batch_id'] !== null
                            || !is_array($details)
                            || ($details['formation_path'] ?? '') !== 'batch'
                            || ($details['school_grade'] ?? '') !== $batch['grade_level']
                            || ($details['school_year'] ?? '') !== $batch['school_year']) {
                            throw new RuntimeException('A selected application is no longer eligible for this group. Refresh the page and try again.');
                        }

                        $assignApplication->execute([$batchId, 'approved', $applicationId]);
                        log_activity(
                            $pdo,
                            (int) current_user()['id'],
                            'sacrament_application_batch_assigned',
                            'sacrament_applications',
                            $applicationId,
                            'Assigned to group ' . $batch['batch_name']
                        );
                    }
                    $pdo->commit();
                    $_SESSION['sacrament_batches_flash'] = ['success' => 'Reviewed applications assigned to the group.'];
                    redirect_to('/admin/requests/sacrament-batches.php?' . http_build_query(['batch_id' => $batchId]) . '#group-management');
                    exit;
                }
            } elseif ($action === 'remove_application') {
                $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
                $batchId = $batchId && $batchId > 0 ? (int) $batchId : 0;
                $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
                $applicationId = $applicationId && $applicationId > 0 ? (int) $applicationId : 0;
                if ($batchId < 1 || $applicationId < 1) {
                    throw new RuntimeException('The selected group or application is invalid.');
                }

                $pdo->beginTransaction();
                $eventStmt = $pdo->prepare('SELECT id FROM schedule_events WHERE batch_id = ? FOR UPDATE');
                $eventStmt->execute([$batchId]);
                if ($eventStmt->fetchColumn() !== false) {
                    throw new RuntimeException('An application cannot be removed after the shared group schedule is confirmed.');
                }
                $applicationStmt = $pdo->prepare(
                    'SELECT status, batch_id FROM sacrament_applications WHERE id = ? FOR UPDATE'
                );
                $applicationStmt->execute([$applicationId]);
                $application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
                if (!$application || (int) $application['batch_id'] !== $batchId || $application['status'] !== 'approved') {
                    throw new RuntimeException('That application is no longer assigned to this unscheduled group.');
                }
                $remove = $pdo->prepare('UPDATE sacrament_applications SET batch_id = NULL WHERE id = ?');
                $remove->execute([$applicationId]);
                log_activity(
                    $pdo,
                    (int) current_user()['id'],
                    'sacrament_application_batch_unassigned',
                    'sacrament_applications',
                    $applicationId,
                    'Removed from group ' . $batchId
                );
                $pdo->commit();
                $_SESSION['sacrament_batches_flash'] = ['success' => 'Application removed from the unscheduled group.'];
                redirect_to('/admin/requests/sacrament-batches.php?' . http_build_query(['batch_id' => $batchId]) . '#group-management');
                exit;
            } elseif ($action === 'save_schedule') {
                $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
                $batchId = $batchId && $batchId > 0 ? (int) $batchId : 0;
                $eventDate = is_string($_POST['event_date'] ?? null) ? trim($_POST['event_date']) : '';
                $startTime = is_string($_POST['start_time'] ?? null) ? trim($_POST['start_time']) : '';
                $endTime = is_string($_POST['end_time'] ?? null) ? trim($_POST['end_time']) : '';
                if ($batchId < 1) {
                    throw new RuntimeException('Choose a valid group before setting its schedule.');
                }
                if (($scheduleError = application_schedule_input_error($eventDate, $startTime, $endTime)) !== null) {
                    throw new RuntimeException($scheduleError);
                }

                $pdo->beginTransaction();
                $batchStmt = $pdo->prepare('SELECT * FROM sacrament_batches WHERE id = ? FOR UPDATE');
                $batchStmt->execute([$batchId]);
                $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
                if (!$batch) {
                    throw new RuntimeException('The selected group could not be found.');
                }
                $memberStmt = $pdo->prepare(
                    "SELECT id, request_no, status
                     FROM sacrament_applications
                     WHERE batch_id = ? AND status IN ('approved', 'scheduled')
                     ORDER BY id
                     FOR UPDATE"
                );
                $memberStmt->execute([$batchId]);
                $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
                if ($members === []) {
                    throw new RuntimeException('Assign at least one approved application before scheduling this group.');
                }
                $eventStmt = $pdo->prepare(
                    'SELECT id, event_date, start_time, end_time
                     FROM schedule_events
                     WHERE batch_id = ?
                     LIMIT 1
                     FOR UPDATE'
                );
                $eventStmt->execute([$batchId]);
                $existingEvent = $eventStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $existingStart = $existingEvent && $existingEvent['start_time'] !== null
                    ? substr((string) $existingEvent['start_time'], 0, 5)
                    : '';
                $existingEnd = $existingEvent && $existingEvent['end_time'] !== null
                    ? substr((string) $existingEvent['end_time'], 0, 5)
                    : '';
                $scheduleChanged = $existingEvent === null
                    || (string) $existingEvent['event_date'] !== $eventDate
                    || $existingStart !== $startTime
                    || $existingEnd !== $endTime;
                if (!$scheduleChanged && !array_filter($members, static fn(array $member): bool => $member['status'] !== 'scheduled')) {
                    $pdo->rollBack();
                    $_SESSION['sacrament_batches_flash'] = ['success' => 'That shared group schedule is already saved.'];
                    redirect_to('/admin/requests/sacrament-batches.php?' . http_build_query(['batch_id' => $batchId]) . '#group-management');
                    exit;
                }

                $preservingExistingSchedule = $existingEvent !== null
                    && !$scheduleChanged
                    && $startTime !== ''
                    && $endTime !== '';
                if (!$preservingExistingSchedule && !parish_schedule_slot_is_available(
                    $pdo,
                    $eventDate,
                    $startTime,
                    $endTime,
                    (int) ($existingEvent['id'] ?? 0)
                )) {
                    throw new RuntimeException('That 60-minute time is no longer available. Choose an open time from the schedule list.');
                }

                $startValue = $startTime !== '' ? $startTime : null;
                $endValue = $endTime !== '' ? $endTime : null;
                $eventTitle = (string) $batch['batch_name'];
                $eventDetails = $sacramentLabels[$batch['sacrament_type']]
                    . ' group for ' . $gradeOptions[$batch['grade_level']]
                    . ', school year ' . $batch['school_year'] . '.';
                if ($existingEvent === null) {
                    $saveEvent = $pdo->prepare(
                        "INSERT INTO schedule_events
                            (event_type, title, public_details, event_date, start_time, end_time, batch_id, notes, created_by)
                         VALUES ('other', ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $saveEvent->execute([
                        $eventTitle,
                        $eventDetails,
                        $eventDate,
                        $startValue,
                        $endValue,
                        $batchId,
                        'Shared sacrament group; applications are linked to group ' . $batchId . '.',
                        (int) current_user()['id'],
                    ]);
                } else {
                    $saveEvent = $pdo->prepare(
                        'UPDATE schedule_events
                         SET event_date = ?, start_time = ?, end_time = ?, public_details = ?
                         WHERE id = ?'
                    );
                    $saveEvent->execute([
                        $eventDate,
                        $startValue,
                        $endValue,
                        $eventDetails,
                        (int) $existingEvent['id'],
                    ]);
                }
                $updateMembers = $pdo->prepare(
                    "UPDATE sacrament_applications
                     SET status = 'scheduled'
                     WHERE batch_id = ? AND status = 'approved'"
                );
                $updateMembers->execute([$batchId]);
                log_activity(
                    $pdo,
                    (int) current_user()['id'],
                    $existingEvent === null ? 'sacrament_batch_schedule_set' : 'sacrament_batch_schedule_updated',
                    'sacrament_batches',
                    $batchId,
                    'Shared schedule: ' . $eventDate . ' ' . ($startTime !== '' ? $startTime : 'time pending')
                );
                $pdo->commit();

                $scheduleNote = 'Confirmed group schedule: ' . date('F j, Y', strtotime($eventDate))
                    . '; time: ' . ($startValue !== null
                        ? format_event_time_range($startValue, $endValue)
                        : 'To be confirmed')
                    . '. Please contact the Parish Office promptly if you cannot attend.';
                foreach ($members as $member) {
                    notify_sacrament_application_status(
                        $pdo,
                        'scheduled',
                        (string) $batch['sacrament_type'],
                        (string) $member['request_no'],
                        $scheduleNote
                    );
                }
                $_SESSION['sacrament_batches_flash'] = [
                    'success' => 'The shared schedule was saved. Status notifications were attempted for all assigned families; check the email log for delivery results.',
                ];
                redirect_to('/admin/requests/sacrament-batches.php?' . http_build_query(['batch_id' => $batchId]) . '#group-management');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Sacrament batch update failed: ' . $e->getMessage());
            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'The group could not be saved. Please try again or contact the system administrator.';
        }
    }
}

$batchListStmt = $pdo->query(
    'SELECT b.id, b.sacrament_type, b.grade_level, b.school_year, b.batch_name,
            e.event_date, e.start_time,
            (SELECT COUNT(*) FROM sacrament_applications sa WHERE sa.batch_id = b.id) AS application_count
     FROM sacrament_batches b
     LEFT JOIN schedule_events e ON e.batch_id = b.id
     ORDER BY b.school_year DESC, b.sacrament_type, b.grade_level, b.id DESC'
);
$batches = $batchListStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedBatch = null;
$batchEvent = null;
$assignedApplications = [];
$candidateApplications = [];
if ($batchId > 0) {
    $batchStmt = $pdo->prepare(
        'SELECT b.*, e.id AS event_id, e.event_date, e.start_time, e.end_time
         FROM sacrament_batches b
         LEFT JOIN schedule_events e ON e.batch_id = b.id
         WHERE b.id = ?
         LIMIT 1'
    );
    $batchStmt->execute([$batchId]);
    $selectedBatch = $batchStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$selectedBatch) {
        $errors[] = 'The selected group could not be found.';
        $batchId = 0;
    } else {
        $batchEvent = !empty($selectedBatch['event_id']) ? $selectedBatch : null;
        $assignedStmt = $pdo->prepare(
            'SELECT id, request_no, applicant_name, applicant_email, status, details
             FROM sacrament_applications
             WHERE batch_id = ?
             ORDER BY applicant_name, id'
        );
        $assignedStmt->execute([$batchId]);
        $assignedApplications = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($batchEvent === null) {
            $candidateStmt = $pdo->prepare(
                "SELECT id, request_no, applicant_name, applicant_email, details
                 FROM sacrament_applications
                 WHERE sacrament_type = ? AND status = 'approved' AND batch_id IS NULL
                 ORDER BY applicant_name, id"
            );
            $candidateStmt->execute([$selectedBatch['sacrament_type']]);
            foreach ($candidateStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                $details = json_decode((string) ($candidate['details'] ?? ''), true);
                if (is_array($details)
                    && ($details['formation_path'] ?? '') === 'batch'
                    && ($details['school_grade'] ?? '') === $selectedBatch['grade_level']
                    && ($details['school_year'] ?? '') === $selectedBatch['school_year']) {
                    $candidateApplications[] = $candidate;
                }
            }
        }
    }
}

require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="admin-list-page sacrament-groups-page">
    <header class="admin-queue-heading sacrament-groups-heading">
        <div>
            <p class="admin-queue-kicker">Requests</p>
            <h1>Sacrament groups</h1>
            <p>Organize approved First Communion or Confirmation applications into groups, then schedule and notify everyone together.</p>
        </div>
        <div class="admin-queue-actions">
            <a class="btn btn-secondary" href="<?= e($basePath . '/admin/requests/applications-queue.php') ?>">Application queue</a>
        </div>
    </header>
    <?php if ($success !== null): ?><p class="alert alert-success"><?= e($success) ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="alert alert-error" role="alert"><?= e((string) $error) ?></p><?php endforeach; ?>

    <ol class="sacrament-group-workflow" aria-label="Secretary workflow">
        <li><span>01</span><div><strong>Review</strong><p>Approve applications requesting group preparation.</p></div></li>
        <li><span>02</span><div><strong>Create</strong><p>Match the sacrament, grade, and school year.</p></div></li>
        <li><span>03</span><div><strong>Assign applicants</strong><p>Add approved applicants to the group.</p></div></li>
        <li><span>04</span><div><strong>Confirm schedule</strong><p>Save the shared date and time to notify assigned families.</p></div></li>
    </ol>
    <p class="sacrament-group-record-note"><strong>After the ceremony:</strong> Enter each completed sacrament in Records. Group scheduling does not create register entries.</p>

    <section class="admin-card sacrament-group-card">
        <div class="sacrament-group-section-heading">
            <div>
                <p class="sacrament-group-eyebrow">Step 2</p>
                <h2>Create a group</h2>
            </div>
            <p>Use one group for applicants with the same sacrament, grade, and school year.</p>
        </div>
        <form method="post" class="sacrament-group-create-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_batch">
            <label>Sacrament *
                <select name="sacrament_type" required>
                    <option value="">-- Select --</option>
                    <?php foreach ($sacramentLabels as $type => $label): ?>
                        <option value="<?= e($type) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Grade level *
                <select name="school_grade" required>
                    <option value="">-- Select --</option>
                    <?php foreach ($gradeOptions as $grade => $label): ?>
                        <option value="<?= e($grade) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>School year *
                <input type="text" name="school_year" inputmode="numeric" pattern="\d{4}-\d{4}" maxlength="9" placeholder="2026-2027" required>
            </label>
            <label>Group name <span class="field-hint">(optional, e.g. Group A)</span>
                <input type="text" name="group_label" maxlength="40">
            </label>
            <button type="submit" class="btn">Create group</button>
        </form>
        <p class="sacrament-group-hint">Only approved applications requesting group preparation and matching these details can be added.</p>
    </section>

    <?php if ($selectedBatch !== null): ?>
        <section class="admin-card sacrament-group-card sacrament-group-manager" id="group-management">
            <header class="sacrament-group-manager-heading">
                <div>
                    <p class="sacrament-group-eyebrow">Selected group</p>
                    <h2><?= e($selectedBatch['batch_name']) ?></h2>
                    <p><?= e($sacramentLabels[$selectedBatch['sacrament_type']] ?? $selectedBatch['sacrament_type']) ?> · <?= e($gradeOptions[$selectedBatch['grade_level']] ?? $selectedBatch['grade_level']) ?> · School year <?= e($selectedBatch['school_year']) ?></p>
                </div>
                <span class="sacrament-group-status<?= $batchEvent !== null ? ' is-scheduled' : '' ?>">
                    <?= $batchEvent !== null ? 'Schedule confirmed' : ($assignedApplications !== [] ? 'Ready to schedule' : 'Needs applicants') ?>
                </span>
            </header>
            <?php if ($batchEvent !== null): ?>
                <p class="sacrament-group-confirmed-schedule"><strong>Shared schedule</strong>
                    <?= e(date('F j, Y', strtotime((string) $batchEvent['event_date']))) ?>
                    <?= $batchEvent['start_time'] ? e(format_event_time_range(
                        (string) $batchEvent['start_time'],
                        $batchEvent['end_time'] !== null ? (string) $batchEvent['end_time'] : null
                    )) : '— time to be confirmed' ?>
                </p>
            <?php endif; ?>

            <?php if ($candidateApplications !== []): ?>
                <form method="post" class="sacrament-group-assignment">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign_applications">
                    <input type="hidden" name="batch_id" value="<?= $batchId ?>">
                    <div class="sacrament-group-subheading">
                        <div>
                            <p class="sacrament-group-eyebrow">Step 3 · Assign applicants</p>
                            <h3>Applicants ready to add</h3>
                            <p>Select the approved applications to place in this group.</p>
                        </div>
                        <span><?= count($candidateApplications) ?> eligible</span>
                    </div>
                    <ul class="sacrament-group-applicant-list">
                        <?php foreach ($candidateApplications as $candidate): ?>
                            <li>
                                <label class="sacrament-group-applicant">
                                    <input type="checkbox" name="application_ids[]" value="<?= (int) $candidate['id'] ?>">
                                    <span class="sacrament-group-applicant__name"><?= e($candidate['applicant_name']) ?></span>
                                    <span class="sacrament-group-applicant__meta"><?= e($candidate['request_no']) ?> · <?= e($candidate['applicant_email']) ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="submit" class="btn">Add selected applicants</button>
                </form>
            <?php elseif ($batchEvent === null): ?>
                <p class="sacrament-group-empty">No approved group applications currently match these details. Review applications in the <a href="<?= e($basePath . '/admin/requests/applications-queue.php') ?>">application queue</a>.</p>
            <?php endif; ?>

            <div class="sacrament-group-assigned">
                <div class="sacrament-group-subheading">
                    <div>
                        <h3>Applicants in this group</h3>
                        <p><?= count($assignedApplications) ?> assigned</p>
                    </div>
                </div>
            <?php if ($assignedApplications === []): ?>
                <p class="sacrament-group-empty">No applicants have been added yet.</p>
            <?php else: ?>
                <ul class="sacrament-group-assigned-list">
                    <?php foreach ($assignedApplications as $assigned): ?>
                        <li>
                            <div class="sacrament-group-assigned-person">
                                <strong><?= e($assigned['applicant_name']) ?></strong>
                                <span><?= e($assigned['request_no']) ?> · <?= e($assigned['applicant_email']) ?></span>
                            </div>
                            <span class="sacrament-group-status"><?= e(status_label('application', (string) $assigned['status'])) ?></span>
                            <a class="sacrament-group-review" href="<?= e($basePath . '/admin/requests/applications-queue.php?' . http_build_query([
                                    'q' => $assigned['request_no'],
                                    'open_application_id' => (int) $assigned['id'],
                                ])) ?>">Review application</a>
                            <?php if ($batchEvent === null): ?>
                                <form method="post" class="sacrament-group-remove-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_application">
                                    <input type="hidden" name="batch_id" value="<?= $batchId ?>">
                                    <input type="hidden" name="application_id" value="<?= (int) $assigned['id'] ?>">
                                    <button type="submit" class="btn btn-small btn-secondary">Remove</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            </div>

            <?php if ($assignedApplications !== []): ?>
                <section class="sacrament-group-schedule">
                <div class="sacrament-group-subheading">
                    <div>
                        <p class="sacrament-group-eyebrow">Step 4 · Confirm schedule</p>
                        <h3><?= $batchEvent === null ? 'Set the shared schedule' : 'Update the shared schedule' ?></h3>
                        <p>One confirmed date and time will be sent to every assigned family.</p>
                    </div>
                </div>
                <form method="post" class="sacrament-group-schedule-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_schedule">
                    <input type="hidden" name="batch_id" value="<?= $batchId ?>">
                    <div
                        class="parish-schedule-picker"
                        data-parish-schedule-picker
                        data-availability-url="<?= e($basePath . '/admin/api/schedule-availability.php') ?>"
                        data-exclude-event-id="<?= (int) ($batchEvent['event_id'] ?? 0) ?>"
                        data-current-date="<?= e((string) ($batchEvent['event_date'] ?? '')) ?>"
                        data-current-start="<?= $batchEvent && $batchEvent['start_time'] ? e(substr((string) $batchEvent['start_time'], 0, 5)) : '' ?>"
                        data-current-end="<?= $batchEvent && $batchEvent['end_time'] ? e(substr((string) $batchEvent['end_time'], 0, 5)) : '' ?>"
                    >
                        <label>Confirmed date *
                            <input type="date" name="event_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e((string) ($batchEvent['event_date'] ?? '')) ?>" data-schedule-date required>
                        </label>
                        <label>Available 60-minute time *
                            <select data-schedule-slot required disabled>
                                <option value="">Choose a date first</option>
                            </select>
                        </label>
                        <input type="hidden" name="start_time" value="<?= $batchEvent && $batchEvent['start_time'] ? e(substr((string) $batchEvent['start_time'], 0, 5)) : '' ?>" data-schedule-start>
                        <input type="hidden" name="end_time" value="<?= $batchEvent && $batchEvent['end_time'] ? e(substr((string) $batchEvent['end_time'], 0, 5)) : '' ?>" data-schedule-end>
                        <p class="field-hint" data-schedule-status aria-live="polite">Choose a date to view parish schedules and open times.</p>
                        <div data-schedule-event-panel hidden>
                            <strong>Schedules already booked that day</strong>
                            <p class="field-hint" data-schedule-event-empty>No schedules are booked on this date.</p>
                            <ul class="parish-schedule-picker__events" data-schedule-events></ul>
                        </div>
                    </div>
                    <button type="submit" class="btn"><?= $batchEvent === null ? 'Confirm and notify families' : 'Update and notify families' ?></button>
                </form>
                </section>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="admin-card sacrament-group-card sacrament-group-list-section">
        <div class="sacrament-group-section-heading">
            <div>
                <p class="sacrament-group-eyebrow">Group directory</p>
                <h2>Existing groups</h2>
            </div>
            <p>Open a group to view its applicants or manage its shared schedule.</p>
        </div>
        <?php if ($batches === []): ?>
            <p class="admin-queue-empty">No sacrament groups have been created.</p>
        <?php else: ?>
            <div class="sacrament-group-list">
                <?php foreach ($batches as $batch): ?>
                    <article class="sacrament-group-list-item">
                        <div class="sacrament-group-list-item__main">
                            <h3><?= e($batch['batch_name']) ?></h3>
                            <p><?= e($sacramentLabels[$batch['sacrament_type']] ?? $batch['sacrament_type']) ?> · School year <?= e($batch['school_year']) ?></p>
                        </div>
                        <div class="sacrament-group-list-item__metric">
                            <span>Applicants</span>
                            <strong><?= (int) $batch['application_count'] ?></strong>
                        </div>
                        <div class="sacrament-group-list-item__metric">
                            <span>Shared schedule</span>
                            <strong><?= $batch['event_date'] ? e(date('M j, Y', strtotime((string) $batch['event_date']))) : 'Not scheduled' ?></strong>
                        </div>
                        <a class="btn btn-small" href="<?= e($basePath . '/admin/requests/sacrament-batches.php?' . http_build_query(['batch_id' => (int) $batch['id']]) . '#group-management') ?>">Open group</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
