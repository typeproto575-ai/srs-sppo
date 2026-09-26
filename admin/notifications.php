<?php
/**
 * =====================================================================
 * SRS-SPPO ADMIN NOTIFICATIONS ARCHIVE
 * =====================================================================
 * Purpose: Full paginated list of all notifications, separate from the
 *          10-item dropdown in the admin bell. Lets the secretary review,
 *          filter, and mark previously unread items as read without being
 *          constrained to the dropdown's short list.
 *
 * Scope: certificate_requests and sacrament_applications status-change
 * notifications only, as described in the project requirements. Event
 * reminders are informational and remain separate from the actionable
 * unread-notification count.
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Notifications';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        die('CSRF validation failed.');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?')->execute([$id]);
        }
    } elseif ($action === 'mark_all_read') {
        $pdo->exec('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0');
    }

    $redirect = $basePath . '/admin/notifications.php';
    header('Location: ' . $redirect);
    exit;
}

$selfUrl = $basePath . '/admin/notifications.php';
$rfConfig = [
    'date_column' => 'created_at',
    'search_columns' => ['type', 'title', 'message'],
    'placeholder' => 'Search notification type, title, or message...',
    'ranges' => ['today', 'week', 'month', 'year'],
];
require_once __DIR__ . '/../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$sort = $_GET['sort'] ?? 'unread';
$allowedSorts = ['unread', 'unread_desc', 'type', 'type_desc', 'title', 'title_desc', 'date_desc', 'date_asc'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'unread';
$orderClauses = [
    'unread' => 'is_read ASC, created_at DESC, id DESC', 'unread_desc' => 'is_read DESC, created_at DESC, id DESC',
    'type' => 'type ASC, created_at DESC, id DESC', 'type_desc' => 'type DESC, created_at DESC, id DESC',
    'title' => 'title ASC, created_at DESC, id DESC', 'title_desc' => 'title DESC, created_at DESC, id DESC',
    'date_desc' => 'created_at DESC, id DESC', 'date_asc' => 'created_at ASC, id ASC',
];
$orderClause = $orderClauses[$sort];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE 1=1 {$rf['where']}");
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE 1=1 {$rf['where']} ORDER BY {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$stmt->execute($rf['params']);
$notifications = $stmt->fetchAll();

require_once __DIR__ . '/../includes/admin-header.php';
?>
<h1>Notifications</h1>

<div class="actions-row" style="margin-bottom:1rem;">
    <form autocomplete="off" method="post" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-small">Mark all read</button>
    </form>
</div>

<?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
<?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

<?php if (empty($notifications)): ?>
    <p>No notifications yet.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th><a class="table-sort-link<?= str_starts_with($sort, 'type') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'type', $sort)) ?>">Type<?= admin_sort_indicator('type', $sort) ?></a></th>
                <th><a class="table-sort-link<?= str_starts_with($sort, 'title') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'title', $sort)) ?>">Title<?= admin_sort_indicator('title', $sort) ?></a></th>
                <th>Message</th>
                <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'date', $sort)) ?>">Created<?= admin_sort_indicator('date', $sort) ?></a></th>
                <th><a class="table-sort-link<?= str_starts_with($sort, 'unread') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'unread', $sort)) ?>">Status<?= admin_sort_indicator('unread', $sort) ?></a></th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($notifications as $n): ?>
                <tr>
                    <td><?= e($n['type']) ?></td>
                    <td><?= e($n['title']) ?></td>
                    <td><?= e($n['message']) ?></td>
                    <td><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></td>
                    <td><?= (int) $n['is_read'] ? 'Read' : 'Unread' ?></td>
                    <td class="row-actions">
                        <?php if ((int) $n['is_read'] === 0): ?>
                            <form autocomplete="off" method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <button type="submit" class="btn btn-small">Mark read</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">Read</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
