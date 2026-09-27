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
require_once __DIR__ . '/../../includes/baptismal-finder.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Confirmation Records';
$errors  = [];
$success = null;

// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form). Nothing is written when it fails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record']) && csrf_verify()) {
    require_role('secretary');

    $entryNo           = trim($_POST['entry_no'] ?? '');
    $confirmationDate  = $_POST['confirmation_date'] ?? '';
    $confirmandName    = trim($_POST['confirmand_full_name'] ?? '');
    $confirmandFirst = trim($_POST['confirmand_first_name'] ?? '');
    $confirmandMiddle = trim($_POST['confirmand_middle_name'] ?? '');
    $confirmandLast = trim($_POST['confirmand_last_name'] ?? '');
    $confirmandSuffix = trim($_POST['confirmand_suffix'] ?? '');
    if ($confirmandFirst !== '' || $confirmandMiddle !== '' || $confirmandLast !== '') {
        $confirmandName = compose_structured_name($confirmandFirst, $confirmandMiddle, $confirmandLast, $confirmandSuffix);
    }
    $birthdate         = $_POST['birthdate'] ?: null;
    $fatherName        = trim($_POST['father_name'] ?? '');
    $motherMaidenName  = trim($_POST['mother_maiden_name'] ?? '');
    $sponsorName       = trim($_POST['sponsor_name'] ?? '');
    $ministerName      = trim($_POST['minister_name'] ?? '');
    $baptismalRecordId = null;
    $remarks           = trim($_POST['remarks'] ?? '');
    $bookNo            = trim($_POST['book_no'] ?? '');
    $pageNo            = trim($_POST['page_no'] ?? '');
    $lineNo            = trim($_POST['line_no'] ?? '');

    if ($confirmationDate === '') $errors[] = 'Confirmation date is required.';
    if ($confirmandName === '')   $errors[] = "Confirmand's full name is required.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $baptismalRecordId = posted_baptismal_record_id($pdo);
            $personId = posted_person_id($pdo, 'person_id');
            $logbook = register_next_logbook_numbers($pdo, 'confirmation_records', true);
            $bookNo = $logbook['book_no'];
            $pageNo = $logbook['page_no'];
            $lineNo = $logbook['line_no'];

            // Make room for a mid-register insert when requested.
            $shifted = 0;
            if ($entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shifted = register_shift_entry_numbers($pdo, 'confirmation_records', $entryNo);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO confirmation_records
                    (entry_no, confirmation_date, confirmand_full_name, confirmand_first_name, confirmand_middle_name, confirmand_last_name, confirmand_suffix, birthdate, father_name, mother_maiden_name,
                     sponsor_name, minister_name, baptismal_record_id, remarks, book_no, page_no, line_no, encoded_by, person_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $confirmationDate, $confirmandName, $confirmandFirst, $confirmandMiddle, $confirmandLast, $confirmandSuffix, $birthdate, $fatherName, $motherMaidenName,
                $sponsorName, $ministerName, $baptismalRecordId, $remarks, $bookNo, $pageNo, $lineNo,
                current_user()['id'], $personId,
            ]);
            $recordId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE confirmation_records SET confirmand_first_name = ?, confirmand_middle_name = ?, confirmand_last_name = ?, confirmand_suffix = ? WHERE id = ?')
                ->execute([$confirmandFirst, $confirmandMiddle, $confirmandLast, $confirmandSuffix, $recordId]);

            log_activity($pdo, current_user()['id'], 'confirmation_record_encoded', 'confirmation_records', $recordId,
                "Confirmation record encoded for {$confirmandName}" . ($shifted > 0 ? " — {$shifted} later entries renumbered" : ''));

            $pdo->commit();
            redirect_to('/admin/records/confirmation.php?added=1' . ($shifted > 0 ? '&shifted=' . $shifted : ''));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('SRS-SPPO confirmation record save failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

// --- Update an existing record (Edit button on a table row) -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record']) && csrf_verify()) {
    require_role('secretary');

    $editId          = (int) ($_POST['edit_id'] ?? 0);
    $entryNo         = trim($_POST['entry_no'] ?? '');
    $confirmationDate = $_POST['confirmation_date'] ?? '';
    $confirmandName  = trim($_POST['confirmand_full_name'] ?? '');
    $confirmandFirst = trim($_POST['confirmand_first_name'] ?? ''); $confirmandMiddle = trim($_POST['confirmand_middle_name'] ?? ''); $confirmandLast = trim($_POST['confirmand_last_name'] ?? ''); $confirmandSuffix = trim($_POST['confirmand_suffix'] ?? '');
    if ($confirmandFirst !== '' || $confirmandMiddle !== '' || $confirmandLast !== '') $confirmandName = compose_structured_name($confirmandFirst, $confirmandMiddle, $confirmandLast, $confirmandSuffix);
    $birthdate       = $_POST['birthdate'] ?: null;
    $fatherName      = trim($_POST['father_name'] ?? '');
    $motherMaidenName = trim($_POST['mother_maiden_name'] ?? '');
    $sponsorName     = trim($_POST['sponsor_name'] ?? '');
    $ministerName    = trim($_POST['minister_name'] ?? '');
    $baptismalRecordId = null;
    $remarks         = trim($_POST['remarks'] ?? '');
    $bookNo          = trim($_POST['book_no'] ?? '');
    $pageNo          = trim($_POST['page_no'] ?? '');
    $lineNo          = trim($_POST['line_no'] ?? '');

    if ($confirmationDate === '') $errors[] = 'Confirmation date is required.';
    if ($confirmandName === '')   $errors[] = "Confirmand's full name is required.";
    if ($editId <= 0)             $errors[] = 'Invalid record selected for update.';

    if (empty($errors)) {
        try {
            $personId = posted_person_id($pdo, 'person_id');
            $baptismalRecordId = posted_baptismal_record_id($pdo);
            $stmt = $pdo->prepare(
                "UPDATE confirmation_records
                    SET entry_no = ?, confirmation_date = ?, confirmand_full_name = ?, birthdate = ?,
                        father_name = ?, mother_maiden_name = ?, sponsor_name = ?, minister_name = ?,
                        baptismal_record_id = ?, remarks = ?, book_no = ?, page_no = ?, line_no = ?, person_id = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $entryNo, $confirmationDate, $confirmandName, $birthdate, $fatherName, $motherMaidenName,
                $sponsorName, $ministerName, $baptismalRecordId, $remarks, $bookNo, $pageNo, $lineNo, $personId,
                $editId,
            ]);
            $pdo->prepare('UPDATE confirmation_records SET confirmand_first_name = ?, confirmand_middle_name = ?, confirmand_last_name = ?, confirmand_suffix = ? WHERE id = ?')->execute([$confirmandFirst, $confirmandMiddle, $confirmandLast, $confirmandSuffix, $editId]);

            log_activity($pdo, current_user()['id'], 'confirmation_record_updated', 'confirmation_records', $editId,
                "Confirmation record updated for {$confirmandName}");

            redirect_to('/admin/records/confirmation.php?updated=1');
            exit;
        } catch (Exception $e) {
            error_log('SRS-SPPO confirmation record update failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while updating this record. Please try again.';
        }
    }
}

