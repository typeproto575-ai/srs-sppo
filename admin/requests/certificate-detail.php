<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$requestId = (int) ($_GET['id'] ?? 0);
if ($requestId < 1) {
    redirect_to('/admin/requests/queue.php');
}

$detailUrl = $basePath . '/admin/requests/certificate-detail.php?id=' . $requestId;
$pageTitle = 'Certificate Request';
$notice = null;

function certificate_detail_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function certificate_detail_label(string $value): string
{
    return ucwords(str_replace('_', ' ', $value));
}

$requestStmt = $pdo->prepare('SELECT * FROM certificate_requests WHERE id = ?');
$requestStmt->execute([$requestId]);
$req = $requestStmt->fetch();
if (!$req) {
    redirect_to('/admin/requests/queue.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $notice = ['error', 'Your session token is invalid or expired. Refresh the page and try again.'];
    } else {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id, status, sacrament_type, channel, person_id, record_id, paid_at FROM certificate_requests WHERE id = ? FOR UPDATE');
            $lock->execute([$requestId]);
            $current = $lock->fetch();
            if (!$current) {
                throw new DomainException('This certificate request no longer exists.');
            }

            $action = certificate_detail_post_string('action');
            $userId = (int) current_user()['id'];
            $message = '';

            if ($action === 'link_record') {
                $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$recordId || !in_array($current['status'], ['new', 'matched'], true)) {
                    throw new DomainException('Select a valid sacramental record for a request that can be matched.');
                }
                $record = sd_get_record($pdo, (int) $recordId);
                if (!$record || (string) ($record['sacrament_type'] ?? '') !== $current['sacrament_type']) {
                    throw new DomainException('The selected record does not exist for this sacrament type.');
                }
                $nextStatus = $current['status'] === 'new' ? 'matched' : $current['status'];
                if ($nextStatus !== $current['status'] && !cert_request_transition_valid($current['status'], $nextStatus)) {
                    throw new DomainException('The request cannot move from its current status to Matched.');
                }
                $update = $pdo->prepare(
                    'UPDATE certificate_requests
                     SET person_id = ?, record_id = ?, matched_by = ?, matched_at = NOW(), status = ?
                     WHERE id = ?'
                );
                $update->execute([(int) $record['person_id'], (int) $record['id'], $userId, $nextStatus, $requestId]);
                log_activity($pdo, $userId, 'certificate.match', 'certificate_requests', $requestId, 'Matched to sacrament record ' . (int) $record['id'] . '.');
                $message = 'The certificate request is linked to the selected sacrament record.';
            } elseif ($action === 'verify_book') {
                $verifyRecordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$verifyRecordId) {
                    throw new DomainException('A valid record ID is required.');
                }
                $verifyRecord = sd_get_record($pdo, (int) $verifyRecordId);
                if (!$verifyRecord || (int) ($verifyRecord['id'] ?? 0) !== (int) $req['record_id']) {
                    throw new DomainException('The record does not match this request.');
                }
                sd_verify_book_entry($pdo, (int) $verifyRecordId, $userId);
                log_activity($pdo, $userId, 'record.verify', 'sacrament_records', (int) $verifyRecordId, 'Verified book entry');
                $message = 'Book entry has been verified.';
            } elseif ($action === 'mark_for_signature') {
                $certificateNo = certificate_detail_post_string('certificate_no');
                if (!cert_request_transition_valid($current['status'], 'for_signature')) {
                    throw new DomainException('This request cannot move to For Signature from its current status.');
                }
                if ((int) $current['record_id'] < 1 || $certificateNo === '' || strlen($certificateNo) > 30) {
                    throw new DomainException('A linked sacrament record and a certificate number (maximum 30 characters) are required.');
                }
                $linkedRecord = sd_get_record($pdo, (int) $current['record_id']);
                if (!sd_can_print_certificate($linkedRecord ?: [])) {
                    throw new DomainException('Verify the book entry first (this-parish records only).');
                }
                $update = $pdo->prepare(
                    "UPDATE certificate_requests
                     SET status = 'for_signature', certificate_no = ?, printed_by = ?, printed_at = NOW()
                     WHERE id = ?"
                );
                $update->execute([$certificateNo, $userId, $requestId]);
                log_activity($pdo, $userId, 'certificate.print', 'certificate_requests', $requestId, 'Certificate recorded as printed and awaiting priest signature.');
                $message = 'The request is now awaiting the priest signature.';
            } elseif ($action === 'mark_ready') {
                if (!cert_request_transition_valid($current['status'], 'ready') || certificate_detail_post_string('signature_confirmed') !== '1') {
                    throw new DomainException('Confirm that the priest has signed the certificate before marking it Ready.');
                }
                $update = $pdo->prepare("UPDATE certificate_requests SET status = 'ready', signed_at = NOW() WHERE id = ?");
                $update->execute([$requestId]);
                log_activity($pdo, $userId, 'certificate.sign', 'certificate_requests', $requestId, 'Priest signature confirmed; request marked ready.');
                $message = 'The certificate request is marked Ready for pickup.';
            } elseif ($action === 'release') {
                $claimantType = certificate_detail_post_string('claimant_type');
                $representativeName = certificate_detail_post_string('representative_name');
                $representativeRelationship = certificate_detail_post_string('representative_relationship');
                $claimantNotes = certificate_detail_post_string('claimant_notes');
                $idVerified = certificate_detail_post_string('id_verified') === '1';
                $paidNow = certificate_detail_post_string('paid_now') === '1';
                $repDocsReceived = certificate_detail_post_string('rep_docs_received') === '1';
                if (!cert_request_transition_valid($current['status'], 'released')) {
                    throw new DomainException('Only a Ready request can be released.');
                }
                if (!in_array($claimantType, ['self', 'representative'], true)
                    || certificate_detail_post_string('id_verified') !== '1'
                    || ($claimantType === 'representative' && ($representativeName === '' || $representativeRelationship === ''))) {
                    throw new DomainException('Verify the claimant’s valid ID and provide representative details when applicable.');
                }
                if (empty($current['paid_at']) && !$paidNow) {
                    throw new DomainException('Record the payment before releasing the certificate.');
                }
                if ($claimantType === 'representative' && !$repDocsReceived) {
                    throw new DomainException("Confirm that the representative's ID, the signed authorization letter and a copy of the subject's ID were received.");
                }
                $setParts = [
                    "status = 'released'",
                    'claimant_type = ?',
                    'representative_name = ?',
                    'representative_relationship = ?',
                    'claimant_notes = ?',
                    'id_verified = 1',
                ];
                $params = [
                    $claimantType,
                    $claimantType === 'representative' ? $representativeName : null,
                    $claimantType === 'representative' ? ($representativeRelationship !== '' ? $representativeRelationship : null) : null,
                    $claimantNotes !== '' ? $claimantNotes : null,
                ];
                if (empty($current['paid_at']) && $paidNow) {
                    $setParts[] = 'paid_at = NOW()';
                    $setParts[] = 'paid_by = ?';
                    $params[] = $userId;
                }
                $setParts[] = 'released_by = ?';
                $setParts[] = 'released_at = NOW()';
                $params[] = $userId;
                $params[] = $requestId;
                $update = $pdo->prepare(
                    'UPDATE certificate_requests SET ' . implode(', ', $setParts) . ' WHERE id = ?'
                );
                $update->execute($params);
                log_activity($pdo, $userId, 'certificate.release', 'certificate_requests', $requestId, 'Certificate released after claimant identity verification.');
                $message = 'The certificate request is marked Released.';
            } elseif ($action === 'reject') {
                $reason = certificate_detail_post_string('rejection_reason');
                if (!cert_request_transition_valid($current['status'], 'rejected')) {
                    throw new DomainException('This request cannot be rejected from its current status.');
                }
                if ($reason === '') {
                    throw new DomainException('Enter a reason for rejecting this request.');
                }
                $update = $pdo->prepare("UPDATE certificate_requests SET status = 'rejected', rejection_reason = ? WHERE id = ?");
                $update->execute([$reason, $requestId]);
                log_activity($pdo, $userId, 'certificate.reject', 'certificate_requests', $requestId, 'Certificate request rejected.', $reason);
                $message = 'The certificate request was rejected.';
            } elseif ($action === 'mark_paid') {
                if (!empty($current['paid_at']) || in_array($current['status'], ['released', 'rejected'], true)) {
                    throw new DomainException('Payment cannot be recorded for this request in its current state.');
                }
                $update = $pdo->prepare('UPDATE certificate_requests SET paid_at = NOW(), paid_by = ? WHERE id = ?');
                $update->execute([$userId, $requestId]);
                log_activity($pdo, $userId, 'certificate.payment', 'certificate_requests', $requestId, 'Payment received and recorded.');
                $message = 'Payment has been recorded.';
            } else {
                throw new DomainException('The requested action is not supported.');
            }

            $pdo->commit();
            if ($action === 'mark_ready' && !empty($req['requester_email'])) {
                $sacramentLabel = sd_type_label($req['sacrament_type']);
                $body = "Your " . $sacramentLabel . " certificate for " . $req['subject_name'] . " is ready for pickup at the parish office.\n"
                    . "Please bring: a valid government-issued photo ID.\n"
                    . "If someone else will claim it for you, they must bring: their own valid government ID, a signed authorization letter from you, and a copy of your valid ID.\n"
                    . "The fee is paid at pickup.";
                try {
                    send_mail($req['requester_email'], 'Your certificate is ready for pickup', $body, $pdo, 'certificate_ready', 'certificate_requests', $requestId);
                } catch (Throwable $e) {
                    error_log('SRS-SPPO ready email failed: ' . $e->getMessage());
                }
            }
            $notice = ['success', $message];
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $notice = ['error', $e->getMessage()];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Certificate request update failed for id ' . $requestId . ': ' . $e->getMessage());
            $notice = ['error', 'The certificate request could not be updated. No changes were saved.'];
        }

        $requestStmt->execute([$requestId]);
        $req = $requestStmt->fetch();
        if (!$req) {
            redirect_to('/admin/requests/queue.php');
        }
    }
}

