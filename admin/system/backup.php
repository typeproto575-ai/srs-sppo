<?php
/**
 * =====================================================================
 * SPPO-IS DATABASE BACKUP / EXPORT (admin, secretary-only)
 * =====================================================================
 * Purpose: One-click portable .sql dump of the whole database
 *          (structure + data), generated in pure PHP/PDO by
 *          export_database_sql() below so it works on any XAMPP
 *          without relying on mysqldump being installed.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path(); // backup/export is a secretary-only system tool

$pageTitle = 'Database Backup / Export';

/**
 * Produce a portable .sql dump of every table (structure + data) using
 * plain PDO queries -- avoids depending on shell_exec/mysqldump being
 * available, which is not guaranteed on every XAMPP setup.
 */
function export_database_sql(PDO $pdo, string $dbName): string
{
    $output = "-- SPPO-IS database export\n-- Generated " . date('Y-m-d H:i:s') . "\n\n";
    $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $output .= "-- ----------------------------\n-- Table: {$table}\n-- ----------------------------\n";
        $output .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $output .= $createRow['Create Table'] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
            $vals = array_map(function ($v) use ($pdo) {
                if ($v === null) return 'NULL';
                return $pdo->quote((string) $v);
            }, array_values($row));
            $output .= "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
        }
        $output .= "\n";
    }

    $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $output;
}

if (isset($_GET['download']) && $_GET['download'] === '1') {
    global $DB_NAME;
    $sql = export_database_sql($pdo, $DB_NAME);

    log_activity($pdo, current_user()['id'], 'database_backup_exported', null, null, 'Full database backup downloaded');

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="sppo-is-backup-' . date('Y-m-d_His') . '.sql"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

// Quick counts for context on the page.
$tableCounts = [];
foreach (['users','baptismal_records','confirmation_records','first_communion_records','marriage_records',
          'certificate_requests','sacrament_applications','announcements','audit_logs'] as $t) {
    $tableCounts[$t] = (int) $pdo->query("SELECT COUNT(*) c FROM `{$t}`")->fetch()['c'];
}
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
        <h1>Database Backup / Export</h1>
        <p class="field-hint">Downloads a full .sql dump (structure + data) of the database. Store this somewhere safe outside the server — this is your disaster-recovery copy alongside the physical logbooks.</p>

        <table class="admin-table" style="max-width:500px;">
            <thead><tr><th>Table</th><th>Rows</th></tr></thead>
            <tbody>
                <?php foreach ($tableCounts as $t => $c): ?>
                    <tr><td><?= e($t) ?></td><td><?= $c ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:1.5rem;">
            <a href="?download=1" class="btn">Download Full Backup (.sql)</a>
        </p>
        <p class="field-hint">This may take a moment for large tables. The download starts automatically once ready.</p>
    </main>
</div>
</body>
</html>
