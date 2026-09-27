<?php
/**
 * =====================================================================
 * SRS-SPPO MARRIAGE RECORDS (admin register)
 * =====================================================================
 * Purpose: Digital mirror of the physical marriage register: encode
 *          entries from the logbook, list/search, and serve as the
 *          source of truth for marriage certificate requests.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

// This page's own app-relative URL (path + current query string) so the record
// view can send the user back here with the same search, filter and page.
$selfReturn = '/admin/records/marriage.php' . ((($_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $_SERVER['QUERY_STRING'] : '');

$pageTitle = 'Marriage Records';
$errors  = [];
$success = null;

// --- Pre-fill from an approved sacrament application (optional) -----------
// Opened from a marriage application detail page via ?from_application=N:
// couple data becomes the form defaults. GET-only seeding; a POST always
// keeps what the secretary typed. NOTE: the register stores the wife's
// MAIDEN name — the pre-fill uses the application's bride name as-is and
// the secretary must verify/correct it before saving.
$fromApplicationId = 0;
if (!empty($_GET['from_application']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $s = $pdo->prepare(
        "SELECT sa.*, ma.* FROM sacrament_applications sa
         JOIN marriage_applications ma ON ma.application_id = sa.id
         WHERE sa.id = ? AND sa.sacrament_type = 'marriage'"
    );
    $s->execute([(int) $_GET['from_application']]);
    if ($app = $s->fetch()) {
        $fromApplicationId = (int) $app['id'];
        $_POST['husband_full_name']   = (string) ($app['groom_full_name'] ?? '');
        $_POST['husband_birthdate']   = (string) ($app['groom_birthdate'] ?? '');
        $_POST['husband_birthplace']  = (string) ($app['groom_birthplace'] ?? '');
        $_POST['wife_maiden_name']    = (string) ($app['bride_full_name'] ?? '');
        $_POST['wife_birthdate']      = (string) ($app['bride_birthdate'] ?? '');
        $_POST['wife_birthplace']     = (string) ($app['bride_birthplace'] ?? '');
        $_POST['marriage_date']       = (string) ($app['preferred_wedding_date'] ?? '');
        $_POST['officiating_minister'] = (string) ($app['assigned_priest_name'] ?? '');
        $_POST['remarks']             = 'Encoded from application ' . $app['tracking_number'];
    }
}

// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form). Nothing is written when it fails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record']) && csrf_verify()) {
    require_role('secretary');

    $entryNo         = trim($_POST['entry_no'] ?? '');
    $marriageDate    = $_POST['marriage_date'] ?? '';
    $husbandName     = trim($_POST['husband_full_name'] ?? '');
    $wifeMaidenName  = trim($_POST['wife_maiden_name'] ?? '');
    $husbandFirst = trim($_POST['husband_first_name'] ?? '');
    $husbandMiddle = trim($_POST['husband_middle_name'] ?? '');
    $husbandLast = trim($_POST['husband_last_name'] ?? '');
    $husbandSuffix = trim($_POST['husband_suffix'] ?? '');
    $wifeFirst = trim($_POST['wife_first_name'] ?? '');
    $wifeMiddle = trim($_POST['wife_middle_name'] ?? '');
    $wifeLast = trim($_POST['wife_last_name'] ?? '');
    $wifeSuffix = trim($_POST['wife_suffix'] ?? '');
    if ($husbandFirst !== '' || $husbandMiddle !== '' || $husbandLast !== '') $husbandName = compose_structured_name($husbandFirst, $husbandMiddle, $husbandLast, $husbandSuffix);
    if ($wifeFirst !== '' || $wifeMiddle !== '' || $wifeLast !== '') $wifeMaidenName = compose_structured_name($wifeFirst, $wifeMiddle, $wifeLast, $wifeSuffix);
    $husbandBirthdate  = $_POST['husband_birthdate'] ?: null;
    $husbandBirthplace = trim($_POST['husband_birthplace'] ?? '');
    $husbandCivilStatus = trim($_POST['husband_civil_status'] ?? '');
    $husbandAge        = isset($_POST['husband_age']) && $_POST['husband_age'] !== '' ? (int) $_POST['husband_age'] : null;
    $husbandOrigin     = trim($_POST['husband_origin'] ?? '');
    $husbandResidence  = trim($_POST['husband_residence'] ?? '');
    $husbandParents    = trim($_POST['husband_parents_name'] ?? '');
    $wifeBirthdate     = $_POST['wife_birthdate'] ?: null;
    $wifeBirthplace    = trim($_POST['wife_birthplace'] ?? '');
    $wifeCivilStatus   = trim($_POST['wife_civil_status'] ?? '');
    $wifeAge           = isset($_POST['wife_age']) && $_POST['wife_age'] !== '' ? (int) $_POST['wife_age'] : null;
    $wifeOrigin        = trim($_POST['wife_origin'] ?? '');
    $wifeResidence     = trim($_POST['wife_residence'] ?? '');
    $wifeParents       = trim($_POST['wife_parents_name'] ?? '');
    $ministerName    = trim($_POST['officiating_minister'] ?? '');
    $remarks         = trim($_POST['remarks'] ?? '');
    $bookNo          = trim($_POST['book_no'] ?? '');
    $pageNo          = trim($_POST['page_no'] ?? '');
    $lineNo          = trim($_POST['line_no'] ?? '');

    $witnessNames      = $_POST['witness_name'] ?? [];
    if (!is_array($witnessNames)) { $witnessNames = []; }
    $witnessResidences = $_POST['witness_residence'] ?? [];
    if (!is_array($witnessResidences)) { $witnessResidences = []; }

    if ($marriageDate === '')   $errors[] = 'Marriage date is required.';
    if ($husbandName === '')    $errors[] = "Husband's full name is required.";
    if ($wifeMaidenName === '') $errors[] = "Wife's maiden name is required.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $logbook = register_next_logbook_numbers($pdo, 'marriage_records', true);
            $bookNo = $logbook['book_no'];
            $pageNo = $logbook['page_no'];
            $lineNo = $logbook['line_no'];

            // Make room for a mid-register insert when requested.
            $shifted = 0;
            if ($entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shifted = register_shift_entry_numbers($pdo, 'marriage_records', $entryNo);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO marriage_records
                    (entry_no, marriage_date, husband_full_name, wife_maiden_name, husband_birthdate,
                     husband_birthplace, husband_civil_status, husband_age, husband_origin, husband_residence,
                     husband_parents_name, wife_birthdate, wife_birthplace, wife_civil_status, wife_age,
                     wife_origin, wife_residence, wife_parents_name, officiating_minister, remarks,
                     book_no, page_no, line_no, encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $marriageDate, $husbandName, $wifeMaidenName, $husbandBirthdate,
                $husbandBirthplace, $husbandCivilStatus, $husbandAge, $husbandOrigin, $husbandResidence,
                $husbandParents, $wifeBirthdate, $wifeBirthplace, $wifeCivilStatus, $wifeAge,
                $wifeOrigin, $wifeResidence, $wifeParents, $ministerName, $remarks,
                $bookNo, $pageNo, $lineNo, current_user()['id'],
            ]);
            $recordId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE marriage_records SET husband_first_name = ?, husband_middle_name = ?, husband_last_name = ?, husband_suffix = ?, wife_first_name = ?, wife_middle_name = ?, wife_last_name = ?, wife_suffix = ? WHERE id = ?')
                ->execute([$husbandFirst, $husbandMiddle, $husbandLast, $husbandSuffix, $wifeFirst, $wifeMiddle, $wifeLast, $wifeSuffix, $recordId]);

            $witnessStmt = $pdo->prepare(
                "INSERT INTO marriage_witnesses (marriage_record_id, witness_name, witness_residence) VALUES (?, ?, ?)"
            );
            foreach ($witnessNames as $i => $name) {
                if (!is_string($name)) continue;
                $name = trim($name);
                if ($name === '') continue;
                $witnessStmt->execute([$recordId, $name, trim(isset($witnessResidences[$i]) && is_string($witnessResidences[$i]) ? $witnessResidences[$i] : '')]);
            }

            log_activity($pdo, current_user()['id'], 'marriage_record_encoded', 'marriage_records', $recordId,
                "Marriage record encoded for {$husbandName} & {$wifeMaidenName}" . ($shifted > 0 ? " — {$shifted} later entries renumbered" : ''));

            $pdo->commit();

            // If this entry was encoded from an approved marriage application,
            // close the loop: mark the application completed and audit the link.
            $appDone = '';
            if (!empty($_POST['from_application'])) {
                $u = $pdo->prepare(
                    "UPDATE sacrament_applications
                        SET status = 'completed', date_processed = NOW(), processed_by = ?
                      WHERE id = ? AND sacrament_type = 'marriage' AND status IN ('approved','scheduled')"
                );
                $u->execute([current_user()['id'], (int) $_POST['from_application']]);
                if ($u->rowCount()) {
                    $appRow = $pdo->query("SELECT tracking_number, sacrament_type FROM sacrament_applications WHERE id = " . (int) $_POST['from_application'])->fetch();
                    log_activity($pdo, current_user()['id'], 'application_recorded_in_register', 'sacrament_applications', (int) $_POST['from_application'],
                        "Marriage encoded into register (record #{$recordId}); application completed");
                    notify_sacrament_application_status($pdo, 'completed', (string) $appRow['sacrament_type'], (string) $appRow['tracking_number'], "Marriage encoded into register (record #{$recordId}).");
                    $appDone = '&app_completed=1';
                }
            }

            redirect_to('/admin/records/marriage.php?added=1' . ($shifted > 0 ? '&shifted=' . $shifted : '') . $appDone);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

// --- Update an existing record (Edit button on a table row) -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record']) && csrf_verify()) {
    require_role('secretary');

    $editId            = (int) ($_POST['edit_id'] ?? 0);
    $entryNo           = trim($_POST['entry_no'] ?? '');
    $marriageDate      = $_POST['marriage_date'] ?? '';
    $husbandName       = trim($_POST['husband_full_name'] ?? '');
    $wifeMaidenName    = trim($_POST['wife_maiden_name'] ?? '');
    $husbandFirst = trim($_POST['husband_first_name'] ?? ''); $husbandMiddle = trim($_POST['husband_middle_name'] ?? ''); $husbandLast = trim($_POST['husband_last_name'] ?? ''); $husbandSuffix = trim($_POST['husband_suffix'] ?? '');
    $wifeFirst = trim($_POST['wife_first_name'] ?? ''); $wifeMiddle = trim($_POST['wife_middle_name'] ?? ''); $wifeLast = trim($_POST['wife_last_name'] ?? ''); $wifeSuffix = trim($_POST['wife_suffix'] ?? '');
    if ($husbandFirst !== '' || $husbandMiddle !== '' || $husbandLast !== '') $husbandName = compose_structured_name($husbandFirst, $husbandMiddle, $husbandLast, $husbandSuffix);
    if ($wifeFirst !== '' || $wifeMiddle !== '' || $wifeLast !== '') $wifeMaidenName = compose_structured_name($wifeFirst, $wifeMiddle, $wifeLast, $wifeSuffix);
    $husbandBirthdate  = $_POST['husband_birthdate'] ?: null;
    $husbandBirthplace = trim($_POST['husband_birthplace'] ?? '');
    $husbandCivilStatus = trim($_POST['husband_civil_status'] ?? '');
    $husbandAge        = isset($_POST['husband_age']) && $_POST['husband_age'] !== '' ? (int) $_POST['husband_age'] : null;
    $husbandOrigin     = trim($_POST['husband_origin'] ?? '');
    $husbandResidence  = trim($_POST['husband_residence'] ?? '');
    $husbandParents    = trim($_POST['husband_parents_name'] ?? '');
    $wifeBirthdate     = $_POST['wife_birthdate'] ?: null;
    $wifeBirthplace    = trim($_POST['wife_birthplace'] ?? '');
    $wifeCivilStatus   = trim($_POST['wife_civil_status'] ?? '');
    $wifeAge           = isset($_POST['wife_age']) && $_POST['wife_age'] !== '' ? (int) $_POST['wife_age'] : null;
    $wifeOrigin        = trim($_POST['wife_origin'] ?? '');
    $wifeResidence     = trim($_POST['wife_residence'] ?? '');
    $wifeParents       = trim($_POST['wife_parents_name'] ?? '');
    $ministerName      = trim($_POST['officiating_minister'] ?? '');
    $remarks           = trim($_POST['remarks'] ?? '');
    $bookNo            = trim($_POST['book_no'] ?? '');
    $pageNo            = trim($_POST['page_no'] ?? '');
    $lineNo            = trim($_POST['line_no'] ?? '');
    $witnessNames      = $_POST['witness_name'] ?? [];
    if (!is_array($witnessNames)) { $witnessNames = []; }
    $witnessResidences = $_POST['witness_residence'] ?? [];
    if (!is_array($witnessResidences)) { $witnessResidences = []; }

    if ($marriageDate === '')   $errors[] = 'Marriage date is required.';
    if ($husbandName === '')    $errors[] = "Husband's full name is required.";
    if ($wifeMaidenName === '') $errors[] = "Wife's maiden name is required.";
    if ($editId <= 0)           $errors[] = 'Invalid record selected for update.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "UPDATE marriage_records
                    SET entry_no = ?, marriage_date = ?, husband_full_name = ?, wife_maiden_name = ?,
                        husband_birthdate = ?, husband_birthplace = ?, husband_civil_status = ?, husband_age = ?,
                        husband_origin = ?, husband_residence = ?, husband_parents_name = ?, wife_birthdate = ?,
                        wife_birthplace = ?, wife_civil_status = ?, wife_age = ?, wife_origin = ?,
                        wife_residence = ?, wife_parents_name = ?, officiating_minister = ?, remarks = ?, book_no = ?,
                        page_no = ?, line_no = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $entryNo, $marriageDate, $husbandName, $wifeMaidenName, $husbandBirthdate,
                $husbandBirthplace, $husbandCivilStatus, $husbandAge, $husbandOrigin, $husbandResidence,
                $husbandParents, $wifeBirthdate, $wifeBirthplace, $wifeCivilStatus, $wifeAge,
                $wifeOrigin, $wifeResidence, $wifeParents, $ministerName, $remarks,
                $bookNo, $pageNo, $lineNo, $editId,
            ]);
            $pdo->prepare('UPDATE marriage_records SET husband_first_name = ?, husband_middle_name = ?, husband_last_name = ?, husband_suffix = ?, wife_first_name = ?, wife_middle_name = ?, wife_last_name = ?, wife_suffix = ? WHERE id = ?')->execute([$husbandFirst, $husbandMiddle, $husbandLast, $husbandSuffix, $wifeFirst, $wifeMiddle, $wifeLast, $wifeSuffix, $editId]);

            // Replace the witness set wholesale with what was submitted.
            $pdo->prepare("DELETE FROM marriage_witnesses WHERE marriage_record_id = ?")->execute([$editId]);
            $witnessStmt = $pdo->prepare(
                "INSERT INTO marriage_witnesses (marriage_record_id, witness_name, witness_residence) VALUES (?, ?, ?)"
            );
            foreach ($witnessNames as $i => $name) {
                if (!is_string($name)) continue;
                $name = trim($name);
                if ($name === '') continue;
                $witnessStmt->execute([$editId, $name, trim(isset($witnessResidences[$i]) && is_string($witnessResidences[$i]) ? $witnessResidences[$i] : '')]);
            }

            log_activity($pdo, current_user()['id'], 'marriage_record_updated', 'marriage_records', $editId,
                "Marriage record updated for {$husbandName} & {$wifeMaidenName}");

            $pdo->commit();
            redirect_to('/admin/records/marriage.php?updated=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while updating this record. Please try again.';
        }
    }
}

// Permanent deletion is intentionally unavailable; use Admin > Archive or Restore Record.

// --- Edit mode: load the requested record into the encode form -----------
$editRecord    = null;
$editWitnesses = [];
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM marriage_records WHERE id = ?");
    $stmt->execute([$editId]);
    $editRecord = $stmt->fetch();
    if (!$editRecord) {
        redirect_to('/admin/records/marriage.php');
        exit;
    }
    $stmt = $pdo->prepare("SELECT witness_name, witness_residence FROM marriage_witnesses WHERE marriage_record_id = ? ORDER BY id");
    $stmt->execute([(int) $editRecord['id']]);
    $editWitnesses = $stmt->fetchAll();
}
$isEdit = $editRecord !== null;

// Suggested next logbook numbers for the encode dialog (create mode only).
$suggest = $isEdit
    ? ['entry_no' => '', 'book_no' => '', 'page_no' => '', 'line_no' => '']
    : register_next_logbook_numbers($pdo, 'marriage_records');
$husbandParts = $isEdit ? structured_name_parts((string) $editRecord['husband_full_name']) : ['', '', '', ''];
$wifeParts = $isEdit ? structured_name_parts((string) $editRecord['wife_maiden_name']) : ['', '', '', ''];

if (isset($_GET['added'])) $success = 'Marriage record saved.' . (isset($_GET['shifted']) ? ' Following entries were renumbered to make room.' : '') . (isset($_GET['app_completed']) ? ' The linked marriage application was marked completed.' : '');
if (isset($_GET['updated'])) $success = 'Marriage record updated.';
if (isset($_GET['deleted'])) $success = 'Marriage record deleted.';

// --- Filter / paginate (shared component) --------------------------------
$rfConfig = [
    'date_column'    => 'marriage_date',
    'search_columns' => ['husband_full_name', 'wife_maiden_name', 'officiating_minister'],
    'placeholder'    => 'Search spouse names or minister...',
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
    $orderClause = "ORDER BY marriage_date DESC, husband_full_name ASC";
} elseif ($sort === 'date_asc') {
    $orderClause = "ORDER BY marriage_date ASC, husband_full_name ASC";
} elseif ($sort === 'page_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'page') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'line_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'line') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'name_desc') {
    $orderClause = "ORDER BY CONCAT(husband_full_name, ' & ', wife_maiden_name) DESC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'name') {
    $orderClause = "ORDER BY CONCAT(husband_full_name, ' & ', wife_maiden_name) ASC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'book_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) DESC";
} else {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) ASC";
}

function marriage_sort_url(string $key): string
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
    return $basePath . '/admin/records/marriage.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function marriage_sort_indicator(string $key): string
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

$stmt = $pdo->prepare("SELECT COUNT(*) FROM marriage_records WHERE is_archived = 0 {$rf['where']}");
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM marriage_records WHERE is_archived = 0 {$rf['where']} {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$records = $stmt->fetchAll();
$isSecretary = in_array(current_user()['role'] ?? '', ['secretary', 'parish_admin'], true);
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <h1>Marriage Records</h1>
            <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/index.php') ?>">Back to All Records</a>
            <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>

                <?php rf_render_bar($basePath . '/admin/records/marriage.php', $rfConfig, $rf); ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= $sort === 'book' ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url('book')) ?>">Book<?= marriage_sort_indicator('book') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'page' ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url('page')) ?>">Page<?= marriage_sort_indicator('page') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'line' ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url('line')) ?>">Line<?= marriage_sort_indicator('line') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'name' ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url('name')) ?>">Full Name<?= marriage_sort_indicator('name') ?></a></th>
                    <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url($sort === 'date_desc' ? 'date_asc' : 'date_desc')) ?>">Date of Marriage<?= marriage_sort_indicator($sort === 'date_desc' || $sort === 'date_asc' ? $sort : 'date_desc') ?></a></th>
                    <th>Linked</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="7" class="empty-table-row">
                            <div class="empty-state"><h2>No marriage records found</h2><p><?= $rf['q'] !== '' ? 'No matches for "' . e($rf['q']) . '".' : 'New records will appear here after they are encoded.' ?></p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $rec): ?>
                        <tr>
                            <td><?= e($rec['book_no'] ?: '—') ?></td>
                            <td><?= e($rec['page_no'] ?: '—') ?></td>
                            <td><?= e($rec['line_no'] ?: '—') ?></td>
                            <td><strong><?= e(display_name_with_middle_initial((string) $rec['husband_full_name']) . ' & ' . display_name_with_middle_initial((string) $rec['wife_maiden_name'])) ?></strong></td>
                            <td><?= e(date('M j, Y', strtotime($rec['marriage_date']))) ?></td>
                            <?php
                            $husbandLinked = ((int) ($rec['husband_person_id'] ?? 0)) > 0;
                            $wifeLinked = ((int) ($rec['wife_person_id'] ?? 0)) > 0;
                            $linkStatus = ($husbandLinked && $wifeLinked) ? 'linked' : (($husbandLinked || $wifeLinked) ? 'partial' : 'unlinked');
                            $linkBadgeLabels = ['linked' => 'Linked', 'partial' => 'Partially linked', 'unlinked' => 'Unlinked'];
                            ?>
                            <td><span class="link-status-badge link-status-<?= e($linkStatus) ?>"><?= e($linkBadgeLabels[$linkStatus]) ?></span></td>
                            <td class="row-actions">
                                <a href="<?= $basePath ?>/admin/certificates/generate.php?record_type=marriage&record_id=<?= (int) $rec['id'] ?>" class="btn btn-success btn-small">Generate</a>
                                <a href="<?= e($basePath . '/admin/records/view.php?type=marriage&id=' . (int) $rec['id'] . '&return=' . urlencode($selfReturn)) ?>" class="btn btn-secondary btn-small">View record</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($basePath . '/admin/records/marriage.php', $rfTotal, $rf); ?>

        <dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || !empty($errors) || $fromApplicationId) ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $isEdit ? 'Edit Entry #' . (int) $editRecord['id'] . ' - ' . e($editRecord['husband_full_name']) . ' & ' . e($editRecord['wife_maiden_name']) : 'Encode New Entry' ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>
            <div class="dialog-body">
        <?php if ($isEdit): ?>
            <p class="field-hint"><a href="<?= $basePath ?>/admin/records/marriage.php">Cancel edit - back to adding a new entry.</a></p>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form autocomplete="off" method="post" action="" data-review data-review-title="Review Marriage Entry" data-review-confirm="Confirm and Save">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
            <input type="hidden" name="entry_no" value="<?= e($_POST['entry_no'] ?? $editRecord['entry_no'] ?? '') ?>">
            <?php if ($fromApplicationId): ?>
                <input type="hidden" name="from_application" value="<?= (int) $fromApplicationId ?>">
                <p class="field-hint">Pre-filled from marriage application #<?= (int) $fromApplicationId ?> — verify the wife's MAIDEN name, add the logbook numbers, and save. Saving marks that application completed.</p>
            <?php endif; ?>
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
                <legend>Husband</legend>
                <div class="inline-fields structured-name-fields">
                    <label>First Name <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="husband_first_name" value="<?= e($_POST['husband_first_name'] ?? $editRecord['husband_first_name'] ?? $husbandParts[0]) ?>" required></label>
                    <label>Middle Name <input autocomplete="off" type="text" name="husband_middle_name" value="<?= e($_POST['husband_middle_name'] ?? $editRecord['husband_middle_name'] ?? $husbandParts[1]) ?>"></label>
                    <label>Surname <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="husband_last_name" value="<?= e($_POST['husband_last_name'] ?? $editRecord['husband_last_name'] ?? $husbandParts[2]) ?>" required></label>
                    <label>Suffix <input autocomplete="off" type="text" name="husband_suffix" value="<?= e($_POST['husband_suffix'] ?? $editRecord['husband_suffix'] ?? $husbandParts[3]) ?>"></label>
                </div>
                <input type="hidden" name="husband_full_name" value="<?= e($_POST['husband_full_name'] ?? $editRecord['husband_full_name'] ?? '') ?>">
                <div class="inline-fields">
                    <label>Birthdate <input autocomplete="off" type="date" name="husband_birthdate" value="<?= e($_POST['husband_birthdate'] ?? $editRecord['husband_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace <input autocomplete="off" type="text" name="husband_birthplace" value="<?= e($_POST['husband_birthplace'] ?? $editRecord['husband_birthplace'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Wife</legend>
                <div class="inline-fields structured-name-fields">
                    <label>First Name <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="wife_first_name" value="<?= e($_POST['wife_first_name'] ?? $editRecord['wife_first_name'] ?? $wifeParts[0]) ?>" required></label>
                    <label>Middle Name <input autocomplete="off" type="text" name="wife_middle_name" value="<?= e($_POST['wife_middle_name'] ?? $editRecord['wife_middle_name'] ?? $wifeParts[1]) ?>"></label>
                    <label>Maiden Surname <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="wife_last_name" value="<?= e($_POST['wife_last_name'] ?? $editRecord['wife_last_name'] ?? $wifeParts[2]) ?>" required></label>
                    <label>Suffix <input autocomplete="off" type="text" name="wife_suffix" value="<?= e($_POST['wife_suffix'] ?? $editRecord['wife_suffix'] ?? $wifeParts[3]) ?>"></label>
                </div>
                <input type="hidden" name="wife_maiden_name" value="<?= e($_POST['wife_maiden_name'] ?? $editRecord['wife_maiden_name'] ?? '') ?>">
                <div class="inline-fields">
                    <label>Birthdate <input autocomplete="off" type="date" name="wife_birthdate" value="<?= e($_POST['wife_birthdate'] ?? $editRecord['wife_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace <input autocomplete="off" type="text" name="wife_birthplace" value="<?= e($_POST['wife_birthplace'] ?? $editRecord['wife_birthplace'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Marriage Details</legend>
                <label>Marriage Date <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="date" name="marriage_date" value="<?= e($_POST['marriage_date'] ?? $editRecord['marriage_date'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Husband Civil Status <input autocomplete="off" type="text" name="husband_civil_status" value="<?= e($_POST['husband_civil_status'] ?? $editRecord['husband_civil_status'] ?? '') ?>"></label>
                    <label>Husband Age <input autocomplete="off" type="number" min="0" max="120" name="husband_age" value="<?= e($_POST['husband_age'] ?? $editRecord['husband_age'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Husband Origin <input autocomplete="off" type="text" name="husband_origin" value="<?= e($_POST['husband_origin'] ?? $editRecord['husband_origin'] ?? '') ?>"></label>
                    <label>Husband Residence <input autocomplete="off" type="text" name="husband_residence" value="<?= e($_POST['husband_residence'] ?? $editRecord['husband_residence'] ?? '') ?>"></label>
                </div>
                <label>Husband Parents <input autocomplete="off" type="text" name="husband_parents_name" value="<?= e($_POST['husband_parents_name'] ?? $editRecord['husband_parents_name'] ?? '') ?>"></label>
                <div class="inline-fields">
                    <label>Wife Civil Status <input autocomplete="off" type="text" name="wife_civil_status" value="<?= e($_POST['wife_civil_status'] ?? $editRecord['wife_civil_status'] ?? '') ?>"></label>
                    <label>Wife Age <input autocomplete="off" type="number" min="0" max="120" name="wife_age" value="<?= e($_POST['wife_age'] ?? $editRecord['wife_age'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Wife Origin <input autocomplete="off" type="text" name="wife_origin" value="<?= e($_POST['wife_origin'] ?? $editRecord['wife_origin'] ?? '') ?>"></label>
                    <label>Wife Residence <input autocomplete="off" type="text" name="wife_residence" value="<?= e($_POST['wife_residence'] ?? $editRecord['wife_residence'] ?? '') ?>"></label>
                </div>
                <label>Wife Parents <input autocomplete="off" type="text" name="wife_parents_name" value="<?= e($_POST['wife_parents_name'] ?? $editRecord['wife_parents_name'] ?? '') ?>"></label>
                <label>Officiating Minister <input autocomplete="off" type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? $editRecord['officiating_minister'] ?? '') ?>"></label>
            </fieldset>

            <?php
            // Row source: values just submitted (validation re-render), then
            // loaded witnesses (edit mode), else a single blank row.
            // New rows are cloned client-side — see main.js, section 9.
            $witnessRows = $_POST['witness_name'] ?? array_column($editWitnesses, 'witness_name');
            if (!is_array($witnessRows)) { $witnessRows = []; }
            $witnessRows = array_values($witnessRows);
            $witnessRes = $_POST['witness_residence'] ?? array_column($editWitnesses, 'witness_residence');
            if (!is_array($witnessRes)) { $witnessRes = []; }
            $witnessRes = array_values($witnessRes);
            if (count($witnessRows) === 0) { $witnessRows = ['']; }
            ?>
            <fieldset>
                <legend>Witnesses</legend>
                <div data-repeat-group="witness" data-repeat-max="2">
                    <?php foreach ($witnessRows as $i => $rowName): ?>
                        <div class="godparent-row">
                            <div class="inline-fields">
                                <label>Witness Name <input autocomplete="off" type="text" name="witness_name[]" value="<?= e(is_string($rowName) ? $rowName : '') ?>"></label>
                                <label>Residence <input autocomplete="off" type="text" name="witness_residence[]" value="<?= e(isset($witnessRes[$i]) && is_string($witnessRes[$i]) ? $witnessRes[$i] : '') ?>"></label>
                            </div>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="witness">Add another witness</button>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
                <label>Remarks <textarea autocomplete="off" name="remarks"><?= e($_POST['remarks'] ?? $editRecord['remarks'] ?? '') ?></textarea></label>
            </fieldset>

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