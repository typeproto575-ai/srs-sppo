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
    'death_burial'      => ['table' => 'death_burial_certificate_requests',     'name_col' => 'deceased_full_name'],
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

        // Notify the requester (email) when their certificate is ready for
        // pickup, or when it has been released at the counter. Safe no-op and
        // logged when mail is enabled; never blocks the status change.
        if ($newStatus === 'ready_for_pickup' || $newStatus === 'released') {
            $mailStmt = $pdo->prepare('SELECT requester_email, tracking_number, sacrament_type FROM certificate_requests WHERE id = ?');
            $mailStmt->execute([$requestId]);
            $mailRow = $mailStmt->fetch();
            $mailTo = trim((string) ($mailRow['requester_email'] ?? ''));
            if ($mailTo !== '') {
                $kind = ucwords(str_replace('_', ' ', (string) ($mailRow['sacrament_type'] ?? ''))) . ' Certificate';
                if (mail_status_update($mailTo, (string) $mailRow['tracking_number'], humanize_label($newStatus), $kind)) {
                    log_activity($pdo, current_user()['id'], 'status_email_sent', 'certificate_requests', $requestId,
                        "Status email sent to {$mailTo}");
                }
            }
        }
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

// --- Search / filter / pagination --
$selfUrl = $basePath . '/admin/requests/queue.php';
$rfConfig = ['date_column' => 'date_submitted', 'search_columns' => ['tracking_number', 'requester_name', 'requester_email']];
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
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';