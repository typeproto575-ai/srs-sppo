<?php
/**
 * =====================================================================
 * SRS-SPPO FIRST COMMUNION RECORDS (admin register)
 * =====================================================================
 * Purpose: Digital mirror of the physical First Communion register:
 *          encode entries from the logbook, list/search, and serve as
 *          the source of truth for First Communion certificate requests.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/person-linking.php';
require_once __DIR__ . '/../../includes/baptismal-finder.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

// This page's own app-relative URL (path + current query string) so the record
// view can send the user back here with the same search, filter and page.
$selfReturn = '/admin/records/first-communion.php' . ((($_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $_SERVER['QUERY_STRING'] : '');

$pageTitle = 'First Communion Records';
$errors  = [];
$success = null;

// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form). Nothing is written when it fails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record']) && csrf_verify()) {
    require_role('secretary');

    $entryNo         = trim($_POST['entry_no'] ?? '');
    $communionDate   = $_POST['communion_date'] ?? '';
    $communicantName   = trim($_POST['communicant_full_name'] ?? '');
    $communicantFirst = trim($_POST['communicant_first_name'] ?? ''); $communicantMiddle = trim($_POST['communicant_middle_name'] ?? ''); $communicantLast = trim($_POST['communicant_last_name'] ?? ''); $communicantSuffix = trim($_POST['communicant_suffix'] ?? '');
    if ($communicantFirst !== '' || $communicantMiddle !== '' || $communicantLast !== '') $communicantName = compose_structured_name($communicantFirst, $communicantMiddle, $communicantLast, $communicantSuffix);
    $communicantFirst = trim($_POST['communicant_first_name'] ?? '');
    $communicantMiddle = trim($_POST['communicant_middle_name'] ?? '');
    $communicantLast = trim($_POST['communicant_last_name'] ?? '');
    $communicantSuffix = trim($_POST['communicant_suffix'] ?? '');
    if ($communicantFirst !== '' || $communicantMiddle !== '' || $communicantLast !== '') $communicantName = compose_structured_name($communicantFirst, $communicantMiddle, $communicantLast, $communicantSuffix);
    $domicile          = trim($_POST['domicile'] ?? '');
    $fatherName        = compose_person_name($_POST, 'father') ?: trim($_POST['father_name'] ?? '');
    $motherMaidenName  = compose_person_name($_POST, 'mother') ?: trim($_POST['mother_maiden_name'] ?? '');
    $ministerName      = compose_person_name($_POST, 'minister') ?: trim($_POST['minister_name'] ?? '');
    $baptismalRecordId = null;
    $remarks         = trim($_POST['remarks'] ?? '');
    $bookNo          = trim($_POST['book_no'] ?? '');
    $pageNo          = trim($_POST['page_no'] ?? '');
    $lineNo          = trim($_POST['line_no'] ?? '');

    if ($communionDate === '')   $errors[] = 'Date of First Communion is required.';
    if ($communicantName === '') $errors[] = "Communicant's full name is required.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $baptismalRecordId = posted_baptismal_record_id($pdo);
            $personId = posted_person_id($pdo, 'person_id');
            $logbook = register_next_logbook_numbers($pdo, 'first_communion_records', true);
            $bookNo = $logbook['book_no'];
            $pageNo = $logbook['page_no'];
            $lineNo = $logbook['line_no'];

            // Make room for a mid-register insert when requested.
            $shifted = 0;
            if ($entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shifted = register_shift_entry_numbers($pdo, 'first_communion_records', $entryNo);
            }

            $stmt = $pdo->prepare(
                    "INSERT INTO first_communion_records
                        (entry_no, communion_date, communicant_full_name, domicile, father_name, mother_maiden_name,
                         minister_name, baptismal_record_id, remarks, book_no, page_no, line_no, encoded_by, person_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                    $entryNo, $communionDate, $communicantName, $domicile, $fatherName, $motherMaidenName,
                    $ministerName, $baptismalRecordId, $remarks, $bookNo, $pageNo, $lineNo,
                    current_user()['id'], $personId,
            ]);
            $recordId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE first_communion_records SET communicant_first_name = ?, communicant_middle_name = ?, communicant_last_name = ?, communicant_suffix = ? WHERE id = ?')
                ->execute([$communicantFirst, $communicantMiddle, $communicantLast, $communicantSuffix, $recordId]);

            log_activity($pdo, current_user()['id'], 'first_communion_record_encoded', 'first_communion_records', $recordId,
                "First Communion record encoded for {$communicantName}" . ($shifted > 0 ? " — {$shifted} later entries renumbered" : ''));

            $pdo->commit();
            redirect_to('/admin/records/first-communion.php?added=1' . ($shifted > 0 ? '&shifted=' . $shifted : ''));
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

    $editId          = (int) ($_POST['edit_id'] ?? 0);
    $entryNo         = trim($_POST['entry_no'] ?? '');
    $communionDate     = $_POST['communion_date'] ?? '';
    $domicile          = trim($_POST['domicile'] ?? '');
    $communicantName   = trim($_POST['communicant_full_name'] ?? '');
    $fatherName        = compose_person_name($_POST, 'father') ?: trim($_POST['father_name'] ?? '');
    $motherMaidenName  = compose_person_name($_POST, 'mother') ?: trim($_POST['mother_maiden_name'] ?? '');
    $ministerName      = compose_person_name($_POST, 'minister') ?: trim($_POST['minister_name'] ?? '');
    $baptismalRecordId = null;
    $remarks         = trim($_POST['remarks'] ?? '');
    $bookNo          = trim($_POST['book_no'] ?? '');
    $pageNo          = trim($_POST['page_no'] ?? '');
    $lineNo          = trim($_POST['line_no'] ?? '');

    if ($communionDate === '')   $errors[] = 'Date of First Communion is required.';
    if ($communicantName === '') $errors[] = "Communicant's full name is required.";
    if ($editId <= 0)            $errors[] = 'Invalid record selected for update.';

    if (empty($errors)) {
        try {
            $personId = posted_person_id($pdo, 'person_id');
            $baptismalRecordId = posted_baptismal_record_id($pdo);
            $stmt = $pdo->prepare(
                    "UPDATE first_communion_records
                        SET entry_no = ?, communion_date = ?, communicant_full_name = ?, domicile = ?, father_name = ?,
                            mother_maiden_name = ?, minister_name = ?, baptismal_record_id = ?, remarks = ?,
                            book_no = ?, page_no = ?, line_no = ?, person_id = ?
                      WHERE id = ?"
            );
            $stmt->execute([
                    $entryNo, $communionDate, $communicantName, $domicile, $fatherName, $motherMaidenName,
                    $ministerName, $baptismalRecordId, $remarks, $bookNo, $pageNo, $lineNo, $personId,
                    $editId,
            ]);
            $pdo->prepare('UPDATE first_communion_records SET communicant_first_name = ?, communicant_middle_name = ?, communicant_last_name = ?, communicant_suffix = ? WHERE id = ?')->execute([$communicantFirst, $communicantMiddle, $communicantLast, $communicantSuffix, $editId]);

            log_activity($pdo, current_user()['id'], 'first_communion_record_updated', 'first_communion_records', $editId,
                "First Communion record updated for {$communicantName}");

            redirect_to('/admin/records/first-communion.php?updated=1');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Something went wrong while updating this record. Please try again.';
        }
    }
}

// Permanent deletion is intentionally unavailable; use Admin > Archive or Restore Record.

// --- Edit mode: load the requested record into the encode form -----------
$editRecord = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM first_communion_records WHERE id = ?");
    $stmt->execute([$editId]);
    $editRecord = $stmt->fetch();
    if (!$editRecord) {
        redirect_to('/admin/records/first-communion.php');
        exit;
    }
}
$isEdit = $editRecord !== null;

// Suggested next logbook numbers for the encode dialog (create mode only).
$suggest = $isEdit
    ? ['entry_no' => '', 'book_no' => '', 'page_no' => '', 'line_no' => '']
    : register_next_logbook_numbers($pdo, 'first_communion_records');
$communicantParts = $isEdit ? structured_name_parts((string) $editRecord['communicant_full_name']) : ['', '', '', ''];
$recordNameFormValues = $_POST;
foreach ([
    'father' => $isEdit ? structured_name_parts((string) ($editRecord['father_name'] ?? '')) : ['', '', '', ''],
    'mother' => $isEdit ? structured_name_parts((string) ($editRecord['mother_maiden_name'] ?? '')) : ['', '', '', ''],
    'minister' => $isEdit ? structured_name_parts((string) ($editRecord['minister_name'] ?? '')) : ['', '', '', ''],
] as $prefix => $parts) {
    foreach (['first', 'middle', 'last', 'suffix'] as $index => $part) {
        $recordNameFormValues[$prefix . '_' . $part] ??= $parts[$index];
    }
}

if (isset($_GET['added'])) $success = 'First Communion record saved.' . (isset($_GET['shifted']) ? ' Following entries were renumbered to make room.' : '');
if (isset($_GET['updated'])) $success = 'First Communion record updated.';
if (isset($_GET['deleted'])) $success = 'First Communion record deleted.';

// --- Filter / paginate (shared component) --------------------------------
$rfConfig = [
    'date_column'    => 'fc.communion_date',
    'search_columns' => ['fc.communicant_full_name', 'fc.father_name', 'fc.mother_maiden_name'],
    'placeholder'    => 'Search communicant, parents...',
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
    $orderClause = "ORDER BY fc.communion_date DESC, fc.communicant_full_name ASC";
} elseif ($sort === 'date_asc') {
    $orderClause = "ORDER BY fc.communion_date ASC, fc.communicant_full_name ASC";
} elseif ($sort === 'page_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(fc.page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(fc.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(fc.line_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'page') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(fc.page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(fc.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(fc.line_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'line_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(fc.line_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(fc.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(fc.page_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'line') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(fc.line_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(fc.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(fc.page_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'name_desc') {
    $orderClause = "ORDER BY fc.communicant_full_name DESC, CAST(COALESCE(NULLIF(fc.book_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'name') {
    $orderClause = "ORDER BY fc.communicant_full_name ASC, CAST(COALESCE(NULLIF(fc.book_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'book_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(fc.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(fc.page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(fc.line_no, ''), '0') AS UNSIGNED) DESC";
} else {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(fc.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(fc.page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(fc.line_no, ''), '0') AS UNSIGNED) ASC";
}

function first_communion_sort_url(string $key): string
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
    return $basePath . '/admin/records/first-communion.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function first_communion_sort_indicator(string $key): string
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

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM first_communion_records fc
     LEFT JOIN baptismal_records br ON br.id = fc.baptismal_record_id
     WHERE fc.is_archived = 0 {$rf['where']}"
);
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT fc.*, br.child_full_name AS baptismal_name FROM first_communion_records fc
     LEFT JOIN baptismal_records br ON br.id = fc.baptismal_record_id
     WHERE fc.is_archived = 0 {$rf['where']} {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$records = $stmt->fetchAll();
$isSecretary = (current_user()['role'] ?? '') === 'secretary';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <h1>First Communion Records</h1>
            <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/index.php') ?>">Back to All Records</a>
            <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
        </div>
        <p class="field-hint">Tracked as a one-time received/not-received event, not per-Mass attendance.</p>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>

                <?php rf_render_bar($basePath . '/admin/records/first-communion.php', $rfConfig, $rf); ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= $sort === 'book' ? ' is-active' : '' ?>" href="<?= e(first_communion_sort_url('book')) ?>">Book<?= first_communion_sort_indicator('book') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'page' ? ' is-active' : '' ?>" href="<?= e(first_communion_sort_url('page')) ?>">Page<?= first_communion_sort_indicator('page') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'line' ? ' is-active' : '' ?>" href="<?= e(first_communion_sort_url('line')) ?>">Line<?= first_communion_sort_indicator('line') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'name' ? ' is-active' : '' ?>" href="<?= e(first_communion_sort_url('name')) ?>">Full Name<?= first_communion_sort_indicator('name') ?></a></th>
                    <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(first_communion_sort_url($sort === 'date_desc' ? 'date_asc' : 'date_desc')) ?>">Date of First Communion<?= first_communion_sort_indicator($sort === 'date_desc' || $sort === 'date_asc' ? $sort : 'date_desc') ?></a></th>
                    <th>Linked</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="7" class="empty-table-row">
                            <div class="empty-state"><h2>No First Communion records found</h2><p><?= $rf['q'] !== '' ? 'No matches for "' . e($rf['q']) . '".' : 'New records will appear here after they are encoded.' ?></p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $rec): ?>
                        <tr>
                            <td><?= e($rec['book_no'] ?: '—') ?></td>
                            <td><?= e($rec['page_no'] ?: '—') ?></td>
                            <td><?= e($rec['line_no'] ?: '—') ?></td>
                            <td><strong><?= e(display_name_with_middle_initial(
                                (string) $rec['communicant_full_name'],
                                isset($rec['communicant_first_name']) ? (string) $rec['communicant_first_name'] : null,
                                isset($rec['communicant_middle_name']) ? (string) $rec['communicant_middle_name'] : null,
                                isset($rec['communicant_last_name']) ? (string) $rec['communicant_last_name'] : null,
                                isset($rec['communicant_suffix']) ? (string) $rec['communicant_suffix'] : null
                            )) ?></strong></td>
                            <td><?= e(date('M j, Y', strtotime($rec['communion_date']))) ?></td>
                            <?php $linkStatus = ((int) ($rec['person_id'] ?? 0)) > 0 ? 'linked' : 'unlinked'; ?>
                            <td><span class="link-status-badge link-status-<?= e($linkStatus) ?>"><?= $linkStatus === 'linked' ? 'Linked' : 'Unlinked' ?></span></td>
                            <td class="row-actions">
                                <a href="<?= $basePath ?>/admin/certificates/generate.php?record_type=first_communion&record_id=<?= (int) $rec['id'] ?>" class="btn btn-success btn-small">Generate</a>
                                <a href="<?= e($basePath . '/admin/records/view.php?type=first_communion&id=' . (int) $rec['id'] . '&return=' . urlencode($selfReturn)) ?>" class="btn btn-secondary btn-small">View record</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($basePath . '/admin/records/first-communion.php', $rfTotal, $rf); ?>

        <dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || !empty($errors)) ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $isEdit ? 'Edit Entry #' . (int) $editRecord['id'] . ' - ' . e($editRecord['communicant_full_name']) : 'Encode New Entry' ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>
            <div class="dialog-body">
        <?php if ($isEdit): ?>
            <p class="field-hint"><a href="<?= $basePath ?>/admin/records/first-communion.php">Cancel edit - back to adding a new entry.</a></p>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form autocomplete="off" method="post" action="" data-review data-review-title="Review First Communion Entry" data-review-confirm="Confirm and Save">
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
                <legend>Communicant</legend>
                <div class="inline-fields structured-name-fields">
                    <label>First Name <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="communicant_first_name" value="<?= e($_POST['communicant_first_name'] ?? $editRecord['communicant_first_name'] ?? $communicantParts[0]) ?>" required></label>
                    <label>Middle Name <span class="field-hint">(optional)</span> <input autocomplete="off" type="text" name="communicant_middle_name" value="<?= e($_POST['communicant_middle_name'] ?? $editRecord['communicant_middle_name'] ?? $communicantParts[1]) ?>"></label>
                    <label>Surname <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="communicant_last_name" value="<?= e($_POST['communicant_last_name'] ?? $editRecord['communicant_last_name'] ?? $communicantParts[2]) ?>" required></label>
                    <label>Suffix <input autocomplete="off" type="text" name="communicant_suffix" value="<?= e($_POST['communicant_suffix'] ?? $editRecord['communicant_suffix'] ?? $communicantParts[3]) ?>"></label>
                </div>
                <input type="hidden" name="communicant_full_name" value="<?= e($_POST['communicant_full_name'] ?? $editRecord['communicant_full_name'] ?? '') ?>">
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
                <label>Date of First Communion <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="date" name="communion_date" value="<?= e($_POST['communion_date'] ?? $editRecord['communion_date'] ?? '') ?>" required></label>
                <label>Residence (Domicile) / Address <input autocomplete="off" type="text" name="domicile" value="<?= e($_POST['domicile'] ?? $editRecord['domicile'] ?? '') ?>"></label>
                <div class="inline-fields">
                    <?= person_name_fields('father', "Father's Name", $recordNameFormValues, false) ?>
                    <?= person_name_fields('mother', "Mother's Maiden Name", $recordNameFormValues, false) ?>
                </div>
            </fieldset>

            <fieldset>
                <legend>Prerequisite</legend>
                <div class="baptismal-finder" data-baptismal-finder>
                    <label>Find possible Baptismal records
                        <input autocomplete="off" type="search" data-baptismal-search placeholder="Search after entering the person details">
                    </label>
                    <div class="mapper-actions">
                        <button type="button" class="btn btn-secondary btn-small" data-baptismal-find data-endpoint="<?= e($basePath . '/admin/api/baptismal-record-search.php') ?>">Find possible matches</button>
                    </div>
                    <input type="hidden" name="baptismal_record_id" data-baptismal-id value="<?= e($_POST['baptismal_record_id'] ?? $editRecord['baptismal_record_id'] ?? '') ?>">
                    <div class="baptismal-finder-selection" data-baptismal-selection aria-live="polite"></div>
                    <div class="baptismal-finder-results" data-baptismal-results></div>
                    <p class="field-hint">Select a record to link it. Leave blank if the Baptismal record is not known yet.</p>
                </div>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
                <?= person_name_fields('minister', 'Officiating Minister', $recordNameFormValues, false) ?>
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