$person = null;
if (!empty($req['person_id'])) {
    $person = sd_get_person($pdo, (int) $req['person_id']);
}

$record = null;
if (!empty($req['record_id'])) {
    $record = sd_get_record($pdo, (int) $req['record_id']);
    if ($record && (string) ($record['sacrament_type'] ?? '') !== $req['sacrament_type']) {
        $record = null;
    }
}

$details = [];
if (!empty($req['subject_details'])) {
    $decodedDetails = json_decode((string) $req['subject_details'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedDetails)) {
        $details = $decodedDetails;
    }
}

$auditStmt = $pdo->prepare(
    'SELECT actor_name, action, description, reason, created_at
     FROM audit_logs
     WHERE target_table = ? AND target_id = ?
     ORDER BY created_at DESC, id DESC'
);
$auditStmt->execute(['certificate_requests', $requestId]);
$auditHistory = $auditStmt->fetchAll();

$requesterName = $req['requester_name'];
$statusLabel = certificate_detail_label((string) $req['status']);
$recordName = '';
if ($record && !empty($record['person_id'])) {
    $recordPerson = sd_get_person($pdo, (int) $record['person_id']);
    if ($recordPerson) {
        $recordName = sd_full_name($recordPerson);
    }
}
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<section class="admin-page-heading">
    <div>
        <p class="admin-queue-kicker">Certificate request</p>
        <h1><?= e($req['request_no']) ?></h1>
        <p><?= e(certificate_detail_label((string) $req['sacrament_type'])) ?> for <?= e($req['subject_name']) ?></p>
    </div>
    <div>
        <?php if (!empty($req['record_id']) && in_array((string) $req['status'], ['for_signature', 'ready', 'released'], true)): ?>
            <a class="btn btn-primary" href="<?= e($basePath . '/admin/requests/certificate-print.php?id=' . (int) $req['id']) ?>">Print certificate</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= e($basePath . '/admin/requests/queue.php') ?>">Back to queue</a>
    </div>
