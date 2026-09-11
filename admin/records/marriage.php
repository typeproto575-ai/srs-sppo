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

$pageTitle = 'Marriage Records';
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
    $marriageDate    = $_POST['marriage_date'] ?? '';
    $husbandName     = trim($_POST['husband_full_name'] ?? '');
    $wifeMaidenName  = trim($_POST['wife_maiden_name'] ?? '');
    $husbandBirthdate  = $_POST['husband_birthdate'] ?: null;
    $husbandBirthplace = trim($_POST['husband_birthplace'] ?? '');
    $wifeBirthdate     = $_POST['wife_birthdate'] ?: null;
    $wifeBirthplace    = trim($_POST['wife_birthplace'] ?? '');
    $ministerName    = trim($_POST['officiating_minister'] ?? '');
    $remarks         = trim($_POST['remarks'] ?? '');
    $bookNo          = trim($_POST['book_no'] ?? '');
    $pageNo          = trim($_POST['page_no'] ?? '');
    $lineNo          = trim($_POST['line_no'] ?? '');

    $witnessNames      = $_POST['witness_name'] ?? [];
    $witnessResidences = $_POST['witness_residence'] ?? [];

    if ($marriageDate === '')   $errors[] = 'Marriage date is required.';
    if ($husbandName === '')    $errors[] = "Husband's full name is required.";
    if ($wifeMaidenName === '') $errors[] = "Wife's maiden name is required.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Make room for a mid-register insert when requested.
            $shifted = 0;
            if ($entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shifted = register_shift_entry_numbers($pdo, 'marriage_records', $entryNo);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO marriage_records
                    (entry_no, marriage_date, husband_full_name, wife_maiden_name, husband_birthdate,
                     husband_birthplace, wife_birthdate, wife_birthplace, officiating_minister, remarks,
                     book_no, page_no, line_no, encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $marriageDate, $husbandName, $wifeMaidenName, $husbandBirthdate,
                $husbandBirthplace, $wifeBirthdate, $wifeBirthplace, $ministerName, $remarks,
                $bookNo, $pageNo, $lineNo, current_user()['id'],
            ]);
            $recordId = (int) $pdo->lastInsertId();

            $witnessStmt = $pdo->prepare(
                "INSERT INTO marriage_witnesses (marriage_record_id, witness_name, witness_residence) VALUES (?, ?, ?)"
            );
            foreach ($witnessNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $witnessStmt->execute([$recordId, $name, trim($witnessResidences[$i] ?? '')]);
            }

            log_activity($pdo, current_user()['id'], 'marriage_record_encoded', 'marriage_records', $recordId,
                "Marriage record encoded for {$husbandName} & {$wifeMaidenName}" . ($shifted > 0 ? " — {$shifted} later entries renumbered" : ''));

            $pdo->commit();
            redirect_to('/admin/records/marriage.php?added=1' . ($shifted > 0 ? '&shifted=' . $shifted : ''));
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
    $husbandBirthdate  = $_POST['husband_birthdate'] ?: null;
    $husbandBirthplace = trim($_POST['husband_birthplace'] ?? '');
    $wifeBirthdate     = $_POST['wife_birthdate'] ?: null;
    $wifeBirthplace    = trim($_POST['wife_birthplace'] ?? '');
    $ministerName      = trim($_POST['officiating_minister'] ?? '');
    $remarks           = trim($_POST['remarks'] ?? '');
    $bookNo            = trim($_POST['book_no'] ?? '');
    $pageNo            = trim($_POST['page_no'] ?? '');
    $lineNo            = trim($_POST['line_no'] ?? '');
    $witnessNames      = $_POST['witness_name'] ?? [];
    $witnessResidences = $_POST['witness_residence'] ?? [];

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
                        husband_birthdate = ?, husband_birthplace = ?, wife_birthdate = ?,
                        wife_birthplace = ?, officiating_minister = ?, remarks = ?, book_no = ?,
                        page_no = ?, line_no = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $entryNo, $marriageDate, $husbandName, $wifeMaidenName, $husbandBirthdate,
                $husbandBirthplace, $wifeBirthdate, $wifeBirthplace, $ministerName, $remarks,
                $bookNo, $pageNo, $lineNo, $editId,
            ]);

            // Replace the witness set wholesale with what was submitted.
            $pdo->prepare("DELETE FROM marriage_witnesses WHERE marriage_record_id = ?")->execute([$editId]);
            $witnessStmt = $pdo->prepare(
                "INSERT INTO marriage_witnesses (marriage_record_id, witness_name, witness_residence) VALUES (?, ?, ?)"
            );
            foreach ($witnessNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $witnessStmt->execute([$editId, $name, trim($witnessResidences[$i] ?? '')]);
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

// --- Delete a record (Delete button on a table row; linked refs unlink) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record']) && csrf_verify()) {
    require_role('secretary');

    $delId = (int) ($_POST['delete_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT husband_full_name FROM marriage_records WHERE id = ?");
        $stmt->execute([$delId]);
        $deletedName = (string) ($stmt->fetchColumn() ?: 'Unknown');

        $stmt = $pdo->prepare("DELETE FROM marriage_records WHERE id = ?");
        $stmt->execute([$delId]);

        log_activity($pdo, current_user()['id'], 'marriage_record_deleted', 'marriage_records', $delId,
            "Marriage record deleted ({$deletedName})");

        redirect_to('/admin/records/marriage.php?deleted=1');
        exit;
    } catch (Exception $e) {
        $errors[] = 'Something went wrong while deleting this record. Please try again.';
    }
}

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

if (isset($_GET['added'])) $success = 'Marriage record saved.' . (isset($_GET['shifted']) ? ' Following entries were renumbered to make room.' : '');
if (isset($_GET['updated'])) $success = 'Marriage record updated.';
if (isset($_GET['deleted'])) $success = 'Marriage record deleted.';

// --- Filter / paginate (shared component) --------------------------------
$rfConfig = [
    'date_column'    => 'marriage_date',
    'search_columns' => ['husband_full_name', 'wife_maiden_name', 'entry_no', 'officiating_minister'],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM marriage_records WHERE 1=1 {$rf['where']}");
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM marriage_records WHERE 1=1 {$rf['where']}
     ORDER BY marriage_date DESC LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$records = $stmt->fetchAll();
$isSecretary = current_user()['role'] === 'secretary';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <h1>Marriage Records</h1>
            <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>

        <?php rf_render_bar($basePath . '/admin/records/marriage.php', $rfConfig, $rf); ?>

        <?php if (empty($records)): ?>
            <p>No marriage records found<?= $rf['q'] !== '' ? ' for "' . e($rf['q']) . '"' : '' ?>.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Husband</th>
                    <th>Wife (Maiden Name)</th>
                    <th>Marriage Date</th>
                    <th>Minister</th>
                    <th>Book/Page/Line</th>
                    <th>Certificate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                    <tr>
                        <td><?= e($rec['husband_full_name']) ?></td>
                        <td><?= e($rec['wife_maiden_name']) ?></td>
                        <td><?= e(date('M j, Y', strtotime($rec['marriage_date']))) ?></td>
                        <td><?= e($rec['officiating_minister']) ?></td>
                        <td><?= e($rec['book_no']) ?> / <?= e($rec['page_no']) ?> / <?= e($rec['line_no']) ?></td>
                        <td class="row-actions">
                            <a href="<?= $basePath ?>/admin/certificates/generate.php?record_type=marriage&record_id=<?= (int) $rec['id'] ?>" class="btn btn-success btn-small">Generate</a>
                            <a href="?edit=<?= (int) $rec['id'] ?>" class="btn btn-warning btn-small">Edit</a>
                            <form method="post" action="" class="inline-form" data-confirm="Delete this marriage record permanently? Linked entries will be unlinked.">
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
        <?php rf_render_pagination($basePath . '/admin/records/marriage.php', $rfTotal, $rf); ?>

        <dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || !empty($errors)) ? ' data-autoshow' : '' ?>>
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
                <legend>Husband</legend>
                <label>Full Name * <input type="text" name="husband_full_name" value="<?= e($_POST['husband_full_name'] ?? $editRecord['husband_full_name'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Birthdate <input type="date" name="husband_birthdate" value="<?= e($_POST['husband_birthdate'] ?? $editRecord['husband_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace <input type="text" name="husband_birthplace" value="<?= e($_POST['husband_birthplace'] ?? $editRecord['husband_birthplace'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Wife</legend>
                <label>Maiden Name * <input type="text" name="wife_maiden_name" value="<?= e($_POST['wife_maiden_name'] ?? $editRecord['wife_maiden_name'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Birthdate <input type="date" name="wife_birthdate" value="<?= e($_POST['wife_birthdate'] ?? $editRecord['wife_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace <input type="text" name="wife_birthplace" value="<?= e($_POST['wife_birthplace'] ?? $editRecord['wife_birthplace'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Marriage Details</legend>
                <label>Marriage Date * <input type="date" name="marriage_date" value="<?= e($_POST['marriage_date'] ?? $editRecord['marriage_date'] ?? '') ?>" required></label>
                <label>Officiating Minister <input type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? $editRecord['officiating_minister'] ?? '') ?>"></label>
            </fieldset>

            <fieldset>
                <legend>Witnesses</legend>
                <?php for ($i = 0; $i < 2; $i++): ?>
                    <div class="inline-fields">
                        <label>Witness Name <input type="text" name="witness_name[]" value="<?= e($_POST['witness_name'][$i] ?? $editWitnesses[$i]['witness_name'] ?? '') ?>"></label>
                        <label>Residence <input type="text" name="witness_residence[]" value="<?= e($_POST['witness_residence'][$i] ?? $editWitnesses[$i]['witness_residence'] ?? '') ?>"></label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
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