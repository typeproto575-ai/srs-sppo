<?php
/**
 * SRS-SPPO FIRST COMMUNION APPLICATION DETAIL (admin)
 * Drill-down view for one first communion sacrament application.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$applicationId = (int) ($_GET['id'] ?? 0);
if (!$applicationId) { redirect_to('/admin/requests/applications-queue.php'); }

$notice = null;
$stmt = $pdo->prepare("SELECT sa.*, fca.* FROM sacrament_applications sa JOIN first_communion_applications fca ON fca.application_id = sa.id WHERE sa.id = ? AND sa.sacrament_type = 'first_communion'");
$stmt->execute([$applicationId]);
$app = $stmt->fetch();
if (!$app) { redirect_to('/admin/requests/applications-queue.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && isset($_POST['update_status'])) {
    $newStatus = $_POST['new_status'] ?? '';
    $validStatuses = ['submitted', 'documents_pending', 'interview_scheduled', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'];
    if (in_array($newStatus, $validStatuses, true)) {
        $stmt = $pdo->prepare("UPDATE sacrament_applications SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?");
        $stmt->execute([$newStatus, current_user()['id'], $applicationId]);
        log_activity($pdo, current_user()['id'], 'sacrament_application_status_updated', 'sacrament_applications', $applicationId, "Status changed to {$newStatus}");
        $notice = 'Status updated.';
        $stmt = $pdo->prepare("SELECT sa.*, fca.* FROM sacrament_applications sa JOIN first_communion_applications fca ON fca.application_id = sa.id WHERE sa.id = ?");
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
    }
}

function humanize_label(string $s): string { return ucwords(str_replace('_', ' ', $s)); }
$statusOptions = ['submitted', 'documents_pending', 'interview_scheduled', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'];
require_once __DIR__ . '/../../includes/admin-header.php';
?><p><a href="<?= $basePath ?>/admin/requests/applications-queue.php">&larr; Back to Queue</a></p>
<h1>First Communion Application Detail</h1>
<?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>

<h2>Applicant</h2>
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Full Name</th><td><?= e($app['applicant_full_name']) ?></td></tr>
<tr><th>Birthdate</th><td><?= e($app['applicant_birthdate'] ? date('F j, Y', strtotime($app['applicant_birthdate'])) : '—') ?></td></tr>
<tr><th>Age Eligibility Flag</th><td><?= $app['age_eligibility_flag'] ? '<span class="flag-badge">Outside configured range</span>' : 'Within range' ?></td></tr>
</tbody></table>

<h2>Parent / Guardian</h2>
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Name</th><td><?= e($app['parent_guardian_name']) ?></td></tr>
<tr><th>Contact Number</th><td><?= e($app['contact_number']) ?></td></tr>
<tr><th>Email</th><td><?= e($app['email']) ?></td></tr>
</tbody></table>

<h2>Sacramental Prerequisites</h2>
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Baptized Here</th><td><?= $app['baptized_here'] ? 'Yes' : 'No' ?></td></tr>
<tr><th>Baptismal Record</th><td><?= $app['baptismal_record_id'] ? '#' . (int) $app['baptismal_record_id'] : '—' ?></td></tr>
<tr><th>Baptismal Cert Status</th><td><?= e(humanize_label($app['baptismal_cert_status'])) ?></td></tr>
<tr><th>Preferred Batch</th><td><?= e($app['preferred_batch'] ?: '—') ?></td></tr>
</tbody></table>

<h2>Application Details</h2>
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Tracking Number</th><td><?= e($app['tracking_number']) ?></td></tr>
<tr><th>Status</th><td><span class="status-badge status-<?= e($app['status']) ?>"><?= e(humanize_label($app['status'])) ?></span></td></tr>
<tr><th>Date Submitted</th><td><?= e(date('F j, Y g:i A', strtotime($app['date_submitted']))) ?></td></tr>
<?php if ($app['notes']): ?><tr><th>Notes</th><td><?= e($app['notes']) ?></td></tr><?php endif; ?>
</tbody></table>

<h2>Update Status</h2>
<form method="post" class="inline-fields">
<?= csrf_field() ?>
<input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
<select name="new_status">
<?php foreach ($statusOptions as $s): ?>
<option value="<?= e($s) ?>" <?= $app['status'] === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
<?php endforeach; ?>
</select>
<button type="submit" name="update_status" value="1" class="btn btn-small">Update</button>
</form>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>