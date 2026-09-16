<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Certificate Request';
$notice = null;

const CERT_EXT = [
    'baptismal'       => ['table' => 'baptismal_certificate_requests',       'name_col' => 'child_full_name',      'record_table' => 'baptismal_records',       'record_name' => 'child_full_name'],
    'confirmation'    => ['table' => 'confirmation_certificate_requests',    'name_col' => 'confirmand_full_name',  'record_table' => 'confirmation_records',    'record_name' => 'confirmand_full_name'],
    'first_communion' => ['table' => 'first_communion_certificate_requests', 'name_col' => 'communicant_full_name', 'record_table' => 'first_communion_records', 'record_name' => 'communicant_full_name'],
    'marriage'        => ['table' => 'marriage_certificate_requests',        'name_col' => 'party_a_full_name',     'record_table' => 'marriage_records',        'record_name' => 'husband_full_name'],
];

$requestId = (int) ($_GET['id'] ?? 0);
if (!$requestId) { redirect_to('/admin/requests/queue.php'); }

$stmt = $pdo->prepare('SELECT * FROM certificate_requests WHERE id = ?');
$stmt->execute([$requestId]);
$req = $stmt->fetch();
if (!$req) { redirect_to('/admin/requests/queue.php'); }

$config = CERT_EXT[$req['sacrament_type']] ?? null;
$extRow = null;
$matchedRecord = null;

