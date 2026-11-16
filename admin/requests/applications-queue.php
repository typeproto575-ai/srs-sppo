<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Sacrament Pre-Applications Queue';
if (!defined('APP_IN_PROGRESS_STATUSES')) {
    define('APP_IN_PROGRESS_STATUSES', ['reviewing', 'requirements_pending', 'approved', 'scheduled']);
}
$statuses = array_merge(['in_progress'], APP_IN_PROGRESS_STATUSES, ['new', 'completed', 'rejected']);
if (!defined('APPLICATION_STALE_DAYS')) {
    define('APPLICATION_STALE_DAYS', 180);
}
if (!defined('APPLICATION_CLOSE_REASONS')) {
    define('APPLICATION_CLOSE_REASONS', [
        'Entered by mistake',
        'No response after a long time',
    ]);
}
$sacraments = ['baptism', 'first_communion', 'confirmation', 'marriage'];
$staffUsers = $pdo->query(
    'SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name, id'
)->fetchAll(PDO::FETCH_ASSOC);
$assigneeOptions = ['unassigned' => 'Unassigned'];
foreach ($staffUsers as $staffUser) {
    $assigneeOptions[(string) $staffUser['id']] = (string) $staffUser['full_name'];
}
if (isset($_GET['assignee']) && !is_string($_GET['assignee'])) {
    unset($_GET['assignee']);
}
$requirementStatuses = ['not_received', 'received', 'verified'];
$requirementKeys = [
    'baptism' => ['psa_birth_certificate', 'godparent_eligibility', 'guardianship_papers'],
    'first_communion' => ['origin_baptismal_certificate', 'first_reconciliation', 'catechism_completion', 'parent_guardian_commitment'],
    'confirmation' => ['psa_birth_certificate', 'origin_baptismal_certificate', 'first_communion_verified', 'confirmation_sponsor_details', 'parent_guardian_commitment'],
    'marriage' => [
        'psa_cenomar_groom',
        'psa_cenomar_bride',
        'origin_baptismal_certificate_groom',
        'origin_baptismal_certificate_bride',
        'transfer_letter',
        'pre_cana_seminar',
        'marriage_license_application',
        'origin_confirmation_certificate_groom',
        'origin_confirmation_certificate_bride',
    ],
];
$returnKeys = ['status', 'sacrament', 'channel', 'assignee', 'q', 'range', 'date_from', 'date_to', 'per_page', 'page', 'open_application_id'];
$returnParams = array_intersect_key($_GET, array_flip($returnKeys));
$returnQuery = http_build_query($returnParams);
$returnUrl = '/admin/requests/applications-queue.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
$openApplicationId = filter_var($_GET['open_application_id'] ?? null, FILTER_VALIDATE_INT);
$openApplicationId = $openApplicationId && $openApplicationId > 0 ? (int) $openApplicationId : 0;

function application_queue_label(string $value): string
{
    $labels = [
        'age_at_submission' => 'Age at Submission',
        'age_eligibility_status' => 'Age Eligibility Assessment',
        'birth_place' => 'Place of Birth',
        'baptized_here' => 'Baptism Location',
        'formation_path' => 'Requested Formation Pathway',
        'school_grade' => 'Current Grade Level',
        'school_year' => 'School Year',
        'sponsor_names' => 'Proposed Confirmation Sponsor(s)',
        'sponsor_eligibility_confirmed' => 'Sponsor Eligibility Declared at Submission',
        'guardian_relationship' => 'Guardian Relationship',
        'parent_guardian_name' => 'Guardian / Authorized Representative',
        'batch' => 'Regular parish group',
        'individual_catchup' => 'Individual / catch-up pathway',
        'not_sure' => 'Not sure — please advise',
        'this_parish' => 'This parish',
        'elsewhere' => 'Another parish / church',
    ];

    if (isset($labels[$value])) {
        return $labels[$value];
    }

    return ucwords(str_replace('_', ' ', $value));
}

function application_queue_render_detail_value(mixed $value): void
{
    if (is_array($value)) {
        if ($value === []) {
            echo '—';
            return;
        }

        $isParticipantList = array_is_list($value);
        foreach ($value as $item) {
            if (!is_array($item)
                || count($item) !== 2
                || !is_scalar($item['role'] ?? null)
                || !is_scalar($item['full_name'] ?? null)) {
                $isParticipantList = false;
                break;
            }
        }
        if ($isParticipantList) {
            echo '<ol class="application-detail-compact">';
            foreach ($value as $item) {
                echo '<li><span class="detail-role">' . e(application_queue_label((string) $item['role'])) . '</span> '
                    . e((string) $item['full_name']) . '</li>';
            }
            echo '</ol>';
            return;
        }

        $isList = array_is_list($value);
        echo '<ul class="application-detail-values">';
        foreach ($value as $key => $nestedValue) {
            echo '<li>';
            if (!$isList) {
                echo '<strong>' . e(application_queue_label((string) $key)) . ':</strong> ';
            }
            application_queue_render_detail_value($nestedValue);
            echo '</li>';
        }
        echo '</ul>';
        return;
    }

    if ($value === null || $value === '') {
        echo '—';
        return;
    }

    if (is_bool($value)) {
        echo $value ? 'Yes' : 'No';
        return;
    }

    $text = (string) $value;
    $knownValueLabels = ['batch', 'individual_catchup', 'not_sure', 'this_parish', 'elsewhere'];
    echo e(str_contains($text, '_') || in_array($text, $knownValueLabels, true)
        ? application_queue_label($text)
        : $text);
}

function application_queue_details(array $application): array
{
    $details = json_decode((string) ($application['details'] ?? ''), true);
    return is_array($details) ? $details : [];
}

function application_queue_has_verified_baptism_record(PDO $pdo, int $personId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM sacrament_records
         WHERE person_id = ? AND sacrament_type = 'baptism'
           AND book_verified_at IS NOT NULL
         LIMIT 1"
    );
    $stmt->execute([$personId]);
    return $stmt->fetchColumn() !== false;
}

