<?php
/**
 * =====================================================================
 * SRS-SPPO CERTIFICATE CORRECTION REQUESTS QUEUE (admin)
 * =====================================================================
 * Purpose: Manage requests for corrections to previously issued certificates.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Correction Requests Queue';

// --- Search / filters / pagination ----------------------------------------
$allowedStatuses = ['pending', 'processing', 'completed', 'rejected'];
$selfUrl = $basePath . '/admin/corrections/correction-list.php';
$rfConfig = [
    'date_column' => 'created_at',
    'search_columns' => ['tracking_number', 'requester_name', 'requester_email', 'requested_changes'],
    'placeholder' => 'Search tracking no., requester, or requested change...',
    'ranges' => ['today', 'week', 'month', 'year'],
    'filters' => [
        'status' => ['label' => 'Status', 'options' => array_combine($allowedStatuses, array_map('humanize_label', $allowedStatuses))],
    ],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$sort = $_GET['sort'] ?? 'date_desc';
$allowedSorts = ['tracking', 'tracking_desc', 'certificate', 'certificate_desc', 'requester', 'requester_desc', 'date_desc', 'date_asc', 'status', 'status_desc'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'date_desc';
$orderClauses = [
    'tracking' => 'tracking_number ASC, id ASC', 'tracking_desc' => 'tracking_number DESC, id DESC',
    'certificate' => 'certificate_id ASC, id ASC', 'certificate_desc' => 'certificate_id DESC, id DESC',
    'requester' => 'requester_name ASC, id ASC', 'requester_desc' => 'requester_name DESC, id DESC',
    'date_desc' => 'created_at DESC, id DESC', 'date_asc' => 'created_at ASC, id ASC',
    'status' => 'status ASC, id ASC', 'status_desc' => 'status DESC, id DESC',
];
$orderClause = $orderClauses[$sort];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM correction_requests WHERE 1=1 {$rf['where']}");
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM correction_requests WHERE 1=1 {$rf['where']} ORDER BY {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$stmt->execute($rf['params']);
$requests = $stmt->fetchAll();

function humanize_label(string $s): string {
    return ucwords(str_replace('_', ' ', $s));
}

require_once __DIR__ . '/../../includes/admin-header.php';
?>

<div class="admin-page-header">
    <h1>Correction Requests Queue</h1>
</div>

<?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
<?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

<?php if (empty($requests)): ?>
    <div class="empty-state">
        <p>No certificate correction requests found.</p>
    </div>
<?php else: ?>
    <div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th><a class="table-sort-link<?= str_starts_with($sort, 'tracking') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'tracking', $sort)) ?>">Tracking No.<?= admin_sort_indicator('tracking', $sort) ?></a></th>
                <th><a class="table-sort-link<?= str_starts_with($sort, 'certificate') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'certificate', $sort)) ?>">Certificate Type<?= admin_sort_indicator('certificate', $sort) ?></a></th>
                <th><a class="table-sort-link<?= str_starts_with($sort, 'requester') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'requester', $sort)) ?>">Requester<?= admin_sort_indicator('requester', $sort) ?></a></th>
                <th>Requested Change</th>
                <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'date', $sort)) ?>">Submitted<?= admin_sort_indicator('date', $sort) ?></a></th>
                <th><a class="table-sort-link<?= str_starts_with($sort, 'status') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'status', $sort)) ?>">Status<?= admin_sort_indicator('status', $sort) ?></a></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($requests as $req): ?>
                <tr>
                    <td><strong><?= e($req['tracking_number']) ?></strong></td>
                    <td>Certificate #<?= (int) $req['certificate_id'] ?></td>
                    <td><?= e($req['requester_name']) ?><br><small><?= e($req['requester_email']) ?></small></td>
                    <td><?= e(mb_strimwidth($req['requested_changes'], 0, 60, '...')) ?></td>
                    <td><?= e(date('M j, Y', strtotime($req['created_at']))) ?></td>
                    <td><span class="status-badge status-<?= e($req['status']) ?>"><?= e(humanize_label($req['status'])) ?></span></td>
                    <td class="row-actions">
                        <a href="<?= $basePath ?>/admin/corrections/correction-detail.php?id=<?= (int) $req['id'] ?>" class="btn btn-small">Process</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
