<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');
$basePath = $basePath ?? base_path();
$pageTitle = 'Online Submissions';
$channel = 'online';
$certificateRows = $pdo->query("SELECT id, tracking_number, sacrament_type, requester_name AS party, requester_email, status, date_submitted, 'certificate' AS kind FROM certificate_requests WHERE submission_channel = 'online'")->fetchAll();
$applicationRows = $pdo->query(
    "SELECT sa.id, sa.tracking_number, sa.sacrament_type, sa.status, sa.date_submitted,
            'application' AS kind,
            COALESCE(ba.child_full_name, ca.applicant_full_name, fca.applicant_full_name,
                     CONCAT(ma.groom_full_name, ' & ', ma.bride_full_name)) AS party
     FROM sacrament_applications sa
     LEFT JOIN baptismal_applications ba ON ba.application_id = sa.id
     LEFT JOIN confirmation_applications ca ON ca.application_id = sa.id
     LEFT JOIN first_communion_applications fca ON fca.application_id = sa.id
     LEFT JOIN marriage_applications ma ON ma.application_id = sa.id
     WHERE sa.submission_channel = 'online'"
)->fetchAll();
$rows = array_merge($certificateRows, $applicationRows);
usort($rows, static function (array $a, array $b): int { return strcmp((string) $b['date_submitted'], (string) $a['date_submitted']); });
$detailMap = [
    'certificate' => 'certificate-detail.php',
    'baptismal' => 'baptismal-detail.php',
    'marriage' => 'marriage-detail.php',
    'confirmation' => 'confirmation-detail.php',
    'first_communion' => 'first-communion-detail.php',
];
function online_label(string $value): string { return ucwords(str_replace('_', ' ', $value)); }
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="admin-list-page online-submissions-page">
<header class="admin-queue-heading">
    <div>
        <p class="admin-queue-kicker">Requests</p>
        <h1>Online submissions</h1>
        <p>Review public requests and applications before taking the next step.</p>
    </div>
    <div class="admin-queue-total" aria-label="<?= count($rows) ?> online submissions">
        <strong><?= count($rows) ?></strong>
        <span><?= count($rows) === 1 ? 'submission' : 'submissions' ?></span>
    </div>
</header>
<div class="online-submission-notice" role="note">
    <strong>Important: online submissions are pre-applications only.</strong>
    Review requirements and verify identity onsite before approving or processing anything. They do not become official parish records automatically.
</div>
<div class="admin-actions"><a class="btn btn-secondary btn-small" href="<?= $basePath ?>/admin/requests/queue.php?channel=online">Certificate Requests</a><a class="btn btn-secondary btn-small" href="<?= $basePath ?>/admin/requests/applications-queue.php?channel=online">Sacrament Applications</a></div>
<?php if (empty($rows)): ?>
    <p class="admin-queue-empty">No online submissions have been received yet.</p>
<?php else: ?>
<div class="table-scroll">
<table class="admin-table">
<thead><tr><th>Tracking No.</th><th>Type</th><th>Requester / Application</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($rows as $row):
    $target = $row['kind'] === 'certificate' ? $detailMap['certificate'] : ($detailMap[$row['sacrament_type']] ?? 'applications-queue.php');
    $href = $basePath . '/admin/requests/' . $target . '?id=' . (int) $row['id'];
?>
<tr>
    <td><?= e($row['tracking_number']) ?></td>
    <td><?= e(online_label($row['kind'])) ?> · <?= e(online_label($row['sacrament_type'])) ?></td>
    <td><?= e($row['party'] ?: 'Application details unavailable') ?><?php if (!empty($row['requester_email'])): ?><br><small><?= e($row['requester_email']) ?></small><?php endif; ?></td>
    <td><?= e(date('M j, Y g:i A', strtotime($row['date_submitted']))) ?></td>
    <td><span class="status-badge status-<?= e($row['status']) ?>"><?= e(online_label($row['status'])) ?></span></td>
    <td class="row-actions"><a class="btn btn-small" href="<?= e($href) ?>">Review</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/admin-footer.php';