</section>

<?php if ($notice): ?>
    <div class="alert alert-<?= e($notice[0]) ?>" role="status"><?= e($notice[1]) ?></div>
<?php endif; ?>

<section class="card">
    <h2>Request summary</h2>
    <dl class="detail-grid">
        <dt>Status</dt><dd><span class="status-badge status-<?= e($req['status']) ?>"><?= e($statusLabel) ?></span></dd>
        <dt>Channel</dt><dd><?= e(ucfirst((string) $req['channel'])) ?></dd>
        <dt>Submitted</dt><dd><?= e((string) $req['created_at']) ?></dd>
        <dt>Purpose</dt><dd><?= e($req['purpose'] ? certificate_detail_label((string) $req['purpose']) : 'Not specified') ?><?= $req['purpose_other'] ? ' — ' . e($req['purpose_other']) : '' ?></dd>
        <dt>Requester</dt><dd><?= e($requesterName) ?></dd>
        <dt>Relationship</dt><dd><?= e(certificate_detail_label((string) $req['requester_relationship'])) ?><?= $req['requester_relationship_other'] ? ' — ' . e($req['requester_relationship_other']) : '' ?></dd>
        <dt>Email</dt><dd><?= e($req['requester_email']) ?></dd>
        <dt>Contact</dt><dd><?= e($req['requester_contact_number'] ?: 'Not provided') ?></dd>
        <dt>Payment</dt><dd><?= $req['paid_at'] ? 'Recorded on ' . e($req['paid_at']) : 'Not recorded' ?></dd>
        <?php if (!empty($req['certificate_no'])): ?>
            <dt>Certificate number</dt><dd><?= e($req['certificate_no']) ?></dd>
        <?php endif; ?>
        <?php if (!empty($req['printed_at'])): ?>
            <dt>Printed</dt><dd><?= e($req['printed_at']) ?></dd>
        <?php endif; ?>
        <?php if (!empty($req['signed_at'])): ?>
            <dt>Signed</dt><dd><?= e($req['signed_at']) ?></dd>
        <?php endif; ?>
        <?php if (!empty($req['released_at'])): ?>
            <dt>Released</dt><dd><?= e($req['released_at']) ?></dd>
            <dt>Claimant</dt><dd><?= e(certificate_detail_label((string) $req['claimant_type'])) ?></dd>
            <?php if (!empty($req['representative_name'])): ?>
                <dt>Representative</dt><dd><?= e($req['representative_name']) ?> (<?= e($req['representative_relationship']) ?>)</dd>
            <?php endif; ?>
            <dt>ID verified</dt><dd><?= (int) $req['id_verified'] === 1 ? 'Yes' : 'No' ?></dd>
        <?php endif; ?>
    </dl>
    <?php if (!empty($req['notes'])): ?>
        <h3>Requester notes</h3>
        <p><?= nl2br(e($req['notes'])) ?></p>
    <?php endif; ?>
    <?php if (!empty($req['rejection_reason'])): ?>
        <h3>Rejection reason</h3>
        <p><?= nl2br(e($req['rejection_reason'])) ?></p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Information provided</h2>
    <dl class="detail-grid">
        <dt>Subject</dt><dd><?= e($req['subject_name']) ?></dd>
        <?php foreach ($details as $key => $value): ?>
            <?php if (strtolower((string) $key) === 'legitimacy') continue; ?>
            <dt><?= e(certificate_detail_label((string) $key)) ?></dt>
            <dd><?= e(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></dd>
        <?php endforeach; ?>
    </dl>
</section>

<section class="card">
    <h2>Matched parish record</h2>
    <?php if ($person): ?>
        <p><strong>Person:</strong> <?= e(trim(implode(' ', array_filter([$person['first_name'], $person['middle_name'], $person['last_name'], $person['suffix']]))) ?: 'Name unavailable') ?> (Person #<?= (int) $person['id'] ?>)</p>
    <?php else: ?>
        <p>No person is linked to this request.</p>
    <?php endif; ?>
    <?php if ($record): ?>
        <dl class="detail-grid">
            <dt>Record</dt><dd>#<?= (int) $record['id'] ?> — <?= e($recordName) ?></dd>
            <dt>Event date</dt><dd><?= e($record['event_date'] ?: 'Not recorded') ?></dd>
            <dt>Place</dt><dd><?= e($record['place'] ?: 'Not recorded') ?></dd>
            <dt>Register reference</dt><dd><?= e(implode(' / ', array_filter([$record['entry_no'], $record['book_no'], $record['page_no'], $record['line_no']]))) ?: 'Not recorded' ?></dd>
            <dt>Source</dt><dd><?= (!empty($record['source']) && $record['source'] === 'other_parish') ? 'Other parish' : 'This parish' ?></dd>
            <dt>Book entry</dt><dd><?= !empty($record['book_verified_at']) ? 'Book entry verified' : 'Not yet verified' ?></dd>
        </dl>
        <?php if (!empty($record['source']) && $record['source'] === 'other_parish'): ?>
            <p class="field-hint">No certificate is printed for records from another parish.</p>
        <?php elseif (empty($record['book_verified_at'])): ?>
            <form method="post" action="<?= e($detailUrl) ?>" style="margin-top:.5rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_book">
                <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                <button type="submit" class="btn btn-small">Verify book entry</button>
            </form>
        <?php endif; ?>
    <?php elseif (!empty($req['record_id'])): ?>
        <p>The linked record is unavailable or has the wrong sacrament type.</p>
    <?php else: ?>
        <p>No sacrament record is linked.</p>
    <?php endif; ?>

    <?php if (in_array($req['status'], ['new', 'matched'], true)): ?>
        <?php $searchQ = trim((string) ($_GET['q'] ?? $req['subject_name'])); ?>
        <form method="get" action="<?= e($detailUrl) ?>" class="stacked-form" style="margin-bottom:1rem;">
            <label for="q">Search person by name</label>
            <input type="text" id="q" name="q" value="<?= e($searchQ) ?>">
            <button type="submit" class="btn btn-secondary">Search</button>
        </form>
        <?php if ($searchQ !== ''): ?>
            <?php $searchResults = sd_search_persons($pdo, $searchQ); ?>
            <?php if (empty($searchResults)): ?>
                <p>No matching person found.</p>
            <?php else: ?>
                <?php foreach ($searchResults as $sp): ?>
                    <?php $spRecords = sd_get_person_records($pdo, (int) $sp['id']); ?>
                    <?php
                        $recsToShow = [];
                        if ($req['sacrament_type'] === 'marriage') {
                            $recsToShow = $spRecords['marriages'] ?? [];
                        } else {
                            $r = $spRecords[$req['sacrament_type']] ?? null;
                            if ($r) $recsToShow = [$r];
                        }
                    ?>
                    <div style="margin-bottom:1rem;">
                        <p><strong><?= e($sp['full_name']) ?></strong> (Person #<?= (int) $sp['id'] ?>)</p>
                        <?php if (empty($recsToShow)): ?>
                            <p class="field-hint">No <?= e(sd_type_label($req['sacrament_type'])) ?> record for this person.</p>
                        <?php else: ?>
                            <?php foreach ($recsToShow as $r): ?>
                                <div style="margin:.25rem 0; padding:.5rem; border:1px solid #ddd;">
                                    <span><?= e(date('M j, Y', strtotime($r['event_date']))) ?> &middot; <?= e($r['place'] ?: 'no place') ?> &middot; <?= e(implode(' / ', array_filter([$r['entry_no'], $r['book_no'], $r['page_no'], $r['line_no']])) ?: 'no ref') ?></span>
                                    <form method="post" action="<?= e($detailUrl) ?>" style="display:inline;margin-left:.5rem;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="link_record">
                                        <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-small">Use this record</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Processing actions</h2>
    <?php if ($req['status'] === 'matched'): ?>
        <form method="post" action="<?= e($detailUrl) ?>" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_for_signature">
            <label for="certificate_no">Certificate number</label>
            <input type="text" id="certificate_no" name="certificate_no" maxlength="30" required value="<?= e($req['certificate_no'] ?: 'C-' . date('Y') . '-' . str_pad((string) $requestId, 5, '0', STR_PAD_LEFT)) ?>">
            <p>Use this after the certificate has been printed for the priest's signature.</p>
            <?php if ($req['channel'] === 'online' && empty($req['paid_at'])): ?>
                <p>Record the online request payment before continuing.</p>
            <?php endif; ?>
            <button type="submit" class="btn" <?= $req['channel'] === 'online' && empty($req['paid_at']) ? 'disabled' : '' ?>>Mark For Signature</button>
        </form>
    <?php elseif ($req['status'] === 'for_signature'): ?>
        <form method="post" action="<?= e($detailUrl) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_ready">
            <label><input type="checkbox" name="signature_confirmed" value="1" required> I confirm the priest has signed the certificate.</label>
            <button type="submit" class="btn">Mark Ready for Pickup</button>
        </form>
    <?php elseif ($req['status'] === 'ready'): ?>
        <form method="post" action="<?= e($detailUrl) ?>" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="release">
            <label for="claimant_type">Claimant</label>
            <select id="claimant_type" name="claimant_type" required>
                <option value="self">Requester / subject</option>
                <option value="representative">Authorized representative</option>
            </select>
            <label for="representative_name">Representative name (if applicable)</label>
            <input type="text" id="representative_name" name="representative_name" maxlength="150">
            <label for="representative_relationship">Representative relationship (optional)</label>
            <input type="text" id="representative_relationship" name="representative_relationship" maxlength="120">
            <p class="field-hint">Representative must bring: (1) their own valid government ID, (2) a signed authorization letter from the subject, (3) a copy of the subject's valid ID.</p>
            <label><input type="checkbox" name="rep_docs_received" value="1"> I received the representative's valid ID, the signed authorization letter and a copy of the subject's valid ID.</label>
            <label for="claimant_notes">Pickup notes (optional)</label>
            <textarea id="claimant_notes" name="claimant_notes" rows="2"></textarea>
            <?php if (empty($req['paid_at'])): ?>
                <label><input type="checkbox" name="paid_now" value="1"> Payment received</label>
            <?php else: ?>
                <p class="field-hint">Payment recorded on <?= e($req['paid_at']) ?></p>
            <?php endif; ?>
            <label><input type="checkbox" name="id_verified" value="1" required> I verified the claimant's valid ID.</label>
            <button type="submit" class="btn">Verify ID and Release</button>
        </form>
    <?php elseif (in_array($req['status'], ['released', 'rejected'], true)): ?>
        <p>This request is in a terminal status and cannot be advanced.</p>
    <?php else: ?>
        <p>Link the request to its parish record to continue processing.</p>
    <?php endif; ?>

    <?php if (empty($req['paid_at']) && !in_array($req['status'], ['released', 'rejected'], true)): ?>
        <form method="post" action="<?= e($detailUrl) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_paid">
            <button type="submit" class="btn btn-secondary">Record Payment Received</button>
        </form>
    <?php endif; ?>

    <?php if (cert_request_transition_valid((string) $req['status'], 'rejected')): ?>
        <form method="post" action="<?= e($detailUrl) ?>" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <label for="rejection_reason">Reason for rejection</label>
            <textarea id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
            <button type="submit" class="btn btn-danger">Reject Request</button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Activity history</h2>
    <?php if (!$auditHistory): ?>
        <p>No audit events are recorded for this request.</p>
    <?php else: ?>
        <ul class="activity-list">
            <?php foreach ($auditHistory as $event): ?>
                <li>
                    <strong><?= e($event['action']) ?></strong>
                    <span><?= e($event['actor_name'] ?: 'System') ?> · <?= e($event['created_at']) ?></span>
                    <?php if ($event['description']): ?><p><?= e($event['description']) ?></p><?php endif; ?>
                    <?php if ($event['reason']): ?><p>Reason: <?= e($event['reason']) ?></p><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
