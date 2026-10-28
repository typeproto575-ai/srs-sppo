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
    $allowed = in_array($newStatus, ['submitted', 'awaiting_payment', 'requirements_pending', 'requirements_incomplete', 'awaiting_onsite_verification', 'documents_pending', 'interview_scheduled', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected', 'cancelled'], true);
    if (!$allowed) {
        $notice = 'Invalid status value.';
    } elseif (!sacrament_application_transition_valid($app['status'], $newStatus, 'first_communion')) {
        $notice = 'Status change refused: ' . humanize_label($app['status']) . ' to ' . humanize_label($newStatus) . ' is not a valid transition.';
        log_activity($pdo, current_user()['id'], 'sacrament_application_status_rejected', 'sacrament_applications', $applicationId, "Status change refused: {$app['status']} -> {$newStatus}.");
    } elseif (!sacrament_application_payment_gate($app, $newStatus)['ok']) {
        // Online application still awaiting payment: block until marked paid.
        $notice = sacrament_application_payment_gate($app, $newStatus)['reason'];
        log_activity($pdo, current_user()['id'], 'sacrament_application_status_rejected', 'sacrament_applications', $applicationId, "Status change blocked by payment gate: {$app['status']} -> {$newStatus}.");
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

// Mark an online application's payment as received (by hand). Records only
// WHO and WHEN -- never an amount. Only valid while awaiting payment and not
// yet recorded. This unblocks the payment gate in the status handler above.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && isset($_POST['mark_paid'])) {
    if (($app['status'] ?? '') !== 'awaiting_payment') {
        $notice = "Only an application that is awaiting payment can be marked paid.";
    } elseif (!empty($app['paid_at'])) {
        $notice = 'Payment has already been recorded for this application.';
    } else {
        $up = $pdo->prepare("UPDATE sacrament_applications SET paid_at = NOW(), paid_by = ? WHERE id = ?");
        $up->execute([current_user()['id'], $applicationId]);
        log_activity($pdo, current_user()['id'], 'sacrament_application_payment_recorded', 'sacrament_applications', $applicationId, 'Payment recorded by hand (no amount stored).');
        $notice = 'Payment recorded. The application can now move forward.';
        $stmt = $pdo->prepare("SELECT sa.*, fca.* FROM sacrament_applications sa JOIN first_communion_applications fca ON fca.application_id = sa.id WHERE sa.id = ?");
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
    }
}

$statusOptions = ['submitted', 'awaiting_payment', 'requirements_pending', 'requirements_incomplete', 'awaiting_onsite_verification', 'documents_pending', 'interview_scheduled', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected', 'cancelled'];
$workflowEntityType = 'sacrament_application';
$workflowEntityId = $applicationId;
$workflowSacramentType = 'first_communion';
require_once __DIR__ . '/../../includes/admin-header.php';
require __DIR__ . '/../../includes/workflow-checklist-panel.php';
?><p><a href="<?= $basePath ?>/admin/requests/applications-queue.php">&larr; Back to Queue</a></p>
<p class="field-hint"><strong>Submission channel:</strong> <?= e(ucfirst($app['submission_channel'] ?? 'onsite')) ?><?= ($app['submission_channel'] ?? 'onsite') === 'online' ? ' — public pre-application; not yet an official parish record' : '' ?></p>
    <h1>First Communion Application Detail</h1>
<?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>

<h2>Applicant</h2>
<?php
$applicantAge = $app['applicant_birthdate'] ? calculate_age($app['applicant_birthdate']) : null;
$firstCommunionAgeStatus = 'Birthdate not recorded';
if ($applicantAge !== null) {
    $rangeStmt = $pdo->prepare('SELECT min_age, max_age FROM sacrament_eligibility_settings WHERE sacrament_type = ?');
    $rangeStmt->execute(['first_communion']);
    $range = $rangeStmt->fetch() ?: ['min_age' => 0, 'max_age' => null];
    $firstCommunionAgeStatus = $applicantAge < (int) $range['min_age']
        ? 'Not yet age-eligible'
        : ($range['max_age'] !== null && $applicantAge > (int) $range['max_age']
            ? 'Outside configured range - secretary review'
            : 'Age-eligible');
}
?>
<div class="table-scroll">
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Full Name</th><td><?= e($app['applicant_full_name']) ?></td></tr>
<tr><th>Birthdate</th><td><?= e($app['applicant_birthdate'] ? date('F j, Y', strtotime($app['applicant_birthdate'])) : '—') ?></td></tr>
<tr><th>Gender / Birthplace</th><td><?= e(ucfirst($app['applicant_gender'] ?? '—')) ?><?= !empty($app['applicant_birthplace']) ? ' / ' . e($app['applicant_birthplace']) : '' ?></td></tr>
<tr><th>Address</th><td><?= e($app['current_address'] ?? '—') ?></td></tr>
<tr><th>School / Grade / Adviser</th><td><?= e(($app['school_name'] ?? '—') . ' / ' . ($app['grade_level_section'] ?? '—') . ' / ' . ($app['adviser_name'] ?? '—')) ?></td></tr>
<tr><th>Age status</th><td><?= e($firstCommunionAgeStatus) ?><?= $applicantAge !== null ? ' (' . (int) $applicantAge . ' years old)' : '' ?></td></tr>
</tbody></table>
</div>

<h2>Parent / Guardian</h2>
<div class="table-scroll">
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Name</th><td><?= e($app['parent_guardian_name']) ?></td></tr>
<tr><th>Contact Number</th><td><?= e($app['contact_number']) ?></td></tr>
<tr><th>Email</th><td><?= e($app['email']) ?></td></tr>
<tr><th>Father / Mother</th><td><?= e(($app['father_full_name'] ?? '—') . ' / ' . ($app['mother_maiden_name'] ?? '—')) ?></td></tr>
<tr><th>Parents' Catholic marriage</th><td><?= e(humanize_label($app['parents_catholic_married'] ?? '—')) ?></td></tr>
</tbody></table>
</div>

<h2>Sacramental Prerequisites</h2>
<div class="table-scroll">
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Baptized Here</th><td><?= $app['baptized_here'] ? 'Yes' : 'No' ?></td></tr>
<tr><th>Baptismal Record</th><td><?= $app['baptismal_record_id'] ? 'Record linked: #' . (int) $app['baptismal_record_id'] : 'No record linked yet' ?></td></tr>
<tr><th>First Communion Record</th><td>No record linked yet</td></tr>
<tr><th>Baptismal Cert Status</th><td><?= e(humanize_label($app['baptismal_cert_status'])) ?></td></tr>
<tr><th>Baptism details</th><td><?= e(($app['baptism_date'] ?? '—') . ' / ' . ($app['baptism_parish_name'] ?? '—') . ' / ' . ($app['baptism_parish_address'] ?? '—')) ?></td></tr>
<tr><th>Office checklist</th><td>PSA: <?= e(humanize_label($app['psa_birth_certificate_status'] ?? 'pending')) ?>; Catechetical: <?= e(humanize_label($app['catechetical_formation_status'] ?? 'pending')) ?>; Confession: <?= !empty($app['confession_completed']) ? 'Completed' : 'Pending' ?></td></tr>
<tr><th>Preferred Batch</th><td><?= e($app['preferred_batch'] ?: '—') ?></td></tr>
</tbody></table>
</div>

<h2>Application Details</h2>
<div class="table-scroll">
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Tracking Number</th><td><?= e($app['tracking_number']) ?></td></tr>
<tr><th>Status</th><td><span class="status-badge status-<?= e($app['status']) ?>"><?= e(humanize_label($app['status'])) ?></span></td></tr>
<tr><th>Date Submitted</th><td><?= e(date('F j, Y g:i A', strtotime($app['date_submitted']))) ?></td></tr>
<?php if ($app['notes']): ?><tr><th>Notes</th><td><?= e($app['notes']) ?></td></tr><?php endif; ?>
</tbody></table>
</div>
<?php if (!empty($app['person_id'])): ?>
<p class="field-hint">Linked master person: <a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $app['person_id']) ?>">View person profile</a></p>
<?php endif; ?>

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
<form autocomplete="off" method="post" class="inline-fields">
<?= csrf_field() ?>
<label style="display:flex;align-items:center;gap:0.5rem;">
    <input autocomplete="off" type="checkbox" name="seminar_attended" value="1" <?= !empty($app['seminar_attended']) ? 'checked' : '' ?>>
    Seminar completed
</label>
<button type="submit" name="save_prereqs" value="1" class="btn btn-small">Save Prerequisites</button>
</form>

<h2>Update Status</h2>
<?php if (($app['status'] ?? '') === 'awaiting_payment' && empty($app['paid_at'])): ?>
<form autocomplete="off" method="post" style="margin-bottom:0.75rem;" data-confirm="Record that payment for this application has been received (by hand)? No amount is stored.">
    <?= csrf_field() ?>
    <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
    <button type="submit" name="mark_paid" value="1" class="btn btn-small" data-confirm="Record that payment for this application has been received (by hand)? No amount is stored.">Mark as Paid</button>
    <span class="field-hint">Payment is collected outside the system. This records only that it was received, and by whom.</span>
</form>
<?php elseif (!empty($app['paid_at'])): ?>
<p class="field-hint" style="margin-bottom:0.75rem;">Payment recorded on <?= e(date('F j, Y g:i A', strtotime($app['paid_at']))) ?> (no amount stored).</p>
<?php endif; ?>
<form autocomplete="off" method="post" class="inline-fields" data-confirm="Update this application's status?">
<?= csrf_field() ?>
<input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
<select name="new_status">
<?php foreach ($statusOptions as $s): ?>
<option value="<?= e($s) ?>" <?= $app['status'] === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
<?php endforeach; ?>
</select>
<button type="submit" name="update_status" value="1" class="btn btn-small" data-confirm="Update this application's status?">Update</button>
</form>
<?php if (in_array($app['status'], ['approved', 'scheduled'], true)): ?>
<div class="action-cta">
    <a class="btn btn-small" href="<?= $basePath ?>/admin/records/first-communion.php?from_application=<?= (int) $app['id'] ?>">Encode into First Communion register (pre-filled)</a>
    <span class="field-hint">Review the application details against the source register; saving completes the application.</span>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
