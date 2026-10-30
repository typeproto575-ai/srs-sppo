<?php
/**
 * =====================================================================
 * SRS-SPPO CONFIRMATION RECORDS (admin register)
 * =====================================================================
 * Purpose: Digital mirror of the physical confirmation register:
 *          encode entries from the logbook, list/search, and serve as
 *          the source of truth for confirmation certificate requests.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/person-linking.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

// This page's own app-relative URL (path + current query string) so the record
// view can send the user back here with the same search, filter and page.
$selfReturn = '/admin/records/confirmation.php' . ((($_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $_SERVER['QUERY_STRING'] : '');

$pageTitle = 'Confirmation Records';
$errors  = [];
$success = null;

function confirmation_load_person_identity(PDO $pdo, int $personId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, suffix, birth_date,
                birth_place, father_name, mother_maiden_name, address, baptismal_status
         FROM persons WHERE id = ?'
    );
    $stmt->execute([$personId]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    return $person ?: null;
}

function confirmation_birthdate_is_valid(string $birthdate): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $birthdate);
    return $date !== false && $date->format('Y-m-d') === $birthdate && !is_future_date($birthdate);
}

$application = null;
$applicationDetails = [];
$fromApplicationId = 0;
$identity = null;
if (!empty($_GET['from_application']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $applicationStmt = $pdo->prepare(
        "SELECT sa.id AS application_id, sa.request_no, sa.status, sa.person_id,
                sa.details, p.id AS linked_person_id
         FROM sacrament_applications sa
         LEFT JOIN persons p ON p.id = sa.person_id
         WHERE sa.id = ? AND sa.sacrament_type = 'confirmation'
         LIMIT 1"
    );
    $applicationStmt->execute([(int) $_GET['from_application']]);
    $application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
    if ($application && in_array($application['status'], ['approved', 'scheduled'], true)) {
        $fromApplicationId = (int) $application['application_id'];
        $applicationDetails = json_decode((string) ($application['details'] ?? ''), true);
        $applicationDetails = is_array($applicationDetails) ? $applicationDetails : [];
        $_POST['person_id'] = (string) ($application['person_id'] ?? '');
        $_POST['remarks'] = 'Encoded from application ' . $application['request_no'];
        if (empty($application['person_id'])) {
            $applicantName = trim((string) ($applicationDetails['applicant_full_name'] ?? ''));
            [$first, $middle, $last, $suffix] = structured_name_parts($applicantName);
            $_POST['confirmand_first_name'] = $first;
            $_POST['confirmand_middle_name'] = $middle;
            $_POST['confirmand_last_name'] = $last;
            $_POST['confirmand_suffix'] = $suffix;
            $_POST['birthdate'] = (string) ($applicationDetails['birth_date'] ?? '');
            foreach (['father' => 'father_name', 'mother' => 'mother_maiden_name'] as $prefix => $key) {
                $parts = structured_name_parts((string) ($applicationDetails[$key] ?? ''));
                foreach (['first', 'middle', 'last', 'suffix'] as $index => $part) {
                    $_POST[$prefix . '_' . $part] = $parts[$index];
                }
            }
        } else {
            $identity = confirmation_load_person_identity($pdo, (int) $application['person_id']);
            if (!$identity) {
                $errors[] = 'The person linked to this application could not be found.';
            }
        }
    } elseif ($application) {
        $errors[] = 'Application ' . $application['request_no'] . ' must be approved or scheduled before it can be encoded into the register.';
        $application = null;
    } else {
        $errors[] = 'The requested confirmation application could not be found.';
    }
}

$fromApplicationId = $fromApplicationId ?: (int) ($_POST['from_application'] ?? 0);
$editRecord = null;
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if (!$editId || $editId < 1) {
        redirect_to('/admin/records/confirmation.php');
        exit;
    }
    $stmt = $pdo->prepare(
        "SELECT sr.id, sr.person_id, sr.event_date AS confirmation_date,
                sr.entry_no, sr.book_no, sr.page_no, sr.line_no,
                sr.minister_name, sr.remarks, sr.encoded_by,
                p.first_name, p.middle_name, p.last_name, p.suffix, p.birth_date,
                p.birth_place, p.father_name, p.mother_maiden_name, p.address,
                p.baptismal_status,
                CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) AS full_name
         FROM sacrament_records sr
         JOIN persons p ON p.id = sr.person_id
         WHERE sr.id = ? AND sr.sacrament_type = 'confirmation'"
    );
    $stmt->execute([(int) $editId]);
    $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRecord) {
        redirect_to('/admin/records/confirmation.php');
        exit;
    }
    $identity = $editRecord;
    $participantStmt = $pdo->prepare(
        "SELECT full_name FROM sacrament_participants
         WHERE record_id = ? AND role = 'sponsor'
         ORDER BY sort_order, id LIMIT 1"
    );
    $participantStmt->execute([(int) $editRecord['id']]);
    $editRecord['sponsor_name'] = (string) ($participantStmt->fetchColumn() ?: '');
}

if (!$editRecord && !$identity) {
    $selectedPersonId = filter_var($_GET['person_id'] ?? $_POST['person_id'] ?? null, FILTER_VALIDATE_INT);
    if ($selectedPersonId && $selectedPersonId > 0) {
        $identity = confirmation_load_person_identity($pdo, (int) $selectedPersonId);
        if (!$identity) {
            $errors[] = 'The selected person profile could not be found.';
        } else {
            $_POST['person_id'] = (string) $identity['id'];
        }
    }
}

$formValues = $_POST;
$isEdit = $editRecord !== null;
$recordNameFormValues = $formValues;
foreach ([
    'sponsor' => $isEdit ? structured_name_parts((string) ($editRecord['sponsor_name'] ?? '')) : ['', '', '', ''],
    'minister' => $isEdit ? structured_name_parts((string) ($editRecord['minister_name'] ?? '')) : ['', '', '', ''],
] as $prefix => $parts) {
    foreach (['first', 'middle', 'last', 'suffix'] as $index => $part) {
        $recordNameFormValues[$prefix . '_' . $part] ??= $parts[$index];
    }
}

// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form). Nothing is written when it fails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record']) && csrf_verify()) {
    require_role('secretary');

    $entryNo = trim((string) ($_POST['entry_no'] ?? ''));
    $confirmationDate = trim((string) ($_POST['confirmation_date'] ?? ''));
    $confirmandFirst = trim((string) ($_POST['confirmand_first_name'] ?? ''));
    $confirmandMiddle = trim((string) ($_POST['confirmand_middle_name'] ?? ''));
    $confirmandLast = trim((string) ($_POST['confirmand_last_name'] ?? ''));
    $confirmandSuffix = trim((string) ($_POST['confirmand_suffix'] ?? ''));
    $birthdate = trim((string) ($_POST['birthdate'] ?? ''));
    $fatherName = compose_person_name($_POST, 'father');
    $motherMaidenName = compose_person_name($_POST, 'mother');
    $sponsorName = compose_person_name($_POST, 'sponsor');
    $ministerName = compose_person_name($_POST, 'minister');
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $bookNo = trim((string) ($_POST['book_no'] ?? ''));
    $pageNo = trim((string) ($_POST['page_no'] ?? ''));
    $lineNo = trim((string) ($_POST['line_no'] ?? ''));

    if ($confirmationDate === '') $errors[] = 'Confirmation date is required.';
    if ($birthdate !== '' && !confirmation_birthdate_is_valid($birthdate)) $errors[] = 'Enter a valid birth date that is not in the future.';

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $applicationId = filter_var($_POST['from_application'] ?? null, FILTER_VALIDATE_INT);
            $linkedApplication = null;
            if ($applicationId && $applicationId > 0) {
                $applicationStmt = $pdo->prepare(
                    "SELECT id, request_no, status, person_id
                     FROM sacrament_applications
                     WHERE id = ? AND sacrament_type = 'confirmation'
                     FOR UPDATE"
                );
                $applicationStmt->execute([(int) $applicationId]);
                $linkedApplication = $applicationStmt->fetch(PDO::FETCH_ASSOC);
                if (!$linkedApplication || !in_array($linkedApplication['status'], ['approved', 'scheduled'], true)) {
                    throw new InvalidArgumentException('This application must be approved or scheduled before it can be encoded.');
                }
            }

            $personId = $linkedApplication && !empty($linkedApplication['person_id'])
                ? (int) $linkedApplication['person_id']
                : posted_person_id($pdo, 'person_id');
            if ($personId !== null) {
                $identity = confirmation_load_person_identity($pdo, $personId);
                if (!$identity) {
                    throw new InvalidArgumentException('The selected person identity could not be found.');
                }
            } else {
                if ($confirmandFirst === '' || $confirmandLast === '') {
                    throw new InvalidArgumentException("Enter the confirmand's first and last name.");
                }
                $personId = save_person_identity($pdo, null, [
                    'first_name' => $confirmandFirst,
                    'middle_name' => $confirmandMiddle,
                    'last_name' => $confirmandLast,
                    'suffix' => $confirmandSuffix,
                    'birth_date' => $birthdate !== '' ? $birthdate : null,
                    'father_name' => $fatherName,
                    'mother_maiden_name' => $motherMaidenName,
                ]);
                $identity = confirmation_load_person_identity($pdo, $personId);
            }
            $confirmandName = person_identity_label($identity);
            $birthdate = (string) ($identity['birth_date'] ?? '');
            $logbook = register_next_logbook_numbers($pdo, 'confirmation', true);
            $bookNo = $logbook['book_no'];
            $pageNo = $logbook['page_no'];
            $lineNo = $logbook['line_no'];

            // Make room for a mid-register insert when requested.
            $shifted = 0;
            if ($entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shifted = register_shift_entry_numbers($pdo, 'confirmation', $entryNo);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO sacrament_records
                    (sacrament_type, person_id, source, event_date, minister_name, entry_no,
                     book_no, page_no, line_no, remarks, encoded_by)
                 VALUES ('confirmation', ?, 'this_parish', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $personId,
                $confirmationDate,
                $ministerName !== '' ? $ministerName : null,
                $entryNo !== '' ? $entryNo : null,
                $bookNo,
                $pageNo,
                $lineNo,
                $remarks !== '' ? $remarks : null,
                current_user()['id'],
            ]);
            $recordId = (int) $pdo->lastInsertId();
            if ($sponsorName !== '') {
                $participantStmt = $pdo->prepare(
                    "INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
                     VALUES (?, 'sponsor', ?, NULL, 0)"
                );
                $participantStmt->execute([$recordId, $sponsorName]);
            }

            log_activity($pdo, current_user()['id'], 'confirmation_record_encoded', 'sacrament_records', $recordId,
                "Confirmation record encoded for {$confirmandName}" . ($shifted > 0 ? " — {$shifted} later entries renumbered" : ''));

            $appDone = '';
            $applicationRequestNo = null;
            if ($linkedApplication) {
                $completed = $pdo->prepare(
                    "UPDATE sacrament_applications
                     SET status = 'completed', record_id = ?, processed_by = ?, person_id = ?
                     WHERE id = ? AND sacrament_type = 'confirmation'
                       AND status IN ('approved', 'scheduled')"
                );
                $completed->execute([$recordId, current_user()['id'], $personId, (int) $linkedApplication['id']]);
                if ($completed->rowCount() !== 1) {
                    throw new RuntimeException('The confirmation application could not be completed.');
                }
                $applicationRequestNo = (string) $linkedApplication['request_no'];
                log_activity($pdo, current_user()['id'], 'application_recorded_in_register', 'sacrament_applications', (int) $linkedApplication['id'],
                    "Confirmation encoded into register (record #{$recordId}); application completed.");
                $appDone = '&app_completed=1';
            }
            $pdo->commit();
            if ($applicationRequestNo !== null) {
                try {
                    notify_sacrament_application_status($pdo, 'completed', 'confirmation', $applicationRequestNo, "Confirmation encoded into register (record #{$recordId}).");
                } catch (Throwable $e) {
                    error_log('Confirmation application completion notification failed: ' . $e->getMessage());
                }
            }
            redirect_to('/admin/records/confirmation.php?added=1' . ($shifted > 0 ? '&shifted=' . $shifted : '') . $appDone);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SRS-SPPO confirmation record save failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

// --- Update an existing record (Edit button on a table row) -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record']) && csrf_verify()) {
    require_role('secretary');

    $editId = (int) ($_POST['edit_id'] ?? 0);
    $entryNo = trim((string) ($_POST['entry_no'] ?? ''));
    $confirmationDate = trim((string) ($_POST['confirmation_date'] ?? ''));
    $sponsorName = compose_person_name($_POST, 'sponsor');
    $ministerName = compose_person_name($_POST, 'minister');
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $bookNo = trim((string) ($_POST['book_no'] ?? ''));
    $pageNo = trim((string) ($_POST['page_no'] ?? ''));
    $lineNo = trim((string) ($_POST['line_no'] ?? ''));

    if (!$editRecord || $editId !== (int) $editRecord['id']) $errors[] = 'Invalid record selected for update.';
    if ($confirmationDate === '') $errors[] = 'Confirmation date is required.';

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "UPDATE sacrament_records
                 SET event_date = ?, minister_name = ?, entry_no = ?, book_no = ?, page_no = ?,
                     line_no = ?, remarks = ?, encoded_by = ?
                 WHERE id = ? AND sacrament_type = 'confirmation'"
            );
            $stmt->execute([
                $confirmationDate,
                $ministerName !== '' ? $ministerName : null,
                $entryNo !== '' ? $entryNo : null,
                $bookNo,
                $pageNo,
                $lineNo,
                $remarks !== '' ? $remarks : null,
                current_user()['id'],
                $editId,
            ]);

            $pdo->prepare('DELETE FROM sacrament_participants WHERE record_id = ?')->execute([$editId]);
            if ($sponsorName !== '') {
                $participantStmt = $pdo->prepare(
                    "INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
                     VALUES (?, 'sponsor', ?, NULL, 0)"
                );
                $participantStmt->execute([$editId, $sponsorName]);
            }
            log_activity($pdo, current_user()['id'], 'confirmation_record_updated', 'sacrament_records', $editId,
                'Confirmation record updated for ' . person_identity_label($identity));

            $pdo->commit();
            redirect_to('/admin/records/confirmation.php?updated=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SRS-SPPO confirmation record update failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while updating this record. Please try again.';
        }
    }
}

// Permanent deletion is intentionally unavailable; use Admin > Archive or Restore Record.
$prerequisiteWarnings = [];
if ($identity) {
    $prerequisiteStmt = $pdo->prepare(
        "SELECT
             SUM(CASE WHEN sacrament_type = 'baptism' THEN 1 ELSE 0 END) AS baptism_count,
             SUM(CASE WHEN sacrament_type = 'first_communion' THEN 1 ELSE 0 END) AS communion_count
         FROM sacrament_records
         WHERE person_id = ? AND sacrament_type IN ('baptism', 'first_communion')"
    );
    $prerequisiteStmt->execute([(int) $identity['id']]);
    $prerequisites = $prerequisiteStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int) ($prerequisites['baptism_count'] ?? 0) === 0
        && ($identity['baptismal_status'] ?? null) !== 'baptized_catholic') {
        $prerequisiteWarnings[] = 'No baptism record or baptized Catholic status is recorded for this person.';
    }
    if ((int) ($prerequisites['communion_count'] ?? 0) === 0) {
        $prerequisiteWarnings[] = 'No First Communion record is recorded for this person.';
    }
}

// Suggested next logbook numbers for the encode dialog (create mode only).
$suggest = $isEdit
    ? ['entry_no' => '', 'book_no' => '', 'page_no' => '', 'line_no' => '']
    : register_next_logbook_numbers($pdo, 'confirmation');

if (isset($_GET['added'])) $success = 'Confirmation record saved.' . (isset($_GET['shifted']) ? ' Following entries were renumbered to make room.' : '');
if (isset($_GET['app_completed'])) $success = 'Confirmation record saved and application completed.';
if (isset($_GET['updated'])) $success = 'Confirmation record updated.';
if (isset($_GET['deleted'])) $success = 'Confirmation record deleted.';

// --- Filter / paginate (shared component) --------------------------------
$rfConfig = [
    'date_column'    => 'cr.confirmation_date',
    'search_columns' => ['cr.full_name', 'cr.father_name', 'cr.mother_maiden_name', 'cr.sponsor_name'],
    'placeholder'    => 'Search confirmand, parents, sponsor...',
    'ranges'         => ['year', 'last_year', 'last5'],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$allowedSorts = ['register', 'book', 'book_desc', 'page', 'page_desc', 'line', 'line_desc', 'name', 'name_desc', 'date_desc', 'date_asc'];
$sort = $_GET['sort'] ?? 'register';
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'register';
}

if ($sort === 'date_desc') {
    $orderClause = "ORDER BY cr.confirmation_date DESC, cr.full_name ASC";
} elseif ($sort === 'date_asc') {
    $orderClause = "ORDER BY cr.confirmation_date ASC, cr.full_name ASC";
} elseif ($sort === 'page_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'page') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'line_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'line') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'name_desc') {
    $orderClause = "ORDER BY cr.full_name DESC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'name') {
    $orderClause = "ORDER BY cr.full_name ASC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'book_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) DESC";
} else {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) ASC";
}

function confirmation_sort_url(string $key): string
{
    global $basePath, $rf, $sort;
    if ($key === 'date_desc' || $key === 'date_asc') {
        $next = $sort === 'date_desc' ? 'date_asc' : 'date_desc';
    } else {
        $next = $sort === $key ? $key . '_desc' : $key;
        if ($sort === $key . '_desc') {
            $next = $key;
        }
    }
    $query = array_filter([
        'q' => $rf['q'] !== '' ? $rf['q'] : null,
        'range' => $rf['range'] !== '' ? $rf['range'] : null,
        'date_from' => $rf['range'] === 'custom' ? $rf['from'] : null,
        'date_to' => $rf['range'] === 'custom' ? $rf['to'] : null,
        'per_page' => $rf['per_page'] !== 10 ? (int) $rf['per_page'] : null,
        'sort' => $next,
    ], static fn ($value) => $value !== null && $value !== '');
    $queryString = http_build_query($query);
    return $basePath . '/admin/records/confirmation.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function confirmation_sort_indicator(string $key): string
{
    global $sort;
    if ($key === 'date_desc' || $key === 'date_asc') {
        $direction = $sort === 'date_desc' ? 'desc' : ($sort === 'date_asc' ? 'asc' : null);
    } else {
        $direction = $sort === $key ? 'asc' : ($sort === $key . '_desc' ? 'desc' : null);
    }
    if ($direction === 'asc') {
        return ' <span class="sort-indicator" aria-hidden="true">↑</span>';
    }
    if ($direction === 'desc') {
        return ' <span class="sort-indicator" aria-hidden="true">↓</span>';
    }
    return ' <span class="sort-indicator sort-indicator-muted" aria-hidden="true">↕</span>';
}

$confirmationRowsSql = "
    SELECT sr.id, sr.person_id, sr.event_date AS confirmation_date,
           sr.entry_no, sr.book_no, sr.page_no, sr.line_no,
           sr.minister_name, sr.remarks,
           p.first_name, p.middle_name, p.last_name, p.suffix, p.birth_date,
           p.father_name, p.mother_maiden_name,
           CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) AS full_name,
           sponsors.sponsor_name
    FROM sacrament_records sr
    JOIN persons p ON p.id = sr.person_id
    LEFT JOIN (
        SELECT record_id, MAX(full_name) AS sponsor_name
        FROM sacrament_participants
        WHERE role = 'sponsor'
        GROUP BY record_id
    ) sponsors ON sponsors.record_id = sr.id
    WHERE sr.sacrament_type = 'confirmation'";
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM ({$confirmationRowsSql}) cr
     WHERE 1 = 1 {$rf['where']}"
);
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT cr.id, cr.person_id, cr.confirmation_date, cr.entry_no, cr.book_no,
            cr.page_no, cr.line_no, cr.minister_name, cr.remarks,
            cr.first_name, cr.middle_name, cr.last_name, cr.suffix,
            cr.birth_date, cr.father_name, cr.mother_maiden_name,
            cr.full_name, cr.sponsor_name
     FROM ({$confirmationRowsSql}) cr
     WHERE 1 = 1 {$rf['where']} {$orderClause}
     LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$records = $stmt->fetchAll();
$isSecretary = (current_user()['role'] ?? '') === 'secretary';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <h1>Confirmation Records</h1>
            <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/index.php') ?>">Back to All Records</a>
            <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>

                <?php rf_render_bar($basePath . '/admin/records/confirmation.php', $rfConfig, $rf); ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Confirmation register records; scroll horizontally to view all columns">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= $sort === 'book' ? ' is-active' : '' ?>" href="<?= e(confirmation_sort_url('book')) ?>">Book<?= confirmation_sort_indicator('book') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'page' ? ' is-active' : '' ?>" href="<?= e(confirmation_sort_url('page')) ?>">Page<?= confirmation_sort_indicator('page') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'line' ? ' is-active' : '' ?>" href="<?= e(confirmation_sort_url('line')) ?>">Line<?= confirmation_sort_indicator('line') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'name' ? ' is-active' : '' ?>" href="<?= e(confirmation_sort_url('name')) ?>">Full Name<?= confirmation_sort_indicator('name') ?></a></th>
                    <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(confirmation_sort_url($sort === 'date_desc' ? 'date_asc' : 'date_desc')) ?>">Date of Confirmation<?= confirmation_sort_indicator($sort === 'date_desc' || $sort === 'date_asc' ? $sort : 'date_desc') ?></a></th>
                    <th>Linked</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="7" class="empty-table-row">
                            <div class="empty-state"><h2>No confirmation records found</h2><p><?= $rf['q'] !== '' ? 'No matches for "' . e($rf['q']) . '".' : 'New records will appear here after they are encoded.' ?></p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $rec): ?>
                        <tr>
                            <td><?= e($rec['book_no'] ?: '—') ?></td>
                            <td><?= e($rec['page_no'] ?: '—') ?></td>
                            <td><?= e($rec['line_no'] ?: '—') ?></td>
                            <td><strong><?= e(display_name_with_middle_initial(
                                (string) $rec['full_name'],
                                isset($rec['first_name']) ? (string) $rec['first_name'] : null,
                                isset($rec['middle_name']) ? (string) $rec['middle_name'] : null,
                                isset($rec['last_name']) ? (string) $rec['last_name'] : null,
                                isset($rec['suffix']) ? (string) $rec['suffix'] : null
                            )) ?></strong></td>
                            <td><?= e(date('M j, Y', strtotime($rec['confirmation_date']))) ?></td>
                            <?php $linkStatus = ((int) ($rec['person_id'] ?? 0)) > 0 ? 'linked' : 'unlinked'; ?>
                            <td><span class="link-status-badge link-status-<?= e($linkStatus) ?>"><?= $linkStatus === 'linked' ? 'Linked' : 'Unlinked' ?></span></td>
                            <td class="row-actions">
                                <a href="<?= e($basePath . '/admin/records/view.php?type=confirmation&id=' . (int) $rec['id'] . '&return=' . urlencode($selfReturn)) ?>" class="btn btn-secondary btn-small">View record</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($basePath . '/admin/records/confirmation.php', $rfTotal, $rf); ?>

        <dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || !empty($errors) || $fromApplicationId > 0) ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $isEdit ? 'Edit Entry #' . (int) $editRecord['id'] . ' - ' . e(person_identity_label($identity)) : 'Encode New Entry' ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>
            <div class="dialog-body">
        <?php if ($isEdit): ?>
            <p class="field-hint"><a href="<?= $basePath ?>/admin/records/confirmation.php">Cancel edit - back to adding a new entry.</a></p>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form autocomplete="off" method="post" action="" data-review data-review-title="Review Confirmation Entry" data-review-confirm="Confirm and Save">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
            <input type="hidden" name="entry_no" value="<?= e($_POST['entry_no'] ?? $editRecord['entry_no'] ?? '') ?>">
            <fieldset>
                <legend>Logbook Reference</legend>
                <div class="inline-fields">
                    <label>Book No. <input autocomplete="off" type="text" name="book_no" value="<?= e($_POST['book_no'] ?? $editRecord['book_no'] ?? $suggest['book_no']) ?>"<?= !$isEdit ? ' readonly' : '' ?>></label>
                    <label>Page <input autocomplete="off" type="text" name="page_no" value="<?= e($_POST['page_no'] ?? $editRecord['page_no'] ?? $suggest['page_no']) ?>"<?= !$isEdit ? ' readonly' : '' ?>></label>
                    <label>Line <input autocomplete="off" type="text" name="line_no" value="<?= e($_POST['line_no'] ?? $editRecord['line_no'] ?? $suggest['line_no']) ?>"<?= !$isEdit ? ' readonly' : '' ?>></label>
                </div>
                <p class="field-hint">Book, page, and line identify the physical logbook location. The system Record ID is shown on the read-only record page.</p>
            </fieldset>

            <fieldset>
                <legend>Confirmand</legend>
                <?php if ($identity): ?>
                    <input type="hidden" name="person_id" value="<?= (int) $identity['id'] ?>">
                    <p><strong><?= e(person_identity_label($identity)) ?></strong></p>
                    <p>Birth date: <?= e((string) ($identity['birth_date'] ?? 'Not recorded')) ?></p>
                    <p>Father: <?= e((string) ($identity['father_name'] ?? 'Not recorded')) ?></p>
                    <p>Mother's maiden name: <?= e((string) ($identity['mother_maiden_name'] ?? 'Not recorded')) ?></p>
                    <p>Address: <?= e((string) ($identity['address'] ?? 'Not recorded')) ?></p>
                    <p class="field-hint">Identity is read-only from the master persons record.</p>
                    <?php if ($prerequisiteWarnings): ?>
                        <div class="alert alert-warning"><strong>Prerequisite review:</strong><ul><?php foreach ($prerequisiteWarnings as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?></ul></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="inline-fields structured-name-fields">
                        <label>First Name <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="confirmand_first_name" value="<?= e($_POST['confirmand_first_name'] ?? '') ?>" required></label>
                        <label>Middle Name <span class="field-hint">(optional)</span> <input autocomplete="off" type="text" name="confirmand_middle_name" value="<?= e($_POST['confirmand_middle_name'] ?? '') ?>"></label>
                        <label>Surname <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="confirmand_last_name" value="<?= e($_POST['confirmand_last_name'] ?? '') ?>" required></label>
                        <label>Suffix <input autocomplete="off" type="text" name="confirmand_suffix" value="<?= e($_POST['confirmand_suffix'] ?? '') ?>"></label>
                    </div>
                    <div class="person-finder" data-person-finder data-endpoint="<?= e($basePath . '/admin/api/person-search.php') ?>">
                        <label>Find possible people
                            <input autocomplete="off" type="search" data-person-search placeholder="Enter the name first, then search">
                        </label>
                        <button type="button" class="btn btn-secondary btn-small" data-person-find>Find possible people</button>
                        <input type="hidden" name="person_id" data-person-id value="<?= e($_POST['person_id'] ?? '') ?>">
                        <div class="person-finder-selection" data-person-selection aria-live="polite"></div>
                        <div class="person-finder-results" data-person-results></div>
                        <p class="field-hint">Review possible matches before selecting. If there is no match, saving creates a master person profile for this record.</p>
                    </div>
                    <label>Birthdate <input autocomplete="off" type="date" name="birthdate" value="<?= e($_POST['birthdate'] ?? '') ?>"></label>
                    <div class="inline-fields">
                        <?= person_name_fields('father', "Father's Name", $recordNameFormValues) ?>
                        <?= person_name_fields('mother', "Mother's Maiden Name", $recordNameFormValues) ?>
                    </div>
                <?php endif; ?>
                <label>Confirmation Date <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="date" name="confirmation_date" value="<?= e($_POST['confirmation_date'] ?? $editRecord['confirmation_date'] ?? '') ?>" required></label>
                <?= person_name_fields('sponsor', 'Sponsor', $recordNameFormValues, false) ?>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
                <?= person_name_fields('minister', 'Officiating Minister', $recordNameFormValues, false) ?>
                <label>Remarks <textarea autocomplete="off" name="remarks"><?= e($_POST['remarks'] ?? $editRecord['remarks'] ?? '') ?></textarea></label>
            </fieldset>

            <input type="hidden" name="from_application" value="<?= (int) ($_POST['from_application'] ?? $fromApplicationId) ?>">
            <?php if ($isEdit): ?>
                <button type="submit" name="update_record" value="1" class="btn btn-primary">Update Record</button>
            <?php else: ?>
                <button type="submit" name="add_record" value="1" class="btn">Save Record</button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
        </form>
        </div>
        </dialog>

    <?php require_once __DIR__ . '/../../includes/admin-footer.php';