function application_queue_exact_baptism_matches(PDO $pdo, string $fullName, string $birthDate): array
{
    if ($fullName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
        return [];
    }

    $year = (int) substr($birthDate, 0, 4);
    $month = (int) substr($birthDate, 5, 2);
    $day = (int) substr($birthDate, 8, 2);
    if (!checkdate($month, $day, $year)) {
        return [];
    }

    [$firstName, $middleName, $lastName, $suffix] = structured_name_parts($fullName);
    if ($firstName === '' || $lastName === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT p.id, p.first_name, p.middle_name, p.last_name, p.suffix, p.birth_date,
                p.father_name, p.mother_maiden_name, p.address,
                r.id AS baptism_record_id, r.source, r.event_date AS baptism_event_date,
                r.entry_no, r.book_no, r.page_no, r.line_no, r.book_verified_at
         FROM persons p
         INNER JOIN sacrament_records r
            ON r.person_id = p.id AND r.sacrament_type = 'baptism'
         WHERE p.is_deceased = 0
           AND p.first_name = ?
           AND COALESCE(p.middle_name, '') = ?
           AND p.last_name = ?
           AND COALESCE(p.suffix, '') = ?
           AND p.birth_date = ?
           AND r.id = (
               SELECT r2.id
               FROM sacrament_records r2
               WHERE r2.person_id = p.id AND r2.sacrament_type = 'baptism'
               ORDER BY (r2.book_verified_at IS NOT NULL) DESC, r2.event_date DESC, r2.id DESC
               LIMIT 1
           )
         ORDER BY p.id
         LIMIT 2"
    );
    $stmt->execute([$firstName, $middleName, $lastName, $suffix, $birthDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function application_queue_person_match(array $application, array $details, string $role): array
{
    $personDetails = $role === 'spouse' ? ($details['bride'] ?? []) : ($details['groom'] ?? []);
    if (!is_array($personDetails)) {
        $personDetails = [];
    }

    $name = '';
    $birthDate = '';
    if ($application['sacrament_type'] === 'marriage') {
        $name = trim((string) ($personDetails['full_name'] ?? $personDetails['name'] ?? ''));
        $birthDate = trim((string) ($personDetails['birth_date'] ?? $personDetails['birthdate'] ?? ''));
    } else {
        foreach (['child_name', 'candidate_name', 'applicant_full_name', 'subject_name', 'full_name'] as $key) {
            if (isset($details[$key]) && is_string($details[$key]) && trim($details[$key]) !== '') {
                $name = trim($details[$key]);
                break;
            }
        }
        foreach (['child_birth_date', 'child_birthdate', 'candidate_birth_date', 'birth_date', 'birthdate'] as $key) {
            if (isset($details[$key]) && is_string($details[$key]) && trim($details[$key]) !== '') {
                $birthDate = trim($details[$key]);
                break;
            }
        }
        if ($name === '') {
            $name = trim((string) $application['applicant_name']);
        }
    }

    return [
        'role' => $role,
        'name' => $name,
        'birth_date' => $birthDate,
        'person_id' => $role === 'spouse'
            ? ($application['spouse_person_id'] ?? null)
            : ($application['person_id'] ?? null),
    ];
}

function application_queue_return_url_from_post(array $allowedKeys): string
{
    $rawQuery = (string) ($_POST['return_query'] ?? '');
    parse_str($rawQuery, $parsed);
    if (!is_array($parsed)) {
        $parsed = [];
    }
    $params = array_intersect_key($parsed, array_flip($allowedKeys));
    foreach ($params as $key => $value) {
        if (!is_scalar($value)) {
            unset($params[$key]);
        }
    }
    $query = http_build_query($params);
    $url = '/admin/requests/applications-queue.php' . ($query !== '' ? '?' . $query : '');
    $openApplicationId = filter_var($params['open_application_id'] ?? null, FILTER_VALIDATE_INT);
    if ($openApplicationId && $openApplicationId > 0) {
        $url .= '#application-review-' . (int) $openApplicationId;
    }
    return $url;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postReturnUrl = application_queue_return_url_from_post($returnKeys);
    if (!csrf_verify()) {
        $_SESSION['application_queue_error'] = 'Your session expired. Please try again.';
        redirect_to($postReturnUrl);
    }

    $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    if (!$applicationId || $applicationId < 1) {
        $_SESSION['application_queue_error'] = 'Select a valid pre-application.';
        redirect_to($postReturnUrl);
    }

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare(
            'SELECT id, request_no, sacrament_type, person_id, spouse_person_id, status, requirements, assigned_to
             FROM sacrament_applications
             WHERE id = ?
             FOR UPDATE'
        );
        $lock->execute([(int) $applicationId]);
        $application = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$application) {
            $pdo->rollBack();
            $_SESSION['application_queue_error'] = 'The pre-application could not be found.';
            redirect_to($postReturnUrl);
        }

        if (in_array($action, ['link_person', 'update_requirement', 'assign_staff', 'set_schedule', 'set_formation_path'], true)
            && in_array($application['status'], ['completed', 'rejected'], true)) {
            $pdo->rollBack();
            $_SESSION['application_queue_error'] = 'Completed or rejected pre-applications cannot be reassigned or edited.';
            redirect_to($postReturnUrl);
        }

        if ($action === 'set_schedule') {
            $applicationDetails = json_decode((string) ($application['details'] ?? ''), true);
            $formationPath = is_array($applicationDetails)
                ? (string) ($applicationDetails['formation_path'] ?? '')
                : '';
            if (in_array($application['sacrament_type'], ['first_communion', 'confirmation'], true)
                && $formationPath !== ''
                && $formationPath !== 'individual_catchup') {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = $formationPath === 'batch'
                    ? 'This application requests group preparation. Assign it to a shared group and set the group schedule from Sacrament Groups.'
                    : 'Confirm the formation pathway with the family before setting an individual schedule.';
                redirect_to($postReturnUrl);
            }
            if (!in_array($application['status'], ['approved', 'scheduled'], true)) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'Approve the pre-application before setting its confirmed schedule.';
                redirect_to($postReturnUrl);
            }

            $scheduledDate = is_string($_POST['scheduled_date'] ?? null)
                ? trim($_POST['scheduled_date'])
                : '';
            $scheduledStartTime = is_string($_POST['scheduled_start_time'] ?? null)
                ? trim($_POST['scheduled_start_time'])
                : '';
            $scheduledEndTime = is_string($_POST['scheduled_end_time'] ?? null)
                ? trim($_POST['scheduled_end_time'])
                : '';
            $scheduleInputError = application_schedule_input_error(
                $scheduledDate,
                $scheduledStartTime,
                $scheduledEndTime
            );
            if ($scheduleInputError !== null) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = $scheduleInputError;
                redirect_to($postReturnUrl);
            }

            $scheduleEventStmt = $pdo->prepare(
                'SELECT id, event_date, start_time, end_time
                 FROM schedule_events
                 WHERE application_id = ?
                 ORDER BY id
                 FOR UPDATE'
            );
            $scheduleEventStmt->execute([(int) $applicationId]);
            $linkedScheduleEvents = $scheduleEventStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($linkedScheduleEvents) > 1) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'This pre-application has multiple linked calendar events. Resolve the duplicates before changing its schedule.';
                redirect_to($postReturnUrl);
            }

            $existingSchedule = $linkedScheduleEvents[0] ?? null;
            $existingStartTime = $existingSchedule && $existingSchedule['start_time'] !== null
                ? substr((string) $existingSchedule['start_time'], 0, 5)
                : '';
            $existingEndTime = $existingSchedule && $existingSchedule['end_time'] !== null
                ? substr((string) $existingSchedule['end_time'], 0, 5)
                : '';
            $scheduleChanged = $existingSchedule === null
                || (string) $existingSchedule['event_date'] !== $scheduledDate
                || $existingStartTime !== $scheduledStartTime
                || $existingEndTime !== $scheduledEndTime;
            $statusChanged = $application['status'] !== 'scheduled';
            if (!$scheduleChanged && !$statusChanged) {
                $pdo->rollBack();
                $_SESSION['application_queue_success'] = 'That confirmed schedule is already saved.';
                redirect_to($postReturnUrl);
            }

            $eventType = match ($application['sacrament_type']) {
                'baptism' => 'baptism',
                'marriage' => 'wedding',
                default => 'other',
            };
            $eventTitle = match ($application['sacrament_type']) {
                'baptism' => 'Baptism',
                'first_communion' => 'First Communion',
                'confirmation' => 'Confirmation',
                'marriage' => 'Wedding',
                default => 'Parish Sacrament',
            };
            $startTimeValue = $scheduledStartTime !== '' ? $scheduledStartTime : null;
            $endTimeValue = $scheduledEndTime !== '' ? $scheduledEndTime : null;

            if ($existingSchedule === null) {
                $insertSchedule = $pdo->prepare(
                    'INSERT INTO schedule_events
                        (event_type, title, public_details, event_date, start_time, end_time, application_id, notes, created_by)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)'
                );
                $insertSchedule->execute([
                    $eventType,
                    $eventTitle,
                    $scheduledDate,
                    $startTimeValue,
                    $endTimeValue,
                    (int) $applicationId,
                    'Linked to pre-application ' . (string) $application['request_no'] . '.',
                    (int) current_user()['id'],
                ]);
            } else {
                $updateSchedule = $pdo->prepare(
                    'UPDATE schedule_events
                     SET event_type = ?, event_date = ?, start_time = ?, end_time = ?
                     WHERE id = ?'
                );
                $updateSchedule->execute([
                    $eventType,
                    $scheduledDate,
                    $startTimeValue,
                    $endTimeValue,
                    (int) $existingSchedule['id'],
                ]);
            }

            if ($statusChanged) {
                $updateStatus = $pdo->prepare(
                    "UPDATE sacrament_applications SET status = 'scheduled' WHERE id = ?"
                );
                $updateStatus->execute([(int) $applicationId]);
            }

            $dateLabel = date('F j, Y', strtotime($scheduledDate));
            $timeLabel = $startTimeValue !== null
                ? format_event_time_range($startTimeValue, $endTimeValue)
                : 'To be confirmed';
            $scheduleNote = 'Confirmed schedule: ' . $dateLabel . '; time: ' . $timeLabel
                . '. Please contact the Parish Office promptly if you cannot attend.';
            $logAction = $existingSchedule === null
                ? 'sacrament_application_schedule_set'
                : 'sacrament_application_schedule_updated';
            log_activity(
                $pdo,
                (int) current_user()['id'],
                $logAction,
                'sacrament_applications',
                (int) $applicationId,
                'Confirmed schedule set to ' . $dateLabel . ' at ' . $timeLabel . '.'
            );
            $pdo->commit();

            notify_sacrament_application_status(
                $pdo,
                'scheduled',
                (string) $application['sacrament_type'],
                (string) $application['request_no'],
                $scheduleNote
            );
            $_SESSION['application_queue_success'] = $statusChanged
                ? 'Confirmed schedule saved and the application is now Scheduled.'
                : 'Confirmed schedule updated.';
        } elseif ($action === 'assign_staff') {
            $assigneeInput = (string) ($_POST['assignee_id'] ?? '');
            $assigneeId = null;
            $assigneeName = null;
            if ($assigneeInput !== '' && $assigneeInput !== 'unassigned') {
                $validatedAssigneeId = filter_var($assigneeInput, FILTER_VALIDATE_INT);
                if (!$validatedAssigneeId || $validatedAssigneeId < 1) {
                    $pdo->rollBack();
                    $_SESSION['application_queue_error'] = 'Choose an active staff member or leave the request unassigned.';
                    redirect_to($postReturnUrl);
                }

                $assigneeStmt = $pdo->prepare(
                    'SELECT id, full_name FROM users WHERE id = ? AND is_active = 1 FOR UPDATE'
                );
                $assigneeStmt->execute([(int) $validatedAssigneeId]);
                $assignee = $assigneeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$assignee) {
                    $pdo->rollBack();
                    $_SESSION['application_queue_error'] = 'That staff account is inactive or no longer exists.';
                    redirect_to($postReturnUrl);
                }
                $assigneeId = (int) $assignee['id'];
                $assigneeName = (string) $assignee['full_name'];
            }

            $previousAssigneeId = (int) ($application['assigned_to'] ?? 0);
            if ($previousAssigneeId === (int) ($assigneeId ?? 0)) {
                $pdo->rollBack();
                $_SESSION['application_queue_success'] = 'No assignment change was needed.';
                redirect_to($postReturnUrl);
            }

            $previousAssigneeName = null;
            if ($previousAssigneeId > 0) {
                $previousStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
                $previousStmt->execute([$previousAssigneeId]);
                $previousAssigneeName = $previousStmt->fetchColumn();
            }
            $update = $pdo->prepare('UPDATE sacrament_applications SET assigned_to = ? WHERE id = ?');
            $update->execute([$assigneeId, (int) $applicationId]);

            $user = current_user();
            $actionName = $assigneeId === null
                ? 'sacrament_application_unassigned'
                : 'sacrament_application_assigned';
            $description = $assigneeId === null
                ? 'Unassigned request from ' . ($previousAssigneeName ?: 'a previous staff member') . '.'
                : 'Assigned request to ' . $assigneeName . ' (previously '
                    . ($previousAssigneeName ?: 'unassigned') . ').';
            log_activity(
                $pdo,
                isset($user['id']) ? (int) $user['id'] : null,
                $actionName,
                'sacrament_applications',
                (int) $applicationId,
                $description
            );
            $pdo->commit();
            $_SESSION['application_queue_success'] = $assigneeId === null
                ? 'Request returned to the unassigned queue.'
                : 'Request assigned to ' . $assigneeName . '.';
        } elseif ($action === 'link_person') {
            $role = (string) ($_POST['person_role'] ?? '');
            $personId = filter_var($_POST['person_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$personId || $personId < 1 || !in_array($role, ['applicant', 'spouse'], true)) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'Select a valid person profile.';
                redirect_to($postReturnUrl);
            }
            if ($role === 'spouse' && $application['sacrament_type'] !== 'marriage') {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'Only marriage pre-applications can link a spouse profile.';
                redirect_to($postReturnUrl);
            }

            $personExists = $pdo->prepare('SELECT id FROM persons WHERE id = ?');
            $personExists->execute([(int) $personId]);
            if (!$personExists->fetchColumn()) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'That person profile no longer exists. Search again.';
                redirect_to($postReturnUrl);
            }

            $otherPersonId = $role === 'spouse'
                ? (int) ($application['person_id'] ?? 0)
                : (int) ($application['spouse_person_id'] ?? 0);
            if ($application['sacrament_type'] === 'marriage' && $otherPersonId === (int) $personId) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'The two marriage applicants must have different person profiles.';
                redirect_to($postReturnUrl);
            }

            $personColumn = $role === 'spouse' ? 'spouse_person_id' : 'person_id';
            $update = $pdo->prepare("UPDATE sacrament_applications SET {$personColumn} = ? WHERE id = ?");
            $update->execute([(int) $personId, (int) $applicationId]);
            $pdo->commit();
            $_SESSION['application_queue_success'] = 'Person profile linked to the application.';
        } elseif ($action === 'set_formation_path') {
            $newFormationPath = is_string($_POST['formation_path'] ?? null)
                ? $_POST['formation_path']
                : '';
            if (!in_array($application['sacrament_type'], ['first_communion', 'confirmation'], true)
                || !in_array($newFormationPath, ['batch', 'individual_catchup'], true)
                || !empty($application['batch_id'])
                || $application['status'] === 'scheduled') {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'The formation pathway cannot be changed for this application.';
                redirect_to($postReturnUrl);
            }
            $applicationDetails = json_decode((string) ($application['details'] ?? ''), true);
            if (!is_array($applicationDetails)) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'Application details are unreadable, so the pathway was not changed.';
                redirect_to($postReturnUrl);
            }
            $applicationDetails['formation_path'] = $newFormationPath;
            $encodedDetails = json_encode($applicationDetails, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $update = $pdo->prepare('UPDATE sacrament_applications SET details = ? WHERE id = ?');
            $update->execute([$encodedDetails, (int) $applicationId]);
            log_activity(
                $pdo,
                (int) current_user()['id'],
                'sacrament_application_formation_path_updated',
                'sacrament_applications',
                (int) $applicationId,
                'Formation pathway set to ' . $newFormationPath
            );
            $pdo->commit();
            $_SESSION['application_queue_success'] = 'Formation pathway updated.';
        } elseif ($action === 'update_requirement') {
            $requirementKey = (string) ($_POST['requirement_key'] ?? '');
            $requirementStatus = (string) ($_POST['requirement_status'] ?? '');
            $requirementNote = trim((string) ($_POST['requirement_note'] ?? ''));
            if (!in_array($requirementKey, $requirementKeys[$application['sacrament_type']] ?? [], true)
                || !in_array($requirementStatus, $requirementStatuses, true)
                || strlen($requirementNote) > 500) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'The selected requirement is invalid.';
                redirect_to($postReturnUrl);
            }

            $requirements = json_decode((string) ($application['requirements'] ?? ''), true);
            if (!is_array($requirements)) {
                $requirements = [];
            }
            $requirements[$requirementKey] = [
                'status' => $requirementStatus,
                'note' => $requirementNote,
            ];
            $encodedRequirements = json_encode($requirements, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $update = $pdo->prepare('UPDATE sacrament_applications SET requirements = ? WHERE id = ?');
            $update->execute([$encodedRequirements, (int) $applicationId]);
            $pdo->commit();
            $_SESSION['application_queue_success'] = 'Requirement checklist saved.';
        } elseif ($action === 'update_status') {
            $currentStatus = (string) $application['status'];
            $newStatus = (string) ($_POST['new_status'] ?? '');
            if ($newStatus === 'scheduled') {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'Use the confirmed schedule controls to set a date and move the application to Scheduled.';
                redirect_to($postReturnUrl);
            }
            if (!in_array($newStatus, application_queue_next_statuses($currentStatus), true)) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'That status transition is not allowed.';
                redirect_to($postReturnUrl);
            }

            $update = $pdo->prepare('UPDATE sacrament_applications SET status = ? WHERE id = ?');
            $update->execute([$newStatus, (int) $applicationId]);
            if ($newStatus === 'approved') {
                $outstandingKeys = application_outstanding_requirement_keys((string) ($application['requirements'] ?? ''));
                if ($application['sacrament_type'] === 'first_communion'
                    && (int) ($application['person_id'] ?? 0) > 0
                    && application_queue_has_verified_baptism_record($pdo, (int) $application['person_id'])) {
                    $outstandingKeys = array_values(array_diff($outstandingKeys, ['origin_baptismal_certificate']));
                }
                if ($outstandingKeys !== []) {
                    $outstandingLabels = array_map(
                        static fn(string $key): string => application_queue_requirement_label((string) $application['sacrament_type'], $key),
                        $outstandingKeys
                    );
                    $pdo->rollBack();
                    $_SESSION['application_queue_error'] = 'This application cannot be approved yet. Still not received: ' . implode(', ', $outstandingLabels) . '. Mark them received, or keep the application as Pending.';
                    redirect_to($postReturnUrl);
                }
            }
            $closeReason = '';
            if ($newStatus === 'rejected') {
                $closeReason = (string) ($_POST['close_reason'] ?? '');
                if (!in_array($closeReason, APPLICATION_CLOSE_REASONS, true)) {
                    $pdo->rollBack();
                    $_SESSION['application_queue_error'] = 'Choose a reason for closing this application.';
                    redirect_to($postReturnUrl);
                }
            }
            $update = $pdo->prepare(
                "UPDATE sacrament_applications
                 SET status = ?, rejection_reason = ?,
                     batch_id = CASE WHEN ? = 'rejected' THEN NULL ELSE batch_id END
                 WHERE id = ?"
            );
            $update->execute([
                $newStatus,
                $newStatus === 'rejected' ? $closeReason : null,
                $newStatus,
                (int) $applicationId,
            ]);
            $pdo->commit();
            notify_sacrament_application_status(
                $pdo,
                $newStatus,
                (string) $application['sacrament_type'],
                (string) $application['request_no']
            );
            $_SESSION['application_queue_success'] = 'Application status updated to ' . status_label('application', $newStatus) . '.';
        } else {
            $pdo->rollBack();
            $_SESSION['application_queue_error'] = 'The requested action is invalid.';
            redirect_to($postReturnUrl);
        }
    } catch (JsonException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Application requirements JSON encoding failed: ' . $e->getMessage());
        $_SESSION['application_queue_error'] = 'The checklist could not be saved. Please try again.';
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Application queue update failed: ' . $e->getMessage());
        $_SESSION['application_queue_error'] = 'The pre-application update failed. Please try again.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Application queue update failed: ' . $e->getMessage());
        $_SESSION['application_queue_error'] = 'The pre-application update failed. Please try again.';
    }

    redirect_to($postReturnUrl);
}

