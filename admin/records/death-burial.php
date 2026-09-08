<?php
/**
 * ================================================================
 * SPPO-IS DEATH / BURIAL STATUS (admin)
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
    $isDeceased = isset($_POST['is_deceased']) ? 1 : 0;
    $dateOfDeath = $_POST['date_of_death'] ?: null;
    if ($recordId) {
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
// --- Search / list ---
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM death_burial_records WHERE deceased_full_name LIKE ? ORDER BY date_of_death DESC LIMIT 100");
    $stmt->execute(['%' . $q . '%']);
} else {
    $stmt = $pdo->query("SELECT * FROM death_burial_records ORDER BY date_of_death DESC LIMIT 100");
}
$records = $stmt->fetchAll();
?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
        <div class="panel-card">
            <p><a href="<?= base_path() ?>/admin/dashboard.php">&larr; Back to Dashboard</a></p>
            <h1>Death / Burial Status</h1>
            <p class="field-hint">Simplified alive/deceased status tracking per scope decisions. Full Liber Defunctorum register fields are documented in the paper but not implemented.</p>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="get" class="inline-fields filter-form">
            <label>Search by Name
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="e.g. Juan Dela Cruz">
            </label>
            <button type="submit" class="btn btn-small">Search</button>
            <?php if ($q !== ''): ?><a href="<?= base_path() ?>/admin/records/death-burial.php" class="btn btn-secondary btn-small">Clear</a><?php endif; ?>
        </form>

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