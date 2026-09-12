<?php
/**
 * =====================================================================
 * SRS-SPPO AUDIT LOG VIEWER (admin, secretary-only)
 * =====================================================================
 * Purpose: Read-only trail of who did what (login, record encodes,
 *          status changes, backups) with filters by user, action, and
 *          date. Entries are written by log_activity() across the app.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Audit Log';

// --- Search / filter / pagination ---
$selfUrl = $basePath . '/admin/system/audit-log.php';
$rfConfig = ['date_column' => 'al.created_at', 'search_columns' => ['al.action', 'al.description']];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     {$rf['where']}"
);
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT al.*, u.full_name AS user_name
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     {$rf['where']}
     ORDER BY al.created_at DESC
     LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$logs = $stmt->fetchAll();
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Audit Log</h1>

        <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>

        <?php if (empty($logs)): ?>
            <p>No activity found for this filter.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
                        <td><?= e($log['user_name'] ?? 'System / Public Form') ?></td>
                        <td><?= e($log['action']) ?></td>
                        <td><?= $log['target_table'] ? e($log['target_table']) . ' #' . (int) $log['target_id'] : '—' ?></td>
                        <td><?= e($log['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="field-hint">
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        Showing the most recent 200 entries. Public-form submissions (with no logged-in user) show as "System / Public Form".</p>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';