<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Email Log';
$selfUrl = $basePath . '/admin/system/email-log.php';
$statusOptions = [
    'sent' => 'Sent',
    'failed' => 'Failed',
    'disabled' => 'Disabled',
    'invalid' => 'Invalid',
];
$filterStatus = (string) ($_GET['status'] ?? '');
if (!array_key_exists($filterStatus, $statusOptions)) {
    $filterStatus = '';
}

$rfConfig = [
    'date_column' => 'n.created_at',
    'search_columns' => ['n.title', 'n.recipient', 'n.type', 'n.error_message'],
    'placeholder' => 'Search recipient, subject, or error...',
    'ranges' => ['today', 'week', 'month', 'year'],
    'filters' => [
        'status' => ['label' => 'Delivery status', 'options' => $statusOptions],
    ],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$extraWhere = '';
if ($filterStatus !== '') {
    $extraWhere = ' AND n.delivery_status = ?';
    $rf['params'][] = $filterStatus;
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications n
     WHERE n.channel = 'email' {$rf['where']} {$extraWhere}"
);
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$isExport = ($_GET['export'] ?? '') === 'csv';
$stmt = $pdo->prepare(
    "SELECT n.created_at, n.type, n.title, n.recipient, n.delivery_status,
            n.error_message, n.entity_type, n.entity_id
     FROM notifications n
     WHERE n.channel = 'email' {$rf['where']} {$extraWhere}
     ORDER BY n.created_at DESC
     " . ($isExport ? '' : "LIMIT {$rf['per_page']} OFFSET {$rf['offset']}")
);
$stmt->execute($rf['params']);
$logs = $stmt->fetchAll();

if ($isExport) {
    log_activity($pdo, current_user()['id'], 'email_log_exported', 'notifications', null, 'Email delivery log exported as CSV.');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="srs-sppo-email-log-' . date('Y-m-d-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'Event', 'Subject', 'Recipient', 'Status', 'Error', 'Related record']);
    foreach ($logs as $log) {
        $related = $log['entity_type'] ? $log['entity_type'] . ' #' . $log['entity_id'] : '';
        fputcsv($out, [$log['created_at'], $log['type'], $log['title'], $log['recipient'], $log['delivery_status'], $log['error_message'], $related]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Email Log</h1>
        <p>Review email delivery attempts. SMTP credentials are not displayed here.</p>
        <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
        <div class="audit-filter-actions" style="margin:0.75rem 0 1rem;">
            <a class="btn btn-small btn-secondary" href="<?= e($selfUrl . '?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <?php if ($logs === []): ?>
            <p>No email delivery attempts found for this filter.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Event</th>
                    <th>Subject</th>
                    <th>Recipient</th>
                    <th>Status</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
                        <td><?= e($log['type']) ?></td>
                        <td><?= e($log['title']) ?></td>
                        <td><?= e($log['recipient'] ?? '—') ?></td>
                        <td><?= e(ucfirst($log['delivery_status'] ?? 'Unknown')) ?></td>
                        <td><?= e($log['error_message'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';
