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

const ALWAYS_BLANK_ONLINE = [
    'baptismal'       => ['birthplace', 'sponsors_text', 'book_no', 'page_no', 'line_no'],
    'confirmation'    => ['place_of_baptism', 'book_no', 'page_no', 'line_no'],
    'first_communion' => ['book_no', 'page_no', 'line_no'],
    'marriage'        => [
        'party_a_father_name', 'party_a_mother_name', 'party_a_baptized_in_church_of',
        'party_a_date_of_baptism', 'party_a_book_no', 'party_a_page_no', 'party_a_line_no',
        'party_b_father_name', 'party_b_mother_name', 'party_b_baptized_in_church_of',
        'party_b_date_of_baptism', 'party_b_book_no', 'party_b_page_no', 'party_b_line_no',
    ],
];

// Curated register fields for the matched record, in display order, with the
// same labels admin/records/view.php uses so one field never reads two
// different ways across admin screens. Internal columns (legitimacy,
// union_type, mapper/book-verification stamps) stay out. Dates render as full
// dates below; `birthdate` needs an explicit test, matching neither the
// `_date` nor the `_birthdate` suffix.
const RECORD_DISPLAY_FIELDS = [
    'baptismal' => [
        'baptism_date' => 'Date of baptism', 'birth_date' => 'Birth date',
        'baptism_place' => 'Place of baptism', 'child_birthplace' => "Child's birthplace",
        'father_name' => "Father's name", 'father_birthplace' => "Father's birthplace",
        'mother_name' => "Mother's name", 'mother_maiden_name' => "Mother's maiden name",
        'mother_birthplace' => "Mother's birthplace", 'parents_origin' => "Parents' origin",
        'parents_residence' => "Parents' residence", 'proxy_name' => 'Proxy (if any)',
        'minister_name' => 'Minister', 'remarks' => 'Remarks',
    ],
    'confirmation' => [
        'confirmation_date' => 'Date of confirmation', 'birthdate' => 'Birth date',
        'father_name' => "Father's name", 'mother_maiden_name' => "Mother's maiden name",
        'sponsor_name' => 'Sponsor', 'minister_name' => 'Minister', 'remarks' => 'Remarks',
    ],
    'first_communion' => [
        'communion_date' => 'Date of first communion', 'domicile' => 'Residence',
        'father_name' => "Father's name", 'mother_maiden_name' => "Mother's maiden name",
        'minister_name' => 'Minister', 'remarks' => 'Remarks',
    ],
    'marriage' => [
        'marriage_date' => 'Marriage date', 'wife_maiden_name' => "Wife's maiden name",
        'husband_birthdate' => "Husband's birth date", 'husband_birthplace' => "Husband's birthplace",
        'husband_civil_status' => 'Husband civil status', 'wife_birthdate' => "Wife's birth date",
        'wife_birthplace' => "Wife's birthplace", 'wife_civil_status' => 'Wife civil status',
        'officiating_minister' => 'Minister', 'remarks' => 'Remarks',
    ],
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
        $payGate = cert_request_payment_gate($req, 'under_review');
        if (!$payGate['ok']) {
            $notice = $payGate['reason'];
        } elseif (cert_request_transition_valid($req['status'], 'under_review')) {
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
        $payGate = cert_request_payment_gate($req, 'approved');
        if (!$payGate['ok']) {
            $notice = $payGate['reason'];
        } elseif (cert_request_transition_valid($req['status'], 'approved')) {
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

    // Mark an online request's payment as received (by hand). Records only
    // WHO and WHEN -- never an amount. Only valid while awaiting payment and
    // not yet recorded. This is what unblocks the payment gate above.
    if ($action === 'mark_paid') {
        if (($req['status'] ?? '') !== 'awaiting_payment') {
            $notice = "Only a request that is awaiting payment can be marked paid.";
        } elseif (!empty($req['paid_at'])) {
            $notice = 'Payment has already been recorded for this request.';
        } else {
            $stmt = $pdo->prepare("UPDATE certificate_requests SET paid_at = NOW(), paid_by = ? WHERE id = ?");
            $stmt->execute([current_user()['id'], $requestId]);
            log_activity($pdo, current_user()['id'], 'certificate_request_payment_recorded', 'certificate_requests', $requestId, 'Payment recorded by hand (no amount stored).');
            $notice = 'Payment recorded. The request can now move forward.';
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
            $allowed = (($item['requirement_key'] ?? '') === 'payment_received') ? ['not_received', 'received', 'not_applicable'] : $statuses;
            if (!in_array($status, $allowed, true)) continue;
            $note = trim((string) ($notes[$id] ?? ''));
            $update->execute([$status, $note !== '' ? $note : null, current_user()['id'], $id, 'certificate_request', $requestId]);
        }
        log_activity($pdo, current_user()['id'], 'certificate_request_requirements_updated', 'certificate_requests', $requestId, 'Requirements checklist updated.');
        $workflowRequirements = workflow_requirements_for($pdo, 'certificate_request', $requestId);
        $notice = 'Requirements checklist saved.';
    }

    // Confirm the online register match (staff only). The public flow never
    // tells the visitor whether a record exists; the tracking page reveals the
    // payment step only after this is recorded. Valid only while awaiting
    // payment, with a matched record, and not yet confirmed.
    if ($action === 'confirm_match') {
        if (($req['status'] ?? '') !== 'awaiting_payment') {
            $notice = 'Only a request that is awaiting payment can have its match confirmed.';
        } elseif (!empty($req['match_confirmed_at'])) {
            $notice = 'The register match has already been confirmed.';
        } elseif (empty($extRow['matched_record_id'])) {
            $notice = 'This request has no register match to confirm.';
        } else {
            $stmt = $pdo->prepare("UPDATE certificate_requests SET match_confirmed_at = NOW(), match_confirmed_by = ? WHERE id = ?");
            $stmt->execute([current_user()['id'], $requestId]);
            log_activity($pdo, current_user()['id'], 'certificate_request_match_confirmed', 'certificate_requests', $requestId, 'Register match confirmed; requester can now see payment instructions.');
            notify_certificate_requester_status($pdo, $requestId, 'awaiting_payment');
            $notice = 'Register match confirmed. The tracking page now shows the requester how to pay.';
        }
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
        <dt>Payment</dt>
        <dd>
            <?php if (!empty($req['paid_at'])): ?>
                Recorded on <?= e(date('F j, Y g:i A', strtotime($req['paid_at']))) ?><?php
                    $paidByUser = null;
                    if (!empty($req['paid_by'])) {
                        $pb = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
                        $pb->execute([(int) $req['paid_by']]);
                        $paidByUser = $pb->fetchColumn();
                    }
                    ?><?= $paidByUser ? ' by ' . e($paidByUser) : '' ?> <span class="field-hint">(no amount stored)</span>
            <?php elseif (($req['status'] ?? '') === 'awaiting_payment'): ?>
                <strong>Awaiting payment</strong> <span class="field-hint">— payment is made outside the system and recorded by hand.</span>
            <?php else: ?>
                Not applicable
            <?php endif; ?>
        </dd>
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
$alwaysBlankOnline = ($req['submission_channel'] ?? 'onsite') === 'online';
$suppress = ALWAYS_BLANK_ONLINE[$req['sacrament_type']] ?? [];
foreach ($extRow as $col => $val) {
    if ($col === 'id' || $col === 'request_id' || $col === 'matched_record_id') continue;
    // Online forms never collect these columns, so they are structurally
    // blank on every online row. Skip them entirely so they are neither
    // displayed nor reported as "Not Provided" (which would wrongly imply
    // the requester failed to supply something the form never asked for).
    if ($alwaysBlankOnline && in_array($col, $suppress, true)) continue;
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
    <?php foreach (RECORD_DISPLAY_FIELDS[$req['sacrament_type']] ?? [] as $col => $label):
        $val = $matchedRecord[$col] ?? null;
        if ($val === null || trim((string) $val) === '') continue;
        if (str_ends_with($col, '_date') || str_ends_with($col, '_birthdate') || $col === 'birthdate') {
            $val = date('F j, Y', strtotime((string) $val));
        } ?>
    <tr><th><?= e($label) ?></th><td><?= e((string) $val) ?></td></tr>
    <?php endforeach;
    $bookNo = $matchedRecord['book_no'] ?? null;
    $pageNo = $matchedRecord['page_no'] ?? null;
    $lineNo = $matchedRecord['line_no'] ?? null;
    if (($bookNo !== null && $bookNo !== '') || ($pageNo !== null && $pageNo !== '') || ($lineNo !== null && $lineNo !== '')): ?>
    <tr><th>Register Entry</th><td>Book <?= $bookNo !== null && $bookNo !== '' ? e((string) $bookNo) : '&mdash;' ?> &middot; Page <?= $pageNo !== null && $pageNo !== '' ? e((string) $pageNo) : '&mdash;' ?> &middot; Line <?= $lineNo !== null && $lineNo !== '' ? e((string) $lineNo) : '&mdash;' ?></td></tr>
    <?php endif; ?>
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
                <td><select name="requirement_status[<?= (int) $item['id'] ?>]"><?php $rowStatuses = (($item['requirement_key'] ?? '') === 'payment_received') ? ['not_received','received','not_applicable'] : ['not_received','received','under_review','incomplete','verified','not_applicable']; foreach ($rowStatuses as $status): ?><option value="<?= e($status) ?>" <?= $item['status'] === $status ? 'selected' : '' ?>><?= e(workflow_requirement_status_label($status)) ?></option><?php endforeach; ?></select></td>
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
<?php if (($req['status'] ?? '') === 'awaiting_payment' && empty($req['match_confirmed_at']) && !empty($extRow['matched_record_id'])): ?>
<form autocomplete="off" method="post" style="margin-bottom:0.5rem;" data-confirm="Confirm that this request matches the register entry shown above? The requester will then see the payment instructions.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="confirm_match">
    <button type="submit" class="btn btn-small">Confirm Register Match</button>
    <span class="field-hint">Until this is confirmed, the tracking page stays neutral and does not show the payment step.</span>
</form>
<?php endif; ?>
<?php if (!empty($req['match_confirmed_at'])): ?>
<p class="field-hint">Register match confirmed on <?= e(date('F j, Y g:i A', strtotime($req['match_confirmed_at']))) ?>.</p>
<?php endif; ?>
<?php if (($req['status'] ?? '') === 'awaiting_payment' && empty($req['paid_at'])): ?>
<form autocomplete="off" method="post" style="margin-bottom:0.5rem;" data-confirm="Record that payment for this request has been received (by hand)? No amount is stored.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark_paid">
    <button type="submit" class="btn btn-small" data-confirm="Record that payment for this request has been received (by hand)? No amount is stored.">Mark as Paid</button>
    <span class="field-hint">Payment is collected outside the system. This records only that it was received, and by whom.</span>
</form>
<?php endif; ?>
<?php if (cert_request_transition_valid($req['status'], 'under_review')): ?>
<form autocomplete="off" method="post" style="margin-bottom:0.5rem;" data-confirm="Start review of this certificate request?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="start_review">
    <button type="submit" class="btn btn-small" data-confirm="Start review of this certificate request?">Start Review</button>
</form>
<?php endif; ?>

<?php if (cert_request_transition_valid($req['status'], 'approved')): ?>
<form autocomplete="off" method="post" style="margin-bottom:0.5rem;" data-confirm="Approve this certificate request?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="approve">
    <button type="submit" class="btn btn-small">Approve</button>
</form>
<?php endif; ?>

<?php if (cert_request_transition_valid($req['status'], 'rejected')): ?>
    <details class="reject-disclosure"<?= (($_POST['action'] ?? '') === 'reject') ? ' open' : '' ?>>
        <summary class="btn btn-small btn-danger">Reject Request</summary>
        <form autocomplete="off" method="post" style="margin-top:0.5rem;" data-confirm="Reject this request with the reason entered above?">

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
