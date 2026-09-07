<?php
/**
 * =====================================================================
 * SPPO-IS FIRST COMMUNION RECORDS (admin register)
 * =====================================================================
 * Purpose: Digital mirror of the physical First Communion register:
 *          encode entries from the logbook, list/search, and serve as
 *          the source of truth for First Communion certificate requests.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

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
    $communicantName = trim($_POST['communicant_full_name'] ?? '');
    $fatherName      = trim($_POST['father_name'] ?? '');
    $motherName      = trim($_POST['mother_name'] ?? '');
    $ministerName    = trim($_POST['minister_name'] ?? '');
    $baptismalRecordIdRaw = trim($_POST['baptismal_record_id'] ?? '');
    $baptismalRecordId = $baptismalRecordIdRaw === '' ? null : (int) $baptismalRecordIdRaw;
    $remarks         = trim($_POST['remarks'] ?? '');
    $bookNo          = trim($_POST['book_no'] ?? '');
    $pageNo          = trim($_POST['page_no'] ?? '');
    $lineNo          = trim($_POST['line_no'] ?? '');

    if ($communionDate === '')   $errors[] = 'Date of First Communion is required.';
    if ($communicantName === '') $errors[] = "Communicant's full name is required.";

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO first_communion_records
                    (entry_no, communion_date, communicant_full_name, father_name, mother_name,
                     minister_name, baptismal_record_id, remarks, book_no, page_no, line_no, encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $communionDate, $communicantName, $fatherName, $motherName,
                $ministerName, $baptismalRecordId, $remarks, $bookNo, $pageNo, $lineNo,
                current_user()['id'],
            ]);
            $recordId = (int) $pdo->lastInsertId();

            log_activity($pdo, current_user()['id'], 'first_communion_record_encoded', 'first_communion_records', $recordId,
                "First Communion record encoded for {$communicantName}");

            redirect_to('/admin/records/first-communion.php?added=1');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

// --- Update an existing record (Edit button on a table row) -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record']) && csrf_verify()) {
    require_role('secretary');

    $editId          = (int) ($_POST['edit_id'] ?? 0);
    $entryNo         = trim($_POST['entry_no'] ?? '');
    $communionDate   = $_POST['communion_date'] ?? '';
    $communicantName = trim($_POST['communicant_full_name'] ?? '');
    $fatherName      = trim($_POST['father_name'] ?? '');
    $motherName      = trim($_POST['mother_name'] ?? '');
    $ministerName    = trim($_POST['minister_name'] ?? '');
    $baptismalRecordIdRaw = trim($_POST['baptismal_record_id'] ?? '');
    $baptismalRecordId = $baptismalRecordIdRaw === '' ? null : (int) $baptismalRecordIdRaw;
    $remarks         = trim($_POST['remarks'] ?? '');
    $bookNo          = trim($_POST['book_no'] ?? '');
    $pageNo          = trim($_POST['page_no'] ?? '');
    $lineNo          = trim($_POST['line_no'] ?? '');

    if ($communionDate === '')   $errors[] = 'Date of First Communion is required.';
    if ($communicantName === '') $errors[] = "Communicant's full name is required.";
    if ($editId <= 0)            $errors[] = 'Invalid record selected for update.';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "UPDATE first_communion_records
                    SET entry_no = ?, communion_date = ?, communicant_full_name = ?, father_name = ?,
                        mother_name = ?, minister_name = ?, baptismal_record_id = ?, remarks = ?,
                        book_no = ?, page_no = ?, line_no = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $entryNo, $communionDate, $communicantName, $fatherName, $motherName,
                $ministerName, $baptismalRecordId, $remarks, $bookNo, $pageNo, $lineNo,
                $editId,
            ]);

            log_activity($pdo, current_user()['id'], 'first_communion_record_updated', 'first_communion_records', $editId,
                "First Communion record updated for {$communicantName}");

            redirect_to('/admin/records/first-communion.php?updated=1');
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
        $stmt = $pdo->prepare("SELECT communicant_full_name FROM first_communion_records WHERE id = ?");
        $stmt->execute([$delId]);
        $deletedName = (string) ($stmt->fetchColumn() ?: 'Unknown');

        $stmt = $pdo->prepare("DELETE FROM first_communion_records WHERE id = ?");
        $stmt->execute([$delId]);

        log_activity($pdo, current_user()['id'], 'first_communion_record_deleted', 'first_communion_records', $delId,
            "First Communion record deleted ({$deletedName})");

        redirect_to('/admin/records/first-communion.php?deleted=1');
        exit;
    } catch (Exception $e) {
        $errors[] = 'Something went wrong while deleting this record. Please try again.';
    }
}

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

if (isset($_GET['added'])) $success = 'First Communion record saved.';
if (isset($_GET['updated'])) $success = 'First Communion record updated.';
if (isset($_GET['deleted'])) $success = 'First Communion record deleted.';

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare(
        "SELECT fc.*, br.child_full_name AS baptismal_name FROM first_communion_records fc
         LEFT JOIN baptismal_records br ON br.id = fc.baptismal_record_id
         WHERE fc.communicant_full_name LIKE ? ORDER BY fc.communion_date DESC LIMIT 100"
    );
    $stmt->execute(['%' . $q . '%']);
} else {
    $stmt = $pdo->query(
        "SELECT fc.*, br.child_full_name AS baptismal_name FROM first_communion_records fc
         LEFT JOIN baptismal_records br ON br.id = fc.baptismal_record_id
         ORDER BY fc.communion_date DESC LIMIT 100"
    );
}
$records = $stmt->fetchAll();
$isSecretary = current_user()['role'] === 'secretary';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <h1>First Communion Records</h1>
            <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
        </div>
        <p class="field-hint">Tracked as a one-time received/not-received event, not per-Mass attendance.</p>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>

        <form method="get" class="inline-fields filter-form">
            <label>Search by Communicant's Name
                <input type="text" name="q" value="<?= e($q) ?>">
            </label>
            <button type="submit" class="btn btn-small">Search</button>
            <?php if ($q !== ''): ?><a href="<?= $basePath ?>/admin/records/first-communion.php" class="btn btn-secondary btn-small">Clear</a><?php endif; ?>
        </form>

        <?php if (empty($records)): ?>
            <p>No First Communion records found<?= $q !== '' ? ' for "' . e($q) . '"' : '' ?>.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Communicant</th>
                    <th>Date</th>
                    <th>Baptismal Record (prerequisite)</th>
                    <th>Book/Page/Line</th>
                    <th>Certificate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                    <tr>
                        <td><?= e($rec['communicant_full_name']) ?></td>
                        <td><?= e(date('M j, Y', strtotime($rec['communion_date']))) ?></td>
                        <td><?= $rec['baptismal_name'] ? e($rec['baptismal_name']) . ' (#' . (int) $rec['baptismal_record_id'] . ')' : '<span class="flag-badge" title="No baptismal record linked yet">Not linked</span>' ?></td>
                        <td><?= e($rec['book_no']) ?> / <?= e($rec['page_no']) ?> / <?= e($rec['line_no']) ?></td>
                        <td class="row-actions">
                            <a href="<?= $basePath ?>/admin/certificates/generate.php?record_type=first_communion&record_id=<?= (int) $rec['id'] ?>" class="btn btn-success btn-small">Generate</a>
                            <a href="?edit=<?= (int) $rec['id'] ?>" class="btn btn-warning btn-small">Edit</a>
                            <form method="post" action="" class="inline-form" data-confirm="Delete this First Communion record permanently? Linked entries will be unlinked.">
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
        <form method="post" action="">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
            <fieldset>
                <legend>Logbook Reference</legend>
                <div class="inline-fields">
                    <label>Entry No. <input type="text" name="entry_no" value="<?= e($_POST['entry_no'] ?? $editRecord['entry_no'] ?? '') ?>"></label>
                    <label>Book No. <input type="text" name="book_no" value="<?= e($_POST['book_no'] ?? $editRecord['book_no'] ?? '') ?>"></label>
                    <label>Page <input type="text" name="page_no" value="<?= e($_POST['page_no'] ?? $editRecord['page_no'] ?? '') ?>"></label>
                    <label>Line <input type="text" name="line_no" value="<?= e($_POST['line_no'] ?? $editRecord['line_no'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Communicant</legend>
                <label>Full Name * <input type="text" name="communicant_full_name" value="<?= e($_POST['communicant_full_name'] ?? $editRecord['communicant_full_name'] ?? '') ?>" required></label>
                <label>Date of First Communion * <input type="date" name="communion_date" value="<?= e($_POST['communion_date'] ?? $editRecord['communion_date'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Father's Name <input type="text" name="father_name" value="<?= e($_POST['father_name'] ?? $editRecord['father_name'] ?? '') ?>"></label>
                    <label>Mother's Name <input type="text" name="mother_name" value="<?= e($_POST['mother_name'] ?? $editRecord['mother_name'] ?? '') ?>"></label>
                </div>
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