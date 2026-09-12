<?php
/**
 * ================================================================
 * SRS-SPPO DEATH / BURIAL STATUS (admin)
 * ================================================================
 * PURPOSE: Simplified alive/deceased status flag on parishioner records.
 *          Per design decisions, Death/Burial is NOT a full certificate-
 *          issuance flow — it is a status flag only.
 * 
 * NOTE: This is intentionally simplified per scope decisions. The full
 *       Liber Defunctorum register fields (cause of death, place of
 *       burial, spouse, sacraments received, etc.) are documented in
 *       the paper but are NOT implemented as a system feature.
 * ================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Death / Burial Status';
$errors  = [];
$success = null;

// --- Handle status toggle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && csrf_verify()) {
    $recordId = (int) ($_POST['record_id'] ?? 0);
    // The toggle form posts is_deceased=1 (Deceased) or is_deceased=0 (Alive).
    // A bare isset() check is wrong here — it always treats the value as
    // present, so the "Mark Alive" action could never be recorded.
    $isDeceased = ($_POST['is_deceased'] ?? '') === '1' ? 1 : 0;
    $dateOfDeath = trim($_POST['date_of_death'] ?? '');
    $dateOfDeath = $dateOfDeath !== '' ? $dateOfDeath : null;
    if ($recordId) {
        // The toggle form does not carry a date; preserve the existing date
        // when marking a record Deceased without supplying one.
        if ($isDeceased === 1 && $dateOfDeath === null) {
            $stmt = $pdo->prepare('SELECT date_of_death FROM death_burial_records WHERE id = ?');
            $stmt->execute([$recordId]);
            $row = $stmt->fetch();
            if ($row) $dateOfDeath = $row['date_of_death'];
        }
        $stmt = $pdo->prepare("UPDATE death_burial_records SET is_deceased = ?, date_of_death = ? WHERE id = ?");
        $stmt->execute([$isDeceased, $dateOfDeath, $recordId]);
        log_activity($pdo, current_user()['id'], 'death_status_updated', 'death_burial_records', $recordId, 'Status updated');
        $success = 'Status updated.';
    }
}

// --- Handle new entry ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record']) && csrf_verify()) {
    $deceasedName = trim($_POST['deceased_full_name'] ?? '');
    $dateOfDeath = $_POST['date_of_death'] ?: null;
    if ($deceasedName === '') {
        $errors[] = 'Name is required.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO death_burial_records (deceased_full_name, date_of_death, is_deceased, encoded_by) VALUES (?, ?, 1, ?)");
        $stmt->execute([$deceasedName, $dateOfDeath, current_user()['id']]);
        log_activity($pdo, current_user()['id'], 'death_record_added', 'death_burial_records', (int)$pdo->lastInsertId(), "Record added for {$deceasedName}");
        $success = 'Record added.';
    }
}

if (isset($_GET['added'])) $success = 'Record saved.';
// --- Search / filter / pagination --
$selfUrl = $basePath . '/admin/records/death-burial.php';
$rfConfig = ['date_column' => 'date_of_death', 'search_columns' => ['deceased_full_name']];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM death_burial_records {$rf['where']}");
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();
$listStmt = $pdo->prepare("SELECT * FROM death_burial_records {$rf['where']} ORDER BY date_of_death DESC LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$listStmt->execute($rf['params']);
$records = $listStmt->fetchAll();
?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
        <div class="panel-card">
            <h1>Death / Burial Status</h1>
            <p class="field-hint">Simplified alive/deceased status tracking per scope decisions. Full Liber Defunctorum register fields are documented in the paper but not implemented.</p>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

<?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
<?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <?php if (empty($records)): ?>
            <p>No records found<?= $q !== '' ? ' for "' . e($q) . '"' : '' ?>.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Date of Death</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                    <tr>
                        <td><?= e($rec['deceased_full_name']) ?></td>
                        <td><?= e($rec['date_of_death'] ? date('M j, Y', strtotime($rec['date_of_death'])) : '—') ?></td>
                        <td><span class="status-badge <?= !empty($rec['is_deceased']) ? 'status-rejected' : 'status-approved' ?>"><?= !empty($rec['is_deceased']) ? 'Deceased' : 'Alive' ?></span></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="record_id" value="<?= (int) $rec['id'] ?>">
                                <input type="hidden" name="is_deceased" value="<?= !empty($rec['is_deceased']) ? '0' : '1' ?>">
                                <button type="submit" name="update_status" value="1" class="btn btn-small"><?= !empty($rec['is_deceased']) ? 'Mark Alive' : 'Mark Deceased' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>

        <h2 style="margin-top:2.5rem;">Add New Record</h2>
        <p class="field-hint">Add a new death/burial record with simplified status tracking.</p>
        <form method="post" action="">
            <?= csrf_field() ?>
            <fieldset>
                <legend>New Record</legend>
                <label>Full Name * <input type="text" name="deceased_full_name" required></label>
                <label>Date of Death <input type="date" name="date_of_death"></label>
            </fieldset>
            <button type="submit" name="add_record" value="1" class="btn">Add Record</button>
        </form>
        </div>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>