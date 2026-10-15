<?php
/**
 * ================================================================
 * SRS-SPPO DATABASE BACKUP / EXPORT (secretary and Parish Administrator)
 * ================================================================
 * 
 * PURPOSE: Generates a portable .sql dump of every table (structure + data)
 * using plain PDO queries -- avoids depending on shell_exec/mysqldump being
 * available, which is not guaranteed on every XAMPP setup.
 * 
 * SECURITY: Staff roles can access this page (require_role at top).
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
require_once __DIR__ . '/../../includes/backup.php';
require_once __DIR__ . '/../../includes/auth.php';

// SECURITY: Database exports contain all records and password hashes.
require_role('secretary');

// Page title for header
$pageTitle = 'Database Backup / Export';

// ================================================================
// HANDLE DOWNLOAD REQUEST (POST + CSRF only)
// ================================================================
// SECURITY: Uses POST instead of GET to prevent:
// - CSRF attacks (attacker can't forge a POST with CSRF token via link/image)
// - Accidental triggers (crawlers, prefetch, browser preloading)
// - Cached/leaked URLs in browser history or server logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download']) && csrf_verify()) {
    // Generate the SQL dump
    $sql = export_database_sql($pdo);

    // LOG THIS ACTION: Record in audit log for security tracking
    log_activity($pdo, current_user()['id'], 'database_backup_exported', null, null, 'Full database backup downloaded');

    // Set headers for file download
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="srs-sppo-backup-' . date('Y-m-d_His') . '.sql"');
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
          'certificate_requests','sacrament_applications','announcements','audit_logs'] as $table) {
    $backupCounts[$table] = (int) $pdo->query("SELECT COUNT(*) c FROM `{$table}`")->fetch()['c'];
}

// No extra head content needed
$extraHead = '';
?>
<?php // Include the admin header (navigation, styling) ?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
        <h1>Database Backup / Export</h1>
        <p class="field-hint">Downloads a full .sql dump (structure + data) of the database. Store this somewhere safe outside the server — this is your disaster-recovery copy alongside the physical logbooks.</p>
        <p class="field-hint"><strong>Security notice:</strong> The exported file contains all sacramental records and hashed account credentials. Store it encrypted and offline — never on shared drives or sent via email.</p>

        <div class="table-scroll">
        <table class="admin-table" style="max-width:500px;">
            <thead><tr><th>Table</th><th>Rows</th></tr></thead>
            <tbody>
                <?php foreach ($tableCounts as $t => $c): ?>
                    <tr><td><?= e($t) ?></td><td><?= $c ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <form autocomplete="off" method="post" style="margin-top:1.5rem;">
            <?= csrf_field() ?>
            <button type="submit" name="download" value="1" class="btn">Download Full Backup (.sql)</button>
        </form>
        <p class="field-hint">This may take a moment for large tables. The download starts automatically once ready.</p>
<?php // Include the admin footer (closing tags) ?>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?> 
