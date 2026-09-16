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

$where  = [];
$params = [];
if ($filterStatus !== '' && in_array($filterStatus, $statusOptions, true)) {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
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
    'placeholder'    => 'Search tracking no., requester, email&hellip;',
    'ranges'         => ['today', 'week', 'month', 'year'],
    'filters'        => [
        'status'    => ['label' => 'Status',
            'options' => [
                'pending'         => 'Pending',
                'under_review'    => 'Under review',
                'approved'        => 'Approved',
                'ready_for_pickup'=> 'Ready for pickup',
                'released'        => 'Released',
                'rejected'        => 'Rejected',
            ]],
        'sacrament' => ['label' => 'Sacrament',
            'options' => [
                'baptismal'      => 'Baptismal',
                'confirmation'   => 'Confirmation',
                'first_communion'=> 'First Communion',
                'marriage'       => 'Marriage',
                'death_burial'   => 'Death / Burial',
            ]],
    ],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$combinedWhere = $rf['where'];
if ($whereSql !== '') {
    $combinedWhere = $combinedWhere === '' ? "WHERE $where[0]" : "$combinedWhere AND $where[0]";
}
// Handle multiple where clauses
if (count($where) > 1) {
    $extraStr = implode(' AND ', $where);
    $combinedWhere = $rf['where'] === '' ? "WHERE $extraStr" : $rf['where'] . " AND $extraStr";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM certificate_requests $combinedWhere");
$countStmt->execute(array_merge($rf['params'], $params));
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM certificate_requests $combinedWhere ORDER BY date_submitted DESC LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$stmt->execute(array_merge($rf['params'], $params));
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
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Sacrament</th>
                    <th>Subject</th>
                    <th>Requester</th>
                    <th>Purpose</th>
                    <th>Submitted</th>
                    <th>Status</th>
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
                        <td><?= e(date('M j, Y', strtotime($req['date_submitted']))) ?></td>
                        <td><span class="status-badge status-<?= e($req['status']) ?>"><?= e(humanize_label($req['status'])) ?></span></td>
                        <td>
                            <a href="<?= $basePath ?>/admin/requests/certificate-detail.php?id=<?= (int) $req['id'] ?>" class="btn btn-small">Review</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';