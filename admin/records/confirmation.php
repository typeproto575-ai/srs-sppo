<?php
/**
 * =====================================================================
 * SPPO-IS CONFIRMATION RECORDS (admin register)
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
    $motherName        = trim($_POST['mother_name'] ?? '');
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
            $stmt = $pdo->prepare(
                "INSERT INTO confirmation_records
                    (entry_no, confirmation_date, confirmand_full_name, birthdate, father_name, mother_name,
                     sponsor_name, minister_name, baptismal_record_id, remarks, book_no, page_no, line_no, encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $confirmationDate, $confirmandName, $birthdate, $fatherName, $motherName,
                $sponsorName, $ministerName, $baptismalRecordId, $remarks, $bookNo, $pageNo, $lineNo,
                current_user()['id'],
            ]);
            $recordId = (int) $pdo->lastInsertId();

            log_activity($pdo, current_user()['id'], 'confirmation_record_encoded', 'confirmation_records', $recordId,
                "Confirmation record encoded for {$confirmandName}");

            redirect_to('/admin/records/confirmation.php?added=1');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

if (isset($_GET['added'])) $success = 'Confirmation record saved.';

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare(
        "SELECT cr.*, br.child_full_name AS baptismal_name FROM confirmation_records cr
         LEFT JOIN baptismal_records br ON br.id = cr.baptismal_record_id
         WHERE cr.confirmand_full_name LIKE ? ORDER BY cr.confirmation_date DESC LIMIT 100"
    );
    $stmt->execute(['%' . $q . '%']);
} else {
    $stmt = $pdo->query(
        "SELECT cr.*, br.child_full_name AS baptismal_name FROM confirmation_records cr
         LEFT JOIN baptismal_records br ON br.id = cr.baptismal_record_id
         ORDER BY cr.confirmation_date DESC LIMIT 100"
    );
}
$records = $stmt->fetchAll();
$isSecretary = current_user()['role'] === 'secretary';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Confirmation Records</h1>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="get" class="inline-fields filter-form">
            <label>Search by Confirmand's Name
                <input type="text" name="q" value="<?= e($q) ?>">
            </label>
            <button type="submit" class="btn btn-small">Search</button>
            <?php if ($q !== ''): ?><a href="<?= $basePath ?>/admin/records/confirmation.php" class="btn btn-secondary btn-small">Clear</a><?php endif; ?>
        </form>

        <?php if (empty($records)): ?>
            <p>No confirmation records found<?= $q !== '' ? ' for "' . e($q) . '"' : '' ?>.</p>
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
                        <td><a href="<?= $basePath ?>/admin/certificates/generate.php?record_type=confirmation&record_id=<?= (int) $rec['id'] ?>" class="btn btn-success btn-small">Generate</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2 style="margin-top:2.5rem;">Encode New Entry</h2>
        <form method="post" action="">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <fieldset>
                <legend>Logbook Reference</legend>
                <div class="inline-fields">
                    <label>Entry No. <input type="text" name="entry_no"></label>
                    <label>Book No. <input type="text" name="book_no"></label>
                    <label>Page <input type="text" name="page_no"></label>
                    <label>Line <input type="text" name="line_no"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Confirmand</legend>
                <label>Full Name * <input type="text" name="confirmand_full_name" required></label>
                <div class="inline-fields">
                    <label>Birthdate <input type="date" name="birthdate"></label>
                    <label>Confirmation Date * <input type="date" name="confirmation_date" required></label>
                </div>
                <div class="inline-fields">
                    <label>Father's Name <input type="text" name="father_name"></label>
                    <label>Mother's Name <input type="text" name="mother_name"></label>
                </div>
                <label>Sponsor <input type="text" name="sponsor_name"></label>
            </fieldset>

            <fieldset>
                <legend>Prerequisite</legend>
                <label>Baptismal Record ID (look up on the <a href="<?= $basePath ?>/admin/records/baptismal.php" target="_blank">Baptismal Records</a> page)
                    <input type="number" name="baptismal_record_id" min="1">
                </label>
                <p class="field-hint">Leave blank if not yet matched — this can be filled in later.</p>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
                <label>Officiating Minister <input type="text" name="minister_name"></label>
                <label>Remarks <textarea name="remarks"></textarea></label>
            </fieldset>

            <button type="submit" name="add_record" value="1" class="btn">Save Record</button>
        </form>

    <?php require_once __DIR__ . '/../../includes/admin-footer.php';