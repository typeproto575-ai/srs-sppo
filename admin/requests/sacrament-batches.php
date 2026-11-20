<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';
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
    'first_communion' => 'Holy Communion',
    'confirmation' => 'Confirmation',
];
$gradeOptions = sacrament_formation_grade_options();
$batchId = filter_var($_GET['batch_id'] ?? null, FILTER_VALIDATE_INT);
$batchId = $batchId && $batchId > 0 ? (int) $batchId : 0;

function application_batch_candidate_matches(array $details, array $batch): bool
{
    $formationPath = (string) ($details['formation_path'] ?? '');
    if ($batch['grade_level'] === 'general') {
        $grade = (string) ($details['school_grade'] ?? '');
        $requestedGroup = trim((string) ($details['group_label'] ?? ''));
        $batchName = (string) ($batch['batch_name'] ?? '');
        return $formationPath === 'batch'
            && in_array($grade, ['general', ''], true)
            && (string) ($details['school_year'] ?? '') === trim((string) $batch['school_year'])
            && ($requestedGroup === ''
                || mb_stripos($batchName, $requestedGroup . ' —', 0, 'UTF-8') === 0);
    }

    return $formationPath === 'batch'
        && (string) ($details['school_grade'] ?? '') === (string) $batch['grade_level']
        && (string) ($details['school_year'] ?? '') === (string) $batch['school_year'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } else {
        try {
            if ($action === 'review_group_status') {
                $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
                $batchId = $batchId && $batchId > 0 ? (int) $batchId : 0;
                $targetStatus = is_string($_POST['group_status'] ?? null) ? $_POST['group_status'] : '';
                if ($batchId < 1 || !in_array($targetStatus, ['reviewing', 'approved'], true)) {
                    throw new RuntimeException('Choose a valid Communion group and group status.');
                }

                $pdo->beginTransaction();
                $batchStmt = $pdo->prepare(
                    'SELECT id, sacrament_type, grade_level, batch_name
                     FROM sacrament_batches
                     WHERE id = ?
                     FOR UPDATE'
                );
                $batchStmt->execute([$batchId]);
                $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
                if (!$batch || $batch['sacrament_type'] !== 'first_communion' || $batch['grade_level'] !== 'general') {
                    throw new RuntimeException('The selected item is not a Holy Communion group.');
                }

                $memberStmt = $pdo->prepare(
                    'SELECT id, request_no, applicant_name, applicant_email, status, details
                     FROM sacrament_applications
                     WHERE batch_id = ? AND sacrament_type = \'first_communion\'
                     ORDER BY id
                     FOR UPDATE'
                );
                $memberStmt->execute([$batchId]);
                $groupMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
                if ($groupMembers === []) {
                    throw new RuntimeException('This group has no candidate applications to review.');
                }

                $groupReference = '';
                $groupLabel = '';
                $contactEmail = '';
                $membersForEmail = [];
                $eligibleIds = [];
                foreach ($groupMembers as $member) {
                    $memberDetails = json_decode((string) ($member['details'] ?? ''), true);
                    if (!is_array($memberDetails)
                        || ($memberDetails['intake_mode'] ?? '') !== 'group'
                        || trim((string) ($memberDetails['group_reference'] ?? '')) === '') {
                        throw new RuntimeException('A candidate is missing valid shared group details; no status was changed.');
                    }
                    $memberGroupReference = trim((string) $memberDetails['group_reference']);
                    if ($groupReference !== '' && !hash_equals($groupReference, $memberGroupReference)) {
                        throw new RuntimeException('The selected batch contains more than one group; no status was changed.');
                    }
                    $groupReference = $memberGroupReference;
                    $groupLabel = $groupLabel !== '' ? $groupLabel : trim((string) ($memberDetails['group_label'] ?? ''));
                    $contactEmail = $contactEmail !== '' ? $contactEmail : trim((string) ($memberDetails['contact_person_email'] ?? ''));
                    $membersForEmail[] = [
                        'name' => (string) $member['applicant_name'],
                        'request_no' => (string) $member['request_no'],
                    ];
                    if ($targetStatus === 'reviewing' && $member['status'] === 'new') {
                        $eligibleIds[] = (int) $member['id'];
                    } elseif ($targetStatus === 'approved'
                        && !in_array($member['status'], ['completed', 'rejected', 'scheduled', 'approved'], true)) {
                        $eligibleIds[] = (int) $member['id'];
                    }
                }
                if ($eligibleIds === []) {
                    throw new RuntimeException('The group already has the selected status or no candidates are eligible for this update.');
                }
                $scheduleStmt = $pdo->prepare('SELECT id FROM schedule_events WHERE batch_id = ? LIMIT 1 FOR UPDATE');
                $scheduleStmt->execute([$batchId]);
                if ($scheduleStmt->fetchColumn() !== false) {
                    throw new RuntimeException('This group already has a confirmed schedule; manage it from this group page.');
                }

                $updateMember = $pdo->prepare('UPDATE sacrament_applications SET status = ? WHERE id = ?');
                foreach ($eligibleIds as $eligibleId) {
                    $updateMember->execute([$targetStatus, $eligibleId]);
                }
                log_activity(
                    $pdo,
                    (int) current_user()['id'],
                    $targetStatus === 'approved' ? 'sacrament_group_approved' : 'sacrament_group_review_started',
                    'sacrament_batches',
                    $batchId,
                    'Holy Communion group ' . $groupReference . ' updated to ' . $targetStatus
                );
                $pdo->commit();

                $notificationWarning = '';
                if (filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                    try {
                        $emailSent = mail_communion_group_status(
                            $contactEmail,
                            $groupLabel,
                            $membersForEmail,
                            $targetStatus === 'approved' ? 'Approved for group preparation and scheduling' : 'Under review',
                            $pdo,
                            $batchId
                        );
                        if (!$emailSent) {
                            $notificationWarning = ' The group was updated, but email delivery could not be confirmed; check the notification log.';
                        }
                    } catch (Throwable $mailException) {
                        error_log('Communion group status email failed after status save: ' . $mailException->getMessage());
                        $notificationWarning = ' The group was updated, but its email notice failed; check the notification log.';
                    }
                }
                $_SESSION['sacrament_batches_flash'] = [
                    'success' => 'The group was updated in one action. Individual identity and sacramental record checks remain available before register encoding.'
                        . $notificationWarning,
                ];
                redirect_to('/admin/requests/sacrament-batches.php?' . http_build_query(['batch_id' => $batchId]) . '#group-management');
                exit;
            } elseif ($action === 'create_batch') {
                $sacramentType = is_string($_POST['sacrament_type'] ?? null) ? $_POST['sacrament_type'] : '';
                $schoolYear = is_string($_POST['calendar_year'] ?? null) ? trim($_POST['calendar_year']) : '';
                $groupLabel = is_string($_POST['group_label'] ?? null) ? trim($_POST['group_label']) : '';
                if (!isset($sacramentLabels[$sacramentType])) {
                    $errors[] = 'Choose Holy Communion or Confirmation.';
                }
                if (mb_strlen($groupLabel, 'UTF-8') > 60) {
                    $errors[] = 'The group or organization name must be 60 characters or fewer.';
                }
                if (!preg_match('/^\d{4}$/D', $schoolYear)) {
                    $errors[] = 'Enter a four-digit calendar year.';
                }

                if ($errors === []) {
                    $batchName = ($groupLabel !== '' ? $groupLabel . ' — ' : '')
                        . $sacramentLabels[$sacramentType] . ' — ' . $schoolYear;
                    $pdo->beginTransaction();
                    $insert = $pdo->prepare(
                        'INSERT INTO sacrament_batches (sacrament_type, grade_level, school_year, batch_name, created_by)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $insert->execute([
                        $sacramentType,
                        'general',
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
                            || !application_batch_candidate_matches($details, $batch)) {
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
                $confirmedVenue = is_string($_POST['confirmed_venue'] ?? null) ? trim($_POST['confirmed_venue']) : '';
                if ($batchId < 1) {
                    throw new RuntimeException('Choose a valid group before setting its schedule.');
                }
                if (mb_strlen($confirmedVenue, 'UTF-8') > 255) {
                    throw new RuntimeException('The confirmed group venue must be 255 characters or fewer.');
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
                    "SELECT sa.id, sa.request_no, sa.status, sa.applicant_name, sa.applicant_email,
                            sa.details, p.public_reference
                     FROM sacrament_applications sa
                     LEFT JOIN persons p ON p.id = sa.person_id
                     WHERE batch_id = ? AND status IN ('approved', 'scheduled')
                     ORDER BY sa.id
                     FOR UPDATE"
                );
                $memberStmt->execute([$batchId]);
                $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
                if ($members === []) {
                    throw new RuntimeException('Assign at least one approved application before scheduling this group.');
                }
                $eventStmt = $pdo->prepare(
                    'SELECT id, event_date, start_time, end_time, public_details
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
                $eventDetails = $sacramentLabels[$batch['sacrament_type']]
                    . ' group for calendar year ' . trim((string) $batch['school_year']) . '.';
                $eventDetails .= $confirmedVenue !== ''
                    ? ' Confirmed venue: ' . $confirmedVenue . '.'
                    : ' Venue to be confirmed by the Parish Office.';
                $scheduleChanged = $existingEvent === null
                    || (string) $existingEvent['event_date'] !== $eventDate
                    || $existingStart !== $startTime
                    || $existingEnd !== $endTime
                    || (string) $existingEvent['public_details'] !== $eventDetails;
                if (!$scheduleChanged && !array_filter($members, static fn(array $member): bool => $member['status'] !== 'scheduled')) {
                    $pdo->rollBack();
                    $_SESSION['sacrament_batches_flash'] = ['success' => 'That shared group schedule is already saved.'];
                    redirect_to('/admin/requests/sacrament-batches.php?' . http_build_query(['batch_id' => $batchId]) . '#group-management');
                    exit;
                }

                $conflictStmt = $pdo->prepare(
                    'SELECT start_time, end_time FROM schedule_events WHERE event_date = ? AND (? IS NULL OR id <> ?)'
                );
                $conflictStmt->execute([
                    $eventDate,
                    $existingEvent['id'] ?? null,
                    $existingEvent['id'] ?? null,
                ]);
                $hasConflict = false;
                foreach ($conflictStmt->fetchAll(PDO::FETCH_ASSOC) as $existingTime) {
                    if (event_times_overlap(
                        $startTime !== '' ? $startTime : null,
                        $endTime !== '' ? $endTime : null,
                        $existingTime['start_time'],
                        $existingTime['end_time']
                    )) {
                        $hasConflict = true;
                        break;
                    }
                }

                $startValue = $startTime !== '' ? $startTime : null;
                $endValue = $endTime !== '' ? $endTime : null;
                $eventTitle = (string) $batch['batch_name'];
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
                $groupMail = [];
                foreach ($members as $member) {
                    $details = json_decode((string) ($member['details'] ?? ''), true);
                    $details = is_array($details) ? $details : [];
                    $groupReference = trim((string) ($details['group_reference'] ?? ''));
                    $contactEmail = trim((string) ($details['contact_person_email'] ?? ''));
                    if ($batch['sacrament_type'] === 'first_communion'
                        && $groupReference !== ''
                        && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                        $key = $groupReference . '|' . strtolower($contactEmail);
                        if (!isset($groupMail[$key])) {
                            $groupMail[$key] = [
                                'email' => $contactEmail,
                                'label' => (string) ($details['group_label'] ?? ''),
                                'members' => [],
                            ];
                        }
                        $groupMail[$key]['members'][] = [
                            'name' => (string) ($member['applicant_name'] ?? ''),
                            'reference' => (string) ($member['public_reference'] ?? ''),
                            'request_no' => (string) ($member['request_no'] ?? ''),
                        ];
                        notify(
                            $pdo,
                            'application_scheduled',
                            'Application Scheduled',
                            'Holy Communion application (' . $member['request_no'] . ') is scheduled with its group.',
                            '/admin/requests/applications-queue.php?status=scheduled'
                        );
                    } else {
                        notify_sacrament_application_status(
                            $pdo,
                            'scheduled',
                            (string) $batch['sacrament_type'],
                            (string) $member['request_no'],
                            $scheduleNote
                        );
                    }
                }
                foreach ($groupMail as $groupNotification) {
                    try {
                        mail_communion_group_schedule(
                            $groupNotification['email'],
                            $groupNotification['label'],
                            $groupNotification['members'],
                            $eventDate,
                            $startValue !== null ? format_event_time_range($startValue, $endValue) : '',
                            $pdo,
                            $batchId,
                            $confirmedVenue
                        );
                    } catch (Throwable $mailException) {
                        error_log(
                            'Holy Communion schedule saved, but group email failed for batch '
                            . $batchId . ': ' . $mailException->getMessage()
                        );
                    }
                }
                $_SESSION['sacrament_batches_flash'] = [
                    'success' => 'The shared schedule was saved. Schedule notices were attempted for each Communion group contact and other assigned families; check the email log for delivery results.'
                        . ($hasConflict ? ' Check the calendar: this time overlaps another event.' : ''),
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
$groupContactDetails = [];
$confirmedGroupVenue = '';
$isSubmittedCommunionGroup = false;
$activeGroupApplications = [];
$groupNeedsReview = false;
$groupNeedsApproval = false;
if ($batchId > 0) {
    $batchStmt = $pdo->prepare(
        'SELECT b.*, e.id AS event_id, e.event_date, e.start_time, e.end_time,
                e.public_details AS event_public_details
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
            'SELECT id, request_no, applicant_name, applicant_email, person_id, status, details
             FROM sacrament_applications
             WHERE batch_id = ?
             ORDER BY applicant_name, id'
        );
        $assignedStmt->execute([$batchId]);
        $assignedApplications = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($assignedApplications !== []) {
            $groupContactDetails = json_decode((string) ($assignedApplications[0]['details'] ?? ''), true);
            $groupContactDetails = is_array($groupContactDetails) ? $groupContactDetails : [];
        }
        $isSubmittedCommunionGroup = $selectedBatch['sacrament_type'] === 'first_communion'
            && $selectedBatch['grade_level'] === 'general'
            && ($groupContactDetails['intake_mode'] ?? '') === 'group';
        $activeGroupApplications = array_values(array_filter(
            $assignedApplications,
            static fn(array $application): bool => !in_array($application['status'], ['completed', 'rejected', 'scheduled'], true)
        ));
        $groupNeedsReview = array_filter(
            $activeGroupApplications,
            static fn(array $application): bool => $application['status'] === 'new'
        ) !== [];
        $groupNeedsApproval = array_filter(
            $activeGroupApplications,
            static fn(array $application): bool => $application['status'] !== 'approved'
        ) !== [];
        if ($batchEvent !== null
            && preg_match('/Confirmed venue:\s*(.+?)(?:\.|$)/i', (string) ($batchEvent['event_public_details'] ?? ''), $venueMatch) === 1) {
            $confirmedGroupVenue = trim($venueMatch[1]);
        }

        if ($batchEvent === null) {
            $candidateStmt = $pdo->prepare(
                "SELECT id, request_no, applicant_name, applicant_email, details
                 FROM sacrament_applications
                 WHERE sacrament_type = ? AND status = 'approved' AND batch_id IS NULL
                 ORDER BY applicant_name, id"
            );
            $candidateStmt->execute([$selectedBatch['sacrament_type']]);
            $candidateBatch = $selectedBatch;
            foreach ($candidateStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                $details = json_decode((string) ($candidate['details'] ?? ''), true);
                if (is_array($details) && application_batch_candidate_matches($details, $candidateBatch)) {
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
            <p>Review submitted Communion groups, inspect the roster, approve for preparation, and manage each shared schedule here.</p>
        </div>
        <div class="admin-queue-actions">
            <a class="btn btn-secondary" href="<?= e($basePath . '/admin/requests/applications-queue.php') ?>">Application queue</a>
        </div>
    </header>
    <?php if ($success !== null): ?><p class="alert alert-success"><?= e($success) ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="alert alert-error" role="alert"><?= e((string) $error) ?></p><?php endforeach; ?>

    <ol class="sacrament-group-workflow" aria-label="Secretary workflow">
        <li><span>01</span><div><strong>Review together</strong><p>Review and approve a submitted Communion group once from its group page.</p></div></li>
        <li><span>02</span><div><strong>Create</strong><p>Choose the sacrament, calendar year, and group label.</p></div></li>
        <li><span>03</span><div><strong>Organize candidates</strong><p>Submitted Communion groups are already linked; other groups can add reviewed applications.</p></div></li>
        <li><span>04</span><div><strong>Confirm schedule</strong><p>Save the shared date and time to notify assigned families.</p></div></li>
    </ol>
    <p class="sacrament-group-record-note"><strong>After the ceremony:</strong> Enter each completed sacrament in Records. Group scheduling does not create register entries.</p>

    <section class="admin-card sacrament-group-card">
        <div class="sacrament-group-section-heading">
            <div>
                <p class="sacrament-group-eyebrow">Step 2</p>
                <h2>Create a group</h2>
            </div>
            <p>Use one group for applications with the same sacrament and calendar year.</p>
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
            <label>Calendar year *
                <input type="text" name="calendar_year" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="<?= e(date('Y')) ?>" value="<?= e(date('Y')) ?>" required>
            </label>
            <label>Group or organization name <span class="field-hint">(optional)</span>
                <input type="text" name="group_label" maxlength="60">
            </label>
            <button type="submit" class="btn">Create group</button>
        </form>
        <p class="sacrament-group-hint">Only approved group applications for the selected sacrament and calendar year can be added. A submitted group label must match.</p>
    </section>

    <?php if ($selectedBatch !== null): ?>
        <section class="admin-card sacrament-group-card sacrament-group-manager" id="group-management">
            <header class="sacrament-group-manager-heading">
                <div>
                    <p class="sacrament-group-eyebrow">Selected group</p>
                    <h2><?= e($selectedBatch['batch_name']) ?></h2>
                    <p><?= e($sacramentLabels[$selectedBatch['sacrament_type']] ?? $selectedBatch['sacrament_type']) ?> · Calendar year <?= e(trim((string) $selectedBatch['school_year'])) ?><?= $selectedBatch['grade_level'] !== 'general' ? ' · ' . e($gradeOptions[$selectedBatch['grade_level']] ?? $selectedBatch['grade_level']) : '' ?></p>
                </div>
                <span class="sacrament-group-status<?= $batchEvent !== null ? ' is-scheduled' : '' ?>">
                    <?php if ($batchEvent !== null): ?>
                        Schedule confirmed
                    <?php elseif ($assignedApplications === []): ?>
                        Needs applicants
                    <?php elseif (count(array_filter(
                        $assignedApplications,
                        static fn(array $application): bool => !in_array($application['status'], ['approved', 'scheduled', 'rejected', 'completed'], true)
                    )) > 0): ?>
                        Group review needed
                    <?php else: ?>
                        Ready to schedule
                    <?php endif; ?>
                </span>
            </header>
            <?php if ($isSubmittedCommunionGroup): ?>
                <div class="sacrament-group-review-actions">
                    <?php if ($batchEvent === null && $activeGroupApplications !== []): ?>
                        <?php if ($groupNeedsReview): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="review_group_status">
                                <input type="hidden" name="batch_id" value="<?= $batchId ?>">
                                <input type="hidden" name="group_status" value="reviewing">
                                <button type="submit" class="btn btn-secondary">Start group review</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($groupNeedsApproval): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="review_group_status">
                                <input type="hidden" name="batch_id" value="<?= $batchId ?>">
                                <input type="hidden" name="group_status" value="approved">
                                <button type="submit" class="btn">Approve group for preparation</button>
                            </form>
                        <?php else: ?>
                            <span class="field-hint">Group approval is saved; set the shared schedule below.</span>
                        <?php endif; ?>
                    <?php elseif ($batchEvent === null): ?>
                        <p class="field-hint">No active candidates remain to review.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($assignedApplications !== []): ?>
                <?php
                $canScheduleBatch = count(array_filter(
                    $assignedApplications,
                    static fn(array $application): bool => !in_array($application['status'], ['approved', 'scheduled', 'rejected', 'completed'], true)
                )) === 0;
                ?>
                <?php
                $requestedGroupVenue = ($groupContactDetails['communion_venue'] ?? '') === 'other'
                    ? trim((string) ($groupContactDetails['communion_venue_other'] ?? ''))
                    : (($groupContactDetails['communion_venue'] ?? '') === 'parish_church' ? 'St. Patrick’s Parish Church' : '');
                ?>
                <p class="sacrament-group-confirmed-schedule">
                    <strong>Group contact:</strong> <?= e((string) ($groupContactDetails['contact_person_name'] ?? 'Not provided')) ?>
                    · <?= e((string) ($groupContactDetails['contact_person_number'] ?? '')) ?>
                    · <?= e((string) ($groupContactDetails['contact_person_email'] ?? '')) ?>
                </p>
                <?php if ($requestedGroupVenue !== ''): ?>
                    <p class="sacrament-group-confirmed-schedule"><strong>Requested group venue:</strong> <?= e($requestedGroupVenue) ?> (subject to confirmation)</p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($batchEvent !== null): ?>
                <p class="sacrament-group-confirmed-schedule"><strong>Shared schedule</strong>
                    <?= e(date('F j, Y', strtotime((string) $batchEvent['event_date']))) ?>
                    <?= $batchEvent['start_time'] ? e(format_event_time_range(
                        (string) $batchEvent['start_time'],
                        $batchEvent['end_time'] !== null ? (string) $batchEvent['end_time'] : null
                    )) : '— time to be confirmed' ?>
                    <?php if ($confirmedGroupVenue !== ''): ?> · <strong>Venue:</strong> <?= e($confirmedGroupVenue) ?><?php else: ?> · Venue to be confirmed<?php endif; ?>
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
                            <?php $candidateDetails = json_decode((string) ($candidate['details'] ?? ''), true);
                            $candidateDetails = is_array($candidateDetails) ? $candidateDetails : []; ?>
                            <li>
                                <label class="sacrament-group-applicant">
                                    <input type="checkbox" name="application_ids[]" value="<?= (int) $candidate['id'] ?>">
                                    <span class="sacrament-group-applicant__name"><?= e($candidate['applicant_name']) ?></span>
                                    <span class="sacrament-group-applicant__meta"><?= e($candidate['request_no']) ?> · <?= e($candidate['applicant_email']) ?>
                                        <?php if (trim((string) ($candidateDetails['contact_person_name'] ?? '')) !== ''): ?>
                                            · Contact: <?= e((string) $candidateDetails['contact_person_name']) ?>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($candidateDetails['group_label'] ?? '')) !== ''): ?>
                                            · Requested group: <?= e((string) $candidateDetails['group_label']) ?>
                                        <?php endif; ?>
                                    </span>
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
                        <?php $assignedDetails = json_decode((string) ($assigned['details'] ?? ''), true);
                        $assignedDetails = is_array($assignedDetails) ? $assignedDetails : []; ?>
                        <li>
                            <div class="sacrament-group-assigned-person">
                                <strong><?= e($assigned['applicant_name']) ?></strong>
                                <span><?= e($assigned['request_no']) ?> · <?= e($assigned['applicant_email']) ?> · <?= !empty($assigned['person_id']) ? 'Profile linked' : 'Identity match pending' ?></span>
                            </div>
                            <span class="sacrament-group-status"><?= e(status_label('application', (string) $assigned['status'])) ?></span>
                            <?php if (($assignedDetails['intake_mode'] ?? '') !== 'group'): ?>
                                <a class="sacrament-group-review" href="<?= e($basePath . '/admin/requests/applications-queue.php?' . http_build_query([
                                        'q' => $assigned['request_no'],
                                        'open_application_id' => (int) $assigned['id'],
                                    ])) ?>">Review application</a>
                            <?php endif; ?>
                            <?php if ($batchEvent === null && ($assignedDetails['intake_mode'] ?? '') !== 'group'): ?>
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

            <?php if ($assignedApplications !== [] && ($canScheduleBatch ?? false)): ?>
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
                    <label>Confirmed date *
                        <input type="date" name="event_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e((string) ($batchEvent['event_date'] ?? '')) ?>" required>
                    </label>
                    <label>Start time
                        <input type="time" name="start_time" value="<?= $batchEvent && $batchEvent['start_time'] ? e(date('H:i', strtotime((string) $batchEvent['start_time']))) : '' ?>">
                    </label>
                    <label>End time
                        <input type="time" name="end_time" value="<?= $batchEvent && $batchEvent['end_time'] ? e(date('H:i', strtotime((string) $batchEvent['end_time']))) : '' ?>">
                    </label>
                    <label>Confirmed venue <span class="field-hint">(leave blank if still to be confirmed)</span>
                        <input type="text" name="confirmed_venue" maxlength="255" value="<?= e($confirmedGroupVenue) ?>">
                    </label>
                    <button type="submit" class="btn"><?= $batchEvent === null ? 'Confirm and notify families' : 'Update and notify families' ?></button>
                </form>
                </section>
            <?php elseif ($assignedApplications !== []): ?>
                <p class="sacrament-group-empty">Use the group approval controls above before scheduling. Individual profile and Baptism checks remain necessary before register encoding.</p>
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
                            <p><?= e($sacramentLabels[$batch['sacrament_type']] ?? $batch['sacrament_type']) ?> · <?= $batch['grade_level'] === 'general' ? 'Calendar year ' . e(trim((string) $batch['school_year'])) : e($gradeOptions[$batch['grade_level']] ?? $batch['grade_level']) . ' · School year ' . e($batch['school_year']) ?></p>
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