$selfUrl = $basePath . '/admin/requests/applications-queue.php';
$rfConfig = [
    'date_column' => 'DATE(sa.created_at)',
    'search_columns' => ['sa.request_no', 'sa.applicant_name', 'CAST(sa.details AS CHAR)'],
    'placeholder' => 'Search request number, applicant, or application details...',
    'ranges' => ['today', 'week', 'month', 'year'],
    'filters' => [
        'status' => ['label' => 'Status', 'options' => array_combine($statuses, array_map(static fn($s) => status_label('application', (string) $s), $statuses))],
        'channel' => ['label' => 'Channel', 'options' => ['online' => 'Submitted: Online', 'onsite' => 'Submitted: At the office']],
        'sacrament' => ['label' => 'Sacrament', 'options' => array_combine($sacraments, array_map('application_queue_label', $sacraments))],
    ],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$filterStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$filterSacrament = is_string($_GET['sacrament'] ?? null) ? $_GET['sacrament'] : '';
$filterChannel = is_string($_GET['channel'] ?? null) ? $_GET['channel'] : '';
$filterAssignee = is_string($_GET['assignee'] ?? null) ? $_GET['assignee'] : '';
$whereSql = ' WHERE 1 = 1' . $rf['where'];
$params = $rf['params'];

if ($filterStatus === 'in_progress') {
    $placeholders = implode(',', array_fill(0, count(APP_IN_PROGRESS_STATUSES), '?'));
    $whereSql .= " AND sa.status IN ($placeholders)";
    foreach (APP_IN_PROGRESS_STATUSES as $s) {
        $params[] = $s;
    }
} elseif (in_array($filterStatus, $statuses, true)) {
    $whereSql .= ' AND sa.status = ?';
    $params[] = $filterStatus;
}
if (in_array($filterSacrament, $sacraments, true)) {
    $whereSql .= ' AND sa.sacrament_type = ?';
    $params[] = $filterSacrament;
}
if (in_array($filterChannel, ['online', 'onsite'], true)) {
    $whereSql .= ' AND sa.channel = ?';
    $params[] = $filterChannel;
}
if ($filterAssignee === 'unassigned') {
    $whereSql .= ' AND sa.assigned_to IS NULL';
} elseif ($filterAssignee !== '' && isset($assigneeOptions[$filterAssignee])) {
    $whereSql .= ' AND sa.assigned_to = ?';
    $params[] = (int) $filterAssignee;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM sacrament_applications sa{$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$page = min($rf['page'], max(1, (int) ceil($totalRows / $rf['per_page'])));
$offset = ($page - 1) * $rf['per_page'];
$limit = (int) $rf['per_page'];
$offset = (int) $offset;
$rf['page'] = $page;
$rf['offset'] = $offset;
$stmt = $pdo->prepare(
    "SELECT sa.id, sa.request_no, sa.channel, sa.sacrament_type, sa.person_id, sa.batch_id, sa.spouse_person_id,
            sa.applicant_name, sa.applicant_email, sa.preferred_date, sa.preferred_time,
            sa.status, sa.details, sa.requirements, sa.record_id, sa.created_at, 
            sa.updated_at, sa.rejection_reason, sa.assigned_to, 
            assignee.full_name AS assigned_to_name, batch.batch_name
     FROM sacrament_applications sa
     LEFT JOIN users assignee ON assignee.id = sa.assigned_to
     LEFT JOIN sacrament_batches batch ON batch.id = sa.batch_id
     {$whereSql}
     ORDER BY sa.created_at DESC, sa.id DESC
     LIMIT {$limit} OFFSET {$offset}"
);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$scheduleEventsByApplication = [];
if ($applications !== []) {
    $applicationIdsForSchedules = array_map(
        static fn(array $application): int => (int) $application['id'],
        $applications
    );
    $schedulePlaceholders = implode(', ', array_fill(0, count($applicationIdsForSchedules), '?'));
    $scheduleEventsStmt = $pdo->prepare(
        "SELECT se.id, COALESCE(se.application_id, batch_app.id) AS linked_application_id,
                se.batch_id, se.event_date, se.start_time, se.end_time
         FROM schedule_events se
         LEFT JOIN sacrament_applications batch_app ON batch_app.batch_id = se.batch_id
         WHERE se.application_id IN ({$schedulePlaceholders})
            OR batch_app.id IN ({$schedulePlaceholders})
         ORDER BY se.id DESC"
    );
    $scheduleEventsStmt->execute(array_merge($applicationIdsForSchedules, $applicationIdsForSchedules));
    foreach ($scheduleEventsStmt->fetchAll(PDO::FETCH_ASSOC) as $scheduleEvent) {
        $scheduleApplicationId = (int) $scheduleEvent['linked_application_id'];
        if (!isset($scheduleEventsByApplication[$scheduleApplicationId])) {
            $scheduleEventsByApplication[$scheduleApplicationId] = $scheduleEvent;
        }
    }
}

$assignmentHistoryByApplication = [];
if ($applications !== []) {
    $applicationIds = array_map(static fn(array $application): int => (int) $application['id'], $applications);
    $applicationPlaceholders = implode(', ', array_fill(0, count($applicationIds), '?'));
    $historyStmt = $pdo->prepare(
        "SELECT target_id, actor_name, description, created_at
         FROM audit_logs
         WHERE target_table = 'sacrament_applications'
           AND action IN ('sacrament_application_assigned', 'sacrament_application_unassigned')
           AND target_id IN ({$applicationPlaceholders})
         ORDER BY created_at DESC, id DESC"
    );
    $historyStmt->execute($applicationIds);
    foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $historyEntry) {
        $assignmentHistoryByApplication[(int) $historyEntry['target_id']][] = $historyEntry;
    }
}

$linkedPersonIds = [];
foreach ($applications as $application) {
    foreach (['person_id', 'spouse_person_id'] as $personColumn) {
        if (!empty($application[$personColumn])) {
            $linkedPersonIds[] = (int) $application[$personColumn];
        }
    }
}
$linkedPersonIds = array_values(array_unique($linkedPersonIds));
$linkedPersons = [];
if ($linkedPersonIds !== []) {
    $personPlaceholders = implode(', ', array_fill(0, count($linkedPersonIds), '?'));
    $personStmt = $pdo->prepare(
        "SELECT id, first_name, middle_name, last_name, suffix, birth_date, father_name,
                mother_maiden_name, address, baptismal_status
         FROM persons
         WHERE id IN ({$personPlaceholders})"
    );
    $personStmt->execute($linkedPersonIds);
    foreach ($personStmt->fetchAll(PDO::FETCH_ASSOC) as $linkedPerson) {
        $linkedPersons[(int) $linkedPerson['id']] = $linkedPerson;
    }
}

$matchSearchApplicationId = filter_var($_GET['match_search_application_id'] ?? null, FILTER_VALIDATE_INT);
$matchSearchApplicationId = $matchSearchApplicationId ? (int) $matchSearchApplicationId : 0;
$matchSearchRole = (string) ($_GET['match_search_role'] ?? '');
$matchSurname = trim((string) ($_GET['match_surname'] ?? ''));
$matchBirthDate = trim((string) ($_GET['match_birth_date'] ?? ''));
$matchSearchError = '';
$matchResults = [];
$applicationById = [];
foreach ($applications as $application) {
    $applicationById[(int) $application['id']] = $application;
}
if ($matchSearchApplicationId > 0) {
    $searchApplication = $applicationById[$matchSearchApplicationId] ?? null;
    $validMatchRole = $matchSearchRole === 'applicant'
        || ($matchSearchRole === 'spouse' && ($searchApplication['sacrament_type'] ?? '') === 'marriage');
    if (!$searchApplication || !$validMatchRole) {
        $matchSearchError = 'Choose a person search from an application on this page.';
    } elseif ($matchSurname === '' && $matchBirthDate === '') {
        $matchSearchError = 'Enter a surname or date of birth to search.';
    } elseif ($matchBirthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $matchBirthDate)) {
        $matchSearchError = 'Enter a valid date of birth.';
    } elseif ($matchBirthDate !== '' && !checkdate(
        (int) substr($matchBirthDate, 5, 2),
        (int) substr($matchBirthDate, 8, 2),
        (int) substr($matchBirthDate, 0, 4)
    )) {
        $matchSearchError = 'Enter a valid date of birth.';
    } else {
        $conditions = [];
        $searchParams = [];
        if ($matchSurname !== '') {
            $conditions[] = 'last_name LIKE ?';
            $searchParams[] = '%' . $matchSurname . '%';
        }
        if ($matchBirthDate !== '') {
            $conditions[] = 'birth_date = ?';
            $searchParams[] = $matchBirthDate;
        }
        $personSearch = $pdo->prepare(
            'SELECT id, first_name, middle_name, last_name, suffix, birth_date,
                    father_name, mother_maiden_name, address, baptismal_status
             FROM persons
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY last_name, first_name, middle_name, id
             LIMIT 25'
        );
        $personSearch->execute($searchParams);
        $matchResults = $personSearch->fetchAll(PDO::FETCH_ASSOC);
    }
}

$recordRowsByPerson = [];
$baptismRecordRowsByPerson = [];
$allLinkedIds = $linkedPersonIds;
if ($allLinkedIds !== []) {
    $recordPlaceholders = implode(', ', array_fill(0, count($allLinkedIds), '?'));
    $recordStmt = $pdo->prepare(
        "SELECT id, sacrament_type, person_id, spouse_person_id, source, event_date,
                entry_no, book_no, page_no, line_no, book_verified_at
         FROM sacrament_records
         WHERE person_id IN ({$recordPlaceholders})
            OR spouse_person_id IN ({$recordPlaceholders})
         ORDER BY event_date DESC, id DESC"
    );
    $recordStmt->execute(array_merge($allLinkedIds, $allLinkedIds));
    foreach ($recordStmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        foreach (['person_id', 'spouse_person_id'] as $recordPersonColumn) {
            $recordPersonId = (int) ($record[$recordPersonColumn] ?? 0);
            if ($recordPersonId > 0) {
                $recordRowsByPerson[$recordPersonId][] = $record['sacrament_type'];
                if ($record['sacrament_type'] === 'baptism'
                    && (int) $record['person_id'] === $recordPersonId) {
                    $baptismRecordRowsByPerson[$recordPersonId][] = $record;
                }
            }
        }
    }
}

$automaticBaptismMatchesByApplication = [];
foreach ($applications as $application) {
    if ($application['sacrament_type'] !== 'first_communion' || !empty($application['person_id'])) {
        continue;
    }
    $applicationId = (int) $application['id'];
    $personMatch = application_queue_person_match(
        $application,
        application_queue_details($application),
        'applicant'
    );
    $baptismMatches = application_queue_exact_baptism_matches(
        $pdo,
        $personMatch['name'],
        $personMatch['birth_date']
    );
    if ($baptismMatches !== []) {
        $automaticBaptismMatchesByApplication[$applicationId] = $baptismMatches;
    }
}

$queueError = (string) ($_SESSION['application_queue_error'] ?? '');
$queueSuccess = (string) ($_SESSION['application_queue_success'] ?? '');
unset($_SESSION['application_queue_error'], $_SESSION['application_queue_success']);

require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="admin-list-page">
    <header class="admin-queue-heading">
        <div>
            <p class="admin-queue-kicker">Requests</p>
            <h1>Sacrament pre-applications</h1>
            <p>Review pre-applications, link master person profiles, track requirements, and continue the parish review process.</p>
        </div>
        <div class="admin-queue-actions">
            <a href="<?= e($basePath . '/admin/requests/sacrament-batches.php') ?>" class="btn btn-secondary">Manage sacrament groups</a>
            <a href="<?= e($basePath . '/public/sacrament-applications/baptismal.php') ?>" class="btn btn-secondary">New walk-in pre-application</a>
        </div>
        <div class="admin-queue-total" aria-label="<?= (int) $totalRows ?> pre-applications match the current filters">
            <strong><?= (int) $totalRows ?></strong>
            <span><?= $totalRows === 1 ? 'pre-application' : 'pre-applications' ?></span>
        </div>
    </header>

    <?php if ($queueError !== ''): ?><p class="alert alert-error" role="alert"><?= e($queueError) ?></p><?php endif; ?>
    <?php if ($queueSuccess !== ''): ?><p class="alert alert-success"><?= e($queueSuccess) ?></p><?php endif; ?>

    <div class="application-queue-filter-wrap">
        <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
    </div>
    <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

    <?php if ($applications === []): ?>
        <p class="admin-queue-empty">No pre-applications match your search or filters.</p>
    <?php else: ?>
        <div class="table-scroll application-queue-table-scroll">
            <table class="admin-table application-queue-table">
                <thead>
                    <tr>
                        <th>Request No.</th>
                        <th>Sacrament</th>
                        <th>Applicant(s)</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Review</th>
                    </tr>
                </thead>
                <tbody>
            <?php foreach ($applications as $app):
                $applicationReturnParams = $returnParams;
                $applicationReturnParams['open_application_id'] = (int) $app['id'];
                $applicationReturnQuery = http_build_query($applicationReturnParams);
                $details = application_queue_details($app);
                $matches = $app['sacrament_type'] === 'marriage'
                    ? [
                        application_queue_person_match($app, $details, 'applicant'),
                        application_queue_person_match($app, $details, 'spouse'),
                    ]
                    : [application_queue_person_match($app, $details, 'applicant')];
                $requirements = json_decode((string) ($app['requirements'] ?? ''), true);
                if (!is_array($requirements)) {
                    $requirements = [];
                }
                $checklistKeys = array_values(array_unique(array_merge(
                    $requirementKeys[$app['sacrament_type']] ?? [],
                    array_intersect(array_keys($requirements), $requirementKeys[$app['sacrament_type']] ?? [])
                )));
                $registerFormPaths = [
                    'baptism' => 'baptism',
                    'first_communion' => 'first_communion',
                    'confirmation' => 'confirmation',
                    'marriage' => 'marriage',
                ];
                $registerEditorType = $registerFormPaths[$app['sacrament_type']] ?? null;
                $prefillUrl = $registerEditorType === null
                    ? null
                    : $basePath . '/admin/records/editor.php?' . http_build_query([
                        'type' => $registerEditorType,
                        'from_application' => (int) $app['id'],
                    ]);
                $validNextStatuses = array_values(array_filter(
                    application_queue_next_statuses((string) $app['status']),
                    static fn(string $status): bool => $status !== 'scheduled'
                ));
                $prerequisiteWarnings = [];
                $prerequisiteChecks = [];
                $hasParishBaptismRecord = false;
                $hasVerifiedBaptismRecord = false;
                foreach ($matches as $match) {
                    $personId = (int) ($match['person_id'] ?? 0);
                    $label = $match['role'] === 'spouse' ? 'Bride' : ($app['sacrament_type'] === 'marriage' ? 'Groom' : 'Applicant');
                    if ($personId < 1) {
                        if (in_array($app['sacrament_type'], ['first_communion', 'confirmation', 'marriage'], true)) {
                            if ($app['sacrament_type'] === 'first_communion') {
                                $baptismMatches = $automaticBaptismMatchesByApplication[(int) $app['id']] ?? [];
                                if (count($baptismMatches) === 1) {
                                    $suggestedRecord = $baptismMatches[0];
                                    $recordReference = implode(' / ', array_values(array_filter(
                                        [
                                            $suggestedRecord['entry_no'] ?? null,
                                            $suggestedRecord['book_no'] ?? null,
                                            $suggestedRecord['page_no'] ?? null,
                                            $suggestedRecord['line_no'] ?? null,
                                        ],
                                        static fn($value): bool => $value !== null && trim((string) $value) !== ''
                                    )));
                                    $prerequisiteChecks[] = "{$label}: a unique exact Baptism-record match was found"
                                        . ($recordReference !== '' ? " (register reference {$recordReference})" : '')
                                        . '. Confirm and link the profile below.';
                                    $prerequisiteWarnings[] = "{$label}: confirm the suggested identity and link the profile before proceeding.";
                                } elseif (count($baptismMatches) > 1) {
                                    $prerequisiteWarnings[] = "{$label}: multiple exact profiles have Baptism records; review the matches below and link the correct profile.";
                                } else {
                                    $prerequisiteWarnings[] = "{$label}: link a parishioner profile, then verify the Baptism record or check the origin-parish certificate before proceeding.";
                                }
                            } else {
                                $prerequisiteWarnings[] = "{$label}: link a parishioner profile, then verify the Baptism record in this parish or check the origin-parish certificate before proceeding.";
                            }
                        }
                        continue;
                    }
                    $personRecords = $recordRowsByPerson[$personId] ?? [];
                    $profile = $linkedPersons[$personId] ?? [];
                    $baptismRecords = $baptismRecordRowsByPerson[$personId] ?? [];
                    $hasParishBaptismRecord = $baptismRecords !== [];
                    $baptismRecord = null;
                    foreach ($baptismRecords as $candidateRecord) {
                        if ($baptismRecord === null) {
                            $baptismRecord = $candidateRecord;
                        }
                        if (!empty($candidateRecord['book_verified_at'])) {
                            $baptismRecord = $candidateRecord;
                            break;
                        }
                    }
                    $hasVerifiedBaptismRecord = $hasVerifiedBaptismRecord
                        || ($baptismRecord !== null && !empty($baptismRecord['book_verified_at']));
                    $hasBaptism = in_array('baptism', $personRecords, true)
                        || ($profile['baptismal_status'] ?? null) === 'baptized_catholic';
                    if ($app['sacrament_type'] === 'first_communion' && $hasParishBaptismRecord) {
                        $registerReference = implode(' / ', array_values(array_filter(
                            [
                                $baptismRecord['entry_no'] ?? null,
                                $baptismRecord['book_no'] ?? null,
                                $baptismRecord['page_no'] ?? null,
                                $baptismRecord['line_no'] ?? null,
                            ],
                            static fn($value): bool => $value !== null && trim((string) $value) !== ''
                        )));
                        $recordDate = !empty($baptismRecord['event_date'])
                            ? date('M j, Y', strtotime((string) $baptismRecord['event_date']))
                            : 'date not recorded';
                        $verificationStatus = !empty($baptismRecord['book_verified_at'])
                            ? 'verified by the Parish Office'
                            : 'verification pending';
                        $recordSource = ($baptismRecord['source'] ?? '') === 'other_parish'
                            ? 'another parish'
                            : 'this parish';
                        $prerequisiteChecks[] = "{$label}: Baptism record found in the parish system (source: {$recordSource}) — "
                            . ($registerReference !== '' ? "register reference {$registerReference}" : 'no register reference recorded')
                            . ", {$recordDate}; {$verificationStatus}.";
                        if (!$hasVerifiedBaptismRecord) {
                            $prerequisiteWarnings[] = "{$label}: the Baptism record is on file but has not been verified by the Parish Office before proceeding.";
                        }
                    } elseif ($app['sacrament_type'] === 'first_communion') {
                        $prerequisiteWarnings[] = ($profile['baptismal_status'] ?? null) === 'baptized_catholic'
                            ? "{$label}: the profile indicates Catholic baptism, but no parish Baptism record is linked; verify the register or check the origin-parish certificate before proceeding."
                            : "{$label}: no parish Baptism record is linked; verify the register or check the origin-parish certificate before proceeding.";
                    } elseif ($app['sacrament_type'] === 'confirmation') {
                        if (!$hasBaptism) {
                            $prerequisiteWarnings[] = "{$label}: verify the Baptism record in this parish or check the origin-parish certificate before proceeding.";
                        }
                        if (!in_array('first_communion', $personRecords, true)) {
                            $prerequisiteWarnings[] = "{$label}: no First Communion record is linked.";
                        }
                    } elseif ($app['sacrament_type'] === 'marriage' && !$hasBaptism) {
                        $prerequisiteWarnings[] = "{$label}: verify the Baptism record in this parish or check the origin-parish certificate before proceeding.";
                    }
                }
                $canPrefill = !empty($app['person_id'])
                    && ($app['sacrament_type'] !== 'marriage' || !empty($app['spouse_person_id']));
                $subjectNames = [];
                foreach ($matches as $match) {
                    $roleLabel = $match['role'] === 'spouse' ? 'Bride' : ($app['sacrament_type'] === 'marriage' ? 'Groom' : 'Applicant');
                    $subjectNames[] = $roleLabel . ': ' . ($match['name'] !== '' ? $match['name'] : 'Name not provided');
                }
            ?>
                <tr>
                    <td><strong><?= e($app['request_no']) ?></strong></td>
                    <td><?= e(application_queue_label($app['sacrament_type'])) ?></td>
                    <td>
                        <?= e(implode(' · ', $subjectNames)) ?>
                        <span class="application-queue-submitter">Submitted by <?= e($app['applicant_name']) ?></span>
                        <?php if ($app['sacrament_type'] === 'first_communion'):
                            $baptismMatches = $automaticBaptismMatchesByApplication[(int) $app['id']] ?? [];
                            $baptismCheckLabel = $hasVerifiedBaptismRecord
                                ? 'Baptism record found and verified'
                                : ($hasParishBaptismRecord
                                    ? 'Baptism record found; verification pending'
                                    : (count($baptismMatches) === 1
                                        ? 'Unique Baptism record match suggested'
                                        : (count($baptismMatches) > 1
                                            ? 'Multiple Baptism record matches need review'
                                            : 'No Baptism record matched in the parish system')));
                        ?>
                            <span class="application-queue-submitter"><?= e($baptismCheckLabel) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e(application_queue_label($app['channel'])) ?><br>
                        <time datetime="<?= e(date('c', strtotime($app['created_at']))) ?>"><?= e(date('M j, Y g:i A', strtotime($app['created_at']))) ?></time>
                    </td>
                    <td><span class="status-badge status-<?= e($app['status']) ?>"><?= e(status_label('application', (string) $app['status'])) ?></span>
                    <?php if ($app['status'] === 'requirements_pending'): $pendingDays = (int) floor((time() - strtotime((string) $app['updated_at'])) / 86400); ?>
                        <br><small><?= $pendingDays >= APPLICATION_STALE_DAYS ? 'Stale: no activity for ' . $pendingDays . ' days' : 'Pending for ' . $pendingDays . ' days' ?></small>
                    <?php endif; ?></td>
                    <td>
                        <button
                            type="button"
                            class="btn btn-small"
                            data-application-review-toggle
                            aria-controls="application-review-<?= (int) $app['id'] ?>"
                            aria-expanded="<?= $openApplicationId === (int) $app['id'] || $matchSearchApplicationId === (int) $app['id'] ? 'true' : 'false' ?>"
                        ><?= $openApplicationId === (int) $app['id'] || $matchSearchApplicationId === (int) $app['id'] ? 'Close review' : 'Review' ?></button>
                    </td>
                </tr>
                <tr
                    class="application-queue-detail-row"
                    <?= $openApplicationId === (int) $app['id'] || $matchSearchApplicationId === (int) $app['id'] ? '' : 'hidden' ?>
                >
                    <td colspan="6">
                        <details
                            class="application-queue-review"
                            id="application-review-<?= (int) $app['id'] ?>"
                            aria-label="Review <?= e($app['request_no']) ?>"
                            <?= $openApplicationId === (int) $app['id'] || $matchSearchApplicationId === (int) $app['id'] ? 'open' : '' ?>
                        >
                            <summary aria-hidden="true" tabindex="-1">Review details for <?= e($app['request_no']) ?></summary>
                            <article class="application-review-card">
                    <header class="admin-queue-heading">
                        <div>
                            <p class="admin-queue-kicker"><?= e(application_queue_label($app['sacrament_type'])) ?> · <?= e(application_queue_label($app['channel'])) ?></p>
                            <h2><?= e($app['request_no']) ?></h2>
                            <p>Submitted by <?= e($app['applicant_name']) ?> · <?= e($app['applicant_email']) ?> · <?= e(date('M j, Y g:i A', strtotime($app['created_at']))) ?></p>
                        </div>
                        <span class="status-badge status-<?= e($app['status']) ?>"><?= e(status_label('application', (string) $app['status'])) ?></span>
                    </header>

                    <?php
                    $scheduleEvent = $scheduleEventsByApplication[(int) $app['id']] ?? null;
                    $isFormationSacrament = in_array($app['sacrament_type'], ['first_communion', 'confirmation'], true);
                    $formationPath = (string) ($details['formation_path'] ?? '');
                    $isBatchPath = $isFormationSacrament && $formationPath === 'batch';
                    $canScheduleIndividually = !$isFormationSacrament
                        || $formationPath === ''
                        || $formationPath === 'individual_catchup';
                    ?>
                    <section class="application-schedule-panel" aria-label="Application schedule">
                        <h3>Schedule</h3>
                        <?php if ($isBatchPath): ?>
                            <p><strong>Formation path:</strong> Regular parish group</p>
                            <p><strong>Assigned group:</strong>
                                <?php if (!empty($app['batch_id'])): ?>
                                    <a href="<?= e($basePath . '/admin/requests/sacrament-batches.php?' . http_build_query(['batch_id' => (int) $app['batch_id']])) ?>"><?= e((string) $app['batch_name']) ?></a>
                                <?php else: ?>
                                    Awaiting Parish Office assignment
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <p><strong>Applicant’s preferred date:</strong>
                            <?php if ($app['preferred_date']): ?>
                                <?= e(date('M j, Y', strtotime((string) $app['preferred_date']))) ?>
                                <?= $app['preferred_time'] ? e(date('g:i A', strtotime((string) $app['preferred_time']))) : '' ?>
                            <?php else: ?>
                                Not provided
                            <?php endif; ?>
                        </p>
                        <p><strong>Confirmed schedule:</strong>
                            <?php if ($scheduleEvent): ?>
                                <?= e(date('M j, Y', strtotime((string) $scheduleEvent['event_date']))) ?>
                                <?php if ($scheduleEvent['start_time']): ?>
                                    <?= e(format_event_time_range(
                                        (string) $scheduleEvent['start_time'],
                                        $scheduleEvent['end_time'] !== null ? (string) $scheduleEvent['end_time'] : null
                                    )) ?>
                                <?php else: ?>
                                    — time to be confirmed
                                <?php endif; ?>
                            <?php else: ?>
                                Not scheduled
                            <?php endif; ?>
                        </p>
                        <?php if ($app['status'] === 'completed'): ?>
                            <p class="field-hint">
                                This application is complete<?php if (!empty($app['record_id'])): ?> and linked to register record #<?= (int) $app['record_id'] ?><?php endif; ?>. No further scheduling is needed.
                            </p>
                        <?php elseif ($app['status'] === 'rejected'): ?>
                            <p class="field-hint">This application was rejected, so no schedule can be set.</p>
                        <?php elseif ($isBatchPath): ?>
                            <p class="field-hint">The group schedule is managed once for everyone assigned. The Parish Office will notify each family when the date is confirmed.</p>
                        <?php elseif (!$canScheduleIndividually): ?>
                            <p class="field-hint">Confirm whether this should follow a batch or individual catch-up path before setting a schedule.</p>
                            <form method="post" class="inline-fields">
                                <?= csrf_field() ?>
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <input type="hidden" name="action" value="set_formation_path">
                                <input type="hidden" name="return_query" value="<?= e($applicationReturnQuery) ?>">
                                <label>Confirmed formation pathway
                                    <select name="formation_path" required>
                                        <option value="">-- Select --</option>
                                        <option value="batch">Regular parish group</option>
                                        <option value="individual_catchup">Individual / catch-up pathway</option>
                                    </select>
                                </label>
                                <button type="submit" class="btn btn-small">Save pathway</button>
                            </form>
                        <?php elseif (in_array($app['status'], ['approved', 'scheduled'], true)): ?>
                            <form method="post" class="inline-fields application-schedule-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <input type="hidden" name="action" value="set_schedule">
                                <input type="hidden" name="return_query" value="<?= e($applicationReturnQuery) ?>">
                                <label>Confirmed date *
                                    <input
                                        type="date"
                                        name="scheduled_date"
                                        min="<?= e(date('Y-m-d')) ?>"
                                        value="<?= e((string) ($scheduleEvent['event_date'] ?? '')) ?>"
                                        required
                                    >
                                </label>
                                <label>Start time
                                    <input
                                        type="time"
                                        name="scheduled_start_time"
                                        value="<?= $scheduleEvent && $scheduleEvent['start_time'] ? e(date('H:i', strtotime((string) $scheduleEvent['start_time']))) : '' ?>"
                                    >
                                </label>
                                <label>End time
                                    <input
                                        type="time"
                                        name="scheduled_end_time"
                                        value="<?= $scheduleEvent && $scheduleEvent['end_time'] ? e(date('H:i', strtotime((string) $scheduleEvent['end_time']))) : '' ?>"
                                    >
                                </label>
                                <button type="submit" class="btn btn-small">
                                    <?= $scheduleEvent ? 'Update confirmed schedule' : 'Set confirmed schedule' ?>
                                </button>
                            </form>
                            <p class="field-hint">The applicant’s preferred date stays unchanged. Saving this schedule moves the application to Scheduled and emails the confirmed date and time. Check the parish calendar for availability before confirming.</p>
                        <?php else: ?>
                            <p class="field-hint">Approve the application before setting its confirmed schedule.</p>
                        <?php endif; ?>
                    </section>

                    <section aria-label="Pre-application details">
                        <h3>Submitted details</h3>
                        <dl class="application-details-list">
                            <?php if ($details !== []): ?>
                                <?php foreach ($details as $key => $value): ?>
                                    <div>
                                        <?php
                                        $detailKey = (string) $key;
                                        $detailLabel = $detailKey === 'preferred_wedding_venue_other'
                                            ? 'Other venue name'
                                            : application_queue_label($detailKey);
                                        if ($detailKey === 'preferred_wedding_venue') {
                                            $value = match ($value) {
                                                'church' => "St. Patrick's Parish Church",
                                                'other' => 'Other',
                                                'barangay_chapel' => 'Barangay Chapel',
                                                default => $value,
                                            };
                                        }
                                        ?>
                                        <dt><?= e($detailLabel) ?></dt>
                                        <dd><?php application_queue_render_detail_value($value); ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div><dt>Details</dt><dd>No additional submitted details.</dd></div>
                            <?php endif; ?>
                                    </dl>
                    </section>
                                                <?php if ($prerequisiteChecks !== []): ?>
                        <aside class="alert alert-success" role="note">
                            <strong>Parish records:</strong>
                            <ul><?php foreach ($prerequisiteChecks as $check): ?><li><?= e($check) ?></li><?php endforeach; ?></ul>
                        </aside>
                    <?php endif; ?>
                    <?php if ($prerequisiteWarnings !== []): ?>
                        <aside class="alert alert-warning" role="note">
                            <strong>Prerequisite review:</strong>
                            <ul><?php foreach ($prerequisiteWarnings as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?></ul>
                            <span>These are warnings only; they do not block saving or changing status.</span>
                        </aside>
                    <?php endif; ?>

                    <section aria-label="Person matching">
                        <h3><?= in_array($app['status'], ['completed', 'rejected'], true)
                            ? 'Linked applicant profiles'
                            : 'Match applicant' . (count($matches) === 2 ? 's' : '') . ' to master profiles' ?></h3>
                        <div class="application-person-match-grid">
                            <?php foreach ($matches as $match):
                                $matchLabel = $match['role'] === 'spouse' ? 'Bride' : ($app['sacrament_type'] === 'marriage' ? 'Groom' : 'Applicant');
                                $matchPersonId = $match['person_id'];
                                $matchLinkedPerson = !empty($matchPersonId) ? ($linkedPersons[(int) $matchPersonId] ?? null) : null;
                                $baptismMatches = $match['role'] === 'applicant' && $app['sacrament_type'] === 'first_communion'
                                    ? ($automaticBaptismMatchesByApplication[(int) $app['id']] ?? [])
                                    : [];
                                if (in_array($app['status'], ['completed', 'rejected'], true)):
                            ?>
                                <section class="person-match-form" aria-label="<?= e($matchLabel) ?> profile">
                                    <h4><?= e($matchLabel) ?></h4>
                                    <?php if ($matchLinkedPerson): ?>
                                        <strong><?= e(implode(' ', array_filter([
                                            $matchLinkedPerson['first_name'],
                                            $matchLinkedPerson['middle_name'],
                                            $matchLinkedPerson['last_name'],
                                            $matchLinkedPerson['suffix'],
                                        ]))) ?></strong>
                                        <dl class="application-details-list">
                                            <div><dt>Date of birth</dt><dd><?= e($matchLinkedPerson['birth_date'] ?? 'Not recorded') ?></dd></div>
                                            <div><dt>Father</dt><dd><?= e($matchLinkedPerson['father_name'] ?? 'Not recorded') ?></dd></div>
                                            <div><dt>Mother's maiden name</dt><dd><?= e($matchLinkedPerson['mother_maiden_name'] ?? 'Not recorded') ?></dd></div>
                                            <div><dt>Address</dt><dd><?= e($matchLinkedPerson['address'] ?? 'Not recorded') ?></dd></div>
                                        </dl>
                                    <?php else: ?>
                                        <p>No master person profile linked.</p>
                                    <?php endif; ?>
                                </section>
                            <?php else:
                                $matchRole = $match['role'];
                                $matchApplicationId = (int) $app['id'];
                                $matchName = (string) $match['name'];
                                $matchBirthDate = (string) ($matchLinkedPerson['birth_date'] ?? $match['birth_date']);
                                $matchWidgetMode = 'ajax';
                                $matchReturnParams = $applicationReturnParams;
                                $matchReturnQuery = $applicationReturnQuery;
                                $matchSearchResults = $matchSearchApplicationId === $matchApplicationId && $matchSearchRole === $matchRole;
                                if (!$matchPersonId && count($baptismMatches) === 1):
                                    $suggestedPerson = $baptismMatches[0];
                                    $suggestedName = implode(' ', array_filter([
                                        $suggestedPerson['first_name'],
                                        $suggestedPerson['middle_name'],
                                        $suggestedPerson['last_name'],
                                        $suggestedPerson['suffix'],
                                    ]));
                                    $suggestedReference = implode(' / ', array_values(array_filter(
                                        [
                                            $suggestedPerson['entry_no'] ?? null,
                                            $suggestedPerson['book_no'] ?? null,
                                            $suggestedPerson['page_no'] ?? null,
                                            $suggestedPerson['line_no'] ?? null,
                                        ],
                                        static fn($value): bool => $value !== null && trim((string) $value) !== ''
                                    )));
                                ?>
                                    <aside class="alert alert-success" role="note">
                                        <strong>Unique Baptism record match</strong>
                                        <p><?= e($suggestedName) ?> · <?= e($suggestedPerson['birth_date'] ?? 'Date of birth not recorded') ?></p>
                                        <p>
                                            <?= e(($suggestedPerson['source'] ?? '') === 'other_parish' ? 'Origin-parish record' : 'Parish record') ?>
                                            · <?= e($suggestedPerson['baptism_event_date'] ?? 'Baptism date not recorded') ?>
                                            · <?= e($suggestedReference !== '' ? 'Register ' . $suggestedReference : 'No register reference recorded') ?>
                                            · <?= !empty($suggestedPerson['book_verified_at']) ? 'verified' : 'verification pending' ?>
                                        </p>
                                        <form method="post" action="<?= e($basePath . '/admin/requests/applications-queue.php') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="link_person">
                                            <input type="hidden" name="application_id" value="<?= $matchApplicationId ?>">
                                            <input type="hidden" name="person_role" value="applicant">
                                            <input type="hidden" name="person_id" value="<?= (int) $suggestedPerson['id'] ?>">
                                            <input type="hidden" name="return_query" value="<?= e($applicationReturnQuery) ?>">
                                            <button type="submit" class="btn btn-small">Confirm and link this profile</button>
                                        </form>
                                    </aside>
                                <?php elseif (!$matchPersonId && count($baptismMatches) > 1): ?>
                                    <aside class="alert alert-warning" role="note">
                                        <strong>Multiple exact Baptism record matches</strong>
                                        <ul>
                                            <?php foreach ($baptismMatches as $suggestedPerson): ?>
                                                <li>
                                                    <?= e(implode(' ', array_filter([
                                                        $suggestedPerson['first_name'],
                                                        $suggestedPerson['middle_name'],
                                                        $suggestedPerson['last_name'],
                                                        $suggestedPerson['suffix'],
                                                    ]))) ?>
                                                    · <?= e($suggestedPerson['birth_date'] ?? 'Date of birth not recorded') ?>
                                                    · <?= e(implode(' / ', array_values(array_filter([
                                                        $suggestedPerson['entry_no'] ?? null,
                                                        $suggestedPerson['book_no'] ?? null,
                                                        $suggestedPerson['page_no'] ?? null,
                                                        $suggestedPerson['line_no'] ?? null,
                                                    ], static fn($value): bool => $value !== null && trim((string) $value) !== '')))) ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <span>Review the identity and link the correct profile manually.</span>
                                    </aside>
                                <?php endif; ?>
                                <?php require __DIR__ . '/../../includes/person-match-widget.php'; ?>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section aria-label="Document requirements">
                        <h3>Requirements checklist</h3>
                        <div class="application-requirements-list">
                            <?php foreach ($checklistKeys as $requirementKey):
                                $requirement = $requirements[$requirementKey] ?? [];
                                $requirement = is_array($requirement) ? $requirement : [];
                                if (($requirement['status'] ?? '') === 'not_applicable') { continue; }
                                if ($app['sacrament_type'] === 'first_communion'
                                    && $requirementKey === 'origin_baptismal_certificate'
                                    && $hasVerifiedBaptismRecord) {
                                    continue;
                                }
                                $currentRequirementStatus = (string) ($requirement['status'] ?? 'not_received');
                                if (!in_array($currentRequirementStatus, $requirementStatuses, true)) {
                                    $currentRequirementStatus = 'not_received';
                                }
                            ?>
                                <?php if (in_array($app['status'], ['completed', 'rejected'], true)): ?>
                                    <div class="application-requirement-form">
                                        <strong><?= e(application_queue_requirement_label($app['sacrament_type'], $requirementKey)) ?></strong>
                                        <p>Status: <?= e(application_queue_label($currentRequirementStatus)) ?></p>
                                    </div>
                                <?php else: ?>
                                <form method="post" class="application-requirement-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                    <input type="hidden" name="action" value="update_requirement">
                                    <input type="hidden" name="requirement_key" value="<?= e($requirementKey) ?>">
                                    <input type="hidden" name="return_query" value="<?= e($applicationReturnQuery) ?>">
                                    <strong><?= e(application_queue_requirement_label($app['sacrament_type'], $requirementKey)) ?></strong>
                                    <label>Status
                                        <select name="requirement_status">
                                            <?php foreach ($requirementStatuses as $requirementStatus): ?>
                                                <option value="<?= e($requirementStatus) ?>" <?= $currentRequirementStatus === $requirementStatus ? 'selected' : '' ?>><?= e(application_queue_label($requirementStatus)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button type="submit" class="btn btn-small" aria-label="Save <?= e(application_queue_requirement_label($app['sacrament_type'], $requirementKey)) ?> checklist item">Save requirement</button>
                                </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <footer class="application-review-actions">
                        <?php if (in_array($app['status'], ['approved', 'scheduled'], true)): ?>
                            <?php if ($canPrefill && $prefillUrl !== null): ?>
                                <a class="btn btn-small" href="<?= e($prefillUrl) ?>">Open prefilled register form</a>
                                <span class="field-hint">Review the actual sacrament details and save the register entry after the ceremony. Saving it completes this application.</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-small" disabled>Open prefilled register form</button>
                                <span class="field-hint"><?php if ($registerEditorType === null): ?>
                                    No register form is configured for this sacrament type.
                                <?php else: ?>
                                    Link <?= $app['sacrament_type'] === 'marriage' ? 'both applicants' : 'a person' ?> first to prefill identity.
                                <?php endif; ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($validNextStatuses !== []): ?>
                            <?php if ($app['status'] === 'scheduled'): ?>
                                <p class="field-hint">The normal next step after the ceremony is to save the register entry above. “Close” is only for a request that will not proceed.</p>
                            <?php endif; ?>
                            <form method="post" class="inline-fields application-status-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="return_query" value="<?= e($applicationReturnQuery) ?>">
                                <label><?= $app['status'] === 'scheduled' ? 'Other status action' : 'Move application to' ?>
                                    <select name="new_status" required>
                                        <option value="" selected disabled>Choose…</option>
                                        <?php foreach ($validNextStatuses as $nextStatus): ?>
                                            <option value="<?= e($nextStatus) ?>"><?= $nextStatus === 'rejected' ? 'Close (will not proceed)' : e(status_label('application', $nextStatus)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <?php if (in_array('rejected', $validNextStatuses, true)): ?>
                                    <label class="close-reason" style="display:none">Reason for closing
                                        <select name="close_reason">
                                            <option value="">Choose a reason…</option>
                                            <?php foreach (APPLICATION_CLOSE_REASONS as $closeOption): ?>
                                                <option value="<?= e($closeOption) ?>"><?= e($closeOption) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-small">Update status</button>
                            </form>
                        <?php endif; ?>
                    </footer>
                </article>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const button = target.closest('[data-application-review-toggle]');
    if (!(button instanceof HTMLButtonElement)) return;
    const review = document.getElementById(button.getAttribute('aria-controls') || '');
    if (!(review instanceof HTMLDetailsElement)) return;
    review.open = !review.open;
    if (review.open) {
        document.querySelectorAll('.application-queue-review[open]').forEach((other) => {
            if (other === review) return;
            other.open = false;
            const otherRow = other.closest('.application-queue-detail-row');
            if (otherRow instanceof HTMLTableRowElement) otherRow.hidden = true;
            document.querySelectorAll('[data-application-review-toggle]').forEach((otherToggle) => {
                if (!(otherToggle instanceof HTMLButtonElement)
                    || otherToggle.getAttribute('aria-controls') !== other.id) return;
                otherToggle.setAttribute('aria-expanded', 'false');
                otherToggle.textContent = 'Review';
            });
        });
    }
    const detailRow = review.closest('.application-queue-detail-row');
    if (detailRow instanceof HTMLTableRowElement) detailRow.hidden = !review.open;
    button.setAttribute('aria-expanded', review.open ? 'true' : 'false');
    button.textContent = review.open ? 'Close review' : 'Review';
    if (review.open) {
        const row = button.closest('tr');
        if (row) row.scrollIntoView({ block: 'nearest' });
    }
});
document.querySelectorAll('form.application-requirement-form').forEach((form) => {
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    const fields = form.querySelectorAll('select[name="requirement_status"], input[name="requirement_note"]');
    const initial = Array.from(fields).map((field) => field.value);
    const sync = () => {
        button.disabled = !Array.from(fields).some((field, i) => field.value !== initial[i]);
        button.style.opacity = button.disabled ? '0.45' : '1';
        button.style.cursor = button.disabled ? 'not-allowed' : 'pointer';
    };
    fields.forEach((field) => {
        field.addEventListener('input', sync);
        field.addEventListener('change', sync);
    });
    sync();
});

document.querySelectorAll('form.application-status-form').forEach((form) => {
    const status = form.querySelector('select[name="new_status"]');
    const reasonLabel = form.querySelector('.close-reason');
    const reason = form.querySelector('select[name="close_reason"]');
    if (!status || !reasonLabel || !reason) return;
    const sync = () => {
        const closing = status.value === 'rejected';
        reasonLabel.style.display = closing ? '' : 'none';
        reason.required = closing;
    };
    status.addEventListener('change', sync);
    form.addEventListener('submit', (event) => {
        if (status.value === 'rejected' && !window.confirm('Close this application? This cannot be undone.')) {
            event.preventDefault();
        }
    });
    sync();
});
</script>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>