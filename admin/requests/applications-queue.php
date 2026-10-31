<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Sacrament Applications Queue';
if (!defined('APP_IN_PROGRESS_STATUSES')) {
    define('APP_IN_PROGRESS_STATUSES', ['reviewing', 'requirements_pending', 'approved', 'scheduled']);
}
$statuses = array_merge(['in_progress'], APP_IN_PROGRESS_STATUSES, ['new', 'completed', 'rejected']);
$sacraments = ['baptism', 'first_communion', 'confirmation', 'marriage'];
$requirementStatuses = ['not_received', 'received', 'verified', 'not_applicable'];
$requirementKeys = [
    'baptism' => ['psa_birth_certificate'],
    'first_communion' => ['origin_baptismal_certificate', 'catechism_completion'],
    'confirmation' => ['origin_baptismal_certificate', 'confirmation_sponsor_details'],
    'marriage' => [
        'psa_cenomar_groom',
        'psa_cenomar_bride',
        'origin_baptismal_certificate_groom',
        'origin_baptismal_certificate_bride',
        'transfer_letter',
        'pre_cana_seminar',
    ],
];
$returnKeys = ['status', 'sacrament', 'channel', 'q', 'range', 'date_from', 'date_to', 'per_page', 'page'];
$returnParams = array_intersect_key($_GET, array_flip($returnKeys));
$returnQuery = http_build_query($returnParams);
$returnUrl = '/admin/requests/applications-queue.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');

function application_queue_label(string $value): string
{
    return ucwords(str_replace('_', ' ', $value));
}

