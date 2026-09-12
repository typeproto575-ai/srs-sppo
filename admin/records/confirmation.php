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
    $birthdate         = $_POST['birthdate'] ?: null;
    $fatherName        = trim($_POST['father_name'] ?? '');
    $motherMaidenName  = trim($_POST['mother_maiden_name'] ?? '');
    $sponsorName       = trim($_POST['sponsor_name'] ?? '');
    $ministerName      = trim($_POST['minister_name'] ?? '');
    $baptismalRecordIdRaw = trim($_POST['baptismal_record_id'] ?? '');
    $baptismalRecordId = $baptismalRecordIdRaw === '' ? null : (int) $baptismalRecordIdRaw;
    $remarks           = trim($_POST['remarks'] ?? '');
    $bookNo            = trim($_POST['book_no'] ?? '');
    $pageNo            = trim($_POST['page_no'] ?? '');
    $lineNo            = trim($_POST['line_no'] ?? '');

    if ($confirmationDate === '') $errors[] = 'Confirmation date is required.';
    if ($confirmandName === '')   $errors[] = "Confirmand's full name is required.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Make room for a mid-register insert when requested.
            $shifted = 0;
            if ($entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shifted = register_shift_entry_numbers($pdo, 'confirmation_records', $entryNo);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO confirmation_records
                    (entry_no, confirmation_date, confirmand_full_name, birthdate, father_name, mother_maiden_name,
                     sponsor_name, minister_name, baptismal_record_id, remarks, book_no, page_no, line_no, encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $confirmationDate, $confirmandName, $birthdate, $fatherName, $motherMaidenName,
                $sponsorName, $ministerName, $baptismalRecordId, $remarks, $bookNo, $pageNo, $lineNo,
                current_user()['id'],
            ]);
            $recordId = (int) $pdo->lastInsertId();

            log_activity($pdo, current_user()['id'], 'confirmation_record_encoded', 'confirmation_records', $recordId,
                "Confirmation record encoded for {$confirmandName}" . ($shifted > 0 ? " — {$shifted} later entries renumbered" : ''));

            $pdo->commit();
            redirect_to('/admin/records/confirmation.php?added=1' . ($shifted > 0 ? '&shifted=' . $shifted : ''));
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
    $confirmationDate = $_POST['confirmation_date'] ?? '';
    $confirmandName  = trim($_POST['confirmand_full_name'] ?? '');
    $birthdate       = $_POST['birthdate'] ?: null;
    $fatherName      = trim($_POST['father_name'] ?? '');
    $motherName      = trim($_POST['mother_name'] ?? '');
    $sponsorName     = trim($_POST['sponsor_name'] ?? '');
    $ministerName    = trim($_POST['minister_name'] ?? '');
    $baptismalRecordIdRaw = trim($_POST['baptismal_record_id'] ?? '');
    $baptismalRecordId = $baptismalRecordIdRaw === '' ? null : (int) $baptismalRecordIdRaw;
    $remarks         = trim($_POST['remarks'] ?? '');
    $bookNo          = trim($_POST['book_no'] ?? '');
    $pageNo          = trim($_POST['page_no'] ?? '');
    $lineNo          = trim($_POST['line_no'] ?? '');

    if ($confirmationDate === '') $errors[] = 'Confirmation date is required.';
    if ($confirmandName === '')   $errors[] = "Confirmand's full name is required.";
    if ($editId <= 0)             $errors[] = 'Invalid record selected for update.';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "UPDATE confirmation_records
                    SET entry_no = ?, confirmation_date = ?, confirmand_full_name = ?, birthdate = ?,
                        father_name = ?, mother_name = ?, sponsor_name = ?, minister_name = ?,
                        baptismal_record_id = ?, remarks = ?, book_no = ?, page_no = ?, line_no = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $entryNo, $confirmationDate, $confirmandName, $birthdate, $fatherName, $motherName,
                $sponsorName, $ministerName, $baptismalRecordId, $remarks, $bookNo, $pageNo, $lineNo,
                $editId,
            ]);

            log_activity($pdo, current_user()['id'], 'confirmation_record_updated', 'confirmation_records', $editId,
                "Confirmation record updated for {$confirmandName}");

            redirect_to('/admin/records/confirmation.php?updated=1');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Something went wrong while updating this record. Please try again.';
        }
    }
}