if ($config) {
    $stmt = $pdo->prepare("SELECT * FROM {$config['table']} WHERE request_id = ?");
    $stmt->execute([$requestId]);
    $extRow = $stmt->fetch();
    if ($extRow && !empty($extRow['matched_record_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM {$config['record_table']} WHERE id = ?");
        $stmt->execute([$extRow['matched_record_id']]);
        $matchedRecord = $stmt->fetch();
    }
}

$processedByUser = null;
if (!empty($req['processed_by'])) {
    $stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
    $stmt->execute([$req['processed_by']]);
    $processedByUser = $stmt->fetchColumn();
}

$auditStmt = $pdo->prepare('SELECT al.*, u.full_name AS user_name FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id WHERE al.target_table = ? AND al.target_id = ? ORDER BY al.created_at DESC');
$auditStmt->execute(['certificate_requests', $requestId]);
$auditHistory = $auditStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'start_review') {
        if (cert_request_transition_valid($req['status'], 'under_review')) {
            $stmt = $pdo->prepare("UPDATE certificate_requests SET status = 'under_review', date_processed = NOW(), processed_by = ? WHERE id = ?");
            $stmt->execute([current_user()['id'], $requestId]);
            log_activity($pdo, current_user()['id'], 'certificate_request_status_updated', 'certificate_requests', $requestId, 'Status: pending -> under_review');
            notify($pdo, 'request_under_review', 'Request under review',
                "{$req['sacrament_type']} certificate request for {$req['requester_name']} ({$req['tracking_number']}) is now under review.",
                '/admin/requests/queue.php?status=under_review');
            $notice = 'Request moved to Under Review.';
        } else {
            $notice = "Cannot start review from status '{$req['status']}'.";
        }
    }

    if ($action === 'approve') {
        if (cert_request_transition_valid($req['status'], 'approved')) {
            $stmt = $pdo->prepare("UPDATE certificate_requests SET status = 'approved', date_processed = NOW(), processed_by = ? WHERE id = ?");
            $stmt->execute([current_user()['id'], $requestId]);
            log_activity($pdo, current_user()['id'], 'certificate_request_status_updated', 'certificate_requests', $requestId, 'Status: under_review -> approved');
            notify($pdo, 'request_approved', 'Request approved',
                "{$req['sacrament_type']} certificate request for {$req['requester_name']} ({$req['tracking_number']}) was approved. Next: generate the certificate.",
                '/admin/requests/queue.php?status=approved');
            $notice = 'Request approved.';
        } else {
            $notice = "Cannot approve from status '{$req['status']}'.";
        }
    }

    if ($action === 'reject') {
        $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
        if ($reason === '') {
            $notice = 'A reason is required to reject a request.';
        } elseif (cert_request_transition_valid($req['status'], 'rejected')) {
            $stmt = $pdo->prepare("UPDATE certificate_requests SET status = 'rejected', rejection_reason = ?, date_processed = NOW(), processed_by = ? WHERE id = ?");
            $stmt->execute([$reason, current_user()['id'], $requestId]);
            log_activity($pdo, current_user()['id'], 'certificate_request_rejected', 'certificate_requests', $requestId, 'Rejected: ' . $reason);
            notify($pdo, 'request_rejected', 'Request rejected',
                "{$req['sacrament_type']} certificate request for {$req['requester_name']} ({$req['tracking_number']}) was rejected. Reason: {$reason}",
                '/admin/requests/queue.php?status=rejected');
            $notice = 'Request rejected.';
        } else {
            $notice = "Cannot reject from status '{$req['status']}'.";
        }
    }

    if ($action === 'save_notes') {
        $notes = trim((string) ($_POST['review_notes'] ?? ''));
        $stmt = $pdo->prepare("UPDATE certificate_requests SET review_notes = ? WHERE id = ?");
        $stmt->execute([$notes, $requestId]);
        log_activity($pdo, current_user()['id'], 'certificate_request_notes_updated', 'certificate_requests', $requestId, 'Review notes updated');
        $notice = 'Review notes saved.';
    }

    $stmt = $pdo->prepare('SELECT * FROM certificate_requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
}

function humanize_label(string $s): string { return ucwords(str_replace('_', ' ', $s)); }

require_once __DIR__ . '/../../includes/admin-header.php';
?>
<p><a href="<?= $basePath ?>/admin/requests/queue.php">&larr; Back to Queue</a></p>
<h1>Certificate Request</h1>
<?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>

<table class="admin-table" style="max-width:700px;"><tbody>
    <tr><th>Tracking No.</th><td><?= e($req['tracking_number']) ?></td></tr>
    <tr><th>Status</th><td><span class="status-badge status-<?= e($req['status']) ?>"><?= e(humanize_label($req['status'])) ?></span></td></tr>
    <tr><th>Sacrament</th><td><?= e(humanize_label($req['sacrament_type'])) ?></td></tr>
    <tr><th>Requester</th><td><?= e($req['requester_name']) ?> (<?= e(humanize_label($req['requester_relationship'])) ?><?= $req['requester_relationship'] === 'other' ? ': ' . e($req['requester_relationship_other']) : '' ?>)</td></tr>
    <tr><th>Email</th><td><?= e($req['requester_email']) ?></td></tr>
    <tr><th>Contact</th><td><?= e($req['requester_contact_number']) ?></td></tr>
    <tr><th>Purpose</th><td><?= e(humanize_label($req['purpose'])) ?><?= $req['purpose'] === 'other' ? ' — ' . e($req['purpose_other']) : '' ?></td></tr>
    <tr><th>Copies</th><td><?= (int) $req['num_copies'] ?></td></tr>
    <tr><th>Submitted</th><td><?= e(date('F j, Y g:i A', strtotime($req['date_submitted']))) ?></td></tr>
    <tr><th>Processed</th><td><?= $req['date_processed'] ? e(date('F j, Y g:i A', strtotime($req['date_processed']))) . ($processedByUser ? ' by ' . e($processedByUser) : '') : '—' ?></td></tr>
    <?php if ($req['status'] === 'rejected' && $req['rejection_reason']): ?><tr><th>Rejection Reason</th><td><?= e($req['rejection_reason']) ?></td></tr><?php endif; ?>
</tbody></table>

<?php if (!empty($req['notes'])): ?>
<h2>Requester Notes</h2>
<p><?= e($req['notes']) ?></p>
<?php endif; ?>

<?php if ($extRow): ?>
<h2>Submitted Sacrament Data</h2>
<table class="admin-table" style="max-width:700px;"><tbody>
    <?php foreach ($extRow as $col => $val): ?>
        <?php if ($col === 'id' || $col === 'request_id' || $col === 'matched_record_id') continue; ?>
        <tr><th><?= e(humanize_label($col)) ?></th><td><?= e((string) ($val ?: '—')) ?></td></tr>
    <?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<h2>Matched Register Record</h2>
<?php if ($matchedRecord): ?>
<table class="admin-table" style="max-width:700px;"><tbody>
    <tr><th>Record ID</th><td>#<?= (int) $matchedRecord['id'] ?></td></tr>
    <tr><th>Name</th><td><?= e($matchedRecord[CERT_EXT[$req['sacrament_type']]['record_name']]) ?></td></tr>
</tbody></table>
<?php else: ?>
<p><em>No register record matched yet.</em></p>
<?php endif; ?>

<h2>Actions</h2>
<?php if (cert_request_transition_valid($req['status'], 'under_review')): ?>
<form method="post" style="margin-bottom:0.5rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="start_review">
    <button type="submit" class="btn btn-small">Start Review</button>
</form>
<?php endif; ?>

<?php if (cert_request_transition_valid($req['status'], 'approved')): ?>
<form method="post" style="margin-bottom:0.5rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="approve">
    <button type="submit" class="btn btn-small">Approve</button>
</form>
<?php endif; ?>

<?php if (cert_request_transition_valid($req['status'], 'rejected')): ?>
<form method="post" style="margin-bottom:0.5rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reject">
    <label>Rejection reason (required):
        <textarea name="rejection_reason" rows="2" required></textarea>
    </label>
    <button type="submit" class="btn btn-small">Reject</button>
</form>
<?php endif; ?>

<?php if ($req['status'] === 'approved'): ?>
<p>
    <a class="btn btn-small" href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= $requestId ?>">Open Certificate Preview</a>
    <span class="field-hint">Match a register record and generate the certificate.</span>
</p>
<?php endif; ?>

<h2>Review Notes</h2>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_notes">
    <textarea name="review_notes" rows="4" style="width:100%;max-width:600px;" placeholder="Staff-only notes about this request..."><?= e($req['review_notes'] ?? '') ?></textarea>
    <button type="submit" class="btn btn-small" style="margin-top:0.5rem;">Save Notes</button>
</form>

<h2>Audit History</h2>
<?php if (empty($auditHistory)): ?>
<p><em>No audit entries yet.</em></p>
<?php else: ?>
<table class="admin-table" style="max-width:900px;">
    <thead><tr><th>When</th><th>Staff</th><th>Action</th><th>Description</th></tr></thead>
    <tbody>
    <?php foreach ($auditHistory as $entry): ?>
        <tr>
            <td><?= e(date('M j, Y g:i A', strtotime($entry['created_at']))) ?></td>
            <td><?= e($entry['user_name'] ?? 'System') ?></td>
            <td><?= e($entry['action']) ?></td>
            <td><?= e($entry['description']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php';