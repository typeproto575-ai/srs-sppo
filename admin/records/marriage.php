<?php
/**
 * =====================================================================
 * SPPO-IS MARRIAGE RECORDS (admin register)
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
                "Marriage record encoded for {$husbandName} & {$wifeMaidenName}");

            $pdo->commit();
            redirect_to('/admin/records/marriage.php?added=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

if (isset($_GET['added'])) $success = 'Marriage record saved.';

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM marriage_records WHERE husband_full_name LIKE ? OR wife_maiden_name LIKE ?
         ORDER BY marriage_date DESC LIMIT 100"
    );
    $stmt->execute(['%' . $q . '%', '%' . $q . '%']);
} else {
    $stmt = $pdo->query("SELECT * FROM marriage_records ORDER BY marriage_date DESC LIMIT 100");
}
$records = $stmt->fetchAll();
$isSecretary = current_user()['role'] === 'secretary';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= e($pageTitle) ?> | SPPO-IS</title>
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css">
</head>
<body>
<div class="admin-layout">
    <main class="admin-content">
        <p><a href="<?= $basePath ?>/admin/dashboard.php">&larr; Back to Dashboard</a></p>
        <h1>Marriage Records</h1>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="get" class="inline-fields filter-form">
            <label>Search by Husband or Wife's Name
                <input type="text" name="q" value="<?= e($q) ?>">
            </label>
            <button type="submit" class="btn btn-small">Search</button>
            <?php if ($q !== ''): ?><a href="<?= $basePath ?>/admin/records/marriage.php" class="btn btn-secondary btn-small">Clear</a><?php endif; ?>
        </form>

        <?php if (empty($records)): ?>
            <p>No marriage records found<?= $q !== '' ? ' for "' . e($q) . '"' : '' ?>.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Husband</th>
                    <th>Wife (Maiden Name)</th>
                    <th>Marriage Date</th>
                    <th>Minister</th>
                    <th>Book/Page/Line</th>
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
                <legend>Husband</legend>
                <label>Full Name * <input type="text" name="husband_full_name" required></label>
                <div class="inline-fields">
                    <label>Birthdate <input type="date" name="husband_birthdate"></label>
                    <label>Birthplace <input type="text" name="husband_birthplace"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Wife</legend>
                <label>Maiden Name * <input type="text" name="wife_maiden_name" required></label>
                <div class="inline-fields">
                    <label>Birthdate <input type="date" name="wife_birthdate"></label>
                    <label>Birthplace <input type="text" name="wife_birthplace"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Marriage Details</legend>
                <label>Marriage Date * <input type="date" name="marriage_date" required></label>
                <label>Officiating Minister <input type="text" name="officiating_minister"></label>
            </fieldset>

            <fieldset>
                <legend>Witnesses</legend>
                <?php for ($i = 0; $i < 2; $i++): ?>
                    <div class="inline-fields">
                        <label>Witness Name <input type="text" name="witness_name[]"></label>
                        <label>Residence <input type="text" name="witness_residence[]"></label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
                <label>Remarks <textarea name="remarks"></textarea></label>
            </fieldset>

            <button type="submit" name="add_record" value="1" class="btn">Save Record</button>
        </form>

    </main>
</div>
</body>
</html>
