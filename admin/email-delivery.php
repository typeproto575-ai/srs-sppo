<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('secretary');
$basePath = $basePath ?? base_path();
$pageTitle = 'Email Delivery Log';
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && ($_POST['action'] ?? '') === 'resend') {
    $entityType = (string) ($_POST['entity_type'] ?? '');
    $entityId = (int) ($_POST['entity_id'] ?? 0);
    $sent = 0;
    if ($entityType === 'certificate_request' && $entityId > 0) {
        $s = $pdo->prepare('SELECT requester_email, tracking_number, sacrament_type FROM certificate_requests WHERE id = ?');
        $s->execute([$entityId]); $row = $s->fetch();
        if ($row) {
            $kind = ucwords(str_replace('_', ' ', $row['sacrament_type'])) . ' Certificate Request';
            $sent = mail_request_received($row['requester_email'], $row['tracking_number'], $kind, $pdo, $entityType, $entityId) ? 1 : 0;
        }
    } elseif ($entityType === 'sacrament_application' && $entityId > 0) {
        $s = $pdo->prepare('SELECT sa.tracking_number, sa.sacrament_type FROM sacrament_applications sa WHERE sa.id = ?');
        $s->execute([$entityId]); $app = $s->fetch();
        if ($app) {
            $kind = ucwords(str_replace('_', ' ', $app['sacrament_type'])) . ' Application';
            if ($app['sacrament_type'] === 'marriage') {
                $s = $pdo->prepare('SELECT groom_email, bride_email FROM marriage_applications WHERE application_id = ?');
                $s->execute([$entityId]); $emails = $s->fetch() ?: [];
                foreach (array_unique(array_filter([$emails['groom_email'] ?? '', $emails['bride_email'] ?? ''])) as $email) {
                    $sent += mail_request_received($email, $app['tracking_number'], $kind, $pdo, $entityType, $entityId) ? 1 : 0;
                }
            } else {
                $table = ['baptismal'=>'baptismal_applications','confirmation'=>'confirmation_applications','first_communion'=>'first_communion_applications'][$app['sacrament_type']] ?? null;
                if ($table) {
                    $s = $pdo->prepare("SELECT email FROM {$table} WHERE application_id = ?"); $s->execute([$entityId]);
                    $email = (string) $s->fetchColumn();
                    $sent = mail_request_received($email, $app['tracking_number'], $kind, $pdo, $entityType, $entityId) ? 1 : 0;
                }
            }
        }
    }
    log_activity($pdo, current_user()['id'], 'email_resend_requested', $entityType ?: null, $entityId ?: null, 'Email receipt resend attempted; accepted by mail transport: ' . ($sent > 0 ? 'yes' : 'no'));
    $notice = $sent > 0 ? 'Email resend accepted by the configured mail transport.' : 'The resend was recorded, but the mail transport did not accept it. Review the delivery status below.';
}

$filter = (string) ($_GET['status'] ?? '');
$allowed = ['sent','failed','disabled','invalid'];
$selfUrl = $basePath . '/admin/email-delivery.php';
$rfConfig = [
    'date_column' => 'created_at',
    'search_columns' => ['recipient', 'subject_line', 'tracking_number', 'event_type'],
    'placeholder' => 'Search recipient, subject, tracking no., or event...',
    'ranges' => ['today', 'week', 'month', 'year'],
    'filters' => [
        'status' => [
            'label' => 'Status',
            'options' => array_combine($allowed, array_map('ucfirst', $allowed)),
        ],
    ],
];
require_once __DIR__ . '/../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$sort = $_GET['sort'] ?? 'date_desc';
$allowedSorts = ['date_desc', 'date_asc', 'recipient', 'recipient_desc', 'event', 'event_desc', 'reference', 'reference_desc', 'status', 'status_desc'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'date_desc';
$orderClauses = [
    'date_desc' => 'created_at DESC, id DESC', 'date_asc' => 'created_at ASC, id ASC',
    'recipient' => 'recipient ASC, id ASC', 'recipient_desc' => 'recipient DESC, id DESC',
    'event' => 'event_type ASC, id ASC', 'event_desc' => 'event_type DESC, id DESC',
    'reference' => 'tracking_number ASC, id ASC', 'reference_desc' => 'tracking_number DESC, id DESC',
    'status' => 'delivery_status ASC, id ASC', 'status_desc' => 'delivery_status DESC, id DESC',
];
$orderClause = $orderClauses[$sort];

$baseWhere = 'WHERE 1=1';
$combinedWhere = $baseWhere . $rf['where'];
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM email_delivery_log {$combinedWhere}");
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM email_delivery_log {$combinedWhere} ORDER BY {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$stmt->execute($rf['params']);
$deliveries = $stmt->fetchAll();
require_once __DIR__ . '/../includes/admin-header.php';
?>
<h1>Email Delivery Log</h1>
<?php if ($notice): ?><div class="alert alert-info"><p><?= e($notice) ?></p></div><?php endif; ?>
<p class="field-hint">This log shows whether the configured email transport accepted each alert. A status of “sent” means accepted by the local relay; it is not a guarantee the recipient's inbox delivered it.</p>
<?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
<?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
<?php if (!$deliveries): ?><p>No email delivery attempts found.</p><?php else: ?>
<div class="table-scroll">
<table class="admin-table"><thead><tr><th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'date', $sort)) ?>">Time<?= admin_sort_indicator('date', $sort) ?></a></th><th><a class="table-sort-link<?= str_starts_with($sort, 'recipient') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'recipient', $sort)) ?>">Recipient<?= admin_sort_indicator('recipient', $sort) ?></a></th><th><a class="table-sort-link<?= str_starts_with($sort, 'event') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'event', $sort)) ?>">Event<?= admin_sort_indicator('event', $sort) ?></a></th><th><a class="table-sort-link<?= str_starts_with($sort, 'reference') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'reference', $sort)) ?>">Reference<?= admin_sort_indicator('reference', $sort) ?></a></th><th><a class="table-sort-link<?= str_starts_with($sort, 'status') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'status', $sort)) ?>">Status<?= admin_sort_indicator('status', $sort) ?></a></th><th>Action</th></tr></thead><tbody>
<?php foreach ($deliveries as $mail): ?>
<tr>
    <td><?= e(date('M j, Y g:i A', strtotime($mail['created_at']))) ?></td>
    <td><?= e($mail['recipient']) ?><br><small><?= e($mail['subject_line']) ?></small></td>
    <td><?= e(ucwords(str_replace('_', ' ', $mail['event_type']))) ?></td>
    <td><?= e($mail['tracking_number'] ?: '—') ?></td>
    <td><span class="status-badge status-<?= e($mail['delivery_status']) ?>"><?= e(ucfirst($mail['delivery_status'])) ?></span><?php if ($mail['error_message']): ?><br><small><?= e($mail['error_message']) ?></small><?php endif; ?></td>
    <td class="row-actions"><?php if ($mail['entity_type'] && $mail['entity_id']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resend"><input type="hidden" name="entity_type" value="<?= e($mail['entity_type']) ?>"><input type="hidden" name="entity_id" value="<?= (int) $mail['entity_id'] ?>"><button class="btn btn-small" type="submit">Resend receipt</button></form><?php else: ?>—<?php endif; ?></td>
</tr>
<?php endforeach; ?></tbody></table>
</div>
<?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
