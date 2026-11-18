<?php
/**
 * =====================================================================
 * SRS-SPPO CERTIFICATE REQUESTS QUEUE (admin)
 * =====================================================================
 * Purpose: The secretary's worklist of certificate requests from the
 *          public forms. Lists pending/all requests with requester and
 *          subject details, supports status changes and drill-down into
 *          each request.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();
$retentionNotice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_overdue_requests') {
    if (!csrf_verify()) {
        $retentionNotice = ['error', 'Your session token is invalid or expired. Refresh the page and try again.'];
    } else {
        try {
            $pdo->beginTransaction();
            $overdueStmt = $pdo->query(
                "SELECT id, request_no
                 FROM certificate_requests
                 WHERE channel = 'online'
                   AND status = 'new'
                   AND person_id IS NULL
                   AND record_id IS NULL
                   AND paid_at IS NULL
                   AND anonymized_at IS NULL
                   AND retention_eligible_at <= DATE_SUB(NOW(), INTERVAL 90 DAY)
                 ORDER BY id
                 FOR UPDATE"
            );
            $overdueRequests = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);
            $anonymize = $pdo->prepare(
                "UPDATE certificate_requests
                 SET status = 'cancelled',
                     subject_name = 'Anonymized certificate request',
                     subject_details = NULL,
                     requester_name = 'Anonymized requester',
                     requester_relationship = 'other',
                     requester_relationship_other = NULL,
                     requester_email = CONCAT('redacted+', id, '@example.invalid'),
                     requester_contact_number = NULL,
                     purpose = NULL,
                     purpose_other = NULL,
                     privacy_consent_at = NULL,
                     person_id = NULL,
                     record_id = NULL,
                     matched_by = NULL,
                     matched_at = NULL,
                     notes = NULL,
                     rejection_reason = 'Closed after 90 days without follow-up; personal details anonymized.',
                     anonymized_at = NOW()
                 WHERE id = ?"
            );
            foreach ($overdueRequests as $overdueRequest) {
                $requestId = (int) $overdueRequest['id'];
                $anonymize->execute([$requestId]);
                log_activity(
                    $pdo,
                    (int) current_user()['id'],
                    'certificate.close_no_followup',
                    'certificate_requests',
                    $requestId,
                    'Certificate request ' . (string) $overdueRequest['request_no'] . ' closed and anonymized after 90 days without follow-up.'
                );
            }
            $pdo->commit();
            redirect_to('/admin/requests/queue.php?closed_no_followup=' . count($overdueRequests));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SRS-SPPO overdue certificate request cleanup failed: ' . $e->getMessage());
            $retentionNotice = ['error', 'The overdue requests could not be closed. No changes were saved.'];
        }
    }
}

if (isset($_GET['closed_no_followup']) && ctype_digit((string) $_GET['closed_no_followup'])) {
    $closedCount = (int) $_GET['closed_no_followup'];
    $retentionNotice = [
        'success',
        $closedCount === 1
            ? 'One overdue request was closed and anonymized.'
            : $closedCount . ' overdue requests were closed and anonymized.',
    ];
}

$retentionCountsStmt = $pdo->query(
    "SELECT
        SUM(retention_eligible_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND retention_eligible_at > DATE_SUB(NOW(), INTERVAL 90 DAY)) AS followup_due,
        SUM(retention_eligible_at <= DATE_SUB(NOW(), INTERVAL 90 DAY)) AS closure_due
     FROM certificate_requests
     WHERE channel = 'online'
       AND status = 'new'
       AND person_id IS NULL
       AND record_id IS NULL
       AND paid_at IS NULL
       AND anonymized_at IS NULL
       AND retention_eligible_at IS NOT NULL"
);
$retentionCounts = $retentionCountsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$followupDueCount = (int) ($retentionCounts['followup_due'] ?? 0);
$closureDueCount = (int) ($retentionCounts['closure_due'] ?? 0);

// Status changes are handled on certificate-detail.php (which enforces
// transition validation and captures rejection reasons). The queue is a
// read-only navigational list — no inline POST handler here.


// --- Filters ---------------------------------------------------------------
$filterStatus = $_GET['status'] ?? '';
$filterSacrament = $_GET['sacrament'] ?? '';
$filterChannel = $_GET['channel'] ?? '';
$pageTitle = ($filterChannel === 'online') ? 'Online Certificate Requests' : 'Certificate Requests Queue';
if (!defined('CERT_PENDING_STATUSES')) {
    define('CERT_PENDING_STATUSES', ['new', 'matched', 'for_signature']);
}
$allowedRequestStatuses = array_merge(['pending'], CERT_PENDING_STATUSES, ['ready', 'released', 'rejected']);
$allowedSacraments = ['baptism', 'first_communion', 'confirmation', 'marriage'];

$where  = [];
$params = [];
if ($filterStatus === 'pending') {
    $placeholders = implode(',', array_fill(0, count(CERT_PENDING_STATUSES), '?'));
    $where[] = "cr.status IN ($placeholders)";
    foreach (CERT_PENDING_STATUSES as $s) {
        $params[] = $s;
    }
} elseif ($filterStatus !== '' && in_array($filterStatus, $allowedRequestStatuses, true)) {
    $where[] = 'cr.status = ?';
    $params[] = $filterStatus;
} else {
    $where[] = "cr.status <> 'rejected'";
}
if ($filterChannel !== '' && in_array($filterChannel, ['online', 'onsite'], true)) {
    $where[] = 'cr.channel = ?';
    $params[] = $filterChannel;
}
if ($filterSacrament !== '' && in_array($filterSacrament, $allowedSacraments, true)) {
    $where[] = 'cr.sacrament_type = ?';
    $params[] = $filterSacrament;
}

// --- Search / filter / pagination --
$selfUrl = $basePath . '/admin/requests/queue.php';
$rfConfig = [
    'date_column'    => 'cr.created_at',
    'search_columns' => ['cr.request_no', 'cr.subject_name', 'cr.requester_name', 'cr.requester_email'],
    'placeholder'    => 'Search reference no., subject, requester, email...',
    'ranges'         => ['today', 'week', 'month', 'year'],
    'filters'        => [
        'status'    => ['label' => 'Status',
            'options' => [
                'pending'       => 'Needs action',
                'new'           => 'New request',
                'matched'       => 'Record found',
                'for_signature' => "Waiting for priest's signature",
                'ready'         => 'Ready for pickup',
                'released'      => 'Given to the requester',
                'rejected'      => 'Declined',
            ]],
        'channel'   => ['label' => 'Channel', 'options' => ['online' => 'Submitted: Online', 'onsite' => 'Submitted: At the office']],
        'sacrament' => ['label' => 'Sacrament',
            'options' => [
                'baptism'        => 'Baptism',
                'confirmation'   => 'Confirmation',
                'first_communion'=> 'First Communion',
                'marriage'       => 'Marriage',
            ]],
    ],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$sort = $_GET['sort'] ?? 'date_desc';
$allowedSorts = ['request', 'request_desc', 'sacrament', 'sacrament_desc', 'subject', 'subject_desc', 'requester', 'requester_desc', 'purpose', 'purpose_desc', 'channel', 'channel_desc', 'date_desc', 'date_asc', 'status', 'status_desc'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'date_desc';
$orderClauses = [
    'request' => 'cr.request_no ASC, cr.id ASC', 'request_desc' => 'cr.request_no DESC, cr.id DESC',
    'sacrament' => 'cr.sacrament_type ASC, cr.id ASC', 'sacrament_desc' => 'cr.sacrament_type DESC, cr.id DESC',
    'subject' => 'cr.subject_name ASC, cr.id ASC', 'subject_desc' => 'cr.subject_name DESC, cr.id DESC',
    'requester' => 'cr.requester_name ASC, cr.id ASC', 'requester_desc' => 'cr.requester_name DESC, cr.id DESC',
    'purpose' => 'cr.purpose ASC, cr.id ASC', 'purpose_desc' => 'cr.purpose DESC, cr.id DESC',
    'channel' => 'cr.channel ASC, cr.id ASC', 'channel_desc' => 'cr.channel DESC, cr.id DESC',
    'date_desc' => 'cr.created_at DESC, cr.id DESC', 'date_asc' => 'cr.created_at ASC, cr.id ASC',
    'status' => 'cr.status ASC, cr.id ASC', 'status_desc' => 'cr.status DESC, cr.id DESC',
];
$orderClause = $orderClauses[$sort];

$filterSql = $where === [] ? '' : ' AND (' . implode(' AND ', $where) . ')';
$combinedWhere = ' WHERE 1 = 1' . $rf['where'] . $filterSql;
$allParams = array_merge($rf['params'], $params);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM certificate_requests cr{$combinedWhere}");
$countStmt->execute($allParams);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT cr.* FROM certificate_requests cr{$combinedWhere} ORDER BY {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$stmt->execute($allParams);
$requests = $stmt->fetchAll();

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <header class="admin-queue-heading">
            <div>
                <p class="admin-queue-kicker">Requests</p>
                <h1><?= $filterChannel === 'online' ? 'Online certificate requests' : 'Certificate requests' ?></h1>
                <p><?= $filterChannel === 'online'
                    ? 'Review online requests for copies of existing parish register entries. Start walk-in requests from the person profile.'
                    : 'Track requests for certificates of sacraments already recorded in the parish registers. This queue is separate from the official register records. Start walk-in requests from the person profile.' ?></p>
            </div>
                <div class="admin-queue-actions">
                    <a class="btn btn-primary" href="<?= e($basePath) ?>/admin/dashboard.php#dashboard-person-search">Find a person</a>
                </div>
            <div class="admin-queue-total" aria-label="<?= (int) $totalRows ?> requests match the current filters">
                <strong><?= (int) $totalRows ?></strong>
                <span><?= $totalRows === 1 ? 'request' : 'requests' ?></span>
            </div>
        </header>

        <div class="admin-list-toolbar">
                <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
                <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        </div>

        <?php if (empty($requests)): ?>
            <p class="admin-queue-empty">No certificate requests match your search or filters. Try broadening your search.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'request') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'request', $sort)) ?>">Reference No.<?= admin_sort_indicator('request', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'sacrament') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'sacrament', $sort)) ?>">Sacrament<?= admin_sort_indicator('sacrament', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'subject') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'subject', $sort)) ?>">Subject<?= admin_sort_indicator('subject', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'requester') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'requester', $sort)) ?>">Requester<?= admin_sort_indicator('requester', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'purpose') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'purpose', $sort)) ?>">Requested use<?= admin_sort_indicator('purpose', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'channel') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'channel', $sort)) ?>">Channel<?= admin_sort_indicator('channel', $sort) ?></a></th>
                    <th class="column-date"><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'date', $sort)) ?>">Submitted<?= admin_sort_indicator('date', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'status') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'status', $sort)) ?>">Status<?= admin_sort_indicator('status', $sort) ?></a></th>
                    <th class="row-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req): ?>
                    <tr>
                        <td>
                            <strong><?= e($req['request_no']) ?></strong>
                        </td>
                        <td><?= e(humanize_label($req['sacrament_type'])) ?></td>
                        <td><?= e($req['subject_name']) ?></td>
                        <td><?= e($req['requester_name']) ?><br><small><?= e($req['requester_email']) ?></small></td>
                        <td><?= empty($req['purpose']) ? 'Not specified' : e(humanize_label($req['purpose'])) ?><?= $req['purpose'] === 'other' ? ' — ' . e($req['purpose_other']) : '' ?></td>
                        <td><?= e(ucfirst($req['channel'])) ?></td>
                        <td class="column-date"><time datetime="<?= e(date('c', strtotime($req['created_at']))) ?>"><?= e(date('M j, Y', strtotime($req['created_at']))) ?></time></td>
                        <td><span class="status-badge status-<?= e($req['status']) ?>"><?= e(status_label('certificate', (string) $req['status'])) ?></span></td>
                        <td class="row-actions">
                            <a href="<?= e($basePath . '/admin/requests/certificate-detail.php?id=' . (int) $req['id']) ?>" class="btn btn-small">Review</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';