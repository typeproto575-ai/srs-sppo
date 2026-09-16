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
$prereqNotice = null;
$prereqMissing = [];

// Save prerequisite flag (seminar attended) — separate from a status change.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && isset($_POST['save_prereqs'])) {
    $semFlag = isset($_POST['seminar_attended']) ? 1 : 0;
    $up = $pdo->prepare("UPDATE first_communion_applications SET seminar_attended = ? WHERE application_id = ?");
    $up->execute([$semFlag, $applicationId]);
    log_activity($pdo, current_user()['id'], 'sacrament_application_prereqs_updated', 'sacrament_applications', $applicationId, "Prerequisites saved: seminar_attended={$semFlag}.");
    $notice = 'Prerequisites saved.';
    $stmt = $pdo->prepare("SELECT sa.*, fca.* FROM sacrament_applications sa JOIN first_communion_applications fca ON fca.application_id = sa.id WHERE sa.id = ? AND sa.sacrament_type = 'first_communion'");
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch();
}

// Status change: transition validated per-sacrament; 'approved' is gated on
// can_approve_sacrament_application(). Rejections/blocked approvals are logged.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && isset($_POST['update_status'])) {
    $newStatus = $_POST['new_status'] ?? '';
    $allowed = in_array($newStatus, ['submitted', 'documents_pending', 'interview_scheduled', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'], true);
    if (!$allowed) {
        $notice = 'Invalid status value.';
    } elseif (!sacrament_application_transition_valid($app['status'], $newStatus, 'first_communion')) {
        $notice = 'Status change refused: ' . humanize_label($app['status']) . ' to ' . humanize_label($newStatus) . ' is not a valid transition.';
        log_activity($pdo, current_user()['id'], 'sacrament_application_status_rejected', 'sacrament_applications', $applicationId, "Status change refused: {$app['status']} -> {$newStatus}.");
    } elseif ($newStatus === 'approved') {
        $gate = can_approve_sacrament_application($pdo, $applicationId, 'first_communion');
        if (!$gate['ok']) {
            $prereqNotice = 'Cannot approve yet — missing prerequisites:';
            $prereqMissing = $gate['missing'];
            log_activity($pdo, current_user()['id'], 'sacrament_application_approval_blocked', 'sacrament_applications', $applicationId, 'Approval blocked; missing: ' . implode(' ', $gate['missing']));
        } else {
            $stmt = $pdo->prepare("UPDATE sacrament_applications SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?");
            $stmt->execute([$newStatus, current_user()['id'], $applicationId]);
            log_activity($pdo, current_user()['id'], 'sacrament_application_status_updated', 'sacrament_applications', $applicationId, "Status changed to {$newStatus}");
            notify_sacrament_application_status($pdo, $newStatus, $app['sacrament_type'], $app['tracking_number']);
            $notice = 'Status updated.';
            $stmt = $pdo->prepare("SELECT sa.*, fca.* FROM sacrament_applications sa JOIN first_communion_applications fca ON fca.application_id = sa.id WHERE sa.id = ?");
            $stmt->execute([$applicationId]);
            $app = $stmt->fetch();
        }
    } else {
        $stmt = $pdo->prepare("UPDATE sacrament_applications SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?");
        $stmt->execute([$newStatus, current_user()['id'], $applicationId]);
        log_activity($pdo, current_user()['id'], 'sacrament_application_status_updated', 'sacrament_applications', $applicationId, "Status changed to {$newStatus}");
        notify_sacrament_application_status($pdo, $newStatus, $app['sacrament_type'], $app['tracking_number']);
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

<?php if ($prereqNotice): ?>
    <div class="alert alert-warning"><p><?= e($prereqNotice) ?></p>
    <ul><?php foreach ($prereqMissing as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<h2>Prerequisites for Approval</h2>
<?php
$fcGate = can_approve_sacrament_application($pdo, $applicationId, 'first_communion');
?>
<?php if ($fcGate['ok']): ?>
<p class="field-hint">All prerequisites met — this application can be approved.</p>
<?php else: ?>
<ul class="field-hint"><?php foreach ($fcGate['missing'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<form method="post" class="inline-fields">
<?= csrf_field() ?>
<label style="display:flex;align-items:center;gap:0.5rem;">
    <input type="checkbox" name="seminar_attended" value="1" <?= !empty($app['seminar_attended']) ? 'checked' : '' ?>>
    Seminar completed
</label>
<button type="submit" name="save_prereqs" value="1" class="btn btn-small">Save Prerequisites</button>
</form>

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