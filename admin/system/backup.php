<?php
/**
 * ================================================================
 * SPPO-IS DATABASE BACKUP / EXPORT (admin, secretary-only)
 * ================================================================
 * 
 * PURPOSE: Generates a portable .sql dump of every table (structure + data)
 * using plain PDO queries -- avoids depending on shell_exec/mysqldump being
 * available, which is not guaranteed on every XAMPP setup.
 * 
 * SECURITY: Only secretaries can access this page (require_role at top).
 * The download is logged to the audit log for accountability.
 * 
 * FEATURES:
 * - Full database export (all tables, structure + data)
 * - Table row counts displayed for context
 * - One-click download as .sql file
 * - Audit logging of all downloads
 */

// Load dependencies
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// SECURITY: Restrict to secretary role only
require_role('secretary');

// Page title for header
$pageTitle = 'Database Backup / Export';

/**
 * ================================================================
 * FUNCTION: export_database_sql
 * ================================================================
 * PURPOSE: Export the full database to SQL format as a string
 * 
 * HOW IT WORKS:
 * 1. Gets list of all tables via SHOW TABLES
 * 2. For each table, gets CREATE TABLE statement
 * 3. For each table, gets all rows as INSERT statements
 * 4. Wraps everything in SET FOREIGN_KEY_CHECKS for safe import
 * 
 * @param PDO $pdo The database connection object
 * @return string The complete SQL dump ready for download
 */
function export_database_sql(PDO $pdo): string
{
    // Header comment with timestamp
    $output = "-- SPPO-IS database export\n-- Generated " . date('Y-m-d H:i:s') . "\n\n";
    $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    // Get all table names in the database
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    // Loop through each table and export structure + data
    foreach ($tables as $table) {
        // Get the CREATE TABLE statement
        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        
        // Add table header comment
        $output .= "-- ----------------------------\n-- Table: {$table}\n-- ----------------------------\n";
        
        // Drop table if exists (for clean import)
        $output .= "DROP TABLE IF EXISTS `{$table}`;\n";
        
        // Add CREATE TABLE statement
        $output .= $createRow['Create Table'] . ";\n\n";

        // Get all rows from this table
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert each row to an INSERT statement
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

    // Re-enable foreign key checks
    $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $output;
}

// ================================================================
// HANDLE DOWNLOAD REQUEST
// ================================================================
// When user clicks "Download Full Backup", generate and serve the SQL file
if (isset($_GET['download']) && $_GET['download'] === '1') {
    // Generate the SQL dump
    $sql = export_database_sql($pdo);

    // LOG THIS ACTION: Record in audit log for security tracking
    log_activity($pdo, current_user()['id'], 'database_backup_exported', null, null, 'Full database backup downloaded');

    // Set headers for file download
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="sppo-is-backup-' . date('Y-m-d_His') . '.sql"');
    header('Content-Length: ' . strlen($sql));
    
    // Output the SQL and stop execution
    echo $sql;
    exit;
}

// ================================================================
// DISPLAY PAGE: Show table counts and download button
// ================================================================
// Get row counts for each major table (for display purposes)
$tableCounts = [];
foreach (['users','baptismal_records','confirmation_records','first_communion_records','marriage_records',
          'death_burial_records','certificate_requests','sacrament_applications','announcements','audit_logs'] as $t) {
    $tableCounts[$t] = (int) $pdo->query("SELECT COUNT(*) c FROM `{$t}`")->fetch()['c'];
}

// No extra head content needed
$extraHead = '';
?>
<?php // Include the admin header (navigation, styling) ?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
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
<?php // Include the admin footer (closing tags) ?>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?> 