function application_queue_render_detail_value(mixed $value): void
{
    if (is_array($value)) {
        if ($value === []) {
            echo '—';
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
    echo e(str_contains($text, '_') ? application_queue_label($text) : $text);
}

function application_queue_requirement_label(string $sacramentType, string $requirementKey): string
{
    $labels = [
        'baptism' => [
            'psa_birth_certificate' => 'PSA Birth Certificate',
        ],
        'first_communion' => [
            'origin_baptismal_certificate' => 'Origin Baptismal Certificate',
            'catechism_completion' => 'Catechism Completion',
        ],
        'confirmation' => [
            'origin_baptismal_certificate' => 'Origin Baptismal Certificate',
            'confirmation_sponsor_details' => 'Confirmation Sponsor Details',
        ],
        'marriage' => [
            'psa_cenomar_groom' => 'Groom PSA CENOMAR',
            'psa_cenomar_bride' => 'Bride PSA CENOMAR',
            'origin_baptismal_certificate_groom' => 'Groom Origin Baptismal Certificate',
            'origin_baptismal_certificate_bride' => 'Bride Origin Baptismal Certificate',
            'transfer_letter' => 'Transfer Letter',
            'pre_cana_seminar' => 'Pre-Cana Seminar',
        ],
    ];

    return $labels[$sacramentType][$requirementKey]
        ?? ucfirst(str_replace('_', ' ', $requirementKey));
}

function application_queue_details(array $application): array
{
    $details = json_decode((string) ($application['details'] ?? ''), true);
    return is_array($details) ? $details : [];
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
    return '/admin/requests/applications-queue.php' . ($query !== '' ? '?' . $query : '');
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
        $_SESSION['application_queue_error'] = 'Select a valid application.';
        redirect_to($postReturnUrl);
    }

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare(
            'SELECT id, sacrament_type, person_id, spouse_person_id, status, requirements
             FROM sacrament_applications
             WHERE id = ?
             FOR UPDATE'
        );
        $lock->execute([(int) $applicationId]);
        $application = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$application) {
            $pdo->rollBack();
            $_SESSION['application_queue_error'] = 'The application could not be found.';
            redirect_to($postReturnUrl);
        }

        if (in_array($action, ['link_person', 'update_requirement'], true)
            && in_array($application['status'], ['completed', 'rejected'], true)) {
            $pdo->rollBack();
            $_SESSION['application_queue_error'] = 'Completed or rejected applications are locked.';
            redirect_to($postReturnUrl);
        }

        if ($action === 'link_person') {
            $role = (string) ($_POST['person_role'] ?? '');
            $personId = filter_var($_POST['person_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$personId || $personId < 1 || !in_array($role, ['applicant', 'spouse'], true)) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'Select a valid person profile.';
                redirect_to($postReturnUrl);
            }
            if ($role === 'spouse' && $application['sacrament_type'] !== 'marriage') {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'Only marriage applications can link a spouse profile.';
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
            if (!in_array($newStatus, application_queue_next_statuses($currentStatus), true)) {
                $pdo->rollBack();
                $_SESSION['application_queue_error'] = 'That status transition is not allowed.';
                redirect_to($postReturnUrl);
            }

            $update = $pdo->prepare('UPDATE sacrament_applications SET status = ? WHERE id = ?');
            $update->execute([$newStatus, (int) $applicationId]);
            $pdo->commit();
            $_SESSION['application_queue_success'] = 'Application status updated.';
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
        $_SESSION['application_queue_error'] = 'The application update failed. Please try again.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Application queue update failed: ' . $e->getMessage());
        $_SESSION['application_queue_error'] = 'The application update failed. Please try again.';
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
    "SELECT sa.id, sa.request_no, sa.channel, sa.sacrament_type, sa.person_id, sa.spouse_person_id,
            sa.applicant_name, sa.applicant_email, sa.preferred_date, sa.preferred_time,
            sa.status, sa.details, sa.requirements, sa.record_id, sa.created_at
     FROM sacrament_applications sa{$whereSql}
     ORDER BY sa.created_at DESC, sa.id DESC
     LIMIT {$limit} OFFSET {$offset}"
);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
$allLinkedIds = $linkedPersonIds;
if ($allLinkedIds !== []) {
    $recordPlaceholders = implode(', ', array_fill(0, count($allLinkedIds), '?'));
    $recordStmt = $pdo->prepare(
        "SELECT sacrament_type, person_id, spouse_person_id
         FROM sacrament_records
         WHERE person_id IN ({$recordPlaceholders})
            OR spouse_person_id IN ({$recordPlaceholders})"
    );
    $recordStmt->execute(array_merge($allLinkedIds, $allLinkedIds));
    foreach ($recordStmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        foreach (['person_id', 'spouse_person_id'] as $recordPersonColumn) {
            $recordPersonId = (int) ($record[$recordPersonColumn] ?? 0);
            if ($recordPersonId > 0) {
                $recordRowsByPerson[$recordPersonId][] = $record['sacrament_type'];
            }
        }
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
            <h1>Sacrament applications</h1>
            <p>Review applications, link master person profiles, track requirements, and continue the application lifecycle.</p>
        </div>
        <div class="admin-queue-actions">
            <a href="<?= e($basePath . '/public/sacrament-applications/baptismal.php') ?>" class="btn btn-secondary">New walk-in application</a>
        </div>
        <div class="admin-queue-total" aria-label="<?= (int) $totalRows ?> applications match the current filters">
            <strong><?= (int) $totalRows ?></strong>
            <span><?= $totalRows === 1 ? 'application' : 'applications' ?></span>
        </div>
    </header>

    <?php if ($queueError !== ''): ?><p class="alert alert-error" role="alert"><?= e($queueError) ?></p><?php endif; ?>
    <?php if ($queueSuccess !== ''): ?><p class="alert alert-success"><?= e($queueSuccess) ?></p><?php endif; ?>

    <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>

    <?php if ($applications === []): ?>
        <p class="admin-queue-empty">No applications match your search or filters.</p>
    <?php else: ?>
        <div class="application-review-list">
            <?php foreach ($applications as $app):
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
                    'baptism' => '/admin/records/baptismal.php',
                    'first_communion' => '/admin/records/first-communion.php',
                    'confirmation' => '/admin/records/confirmation.php',
                    'marriage' => '/admin/records/marriage.php',
                ];
                $registerFormPath = $registerFormPaths[$app['sacrament_type']] ?? null;
                $prefillUrl = $registerFormPath === null
                    ? null
                    : $basePath . $registerFormPath . '?' . http_build_query(['from_application' => (int) $app['id']]);
                $validNextStatuses = application_queue_next_statuses((string) $app['status']);
                $prerequisiteWarnings = [];
                foreach ($matches as $match) {
                    $personId = (int) ($match['person_id'] ?? 0);
                    if ($personId < 1) {
                        continue;
                    }
                    $personRecords = $recordRowsByPerson[$personId] ?? [];
                    $profile = $linkedPersons[$personId] ?? [];
                    $hasBaptism = in_array('baptism', $personRecords, true)
                        || ($profile['baptismal_status'] ?? null) === 'baptized_catholic';
                    $label = $match['role'] === 'spouse' ? 'Bride' : ($app['sacrament_type'] === 'marriage' ? 'Groom' : 'Applicant');
                    if ($app['sacrament_type'] === 'first_communion' && !$hasBaptism) {
                        $prerequisiteWarnings[] = "{$label}: no baptism record or baptized-Catholic profile status is linked.";
                    } elseif ($app['sacrament_type'] === 'confirmation') {
                        if (!$hasBaptism) {
                            $prerequisiteWarnings[] = "{$label}: no baptism record or baptized-Catholic profile status is linked.";
                        }
                        if (!in_array('first_communion', $personRecords, true)) {
                            $prerequisiteWarnings[] = "{$label}: no First Communion record is linked.";
                        }
                    } elseif ($app['sacrament_type'] === 'marriage' && !$hasBaptism) {
                        $prerequisiteWarnings[] = "{$label}: no baptism record or baptized-Catholic profile status is linked.";
                    }
                }
                $canPrefill = !empty($app['person_id'])
                    && ($app['sacrament_type'] !== 'marriage' || !empty($app['spouse_person_id']));
            ?>
                <article class="application-review-card">
                    <header class="admin-queue-heading">
                        <div>
                            <p class="admin-queue-kicker"><?= e(application_queue_label($app['sacrament_type'])) ?> · <?= e(application_queue_label($app['channel'])) ?></p>
                            <h2><?= e($app['request_no']) ?></h2>
                            <p>Submitted by <?= e($app['applicant_name']) ?> · <?= e($app['applicant_email']) ?> · <?= e(date('M j, Y g:i A', strtotime($app['created_at']))) ?></p>
                        </div>
                        <span class="status-badge status-<?= e($app['status']) ?>"><?= e(status_label('application', (string) $app['status'])) ?></span>
                    </header>

                    <?php if ($app['preferred_date']): ?>
                        <p><strong>Preferred date:</strong> <?= e(date('M j, Y', strtotime($app['preferred_date']))) ?>
                            <?= $app['preferred_time'] ? e(date('g:i A', strtotime($app['preferred_time']))) : '' ?></p>
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
                                $matchBirthDate = (string) $match['birth_date'];
                                $matchWidgetMode = 'link-only';
                                $matchReturnParams = $returnParams;
                                $matchReturnQuery = $returnQuery;
                                $matchSearchResults = $matchSearchApplicationId === $matchApplicationId && $matchSearchRole === $matchRole;
                                require __DIR__ . '/../../includes/person-match-widget.php';
                            endif;
                            endforeach; ?>
                        </div>
                    </section>

                    <section aria-label="Application details">
                        <h3>Submitted details</h3>
                        <dl class="application-details-list">
                            <?php if ($details !== []): ?>
                                <?php foreach ($details as $key => $value): ?>
                                    <div>
                                        <dt><?= e(application_queue_label((string) $key)) ?></dt>
                                        <dd><?php application_queue_render_detail_value($value); ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div><dt>Details</dt><dd>No additional submitted details.</dd></div>
                            <?php endif; ?>
                        </dl>
                    </section>

                    <section aria-label="Document requirements">
                        <h3>Requirements checklist</h3>
                        <div class="application-requirements-list">
                            <?php foreach ($checklistKeys as $requirementKey):
                                $requirement = $requirements[$requirementKey] ?? [];
                                $requirement = is_array($requirement) ? $requirement : [];
                                $currentRequirementStatus = (string) ($requirement['status'] ?? 'not_received');
                                if (!in_array($currentRequirementStatus, $requirementStatuses, true)) {
                                    $currentRequirementStatus = 'not_received';
                                }
                            ?>
                                <?php if (in_array($app['status'], ['completed', 'rejected'], true)): ?>
                                    <div class="application-requirement-form">
                                        <strong><?= e(application_queue_requirement_label($app['sacrament_type'], $requirementKey)) ?></strong>
                                        <p>Status: <?= e(application_queue_label($currentRequirementStatus)) ?></p>
                                        <p>Note: <?= e((string) ($requirement['note'] ?? '')) ?></p>
                                    </div>
                                <?php else: ?>
                                <form method="post" class="application-requirement-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                    <input type="hidden" name="action" value="update_requirement">
                                    <input type="hidden" name="requirement_key" value="<?= e($requirementKey) ?>">
                                    <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                                    <strong><?= e(application_queue_requirement_label($app['sacrament_type'], $requirementKey)) ?></strong>
                                    <label>Status
                                        <select name="requirement_status">
                                            <?php foreach ($requirementStatuses as $requirementStatus): ?>
                                                <option value="<?= e($requirementStatus) ?>" <?= $currentRequirementStatus === $requirementStatus ? 'selected' : '' ?>><?= e(application_queue_label($requirementStatus)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Note
                                        <input type="text" name="requirement_note" maxlength="500" value="<?= e((string) ($requirement['note'] ?? '')) ?>">
                                    </label>
                                    <button type="submit" class="btn btn-small" aria-label="Save <?= e(application_queue_requirement_label($app['sacrament_type'], $requirementKey)) ?> checklist item">Save requirement</button>
                                </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <footer class="application-review-actions">
                        <?php if ($app['status'] === 'approved'): ?>
                            <?php if ($canPrefill && $prefillUrl !== null): ?>
                                <a class="btn btn-small" href="<?= e($prefillUrl) ?>">Prefill Register Form</a>
                            <?php else: ?>
                                <button type="button" class="btn btn-small" disabled>Prefill Register Form</button>
                                <span class="field-hint"><?php if ($registerFormPath === null): ?>
                                    No register form is configured for this sacrament type.
                                <?php else: ?>
                                    Link <?= $app['sacrament_type'] === 'marriage' ? 'both applicants' : 'a person' ?> first to prefill identity.
                                <?php endif; ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($validNextStatuses !== []): ?>
                            <form method="post" class="inline-fields">
                                <?= csrf_field() ?>
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                                <label>Next status
                                    <select name="new_status">
                                        <?php foreach ($validNextStatuses as $nextStatus): ?>
                                            <option value="<?= e($nextStatus) ?>"><?= e(application_queue_label($nextStatus)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button type="submit" class="btn btn-small">Update status</button>
                            </form>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
