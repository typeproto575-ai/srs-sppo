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
require_once __DIR__ . '/../../includes/mail.php'; // status notification email
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Certificate Requests Queue';

// Extension table + "primary name" column per sacrament type, used to show
// who the request is about without hardcoding five separate queries.
const CERT_EXT = [
    'baptismal'        => ['table' => 'baptismal_certificate_requests',        'name_col' => 'child_full_name'],
    'confirmation'      => ['table' => 'confirmation_certificate_requests',     'name_col' => 'confirmand_full_name'],
    'first_communion'   => ['table' => 'first_communion_certificate_requests',  'name_col' => 'communicant_full_name'],
    'marriage'          => ['table' => 'marriage_certificate_requests',         'name_col' => 'party_a_full_name'],
];

// Status changes are handled on certificate-detail.php (which enforces
// transition validation and captures rejection reasons). The queue is a
// read-only navigational list — no inline POST handler here.


// --- Filters ---------------------------------------------------------------
$filterStatus = $_GET['status'] ?? '';
$filterSacrament = $_GET['sacrament'] ?? '';
$filterChannel = $_GET['channel'] ?? '';
$allowedRequestStatuses = ['pending', 'submitted', 'under_review', 'awaiting_payment', 'requirements_pending', 'requirements_incomplete', 'awaiting_onsite_verification', 'approved', 'processing', 'ready_for_pickup', 'released', 'rejected', 'cancelled'];

