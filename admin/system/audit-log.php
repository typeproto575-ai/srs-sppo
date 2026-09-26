<?php
/**
 * =====================================================================
 * SRS-SPPO AUDIT LOG VIEWER (secretary and Parish Administrator)
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
$filterRole = in_array(($_GET['role'] ?? ''), ['secretary', 'parish_admin'], true) ? $_GET['role'] : '';
$filterUser = max(0, (int) ($_GET['user_id'] ?? 0));
$filterAction = trim((string) ($_GET['action_name'] ?? ''));
$filterModule = trim((string) ($_GET['module'] ?? ''));
$userOptions = $pdo->query('SELECT id, full_name, role FROM users ORDER BY full_name')->fetchAll();
$actionOptions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$moduleOptions = $pdo->query("SELECT DISTINCT target_table FROM audit_logs WHERE target_table IS NOT NULL AND target_table <> '' ORDER BY target_table")->fetchAll(PDO::FETCH_COLUMN);
$rfConfig = [
    'date_column' => 'al.created_at',
    'search_columns' => ['al.action', 'al.description'],
    'placeholder' => 'Search action or description...',
    'ranges' => ['today', 'week', 'month', 'year'],
    'filters' => [
        'role' => ['label' => 'Role', 'options' => ['secretary' => 'Secretary', 'parish_admin' => 'Parish Administrator']],
        'user_id' => ['label' => 'User', 'options' => array_column($userOptions, 'full_name', 'id')],
        'action_name' => ['label' => 'Action', 'options' => array_combine($actionOptions, $actionOptions)],
        'module' => ['label' => 'Module', 'options' => array_combine($moduleOptions, $moduleOptions)],
    ],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$extraWhere = '';
if ($filterRole !== '') { $extraWhere .= ' AND al.role_snapshot = ?'; $rf['params'][] = $filterRole; }
if ($filterUser > 0) { $extraWhere .= ' AND al.user_id = ?'; $rf['params'][] = $filterUser; }
if ($filterAction !== '') { $extraWhere .= ' AND al.action = ?'; $rf['params'][] = $filterAction; }
if ($filterModule !== '') { $extraWhere .= ' AND al.target_table = ?'; $rf['params'][] = $filterModule; }

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE 1=1 {$rf['where']} {$extraWhere}"
);
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT al.*, u.full_name AS user_name
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE 1=1 {$rf['where']} {$extraWhere}
     ORDER BY al.created_at DESC
     " . (isset($_GET['export']) && $_GET['export'] === 'csv' ? '' : "LIMIT {$rf['per_page']} OFFSET {$rf['offset']}")
);
$stmt->execute($rf['params']);
$logs = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    log_activity($pdo, current_user()['id'], 'audit_log_exported', 'audit_logs', null, 'Audit log exported as CSV.');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="srs-sppo-audit-log-' . date('Y-m-d-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'User', 'Role', 'Action', 'Module', 'Target ID', 'Description', 'IP Address', 'User Agent', 'Reason']);
    foreach ($logs as $log) fputcsv($out, [$log['created_at'], $log['user_name'] ?? 'System / Public Form', $log['role_snapshot'] ?? '', $log['action'], $log['target_table'] ?? '', $log['target_id'] ?? '', $log['description'] ?? '', $log['ip_address'] ?? '', $log['user_agent'] ?? '', $log['reason'] ?? '']);
    fclose($out);
    exit;
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Audit Log</h1>

                <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
        <div class="audit-filter-actions" style="margin:0.75rem 0 1rem;">
            <a class="btn btn-small btn-secondary" href="<?= e($selfUrl . '?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <?php if (empty($logs)): ?>
            <p>No activity found for this filter.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>User / Role</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Description</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
                        <td><?= e($log['user_name'] ?? 'System / Public Form') ?><br><small><?= e($log['role_snapshot'] ?? 'System') ?></small></td>
                        <td><?= e($log['action']) ?></td>
                        <td><?= $log['target_table'] ? e($log['target_table']) . ' #' . (int) $log['target_id'] : '—' ?></td>
                        <td><?= e($log['description']) ?><?php if ($log['reason']): ?><br><small>Reason: <?= e($log['reason']) ?></small><?php endif; ?></td>
                        <td><?= e($log['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="field-hint">
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        Showing the most recent 200 entries. Public-form submissions (with no logged-in user) show as "System / Public Form".</p>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';
