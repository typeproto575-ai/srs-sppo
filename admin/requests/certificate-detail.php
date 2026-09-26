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

ensure_workflow_requirements($pdo, 'certificate_request', $requestId, (string) $req['sacrament_type']);
$workflowRequirements = workflow_requirements_for($pdo, 'certificate_request', $requestId);

// --- Keep the "register record located" checklist row in sync with the
// real book-verification flag (book_verified_by/book_verified_at on the
// matched register record -- see migrations/031_add_book_record_verification.sql
// and admin/certificates/generate.php, where the secretary actually performs
// this check). This row is NOT independently editable when a record is
// matched: it would otherwise be a second, disconnected "is this verified?"
// answer that could silently disagree with the one that actually gates
// certificate generation. When no record is matched yet, there's nothing to
// auto-derive, so that row is left as an ordinary manual checklist item.
if ($matchedRecord) {
    $autoStatus = !empty($matchedRecord['book_verified_at']) ? 'verified' : 'not_received';
    foreach ($workflowRequirements as $item) {
        if ($item['requirement_key'] === 'register_record' && $item['status'] !== $autoStatus) {
            $sync = $pdo->prepare('UPDATE workflow_requirements SET status = ? WHERE id = ?');
            $sync->execute([$autoStatus, (int) $item['id']]);
        }
    }
    $workflowRequirements = workflow_requirements_for($pdo, 'certificate_request', $requestId);
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
            notify_certificate_requester_status($pdo, $requestId, 'under_review');
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
            notify_certificate_requester_status($pdo, $requestId, 'approved');
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
            notify_certificate_requester_status($pdo, $requestId, 'rejected');
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

    if ($action === 'save_requirements') {
        $statuses = ['not_received', 'received', 'under_review', 'incomplete', 'verified', 'not_applicable'];
        $posted = is_array($_POST['requirement_status'] ?? null) ? $_POST['requirement_status'] : [];
        $notes = is_array($_POST['requirement_notes'] ?? null) ? $_POST['requirement_notes'] : [];
        $update = $pdo->prepare('UPDATE workflow_requirements SET status = ?, notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND entity_type = ? AND entity_id = ?');
        foreach ($workflowRequirements as $item) {
            $id = (int) $item['id'];
            $status = (string) ($posted[$id] ?? $item['status']);
            if (!in_array($status, $statuses, true)) continue;
            $note = trim((string) ($notes[$id] ?? ''));
            $update->execute([$status, $note !== '' ? $note : null, current_user()['id'], $id, 'certificate_request', $requestId]);
        }
        log_activity($pdo, current_user()['id'], 'certificate_request_requirements_updated', 'certificate_requests', $requestId, 'Requirements checklist updated.');
        $workflowRequirements = workflow_requirements_for($pdo, 'certificate_request', $requestId);
        $notice = 'Requirements checklist saved.';
    }

    $stmt = $pdo->prepare('SELECT * FROM certificate_requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
}

function humanize_label(string $s): string { return ucwords(str_replace('_', ' ', $s)); }

require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="request-detail-heading">
    <a href="<?= $basePath ?>/admin/requests/queue.php">&larr; Back to Queue</a>
    <h1>Certificate Request</h1>
</div>
<?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>

<div class="request-detail-grid">
<section class="request-detail-section request-summary-section">
<h2>Request Summary</h2>
<dl class="request-summary-grid">
    <div class="request-summary-item">
        <dt>Tracking No.</dt>
        <dd><?= e($req['tracking_number']) ?></dd>
    </div>
    <div class="request-summary-item">
        <dt>Status</dt>
        <dd><span class="status-badge status-<?= e($req['status']) ?>"><?= e(humanize_label($req['status'])) ?></span></dd>
    </div>
    <div class="request-summary-item">
        <dt>Submission Channel</dt>
        <dd><?= e(ucfirst($req['submission_channel'] ?? 'onsite')) ?><?= ($req['submission_channel'] ?? 'onsite') === 'online' ? ' — public pre-application; not yet an official parish record' : '' ?></dd>
    </div>
    <div class="request-summary-item">
        <dt>Sacrament</dt>
        <dd><?= e(humanize_label($req['sacrament_type'])) ?></dd>
    </div>
    <div class="request-summary-item">
        <dt>Requester</dt>
        <dd><?= e($req['requester_name']) ?> (<?= e(humanize_label($req['requester_relationship'])) ?><?= $req['requester_relationship'] === 'other' ? ': ' . e($req['requester_relationship_other']) : '' ?>)</dd>
    </div>
    <div class="request-summary-item">
        <dt>Email</dt>
        <dd><?= e($req['requester_email']) ?></dd>
    </div>
    <div class="request-summary-item">
        <dt>Contact</dt>
        <dd><?= e($req['requester_contact_number']) ?></dd>
    </div>
    <div class="request-summary-item">
        <dt>Purpose</dt>
        <dd><?= e(humanize_label($req['purpose'])) ?><?= $req['purpose'] === 'other' ? ' — ' . e($req['purpose_other']) : '' ?></dd>
    </div>
    <div class="request-summary-item">
        <dt>Submitted</dt>
        <dd><?= e(date('F j, Y g:i A', strtotime($req['date_submitted']))) ?></dd>
    </div>
    <div class="request-summary-item">
        <dt>Processed</dt>
        <dd><?= $req['date_processed'] ? e(date('F j, Y g:i A', strtotime($req['date_processed']))) . ($processedByUser ? ' by ' . e($processedByUser) : '') : '—' ?></dd>
    </div>
    <?php if ($req['status'] === 'rejected' && $req['rejection_reason']): ?>
    <div class="request-summary-item">
        <dt>Rejection Reason</dt>
        <dd><?= e($req['rejection_reason']) ?></dd>
    </div>
    <?php endif; ?>
</dl>
</section>

<?php if (!empty($req['notes'])): ?>
<section class="request-detail-section">
<h2>Requester Notes</h2>
<p><?= e($req['notes']) ?></p>
</section>
<?php endif; ?>

<?php if ($extRow): ?>
<section class="request-detail-section">
<h2>Submitted Sacrament Data</h2>
<div class="table-scroll">
<table class="admin-table" style="max-width:700px;"><tbody>
    <?php
$notProvided = [];
foreach ($extRow as $col => $val) {
    if ($col === 'id' || $col === 'request_id' || $col === 'matched_record_id') continue;
    if ($val === null || trim((string) $val) === '') {
        $notProvided[] = humanize_label($col);
        continue;
    }
    ?>
    <tr><th><?= e(humanize_label($col)) ?></th><td><?= e((string) $val) ?></td></tr>
    <?php
}
if ($notProvided): ?>
    <tr><th>Not Provided</th><td><?= e(implode(', ', $notProvided)) ?></td></tr>
<?php endif; ?>
</tbody></table>
</div>
</section>
<?php endif; ?>

<section class="request-detail-section">
<h2>Matched Register Record</h2>
<?php if ($matchedRecord): ?>
<div class="table-scroll">
<table class="admin-table" style="max-width:700px;"><tbody>
    <tr><th>Record ID</th><td>#<?= (int) $matchedRecord['id'] ?></td></tr>
    <tr><th>Name</th><td><?= e($matchedRecord[CERT_EXT[$req['sacrament_type']]['record_name']]) ?></td></tr>
</tbody></table>
</div>
<?php else: ?>
<p><em>No register record matched yet.</em></p>
<?php endif; ?>
</section>

<section class="request-detail-section request-requirements-section">
<h2>Requirements Checklist</h2>
<p class="field-hint">Record whether each requirement was received and verified. Do not upload or store document contents here.</p>
<?php if (empty($workflowRequirements)): ?>
    <p><em>Checklist unavailable until migration 019 is applied.</em></p>
<?php else: ?>
<div class="checklist-bulk-apply">
    <label>Set all to:
        <select id="bulk-requirement-status">
            <option value="">— choose a status —</option>
            <option value="not_received">Not Received</option>
            <option value="received">Received</option>
            <option value="under_review">Under Review</option>
            <option value="incomplete">Incomplete</option>
            <option value="verified">Verified</option>
            <option value="not_applicable">Not Applicable</option>
        </select>
    </label>
    <button type="button" id="bulk-apply-btn" class="btn btn-small btn-secondary">Apply to all</button>
    <span class="field-hint">Applies to the editable rows below, then still needs Save Checklist.</span>
</div>

<form autocomplete="off" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_requirements">
    <div class="table-scroll">
    <table class="admin-table checklist-table" style="max-width:900px;">
        <thead><tr><th>Requirement</th><th>Status</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($workflowRequirements as $item): ?>
            <?php if ($item['requirement_key'] === 'register_record' && $matchedRecord): ?>
                <!-- Auto-derived from the matched record's actual book-verification flag
                     (see the sync block above) -- not independently editable here, so this
                     can never disagree with what admin/certificates/generate.php enforces. -->
                <tr>
                    <td><?= e($item['requirement_label']) ?></td>
                    <td>
                        <?php if ($item['status'] === 'verified'): ?>
                            <span class="flag-badge flag-badge-ok">Verified against register book</span>
                        <?php else: ?>
                            <span class="flag-badge">Not yet verified</span>
                            <a href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= $requestId ?>" class="field-hint">Verify on Certificate Preview &rarr;</a>
                        <?php endif; ?>
                        <input type="hidden" name="requirement_status[<?= (int) $item['id'] ?>]" value="<?= e($item['status']) ?>">
                    </td>
                    <td><input type="text" name="requirement_notes[<?= (int) $item['id'] ?>]" value="<?= e($item['notes'] ?? '') ?>" placeholder="Optional note"></td>
                </tr>
            <?php else: ?>
            <tr>
                <td><?= e($item['requirement_label']) ?></td>
                <td><select name="requirement_status[<?= (int) $item['id'] ?>]"><?php foreach (['not_received','received','under_review','incomplete','verified','not_applicable'] as $status): ?><option value="<?= e($status) ?>" <?= $item['status'] === $status ? 'selected' : '' ?>><?= e(workflow_requirement_status_label($status)) ?></option><?php endforeach; ?></select></td>
                <td><input type="text" name="requirement_notes[<?= (int) $item['id'] ?>]" value="<?= e($item['notes'] ?? '') ?>" placeholder="Optional note"></td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <button type="submit" class="btn btn-small">Save Checklist</button>
</form>
<?php endif; ?>
</section>

<script>
(function() {
    var bulkBtn = document.getElementById('bulk-apply-btn');
    if (!bulkBtn) return;

    bulkBtn.addEventListener('click', function() {
        var bulkStatus = document.getElementById('bulk-requirement-status').value;
        if (!bulkStatus) {
            alert('Please choose a status first.');
            return;
        }

        var selects = document.querySelectorAll('select[name^="requirement_status"]');
        selects.forEach(function(select) {
            select.value = bulkStatus;
        });
    });
})();
</script>

<section class="request-action-panel">
<h2>Next Action</h2>
<?php if (cert_request_transition_valid($req['status'], 'under_review')): ?>
<form autocomplete="off" method="post" style="margin-bottom:0.5rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="start_review">
    <button type="submit" class="btn btn-small">Start Review</button>
</form>
<?php endif; ?>

<?php if (cert_request_transition_valid($req['status'], 'approved')): ?>
<form autocomplete="off" method="post" style="margin-bottom:0.5rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="approve">
    <button type="submit" class="btn btn-small">Approve</button>
</form>
<?php endif; ?>

<?php if (cert_request_transition_valid($req['status'], 'rejected')): ?>
    <details class="reject-disclosure"<?= (($_POST['action'] ?? '') === 'reject') ? ' open' : '' ?>>
        <summary class="btn btn-small btn-danger">Reject Request</summary>
        <form autocomplete="off" method="post" style="margin-top:0.5rem;">

            <?= csrf_field() ?>
            <div class="form-group">
                <label for="rejection_reason">Rejection reason (required):</label>
                <textarea autocomplete="off" name="rejection_reason" id="rejection_reason" rows="2" required style="width:100%;"></textarea>
            </div>
            <input type="hidden" name="action" value="reject">
            <button type="submit" class="btn btn-small btn-danger">Confirm Rejection</button>
        </form>
    </details>
<?php endif; ?>

<?php if ($req['status'] === 'approved'): ?>
<p>
    <a class="btn btn-small" href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= $requestId ?>">Open Certificate Preview</a>
    <span class="field-hint">Match a register record and generate the certificate.</span>
</p>
<?php endif; ?>
</section>

<section class="request-detail-section">
<h2>Review Notes</h2>
<form autocomplete="off" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_notes">
    <textarea autocomplete="off" name="review_notes" rows="4" style="width:100%;max-width:600px;" placeholder="Staff-only notes about this request..."><?= e($req['review_notes'] ?? '') ?></textarea>
    <button type="submit" class="btn btn-small" style="margin-top:0.5rem;">Save Notes</button>
</form>
</section>

<section class="request-detail-section request-audit-section">
<h2>Audit History</h2>
<?php if (empty($auditHistory)): ?>
<p><em>No audit entries yet.</em></p>
<?php else: ?>
<div class="table-scroll">
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
</div>
<?php endif; ?>
</section>
</div>

<?php require_once __DIR__ . '/../../includes/admin-footer.php';