// --- Delete a record (Delete button on a table row; linked refs unlink) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record']) && csrf_verify()) {
    require_role('secretary');

    $delId = (int) ($_POST['delete_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT confirmand_full_name FROM confirmation_records WHERE id = ?");
        $stmt->execute([$delId]);
        $deletedName = (string) ($stmt->fetchColumn() ?: 'Unknown');

        $stmt = $pdo->prepare("DELETE FROM confirmation_records WHERE id = ?");
        $stmt->execute([$delId]);

        log_activity($pdo, current_user()['id'], 'confirmation_record_deleted', 'confirmation_records', $delId,
            "Confirmation record deleted ({$deletedName})");

        redirect_to('/admin/records/confirmation.php?deleted=1');
        exit;
    } catch (Exception $e) {
        $errors[] = 'Something went wrong while deleting this record. Please try again.';
    }
}

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

if (isset($_GET['added'])) $success = 'Confirmation record saved.' . (isset($_GET['shifted']) ? ' Following entries were renumbered to make room.' : '');
if (isset($_GET['updated'])) $success = 'Confirmation record updated.';
if (isset($_GET['deleted'])) $success = 'Confirmation record deleted.';

// --- Filter / paginate (shared component) --------------------------------
$rfConfig = [
    'date_column'    => 'cr.confirmation_date',
    'search_columns' => ['cr.confirmand_full_name', 'cr.father_name', 'cr.mother_name', 'cr.sponsor_name', 'cr.entry_no'],
    'placeholder'    => 'Search confirmand, parents, sponsor, entry no.&hellip;',
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM confirmation_records cr
     LEFT JOIN baptismal_records br ON br.id = cr.baptismal_record_id
     WHERE 1=1 {$rf['where']}"
);
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT cr.*, br.child_full_name AS baptismal_name FROM confirmation_records cr
     LEFT JOIN baptismal_records br ON br.id = cr.baptismal_record_id
     WHERE 1=1 {$rf['where']}
     ORDER BY cr.confirmation_date DESC LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$records = $stmt->fetchAll();