$where  = [];
$params = [];
if ($filterStatus !== '' && in_array($filterStatus, $allowedRequestStatuses, true)) {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}
if ($filterChannel !== '' && in_array($filterChannel, ['online', 'onsite'], true)) {
    $where[] = 'submission_channel = ?';
    $params[] = $filterChannel;
}
if ($filterSacrament !== '' && array_key_exists($filterSacrament, CERT_EXT)) {
    $where[] = 'sacrament_type = ?';
    $params[] = $filterSacrament;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// --- Search / filter / pagination --
$selfUrl = $basePath . '/admin/requests/queue.php';
$rfConfig = [
    'date_column'    => 'date_submitted',
    'search_columns' => ['tracking_number', 'requester_name', 'requester_email'],
    'placeholder'    => 'Search tracking no., requester, email...',
    'ranges'         => ['today', 'week', 'month', 'year'],
    'filters'        => [
        'status'    => ['label' => 'Status',
            'options' => [
                'pending'         => 'Pending',
                'submitted'      => 'Submitted',
                'awaiting_payment' => 'Awaiting payment',
                'under_review'    => 'Under review',
                'requirements_pending' => 'Requirements pending',
                'requirements_incomplete' => 'Requirements incomplete',
                'awaiting_onsite_verification' => 'Awaiting onsite verification',
                'approved'        => 'Approved',
                'ready_for_pickup'=> 'Ready for pickup',
                'released'        => 'Released',
                'rejected'        => 'Rejected',
                'cancelled'       => 'Cancelled',
            ]],
        'channel'   => ['label' => 'Channel', 'options' => ['online' => 'Online', 'onsite' => 'Onsite']],
        'sacrament' => ['label' => 'Sacrament',
            'options' => [
                'baptismal'      => 'Baptismal',
                'confirmation'   => 'Confirmation',
                'first_communion'=> 'First Communion',
                'marriage'       => 'Marriage',
            ]],
    ],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$sort = $_GET['sort'] ?? 'date_desc';
$allowedSorts = ['tracking', 'tracking_desc', 'sacrament', 'sacrament_desc', 'requester', 'requester_desc', 'purpose', 'purpose_desc', 'channel', 'channel_desc', 'date_desc', 'date_asc', 'status', 'status_desc'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'date_desc';
$orderClauses = [
    'tracking' => 'tracking_number ASC, id ASC', 'tracking_desc' => 'tracking_number DESC, id DESC',
    'sacrament' => 'sacrament_type ASC, id ASC', 'sacrament_desc' => 'sacrament_type DESC, id DESC',
    'requester' => 'requester_name ASC, id ASC', 'requester_desc' => 'requester_name DESC, id DESC',
    'purpose' => 'purpose ASC, id ASC', 'purpose_desc' => 'purpose DESC, id DESC',
    'channel' => 'submission_channel ASC, id ASC', 'channel_desc' => 'submission_channel DESC, id DESC',
    'date_desc' => 'date_submitted DESC, id DESC', 'date_asc' => 'date_submitted ASC, id ASC',
    'status' => 'status ASC, id ASC', 'status_desc' => 'status DESC, id DESC',
];
$orderClause = $orderClauses[$sort];

$allClauses = [];
$allParams = [];

if ($rf['where'] !== '') {
    $allClauses[] = ltrim($rf['where'], ' AND');
    $allParams = array_merge($allParams, $rf['params']);
}

if ($where !== []) {
    $allClauses[] = '(' . implode(' AND ', $where) . ')';
    $allParams = array_merge($allParams, $params);
}

$combinedWhere = $allClauses === [] ? '' : ' WHERE ' . implode(' AND ', $allClauses);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM certificate_requests{$combinedWhere}");
$countStmt->execute($allParams);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM certificate_requests{$combinedWhere} ORDER BY {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$stmt->execute($allParams);
$requests = $stmt->fetchAll();

// Fetch the sacrament-specific "who this is about" name for each row.
foreach ($requests as &$req) {
    $ext = CERT_EXT[$req['sacrament_type']] ?? null;
    $req['subject_name'] = null;
    if ($ext) {
        $s = $pdo->prepare("SELECT {$ext['name_col']} AS subject_name FROM {$ext['table']} WHERE request_id = ?");
        $s->execute([$req['id']]);
        $row = $s->fetch();
        $req['subject_name'] = $row['subject_name'] ?? null;
    }
}
unset($req);

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Certificate Requests Queue</h1>

                <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <?php if (empty($requests)): ?>
            <p>No certificate requests match this filter.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'tracking') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'tracking', $sort)) ?>">Tracking No.<?= admin_sort_indicator('tracking', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'sacrament') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'sacrament', $sort)) ?>">Sacrament<?= admin_sort_indicator('sacrament', $sort) ?></a></th>
                    <th>Subject</th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'requester') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'requester', $sort)) ?>">Requester<?= admin_sort_indicator('requester', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'purpose') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'purpose', $sort)) ?>">Purpose<?= admin_sort_indicator('purpose', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'channel') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'channel', $sort)) ?>">Channel<?= admin_sort_indicator('channel', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'date', $sort)) ?>">Submitted<?= admin_sort_indicator('date', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'status') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'status', $sort)) ?>">Status<?= admin_sort_indicator('status', $sort) ?></a></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req): ?>
                    <tr>
                        <td><?= e($req['tracking_number']) ?></td>
                        <td><?= e(humanize_label($req['sacrament_type'])) ?></td>
                        <td><?= e($req['subject_name'] ?? '—') ?></td>
                        <td><?= e($req['requester_name']) ?><br><small><?= e($req['requester_email']) ?></small></td>
                        <td><?= e(humanize_label($req['purpose'])) ?><?= $req['purpose'] === 'other' ? ' — ' . e($req['purpose_other']) : '' ?></td>
                        <td><?= e(ucfirst($req['submission_channel'] ?? 'onsite')) ?></td>
                        <td><?= e(date('M j, Y', strtotime($req['date_submitted']))) ?></td>
                        <td><span class="status-badge status-<?= e($req['status']) ?>"><?= e(humanize_label($req['status'])) ?></span></td>
                        <td class="row-actions">
                            <?php
                            $nextAction = 'Review';
                            $nextUrl = $basePath . '/admin/requests/certificate-detail.php?id=' . (int) $req['id'];
                            if ($req['status'] === 'approved') {
                                $nextAction = 'Preview / Generate';
                                $nextUrl = $basePath . '/admin/certificates/generate.php?preview=' . (int) $req['id'];
                            } elseif ($req['status'] === 'ready_for_pickup') {
                                $generationCheck = $pdo->prepare(
                                    'SELECT id FROM certificate_generation_log WHERE certificate_request_id = ? AND superseded_at IS NULL ORDER BY id DESC LIMIT 1'
                                );
                                $generationCheck->execute([(int) $req['id']]);
                                if ($generationCheck->fetchColumn()) {
                                    $nextAction = 'Print / Pickup';
                                    $nextUrl = $basePath . '/admin/certificates/history.php?request_id=' . (int) $req['id'];
                                } else {
                                    $nextAction = 'Preview / Generate';
                                    $nextUrl = $basePath . '/admin/certificates/generate.php?preview=' . (int) $req['id'];
                                }
                            }
                            ?>
                            <a href="<?= e($nextUrl) ?>" class="btn btn-small<?= $req['status'] === 'ready_for_pickup' ? ' btn-secondary' : '' ?>"><?= e($nextAction) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';