// Permanent deletion is intentionally unavailable; use Admin > Archive or Restore Record.

// --- Edit mode: load the requested record into the encode form -----------
$editRecord = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM confirmation_records WHERE id = ?");
    $stmt->execute([$editId]);
    $editRecord = $stmt->fetch();
    if (!$editRecord) {
        redirect_to('/admin/records/confirmation.php');
        exit;
    }
}
$isEdit = $editRecord !== null;

// Suggested next logbook numbers for the encode dialog (create mode only).
$suggest = $isEdit
    ? ['entry_no' => '', 'book_no' => '', 'page_no' => '', 'line_no' => '']
    : register_next_logbook_numbers($pdo, 'confirmation_records');
$confirmandParts = $isEdit ? structured_name_parts((string) $editRecord['confirmand_full_name']) : ['', '', '', ''];

if (isset($_GET['added'])) $success = 'Confirmation record saved.' . (isset($_GET['shifted']) ? ' Following entries were renumbered to make room.' : '');
if (isset($_GET['updated'])) $success = 'Confirmation record updated.';
if (isset($_GET['deleted'])) $success = 'Confirmation record deleted.';

// --- Filter / paginate (shared component) --------------------------------
$rfConfig = [
    'date_column'    => 'cr.confirmation_date',
    'search_columns' => ['cr.confirmand_full_name', 'cr.father_name', 'cr.mother_maiden_name', 'cr.sponsor_name'],
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
    $orderClause = "ORDER BY cr.confirmation_date DESC, cr.confirmand_full_name ASC";
} elseif ($sort === 'date_asc') {
    $orderClause = "ORDER BY cr.confirmation_date ASC, cr.confirmand_full_name ASC";
} elseif ($sort === 'page_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'page') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'line_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'line') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(cr.line_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(cr.page_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'name_desc') {
    $orderClause = "ORDER BY cr.confirmand_full_name DESC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'name') {
    $orderClause = "ORDER BY cr.confirmand_full_name ASC, CAST(COALESCE(NULLIF(cr.book_no, ''), '0') AS UNSIGNED) ASC";
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

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM confirmation_records cr
     LEFT JOIN baptismal_records br ON br.id = cr.baptismal_record_id
     WHERE cr.is_archived = 0 {$rf['where']}"
);
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT cr.*, br.child_full_name AS baptismal_name FROM confirmation_records cr
     LEFT JOIN baptismal_records br ON br.id = cr.baptismal_record_id
     WHERE cr.is_archived = 0 {$rf['where']} {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$records = $stmt->fetchAll();
$isSecretary = in_array(current_user()['role'] ?? '', ['secretary', 'parish_admin'], true);
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
        <div class="table-scroll">
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
                            <td><strong><?= e(display_name_with_middle_initial((string) $rec['confirmand_full_name'])) ?></strong></td>
                            <td><?= e(date('M j, Y', strtotime($rec['confirmation_date']))) ?></td>
                            <?php $linkStatus = ((int) ($rec['person_id'] ?? 0)) > 0 ? 'linked' : 'unlinked'; ?>
                            <td><span class="link-status-badge link-status-<?= e($linkStatus) ?>"><?= $linkStatus === 'linked' ? 'Linked' : 'Unlinked' ?></span></td>
                            <td class="row-actions">
                                <a href="<?= $basePath ?>/admin/certificates/generate.php?record_type=confirmation&record_id=<?= (int) $rec['id'] ?>" class="btn btn-success btn-small">Generate</a>
                                <a href="<?= e($basePath . '/admin/records/view.php?type=confirmation&id=' . (int) $rec['id']) ?>" class="btn btn-secondary btn-small">View record</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($basePath . '/admin/records/confirmation.php', $rfTotal, $rf); ?>

        <dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || !empty($errors)) ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $isEdit ? 'Edit Entry #' . (int) $editRecord['id'] . ' - ' . e($editRecord['confirmand_full_name']) : 'Encode New Entry' ?></h2>
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
                <div class="inline-fields structured-name-fields">
                    <label>First Name <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="confirmand_first_name" value="<?= e($_POST['confirmand_first_name'] ?? $editRecord['confirmand_first_name'] ?? $confirmandParts[0]) ?>" required></label>
                    <label>Middle Name <input autocomplete="off" type="text" name="confirmand_middle_name" value="<?= e($_POST['confirmand_middle_name'] ?? $editRecord['confirmand_middle_name'] ?? $confirmandParts[1]) ?>"></label>
                    <label>Surname <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="confirmand_last_name" value="<?= e($_POST['confirmand_last_name'] ?? $editRecord['confirmand_last_name'] ?? $confirmandParts[2]) ?>" required></label>
                    <label>Suffix <input autocomplete="off" type="text" name="confirmand_suffix" value="<?= e($_POST['confirmand_suffix'] ?? $editRecord['confirmand_suffix'] ?? $confirmandParts[3]) ?>"></label>
                </div>
                <input type="hidden" name="confirmand_full_name" value="<?= e($_POST['confirmand_full_name'] ?? $editRecord['confirmand_full_name'] ?? '') ?>">
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
                    <label>Birthdate <input autocomplete="off" type="date" name="birthdate" value="<?= e($_POST['birthdate'] ?? $editRecord['birthdate'] ?? '') ?>"></label>
                    <label>Confirmation Date <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="date" name="confirmation_date" value="<?= e($_POST['confirmation_date'] ?? $editRecord['confirmation_date'] ?? '') ?>" required></label>
                </div>
                <div class="inline-fields">
                    <label>Father's Name <input autocomplete="off" type="text" name="father_name" value="<?= e($_POST['father_name'] ?? $editRecord['father_name'] ?? '') ?>"></label>
                    <label>Mother's Maiden Name <input autocomplete="off" type="text" name="mother_maiden_name" value="<?= e($_POST['mother_maiden_name'] ?? $editRecord['mother_maiden_name'] ?? '') ?>"></label>
                </div>
                <label>Sponsor <input autocomplete="off" type="text" name="sponsor_name" value="<?= e($_POST['sponsor_name'] ?? $editRecord['sponsor_name'] ?? '') ?>"></label>
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
                <label>Officiating Minister <input autocomplete="off" type="text" name="minister_name" value="<?= e($_POST['minister_name'] ?? $editRecord['minister_name'] ?? '') ?>"></label>
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