$isSecretary = current_user()['role'] === 'secretary';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <h1>Confirmation Records</h1>
            <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>

                <?php rf_render_bar($basePath . '/admin/records/confirmation.php', $rfConfig, $rf); ?>
        <?php rf_render_pagination($basePath . '/admin/records/confirmation.php', $rfTotal, $rf); ?>

        <?php if (empty($records)): ?>
            <p>No confirmation records found<?= $rf['q'] !== '' ? ' for "' . e($rf['q']) . '"' : '' ?>.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Confirmand</th>
                    <th>Confirmation Date</th>
                    <th>Sponsor</th>
                    <th>Baptismal Record (prerequisite)</th>
                    <th>Book/Page/Line</th>
                    <th>Certificate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                    <tr>
                        <td><?= e($rec['confirmand_full_name']) ?></td>
                        <td><?= e(date('M j, Y', strtotime($rec['confirmation_date']))) ?></td>
                        <td><?= e($rec['sponsor_name']) ?></td>
                        <td><?= $rec['baptismal_name'] ? e($rec['baptismal_name']) . ' (#' . (int) $rec['baptismal_record_id'] . ')' : '<span class="flag-badge" title="No baptismal record linked yet">Not linked</span>' ?></td>
                        <td><?= e($rec['book_no']) ?> / <?= e($rec['page_no']) ?> / <?= e($rec['line_no']) ?></td>
                        <td class="row-actions">
                            <a href="<?= $basePath ?>/admin/certificates/generate.php?record_type=confirmation&record_id=<?= (int) $rec['id'] ?>" class="btn btn-success btn-small">Generate</a>
                            <a href="?edit=<?= (int) $rec['id'] ?>" class="btn btn-warning btn-small">Edit</a>
                            <form method="post" action="" class="inline-form" data-confirm="Delete this confirmation record permanently? Linked entries will be unlinked.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_record" value="1">
                                <input type="hidden" name="delete_id" value="<?= (int) $rec['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-small">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
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
        <form method="post" action="">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
            <fieldset>
                <legend>Logbook Reference</legend>
                <div class="inline-fields">
                    <label>Entry No. <input type="text" name="entry_no" value="<?= e($_POST['entry_no'] ?? $editRecord['entry_no'] ?? $suggest['entry_no']) ?>"></label>
                    <label>Book No. <input type="text" name="book_no" value="<?= e($_POST['book_no'] ?? $editRecord['book_no'] ?? $suggest['book_no']) ?>"></label>
                    <label>Page <input type="text" name="page_no" value="<?= e($_POST['page_no'] ?? $editRecord['page_no'] ?? $suggest['page_no']) ?>"></label>
                    <label>Line <input type="text" name="line_no" value="<?= e($_POST['line_no'] ?? $editRecord['line_no'] ?? $suggest['line_no']) ?>"></label>
                </div>
                <?php if (!$isEdit): ?>
                <label>If the Entry No. already exists
                    <select name="entry_conflict">
                        <option value="shift" selected>Shift later entries to make room</option>
                        <option value="keep">Save as entered (duplicate allowed)</option>
                    </select>
                </label>
                <p class="field-hint">Suggestions are pre-filled from the last entry — edit them freely. "Shift" bumps every later entry with the same numbering up by one, for inserting into the middle of the register.</p>
                <?php endif; ?>
            </fieldset>

            <fieldset>
                <legend>Confirmand</legend>
                <label>Full Name * <input type="text" name="confirmand_full_name" value="<?= e($_POST['confirmand_full_name'] ?? $editRecord['confirmand_full_name'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Birthdate <input type="date" name="birthdate" value="<?= e($_POST['birthdate'] ?? $editRecord['birthdate'] ?? '') ?>"></label>
                    <label>Confirmation Date * <input type="date" name="confirmation_date" value="<?= e($_POST['confirmation_date'] ?? $editRecord['confirmation_date'] ?? '') ?>" required></label>
                </div>
                <div class="inline-fields">
                    <label>Father's Name <input type="text" name="father_name" value="<?= e($_POST['father_name'] ?? $editRecord['father_name'] ?? '') ?>"></label>
                    <label>Mother's Maiden Name <input type="text" name="mother_maiden_name" value="<?= e($_POST['mother_maiden_name'] ?? $editRecord['mother_maiden_name'] ?? '') ?>"></label>
                </div>
                <label>Sponsor <input type="text" name="sponsor_name" value="<?= e($_POST['sponsor_name'] ?? $editRecord['sponsor_name'] ?? '') ?>"></label>
            </fieldset>

            <fieldset>
                <legend>Prerequisite</legend>
                <label>Baptismal Record ID (look up on the <a href="<?= $basePath ?>/admin/records/baptismal.php" target="_blank">Baptismal Records</a> page)
                    <input type="number" name="baptismal_record_id" min="1" value="<?= e($_POST['baptismal_record_id'] ?? $editRecord['baptismal_record_id'] ?? '') ?>">
                </label>
                <p class="field-hint">Leave blank if not yet matched — this can be filled in later.</p>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
                <label>Officiating Minister <input type="text" name="minister_name" value="<?= e($_POST['minister_name'] ?? $editRecord['minister_name'] ?? '') ?>"></label>
                <label>Remarks <textarea name="remarks"><?= e($_POST['remarks'] ?? $editRecord['remarks'] ?? '') ?></textarea></label>
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