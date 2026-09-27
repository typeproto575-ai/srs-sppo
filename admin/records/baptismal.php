<?php
/**
 * =====================================================================
 * SRS-SPPO BAPTISMAL RECORDS (admin register)
 * =====================================================================
 * Purpose: Digital mirror of the physical baptismal register. The
 *          secretary encodes entries from the physical logbook (never
 *          replacing it), lists/searches them, and this is the source
 *          the certificate generator validates requests against.
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
$selfReturn = '/admin/records/baptismal.php' . ((($_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $_SERVER['QUERY_STRING'] : '');

$pageTitle = 'Baptismal Records';
$errors  = [];
$success = null;

// --- Pre-fill from an approved sacrament application (optional) -----------
// Opened from a baptismal application detail page via ?from_application=N:
// the application data becomes the form defaults so the secretary does not
// re-type what the family already provided. GET-only seeding; a POST
// (submit / validation retry) always keeps what the secretary typed.
$fromApplicationId = 0;
if (!empty($_GET['from_application']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $s = $pdo->prepare(
        "SELECT sa.*, ba.* FROM sacrament_applications sa
         JOIN baptismal_applications ba ON ba.application_id = sa.id
         WHERE sa.id = ? AND sa.sacrament_type = 'baptismal'"
    );
    $s->execute([(int) $_GET['from_application']]);
    if ($app = $s->fetch()) {
        $fromApplicationId = (int) $app['id'];
        $_POST['birth_date']      = (string) ($app['child_birth_date'] ?? '');
        $_POST['child_birthplace'] = (string) ($app['child_birthplace'] ?? '');
        $_POST['father_name']     = (string) ($app['father_full_name'] ?? '');
        $_POST['mother_name']     = (string) ($app['mother_guardian_name'] ?? '');
        $_POST['remarks']         = 'Encoded from application ' . $app['tracking_number'];
        // Godparents from the application, split by type for the form arrays.
        $g = $pdo->prepare("SELECT godparent_name, godparent_residence, godparent_type
                            FROM baptismal_application_godparents
                            WHERE baptismal_application_id = ? ORDER BY godparent_type, id");
        $g->execute([$fromApplicationId]);
        $gf = $gm = [];
        foreach ($g->fetchAll() as $gp) {
            if ($gp['godparent_type'] === 'godmother') { $gm[] = $gp; }
            else { $gf[] = $gp; }
        }
        $fill = function (array $rows) {
            $n = $r = [];
            foreach ($rows as $row) { $n[] = $row['godparent_name']; $r[] = $row['godparent_residence']; }
            return [$n, $r];
        };
        if ($gf) { [$_POST['godfather_name'], $_POST['godfather_residence']] = $fill($gf); }
        if ($gm) { [$_POST['godmother_name'], $_POST['godmother_residence']] = $fill($gm); }
    }
}

// --- Handle new record submission (secretary encodes from the physical logbook) ---
// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form). Nothing is written when it fails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record']) && csrf_verify()) {
    require_role('secretary'); // encoding records is a secretary-only task (no priest login exists)

    $entryNo          = trim($_POST['entry_no'] ?? '');
    $baptismDate      = $_POST['baptism_date'] ?? '';
    $baptismPlace     = trim($_POST['baptism_place'] ?? '');
    $childBirthplace  = trim($_POST['child_birthplace'] ?? '');
    $childName        = trim($_POST['child_full_name'] ?? '');
    $childFirst = trim($_POST['child_first_name'] ?? ''); $childMiddle = trim($_POST['child_middle_name'] ?? ''); $childLast = trim($_POST['child_last_name'] ?? ''); $childSuffix = trim($_POST['child_suffix'] ?? '');
    if ($childFirst !== '' || $childMiddle !== '' || $childLast !== '') $childName = compose_structured_name($childFirst, $childMiddle, $childLast, $childSuffix);
    $childFirst = trim($_POST['child_first_name'] ?? '');
    $childMiddle = trim($_POST['child_middle_name'] ?? '');
    $childLast = trim($_POST['child_last_name'] ?? '');
    $childSuffix = trim($_POST['child_suffix'] ?? '');
    if ($childFirst !== '' || $childMiddle !== '' || $childLast !== '') $childName = compose_structured_name($childFirst, $childMiddle, $childLast, $childSuffix);
    $birthDate        = $_POST['birth_date'] ?: null;
    // Rule 7 note: legitimacy is NOT collected on new entries (project owner
    // decision) — the column stays NULL; legacy digitized rows keep theirs.
    $legitimacy       = null;
    $fatherName       = trim($_POST['father_name'] ?? '');
    $motherName       = trim($_POST['mother_name'] ?? '');
    $parentsOrigin    = trim($_POST['parents_origin'] ?? '');
    $parentsResidence = trim($_POST['parents_residence'] ?? '');
    $ministerName     = trim($_POST['minister_name'] ?? '');
    $remarks          = trim($_POST['remarks'] ?? '');
    $bookNo           = trim($_POST['book_no'] ?? '');
    $pageNo           = trim($_POST['page_no'] ?? '');
    $lineNo           = trim($_POST['line_no'] ?? '');

    // Sponsors (Godfathers and Godmothers) - separate arrays for each type
    $godfatherNames      = $_POST['godfather_name'] ?? [];
    if (!is_array($godfatherNames)) { $godfatherNames = []; }
    $godfatherResidences = $_POST['godfather_residence'] ?? [];
    if (!is_array($godfatherResidences)) { $godfatherResidences = []; }
    $godmotherNames      = $_POST['godmother_name'] ?? [];
    if (!is_array($godmotherNames)) { $godmotherNames = []; }
    $godmotherResidences = $_POST['godmother_residence'] ?? [];
    if (!is_array($godmotherResidences)) { $godmotherResidences = []; }
    
    // Keep old field names for backward compatibility with database
    $sponsorNames      = array_merge(is_array($godfatherNames) ? $godfatherNames : [], is_array($godmotherNames) ? $godmotherNames : []);
    $sponsorResidences = array_merge(is_array($godfatherResidences) ? $godfatherResidences : [], is_array($godmotherResidences) ? $godmotherResidences : []);

    if ($baptismDate === '') $errors[] = 'Baptism date is required.';
    if ($childName === '')   $errors[] = "Child's full name is required.";

    if (empty($errors)) {
        try {
            $personId = posted_person_id($pdo, 'person_id');
            $pdo->beginTransaction();
            $logbook = register_next_logbook_numbers($pdo, 'baptismal_records', true);
            $bookNo = $logbook['book_no'];
            $pageNo = $logbook['page_no'];
            $lineNo = $logbook['line_no'];

            // Make room for a mid-register insert when requested.
            $shifted = 0;
            if ($entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shifted = register_shift_entry_numbers($pdo, 'baptismal_records', $entryNo);
            }

            // Collect new parish form fields
            $unionType       = $_POST['union_type'] ?: null;
            $fatherBirthplace = trim($_POST['father_birthplace'] ?? '');
            $motherMaidenName = trim($_POST['mother_maiden_name'] ?? '');
            $motherBirthplace = trim($_POST['mother_birthplace'] ?? '');

            $stmt = $pdo->prepare(
                "INSERT INTO baptismal_records
                    (entry_no, baptism_date, baptism_place, child_birthplace, child_full_name, birth_date, legitimacy, union_type,
                     father_name, father_birthplace, mother_name, mother_maiden_name, mother_birthplace,
                     parents_origin, parents_residence, minister_name, remarks,
                     book_no, page_no, line_no, encoded_by, person_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $baptismDate, $baptismPlace, $childBirthplace, $childName, $birthDate, $legitimacy, $unionType,
                $fatherName, $fatherBirthplace, $motherName, $motherMaidenName, $motherBirthplace,
                $parentsOrigin, $parentsResidence, $ministerName, $remarks,
                $bookNo, $pageNo, $lineNo, current_user()['id'], $personId,
            ]);
            $recordId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE baptismal_records SET child_first_name = ?, child_middle_name = ?, child_last_name = ?, child_suffix = ? WHERE id = ?')
                ->execute([$childFirst, $childMiddle, $childLast, $childSuffix, $recordId]);

            // Insert godfathers (Ninong)
            $sponsorStmt = $pdo->prepare(
                "INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type) VALUES (?, ?, ?, 'godfather')"
            );
            foreach ($godfatherNames as $i => $name) {
                if (!is_string($name)) continue;
                $name = trim($name);
                if ($name === '') continue;
                $sponsorStmt->execute([$recordId, $name, trim(isset($godfatherResidences[$i]) && is_string($godfatherResidences[$i]) ? $godfatherResidences[$i] : '')]);
            }
            
            // Insert godmothers (Ninang)
            $sponsorStmt2 = $pdo->prepare(
                "INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type) VALUES (?, ?, ?, 'godmother')"
            );
            foreach ($godmotherNames as $i => $name) {
                if (!is_string($name)) continue;
                $name = trim($name);
                if ($name === '') continue;
                $sponsorStmt2->execute([$recordId, $name, trim(isset($godmotherResidences[$i]) && is_string($godmotherResidences[$i]) ? $godmotherResidences[$i] : '')]);
            }

            log_activity($pdo, current_user()['id'], 'baptismal_record_encoded', 'baptismal_records', $recordId,
                "Baptismal record encoded for {$childName}" . ($shifted > 0 ? " — {$shifted} later entries renumbered" : ''));

            $pdo->commit();

            // If this entry was encoded from an approved baptismal application,
            // close the loop: mark the application completed and audit the link.
            $appDone = '';
            if (!empty($_POST['from_application'])) {
                $u = $pdo->prepare(
                    "UPDATE sacrament_applications
                        SET status = 'completed', date_processed = NOW(), processed_by = ?
                      WHERE id = ? AND sacrament_type = 'baptismal' AND status IN ('approved','scheduled')"
                );
                $u->execute([current_user()['id'], (int) $_POST['from_application']]);
                if ($u->rowCount()) {
                    $appRow = $pdo->query("SELECT tracking_number, sacrament_type FROM sacrament_applications WHERE id = " . (int) $_POST['from_application'])->fetch();
                    log_activity($pdo, current_user()['id'], 'application_recorded_in_register', 'sacrament_applications', (int) $_POST['from_application'],
                        "Baptism encoded into register (record #{$recordId}); application completed");
                    notify_sacrament_application_status($pdo, 'completed', (string) $appRow['sacrament_type'], (string) $appRow['tracking_number'], "Baptism encoded into register (record #{$recordId}).");
                    $appDone = '&app_completed=1';
                }
            }

            redirect_to('/admin/records/baptismal.php?added=1' . ($shifted > 0 ? '&shifted=' . $shifted : '') . $appDone);
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

    $editId           = (int) ($_POST['edit_id'] ?? 0);
    $unionType        = $_POST['union_type'] ?: null;
    $fatherBirthplace = trim($_POST['father_birthplace'] ?? '');
    $motherMaidenName = trim($_POST['mother_maiden_name'] ?? '');
    $motherBirthplace = trim($_POST['mother_birthplace'] ?? '');
    $godfatherNames      = $_POST['godfather_name'] ?? [];
    if (!is_array($godfatherNames)) { $godfatherNames = []; }
    $godfatherResidences = $_POST['godfather_residence'] ?? [];
    if (!is_array($godfatherResidences)) { $godfatherResidences = []; }
    $godmotherNames      = $_POST['godmother_name'] ?? [];
    if (!is_array($godmotherNames)) { $godmotherNames = []; }
    $godmotherResidences = $_POST['godmother_residence'] ?? [];
    if (!is_array($godmotherResidences)) { $godmotherResidences = []; }
    $entryNo          = trim($_POST['entry_no'] ?? '');
    $baptismDate      = $_POST['baptism_date'] ?? '';
    $baptismPlace     = trim($_POST['baptism_place'] ?? '');
    $childBirthplace  = trim($_POST['child_birthplace'] ?? '');
    $childName        = trim($_POST['child_full_name'] ?? '');
    $childFirst       = trim($_POST['child_first_name'] ?? '');
    $childMiddle      = trim($_POST['child_middle_name'] ?? '');
    $childLast        = trim($_POST['child_last_name'] ?? '');
    $childSuffix      = trim($_POST['child_suffix'] ?? '');
    $birthDate        = $_POST['birth_date'] ?: null;
    $legitimacy       = $_POST['legitimacy'] ?: null;
    $fatherName       = trim($_POST['father_name'] ?? '');
    $motherName       = trim($_POST['mother_name'] ?? '');
    $parentsOrigin    = trim($_POST['parents_origin'] ?? '');
    $parentsResidence = trim($_POST['parents_residence'] ?? '');
    $ministerName     = trim($_POST['minister_name'] ?? '');
    $remarks          = trim($_POST['remarks'] ?? '');
    $bookNo           = trim($_POST['book_no'] ?? '');
    $pageNo           = trim($_POST['page_no'] ?? '');
    $lineNo           = trim($_POST['line_no'] ?? '');
    $sponsorNames      = $_POST['sponsor_name'] ?? [];
    $sponsorResidences = $_POST['sponsor_residence'] ?? [];

    if ($baptismDate === '') $errors[] = 'Baptism date is required.';
    if ($childName === '')   $errors[] = "Child's full name is required.";
    if ($editId <= 0)        $errors[] = 'Invalid record selected for update.';

    if (empty($errors)) {
        try {
            $personId = posted_person_id($pdo, 'person_id');
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "UPDATE baptismal_records
                    SET entry_no = ?, baptism_date = ?, baptism_place = ?, child_birthplace = ?, child_full_name = ?, birth_date = ?,
                        legitimacy = ?, union_type = ?, father_name = ?, father_birthplace = ?,
                        mother_name = ?, mother_maiden_name = ?, mother_birthplace = ?, parents_origin = ?,
                        parents_residence = ?, minister_name = ?, remarks = ?, book_no = ?,
                        page_no = ?, line_no = ?, person_id = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $entryNo, $baptismDate, $baptismPlace, $childBirthplace, $childName, $birthDate, $legitimacy, $unionType,
                $fatherName, $fatherBirthplace, $motherName, $motherMaidenName, $motherBirthplace, $parentsOrigin,
                $parentsResidence, $ministerName, $remarks,
                $bookNo, $pageNo, $lineNo, $personId, $editId,
            ]);
            $pdo->prepare('UPDATE baptismal_records SET child_first_name = ?, child_middle_name = ?, child_last_name = ?, child_suffix = ? WHERE id = ?')->execute([$childFirst, $childMiddle, $childLast, $childSuffix, $editId]);

            // Replace the sponsor set wholesale with what was submitted.
            $pdo->prepare("DELETE FROM baptismal_sponsors WHERE baptismal_record_id = ?")->execute([$editId]);
            $gfStmt = $pdo->prepare(
                "INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type) VALUES (?, ?, ?, 'godfather')"
            );
            foreach ($godfatherNames as $i => $name) {
                if (!is_string($name)) continue;
                $name = trim($name);
                if ($name === '') continue;
                $gfStmt->execute([$editId, $name, trim(isset($godfatherResidences[$i]) && is_string($godfatherResidences[$i]) ? $godfatherResidences[$i] : '')]);
            }
            $gmStmt = $pdo->prepare(
                "INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type) VALUES (?, ?, ?, 'godmother')"
            );
            foreach ($godmotherNames as $i => $name) {
                if (!is_string($name)) continue;
                $name = trim($name);
                if ($name === '') continue;
                $gmStmt->execute([$editId, $name, trim(isset($godmotherResidences[$i]) && is_string($godmotherResidences[$i]) ? $godmotherResidences[$i] : '')]);
            }

            log_activity($pdo, current_user()['id'], 'baptismal_record_updated', 'baptismal_records', $editId,
                "Baptismal record updated for {$childName}");

            $pdo->commit();
            redirect_to('/admin/records/baptismal.php?updated=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while updating this record. Please try again.';
        }
    }
}

// Permanent deletion is intentionally unavailable; use Admin > Archive or Restore Record.

// --- Edit mode: load the requested record into the encode form -----------
$editRecord   = null;
$editSponsors = [];
$editGodfathers = [];
$editGodmothers = [];
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM baptismal_records WHERE id = ?");
    $stmt->execute([$editId]);
    $editRecord = $stmt->fetch();
    if (!$editRecord) {
        redirect_to('/admin/records/baptismal.php');
        exit;
    }
    $stmt = $pdo->prepare("SELECT sponsor_name, sponsor_residence, sponsor_type FROM baptismal_sponsors WHERE baptismal_record_id = ? ORDER BY id");
    $stmt->execute([(int) $editRecord['id']]);
    $editSponsors = $stmt->fetchAll();
    foreach ($editSponsors as $sp) {
        if (($sp['sponsor_type'] ?? 'godfather') === 'godmother') { $editGodmothers[] = $sp; }
        else { $editGodfathers[] = $sp; }
    }
}
$isEdit = $editRecord !== null;

// Suggested next logbook numbers for the encode dialog (create mode only).
$suggest = $isEdit
    ? ['entry_no' => '', 'book_no' => '', 'page_no' => '', 'line_no' => '']
    : register_next_logbook_numbers($pdo, 'baptismal_records');
$childParts = $isEdit ? structured_name_parts((string) $editRecord['child_full_name']) : ['', '', '', ''];

if (isset($_GET['added'])) $success = 'Baptismal record saved.' . (isset($_GET['shifted']) ? ' Following entries were renumbered to make room.' : '') . (isset($_GET['app_completed']) ? ' The linked baptismal application was marked completed.' : '');
if (isset($_GET['updated'])) $success = 'Baptismal record updated.';
if (isset($_GET['deleted'])) $success = 'Baptismal record deleted.';

// --- Filter / paginate (shared component) --------------------------------
$rfConfig = [
    'date_column'    => 'baptism_date',
    'search_columns' => ['child_full_name', 'father_name', 'mother_name', 'minister_name'],
    'placeholder'    => 'Search child, father, mother, minister...',
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
    $orderClause = "ORDER BY baptism_date DESC, child_full_name ASC";
} elseif ($sort === 'date_asc') {
    $orderClause = "ORDER BY baptism_date ASC, child_full_name ASC";
} elseif ($sort === 'page_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'page') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'line_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'line') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'name_desc') {
    $orderClause = "ORDER BY child_full_name DESC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'name') {
    $orderClause = "ORDER BY child_full_name ASC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'book_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) DESC";
} else {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) ASC";
}

function baptismal_sort_url(string $key): string
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
    return $basePath . '/admin/records/baptismal.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function baptismal_sort_indicator(string $key): string
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

$stmt = $pdo->prepare("SELECT COUNT(*) FROM baptismal_records WHERE is_archived = 0 {$rf['where']}");
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM baptismal_records WHERE is_archived = 0 {$rf['where']} {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$records = $stmt->fetchAll();

$isSecretary = in_array(current_user()['role'] ?? '', ['secretary', 'parish_admin'], true);
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <h1>Baptismal Records</h1>
            <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/index.php') ?>">Back to All Records</a>
            <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
        </div>
        <p class="field-hint">This is a digital index of the physical baptismal logbook, used for fast retrieval when matching certificate requests. The physical logbook remains the canonical record.</p>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>

                <?php rf_render_bar($basePath . '/admin/records/baptismal.php', $rfConfig, $rf); ?>
        <div class="table-scroll">
        <table id="record-table" class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= $sort === 'book' ? ' is-active' : '' ?>" href="<?= e(baptismal_sort_url('book')) ?>">Book<?= baptismal_sort_indicator('book') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'page' ? ' is-active' : '' ?>" href="<?= e(baptismal_sort_url('page')) ?>">Page<?= baptismal_sort_indicator('page') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'line' ? ' is-active' : '' ?>" href="<?= e(baptismal_sort_url('line')) ?>">Line<?= baptismal_sort_indicator('line') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'name' ? ' is-active' : '' ?>" href="<?= e(baptismal_sort_url('name')) ?>">Full Name<?= baptismal_sort_indicator('name') ?></a></th>
                    <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(baptismal_sort_url($sort === 'date_desc' ? 'date_asc' : 'date_desc')) ?>">Date of Baptism<?= baptismal_sort_indicator($sort === 'date_desc' || $sort === 'date_asc' ? $sort : 'date_desc') ?></a></th>
                    <th>Linked</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="7" class="empty-table-row">
                            <div class="empty-state"><h2>No baptismal records found</h2><p><?= $rf['q'] !== '' ? 'No matches for "' . e($rf['q']) . '".' : 'New records will appear here after they are encoded.' ?></p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $rec): ?>
                        <tr>
                            <td><?= e($rec['book_no'] ?: '—') ?></td>
                            <td><?= e($rec['page_no'] ?: '—') ?></td>
                            <td><?= e($rec['line_no'] ?: '—') ?></td>
                            <td><strong><?= e(display_name_with_middle_initial((string) $rec['child_full_name'])) ?></strong></td>
                            <td><?= e(date('M j, Y', strtotime($rec['baptism_date']))) ?></td>
                            <?php $linkStatus = ((int) ($rec['person_id'] ?? 0)) > 0 ? 'linked' : 'unlinked'; ?>
                            <td><span class="link-status-badge link-status-<?= e($linkStatus) ?>"><?= $linkStatus === 'linked' ? 'Linked' : 'Unlinked' ?></span></td>
                            <td class="row-actions">
                                <a href="<?= $basePath ?>/admin/certificates/generate.php?record_type=baptismal&record_id=<?= (int) $rec['id'] ?>" class="btn btn-success btn-small">Generate</a>
                                <a href="<?= e($basePath . '/admin/records/view.php?type=baptismal&id=' . (int) $rec['id'] . '&return=' . urlencode($selfReturn)) ?>" class="btn btn-secondary btn-small">View record</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($basePath . '/admin/records/baptismal.php', $rfTotal, $rf); ?>

        <dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || !empty($errors) || $fromApplicationId) ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $isEdit ? 'Edit Entry #' . (int) $editRecord['id'] . ' - ' . e($editRecord['child_full_name']) : 'Encode New Entry' ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>
            <div class="dialog-body">
        <?php if ($isEdit): ?>
            <p class="field-hint"><a href="<?= $basePath ?>/admin/records/baptismal.php">Cancel edit - back to adding a new entry.</a></p>
        <?php else: ?>
            <p class="field-hint">Use this to digitize an entry from the physical logbook. This does not replace the physical record.</p>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form autocomplete="off" method="post" action="" data-review data-review-title="Review Baptismal Entry" data-review-confirm="Confirm and Save">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
            <input type="hidden" name="entry_no" value="<?= e($_POST['entry_no'] ?? $editRecord['entry_no'] ?? '') ?>">
            <?php if ($fromApplicationId): ?>
                <input type="hidden" name="from_application" value="<?= (int) $fromApplicationId ?>">
                <p class="field-hint">Pre-filled from baptismal application #<?= (int) $fromApplicationId ?> — review, add the logbook numbers, and save. Saving marks that application completed.</p>
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
                <legend>Child</legend>
                <div class="inline-fields structured-name-fields">
                    <label>First Name <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="child_first_name" value="<?= e($_POST['child_first_name'] ?? $editRecord['child_first_name'] ?? $childParts[0]) ?>" required></label>
                    <label>Middle Name <input autocomplete="off" type="text" name="child_middle_name" value="<?= e($_POST['child_middle_name'] ?? $editRecord['child_middle_name'] ?? $childParts[1]) ?>"></label>
                    <label>Surname <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="child_last_name" value="<?= e($_POST['child_last_name'] ?? $editRecord['child_last_name'] ?? $childParts[2]) ?>" required></label>
                    <label>Suffix <input autocomplete="off" type="text" name="child_suffix" value="<?= e($_POST['child_suffix'] ?? $editRecord['child_suffix'] ?? $childParts[3]) ?>"></label>
                </div>
                <input type="hidden" name="child_full_name" value="<?= e($_POST['child_full_name'] ?? $editRecord['child_full_name'] ?? '') ?>">
                <div class="person-finder" data-person-finder data-endpoint="<?= e($basePath . '/admin/api/person-search.php') ?>">
                    <label>Find possible people
                        <input autocomplete="off" type="search" data-person-search placeholder="Enter the name first, then search">
                    </label>
                    <button type="button" class="btn btn-secondary btn-small" data-person-find>Find possible people</button>
                    <input type="hidden" name="person_id" data-person-id value="<?= e($_POST['person_id'] ?? $editRecord['person_id'] ?? '') ?>">
                    <div class="person-finder-selection" data-person-selection aria-live="polite"></div>
                    <div class="person-finder-results" data-person-results></div>
                    <p class="field-hint">Review the possible matches and select a person only when you are sure. Leave blank if this record is not linked yet.</p>
                </div>
                <div class="inline-fields">
                    <label>Birth Date <input autocomplete="off" type="date" name="birth_date" value="<?= e($_POST['birth_date'] ?? $editRecord['birth_date'] ?? '') ?>"></label>
                    <label>Birthplace <input autocomplete="off" type="text" name="child_birthplace" value="<?= e($_POST['child_birthplace'] ?? $editRecord['child_birthplace'] ?? '') ?>"></label>
                    <label>Baptism Date <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="date" name="baptism_date" value="<?= e($_POST['baptism_date'] ?? $editRecord['baptism_date'] ?? '') ?>" required></label>
                    <label>Place of Baptism <input autocomplete="off" type="text" name="baptism_place" value="<?= e($_POST['baptism_place'] ?? $editRecord['baptism_place'] ?? '') ?>"></label>
                </div>
                
            </fieldset>

            <fieldset>
                <legend>Parents</legend>
                <div class="inline-fields">
                    <label>Father's Name <input autocomplete="off" type="text" name="father_name" value="<?= e($_POST['father_name'] ?? $editRecord['father_name'] ?? '') ?>"></label>
                    <label>Father's Birthplace <input autocomplete="off" type="text" name="father_birthplace" value="<?= e($_POST['father_birthplace'] ?? $editRecord['father_birthplace'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Mother's Maiden Name <input autocomplete="off" type="text" name="mother_name" value="<?= e($_POST['mother_name'] ?? $editRecord['mother_name'] ?? '') ?>"></label>
                    <label>Mother's Birthplace <input autocomplete="off" type="text" name="mother_birthplace" value="<?= e($_POST['mother_birthplace'] ?? $editRecord['mother_birthplace'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Parents' Origin (Hometown) <input autocomplete="off" type="text" name="parents_origin" value="<?= e($_POST['parents_origin'] ?? $editRecord['parents_origin'] ?? '') ?>"></label>
                    <label>Parents' Residence <input autocomplete="off" type="text" name="parents_residence" value="<?= e($_POST['parents_residence'] ?? $editRecord['parents_residence'] ?? '') ?>"></label>
                </div>
                <!-- Union type: Legitimate (married in church), Civil (married civilly), Natural (not married) -->
                <label>Union Type
                    <select name="union_type">
                        <option value="" <?= ($_POST['union_type'] ?? $editRecord['union_type'] ?? '') === '' ? 'selected' : '' ?>>-- Not specified --</option>
                        <option value="legitimate" <?= ($_POST['union_type'] ?? $editRecord['union_type'] ?? '') === 'legitimate' ? 'selected' : '' ?>>Legitimate (Church Marriage)</option>
                        <option value="civil" <?= ($_POST['union_type'] ?? $editRecord['union_type'] ?? '') === 'civil' ? 'selected' : '' ?>>Civil (Civil Marriage)</option>
                        <option value="natural" <?= ($_POST['union_type'] ?? $editRecord['union_type'] ?? '') === 'natural' ? 'selected' : '' ?>>Natural (Not Married)</option>
                    </select>
                </label>
            </fieldset>

            <!-- Godfathers (Ninong) - up to 8 -->
            <?php
            // Row source: values just submitted (validation re-render), then
            // loaded edit sponsors (edit mode), else a single blank row.
            // New rows are cloned client-side — see main.js, section 9.
            $gfRows = $_POST['godfather_name'] ?? array_column($editGodfathers ?? [], 'sponsor_name');
            if (!is_array($gfRows)) { $gfRows = []; }
            $gfRows = array_values($gfRows);
            $gfRes = $_POST['godfather_residence'] ?? array_column($editGodfathers ?? [], 'sponsor_residence');
            if (!is_array($gfRes)) { $gfRes = []; }
            $gfRes = array_values($gfRes);
            if (count($gfRows) === 0) { $gfRows = ['']; }
            ?>
            <fieldset>
                <legend>Godfathers (Ninong) - Up to 8</legend>
                <div data-repeat-group="godfather" data-repeat-max="8">
                    <?php foreach ($gfRows as $i => $rowName): ?>
                        <div class="godparent-row">
                            <div class="inline-fields">
                                <label>Godfather Name <input autocomplete="off" type="text" name="godfather_name[]" value="<?= e(is_string($rowName) ? $rowName : '') ?>"></label>
                                <label>Residence <input autocomplete="off" type="text" name="godfather_residence[]" value="<?= e(isset($gfRes[$i]) && is_string($gfRes[$i]) ? $gfRes[$i] : '') ?>"></label>
                            </div>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godfather">Add another godfather</button>
            </fieldset>

            <!-- Godmothers (Ninang) - up to 8 -->
            <?php
            $gmRows = $_POST['godmother_name'] ?? array_column($editGodmothers ?? [], 'sponsor_name');
            if (!is_array($gmRows)) { $gmRows = []; }
            $gmRows = array_values($gmRows);
            $gmRes = $_POST['godmother_residence'] ?? array_column($editGodmothers ?? [], 'sponsor_residence');
            if (!is_array($gmRes)) { $gmRes = []; }
            $gmRes = array_values($gmRes);
            if (count($gmRows) === 0) { $gmRows = ['']; }
            ?>
            <fieldset>
                <legend>Godmothers (Ninang) - Up to 8</legend>
                <div data-repeat-group="godmother" data-repeat-max="8">
                    <?php foreach ($gmRows as $i => $rowName): ?>
                        <div class="godparent-row">
                            <div class="inline-fields">
                                <label>Godmother Name <input autocomplete="off" type="text" name="godmother_name[]" value="<?= e(is_string($rowName) ? $rowName : '') ?>"></label>
                                <label>Residence <input autocomplete="off" type="text" name="godmother_residence[]" value="<?= e(isset($gmRes[$i]) && is_string($gmRes[$i]) ? $gmRes[$i] : '') ?>"></label>
                            </div>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godmother">Add another godmother</button>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
                <label>Officiating Minister <input autocomplete="off" type="text" name="minister_name" value="<?= e($_POST['minister_name'] ?? $editRecord['minister_name'] ?? '') ?>"></label>
                <label>Remarks / Observanda <textarea autocomplete="off" name="remarks"><?= e($_POST['remarks'] ?? $editRecord['remarks'] ?? '') ?></textarea></label>
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
