<?php
/**
 * =====================================================================
 * SPPO-IS CERTIFICATE REQUESTS QUEUE (admin)
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

$pageTitle = 'Certificate Requests Queue';

// Extension table + "primary name" column per sacrament type, used to show
// who the request is about without hardcoding five separate queries.
const CERT_EXT = [
    'baptismal'        => ['table' => 'baptismal_certificate_requests',        'name_col' => 'child_full_name'],
    'confirmation'      => ['table' => 'confirmation_certificate_requests',     'name_col' => 'confirmand_full_name'],
    'first_communion'   => ['table' => 'first_communion_certificate_requests',  'name_col' => 'communicant_full_name'],
    'marriage'          => ['table' => 'marriage_certificate_requests',         'name_col' => 'husband_full_name'],
];

$statusOptions = ['pending', 'under_review', 'approved', 'ready_for_pickup', 'released', 'rejected'];

// --- Handle status update ------------------------------------------------
// CSRF: forged or stale POSTs are dropped silently (fail closed) - the
// queue simply re-renders with no status change applied.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    redirect_to('/admin/requests/queue.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && csrf_verify()) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';

    if ($requestId && in_array($newStatus, $statusOptions, true)) {
        $stmt = $pdo->prepare(
            "UPDATE certificate_requests SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?"
        );
        $stmt->execute([$newStatus, current_user()['id'], $requestId]);

        log_activity($pdo, current_user()['id'], 'certificate_request_status_updated', 'certificate_requests', $requestId,
            "Status changed to {$newStatus}");
    }
    redirect_to('/admin/requests/queue.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}

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

$stmt = $pdo->prepare("SELECT * FROM certificate_requests {$whereSql} ORDER BY date_submitted DESC");
$stmt->execute($params);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= e($pageTitle) ?> | SPPO-IS</title>
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css">
</head>
<body>
<div class="admin-layout">
    <main class="admin-content">
        <p><a href="<?= $basePath ?>/admin/dashboard.php">&larr; Back to Dashboard</a></p>
        <h1>Certificate Requests Queue</h1>

        <form method="get" class="inline-fields filter-form">
            <label>Status
                <select name="status" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach ($statusOptions as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Sacrament
                <select name="sacrament" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach (array_keys(CERT_EXT) as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filterSacrament === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

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
                    <th>ID</th>
                    <th>Update</th>
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
                        <td><span class="field-hint" style="margin:0;">Verified at pickup</span></td>
                        <td>
                            <form method="post" class="inline-fields">
                                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                <select name="new_status">
                                    <?php foreach ($statusOptions as $s): ?>
                                        <option value="<?= e($s) ?>" <?= $req['status'] === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_status" value="1" class="btn btn-small">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
