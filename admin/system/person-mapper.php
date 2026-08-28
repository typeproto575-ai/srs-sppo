<?php
/**
 * =====================================================================
 * SPPO-IS PERSON MAPPER (LEGACY RECORD LINKING TOOL)
 * =====================================================================
 * Purpose: Maps legacy record name fields to `persons` entries so that
 *          the same person can be linked across multiple sacrament
 *          records (e.g. a baptismal record and a confirmation record
 *          for the same individual).
 *
 * Access: Secretary only (require_role('secretary')).
 *
 * Security: The $col (column name) is validated against a strict
 *           whitelist before being used in any SQL query, preventing
 *           SQL injection via the 'column' POST parameter.
 * =====================================================================
 */

// Load database connection, shared helpers, and session guard
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Base URL path for building links
$basePath = base_path();
$notice = null; // Success message shown to the user

// --- Handle POST actions (assign or create-and-assign) -------------------
// CSRF: forged or stale POSTs are dropped silently — no mapping changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $notice = null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    // Whitelist of allowed table names (prevents SQL injection via table)
    $allowedTables = [
        'baptismal_records',
        'confirmation_records',
        'first_communion_records',
        'marriage_records',
        'death_burial_records',
    ];

    // Whitelist of allowed column names (prevents SQL injection via column)
    $allowedColumns = [
        'person_id',
        'husband_person_id',
        'wife_person_id',
    ];

    if ($action === 'assign') {
        // --- Assign an existing person to a record ----------------------
        $table    = $_POST['table'] ?? '';
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $personId = (int) ($_POST['person_id'] ?? 0);
        $col      = $_POST['column'] ?? 'person_id';

        // Validate table and column against whitelists before using in SQL
        if ($table && $recordId && $personId && in_array($table, $allowedTables, true) && in_array($col, $allowedColumns, true)) {
            $sql = "UPDATE `$table` SET `$col` = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$personId, $recordId]);

            // Log the mapping action to the audit trail
            log_activity($pdo, current_user()['id'], 'map_person', $table, $recordId, "Mapped to person_id=$personId");
            $notice = 'Assigned successfully.';
        }
    } elseif ($action === 'create_and_assign') {
        // --- Create a new person and assign them to a record -------------
        $table    = $_POST['table'] ?? '';
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $fullname = trim($_POST['new_person_name'] ?? '');
        $col      = $_POST['column'] ?? 'person_id';

        // Validate table and column against whitelists before using in SQL
        if ($fullname && $table && $recordId && in_array($table, $allowedTables, true) && in_array($col, $allowedColumns, true)) {
            // Insert the new person into the persons table
            $stmt = $pdo->prepare("INSERT INTO persons (first_name, created_at) VALUES (?, NOW())");
            $stmt->execute([$fullname]);
            $personId = $pdo->lastInsertId();

            // Link the new person to the record
            $sql = "UPDATE `$table` SET `$col` = ? WHERE id = ?";
            $stmt2 = $pdo->prepare($sql);
            $stmt2->execute([$personId, $recordId]);

            // Log the creation + mapping action
            log_activity($pdo, current_user()['id'], 'create_person_and_map', $table, $recordId, "Created person_id=$personId ($fullname)");
            $notice = 'Created person and assigned.';
        }
    }
}

// --- Fetch existing persons for the dropdown ------------------------------
$persons = $pdo->query("SELECT id, first_name FROM persons ORDER BY id DESC LIMIT 500")->fetchAll();

// --- Fetch unmatched records per sacrament type ---------------------------
// These are records that don't yet have a person_id linked to them
$unmatched = [];
$unmatched['baptismal'] = $pdo->query("SELECT id, child_full_name AS display_name FROM baptismal_records WHERE person_id IS NULL OR person_id = 0 ORDER BY id DESC LIMIT 200")->fetchAll();
$unmatched['confirmation'] = $pdo->query("SELECT id, confirmand_full_name AS display_name FROM confirmation_records WHERE person_id IS NULL OR person_id = 0 ORDER BY id DESC LIMIT 200")->fetchAll();
$unmatched['first_communion'] = $pdo->query("SELECT id, communicant_full_name AS display_name FROM first_communion_records WHERE person_id IS NULL OR person_id = 0 ORDER BY id DESC LIMIT 200")->fetchAll();
$unmatched['marriage_husband'] = $pdo->query("SELECT id, husband_full_name AS display_name FROM marriage_records WHERE husband_person_id IS NULL OR husband_person_id = 0 ORDER BY id DESC LIMIT 200")->fetchAll();
$unmatched['marriage_wife'] = $pdo->query("SELECT id, wife_maiden_name AS display_name FROM marriage_records WHERE wife_person_id IS NULL OR wife_person_id = 0 ORDER BY id DESC LIMIT 200")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Person Mapper | SPPO-IS</title>
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css">
</head>
<body>
<div class="admin-layout">
    <main class="admin-content">
        <h1>Person Mapper</h1>
        <?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>

        <p class="field-hint">Map legacy record name fields to `persons` entries. Create a new person if no match exists.</p>

        <?php foreach ($unmatched as $section => $rows): ?>
            <section style="margin-top:1.25rem;">
                <h2><?= e(ucwords(str_replace(['_','marriage'],' ', $section))) ?></h2>
                <?php if (empty($rows)): ?>
                    <p>No unmatched rows.</p>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <div class="panel-card" style="margin-bottom:0.5rem; display:flex; align-items:center; justify-content:space-between;">
                            <div style="flex:1;">
                                <strong>#<?= (int)$r['id'] ?></strong>
                                <div><?= e($r['display_name'] ?: '—') ?></div>
                            </div>
                            <div style="min-width:360px; text-align:right;">
                                <!-- Assign existing person form -->
                                <form method="post" style="display:inline-block; margin-right:0.5rem;">
                                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                    <input type="hidden" name="action" value="assign">
                                    <input type="hidden" name="table" value="<?= $section === 'baptismal' ? 'baptismal_records' : ($section === 'confirmation' ? 'confirmation_records' : ($section === 'first_communion' ? 'first_communion_records' : 'marriage_records')) ?>">
                                    <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="column" value="<?= $section === 'marriage_husband' ? 'husband_person_id' : ($section === 'marriage_wife' ? 'wife_person_id' : 'person_id') ?>">
                                    <select name="person_id" required>
                                        <option value="">— choose person —</option>
                                        <?php foreach ($persons as $p): ?>
                                            <option value="<?= (int)$p['id'] ?>"><?= e($p['first_name']) ?> (id:<?= (int)$p['id'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-small" type="submit">Assign</button>
                                </form>

                                <!-- Create new person + assign form -->
                                <form method="post" style="display:inline-block;">
                                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                    <input type="hidden" name="action" value="create_and_assign">
                                    <input type="hidden" name="table" value="<?= $section === 'baptismal' ? 'baptismal_records' : ($section === 'confirmation' ? 'confirmation_records' : ($section === 'first_communion' ? 'first_communion_records' : 'marriage_records')) ?>">
                                    <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="column" value="<?= $section === 'marriage_husband' ? 'husband_person_id' : ($section === 'marriage_wife' ? 'wife_person_id' : 'person_id') ?>">
                                    <input type="text" name="new_person_name" placeholder="New person full name" required>
                                    <button class="btn btn-secondary btn-small" type="submit">Create & Assign</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

    </main>
    </div>
</body>
</html>