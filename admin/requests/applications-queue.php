<?php
/**
 * =====================================================================
 * SPPO-IS SACRAMENT APPLICATIONS QUEUE (admin)
 * =====================================================================
 * Purpose: The secretary's worklist of sacrament applications (public
 *          walk-in and online submissions). Each row can be moved
 *          through the workflow statuses (submitted → documents →
 *          interview → banns → seminar → approval → scheduled →
 *          completed / rejected).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Sacrament Applications Queue';

$statusOptions = [
    'submitted', 'documents_pending', 'interview_scheduled', 'banns_in_progress',
    'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected',
];

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

// --- Handle status update ------------------------------------------------
// CSRF: forged or stale POSTs are dropped silently (fail closed) - the
// queue simply re-renders with no status change applied.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    redirect_to('/admin/requests/applications-queue.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && csrf_verify()) {
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $newStatus     = $_POST['new_status'] ?? '';

    if ($applicationId && in_array($newStatus, $statusOptions, true)) {
        $stmt = $pdo->prepare(
            "UPDATE sacrament_applications SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?"
        );
        $stmt->execute([$newStatus, current_user()['id'], $applicationId]);

        log_activity($pdo, current_user()['id'], 'sacrament_application_status_updated', 'sacrament_applications', $applicationId,
            "Status changed to {$newStatus}");
    }
    redirect_to('/admin/requests/applications-queue.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}

// --- Filters ---------------------------------------------------------------
$filterStatus    = $_GET['status'] ?? '';
$filterSacrament = $_GET['sacrament'] ?? '';
$sacramentTypes  = ['baptismal', 'confirmation', 'first_communion', 'marriage'];

$where  = [];
$params = [];
if ($filterStatus !== '' && in_array($filterStatus, $statusOptions, true)) {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}
if ($filterSacrament !== '' && in_array($filterSacrament, $sacramentTypes, true)) {
    $where[] = 'sacrament_type = ?';
    $params[] = $filterSacrament;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM sacrament_applications {$whereSql} ORDER BY date_submitted DESC");
$stmt->execute($params);
$applications = $stmt->fetchAll();

// Fetch sacrament-specific applicant name + flags for each row.
foreach ($applications as &$app) {
    $app['subject_name']  = null;
    $app['flagged']       = false;
    $app['flag_reason']   = [];
    $app['id_view_urls']  = []; // [label => url]

    if ($app['sacrament_type'] === 'baptismal') {
        $s = $pdo->prepare("SELECT child_full_name FROM baptismal_applications WHERE application_id = ?");
        $s->execute([$app['id']]);
        $row = $s->fetch();
        if ($row) {
            $app['subject_name'] = $row['child_full_name'];
        }
    } elseif ($app['sacrament_type'] === 'confirmation') {
        $s = $pdo->prepare("SELECT applicant_full_name, age_eligibility_flag, baptismal_cert_status FROM confirmation_applications WHERE application_id = ?");
        $s->execute([$app['id']]);
        $row = $s->fetch();
        if ($row) {
            $app['subject_name'] = $row['applicant_full_name'];
            if ($row['age_eligibility_flag']) { $app['flagged'] = true; $app['flag_reason'][] = 'outside age range'; }
            if ($row['baptismal_cert_status'] === 'pending') { $app['flag_reason'][] = 'baptismal cert not yet submitted'; }
        }
    } elseif ($app['sacrament_type'] === 'first_communion') {
        $s = $pdo->prepare("SELECT applicant_full_name, age_eligibility_flag, baptismal_cert_status FROM first_communion_applications WHERE application_id = ?");
        $s->execute([$app['id']]);
        $row = $s->fetch();
        if ($row) {
            $app['subject_name'] = $row['applicant_full_name'];
            if ($row['age_eligibility_flag']) { $app['flagged'] = true; $app['flag_reason'][] = 'outside age range'; }
            if ($row['baptismal_cert_status'] === 'pending') { $app['flag_reason'][] = 'baptismal cert not yet submitted'; }
        }
    } elseif ($app['sacrament_type'] === 'marriage') {
        $s = $pdo->prepare(
            "SELECT groom_full_name, bride_full_name, groom_baptismal_cert_status, bride_baptismal_cert_status,
                    groom_confirmation_cert_status, bride_confirmation_cert_status, preferred_wedding_date
             FROM marriage_applications WHERE application_id = ?"
        );
        $s->execute([$app['id']]);
        $row = $s->fetch();
        if ($row) {
            $app['subject_name'] = $row['groom_full_name'] . ' & ' . $row['bride_full_name'];
            $app['wedding_date'] = $row['preferred_wedding_date'];
            foreach (['groom_baptismal_cert_status', 'bride_baptismal_cert_status', 'groom_confirmation_cert_status', 'bride_confirmation_cert_status'] as $col) {
                if ($row[$col] === 'pending') {
                    $app['flag_reason'][] = str_replace('_', ' ', $col) . ' pending';
                }
            }
            if (!empty($app['flag_reason'])) $app['flagged'] = true;
        }
    }
}
unset($app);
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Sacrament Applications Queue</h1>

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
                    <?php foreach ($sacramentTypes as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filterSacrament === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <?php if (empty($applications)): ?>
            <p>No sacrament applications match this filter.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Sacrament</th>
                    <th>Applicant(s)</th>
                    <th>Submitted</th>
                    <th>Flags</th>
                    <th>Actions</th>
                    <th>Status</th>
                    <th>Update</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                    <tr class="<?= $app['flagged'] ? 'row-flagged' : '' ?>">
                        <td><?= e($app['tracking_number']) ?></td>
                        <td><?= e(humanize_label($app['sacrament_type'])) ?></td>
                        <td><?= e($app['subject_name'] ?? '—') ?></td>
                        <td><?= e(date('M j, Y', strtotime($app['date_submitted']))) ?></td>
                        <td>
                            <?php if ($app['flagged']): ?>
                                <span class="flag-badge" title="<?= e(implode('; ', $app['flag_reason'])) ?>">&#9873; For review</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($app['sacrament_type'] === 'marriage'): ?>
                                <a href="<?= $basePath ?>/admin/requests/marriage-detail.php?id=<?= (int) $app['id'] ?>" class="btn btn-secondary btn-small">Detail</a>
                            <?php endif; ?>
                        </td>
                        <td><span class="status-badge status-<?= e($app['status']) ?>"><?= e(humanize_label($app['status'])) ?></span></td>
                        <td>
                            <form method="post" class="inline-fields">
                                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <select name="new_status">
                                    <?php foreach ($statusOptions as $s): ?>
                                        <option value="<?= e($s) ?>" <?= $app['status'] === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_status" value="1" class="btn btn-small">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="field-hint">Flagged rows (age outside configured range, prerequisite documents pending, etc.) are shown for review — they are never auto-rejected. <?php if ($filterSacrament === 'marriage' || $filterSacrament === ''): ?>Marriage applications use the <a href="<?= $basePath ?>/admin/requests/marriage-detail.php">Marriage Detail</a> page for clearance recording and approval.<?php endif; ?></p>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';