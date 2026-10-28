<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
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
                $recordStmt = $pdo->prepare(
                    'SELECT id, person_id FROM sacrament_records WHERE id = ? AND sacrament_type = ?'
                );
                $recordStmt->execute([(int) $recordId, $current['sacrament_type']]);
                $record = $recordStmt->fetch();
                if (!$record) {
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
                log_activity($pdo, $userId, 'certificate_request_matched', 'certificate_requests', $requestId, 'Matched to sacrament record ' . (int) $record['id'] . '.');
                $message = 'The certificate request is linked to the selected sacrament record.';
            } elseif ($action === 'mark_for_signature') {
                $certificateNo = certificate_detail_post_string('certificate_no');
                if (!cert_request_transition_valid($current['status'], 'for_signature')) {
                    throw new DomainException('This request cannot move to For Signature from its current status.');
                }
                $paymentGate = cert_request_payment_gate($current, 'for_signature');
                if (!$paymentGate['ok']) {
                    throw new DomainException($paymentGate['reason']);
                }
                if ((int) $current['record_id'] < 1 || $certificateNo === '' || strlen($certificateNo) > 30) {
                    throw new DomainException('A linked sacrament record and a certificate number (maximum 30 characters) are required.');
                }
                $update = $pdo->prepare(
                    "UPDATE certificate_requests
                     SET status = 'for_signature', certificate_no = ?, printed_by = ?, printed_at = NOW()
                     WHERE id = ?"
                );
                $update->execute([$certificateNo, $userId, $requestId]);
                log_activity($pdo, $userId, 'certificate_request_printed', 'certificate_requests', $requestId, 'Certificate recorded as printed and awaiting priest signature.');
                $message = 'The request is now awaiting the priest signature.';
            } elseif ($action === 'mark_ready') {
                if (!cert_request_transition_valid($current['status'], 'ready') || certificate_detail_post_string('signature_confirmed') !== '1') {
                    throw new DomainException('Confirm that the priest has signed the certificate before marking it Ready.');
                }
                $update = $pdo->prepare("UPDATE certificate_requests SET status = 'ready', signed_at = NOW() WHERE id = ?");
                $update->execute([$requestId]);
                log_activity($pdo, $userId, 'certificate_request_signed', 'certificate_requests', $requestId, 'Priest signature confirmed; request marked ready.');
                $message = 'The certificate request is marked Ready for pickup.';
            } elseif ($action === 'release') {
                $claimantType = certificate_detail_post_string('claimant_type');
                $representativeName = certificate_detail_post_string('representative_name');
                $representativeRelationship = certificate_detail_post_string('representative_relationship');
                $claimantNotes = certificate_detail_post_string('claimant_notes');
                if (!cert_request_transition_valid($current['status'], 'released')) {
                    throw new DomainException('Only a Ready request can be released.');
                }
                if (!in_array($claimantType, ['self', 'representative'], true)
                    || certificate_detail_post_string('id_verified') !== '1'
                    || ($claimantType === 'representative' && ($representativeName === '' || $representativeRelationship === ''))) {
                    throw new DomainException('Verify the claimant’s valid ID and provide representative details when applicable.');
                }
                $update = $pdo->prepare(
                    "UPDATE certificate_requests
                     SET status = 'released', claimant_type = ?, representative_name = ?, representative_relationship = ?,
                         claimant_notes = ?, id_verified = 1, released_by = ?, released_at = NOW()
                     WHERE id = ?"
                );
                $update->execute([
                    $claimantType,
                    $claimantType === 'representative' ? $representativeName : null,
                    $claimantType === 'representative' ? $representativeRelationship : null,
                    $claimantNotes !== '' ? $claimantNotes : null,
                    $userId,
                    $requestId,
                ]);
                log_activity($pdo, $userId, 'certificate_request_released', 'certificate_requests', $requestId, 'Certificate released after claimant identity verification.');
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
                log_activity($pdo, $userId, 'certificate_request_rejected', 'certificate_requests', $requestId, 'Certificate request rejected.', $reason);
                $message = 'The certificate request was rejected.';
            } elseif ($action === 'mark_paid') {
                if (!empty($current['paid_at']) || in_array($current['status'], ['released', 'rejected'], true)) {
                    throw new DomainException('Payment cannot be recorded for this request in its current state.');
                }
                $update = $pdo->prepare('UPDATE certificate_requests SET paid_at = NOW(), paid_by = ? WHERE id = ?');
                $update->execute([$userId, $requestId]);
                log_activity($pdo, $userId, 'certificate_request_payment_recorded', 'certificate_requests', $requestId, 'Payment received and recorded.');
                $message = 'Payment has been recorded.';
            } else {
                throw new DomainException('The requested action is not supported.');
            }

            $pdo->commit();
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
    $personStmt = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, suffix, birth_date
         FROM persons WHERE id = ?'
    );
    $personStmt->execute([(int) $req['person_id']]);
    $person = $personStmt->fetch() ?: null;
}

$record = null;
if (!empty($req['record_id'])) {
    $recordStmt = $pdo->prepare(
        'SELECT sr.id, sr.event_date, sr.place, sr.entry_no, sr.book_no, sr.page_no, sr.line_no,
                p.first_name, p.middle_name, p.last_name, p.suffix
         FROM sacrament_records sr
         JOIN persons p ON p.id = sr.person_id
         WHERE sr.id = ? AND sr.sacrament_type = ?'
    );
    $recordStmt->execute([(int) $req['record_id'], $req['sacrament_type']]);
    $record = $recordStmt->fetch() ?: null;
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
$recordName = $record
    ? trim(implode(' ', array_filter([$record['first_name'], $record['middle_name'], $record['last_name'], $record['suffix']])))
    : '';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<section class="admin-page-heading">
    <div>
        <p class="admin-queue-kicker">Certificate request</p>
        <h1><?= e($req['request_no']) ?></h1>
        <p><?= e(certificate_detail_label((string) $req['sacrament_type'])) ?> for <?= e($req['subject_name']) ?></p>
    </div>
    <a class="btn btn-secondary" href="<?= e($basePath . '/admin/requests/queue.php') ?>">Back to queue</a>
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
        </dl>
    <?php elseif (!empty($req['record_id'])): ?>
        <p>The linked record is unavailable or has the wrong sacrament type.</p>
    <?php else: ?>
        <p>No sacrament record is linked.</p>
    <?php endif; ?>

    <?php if (in_array($req['status'], ['new', 'matched'], true)): ?>
        <form method="post" action="<?= e($detailUrl) ?>" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="link_record">
            <label for="record_id">Sacrament record ID</label>
            <input type="number" id="record_id" name="record_id" min="1" required value="<?= !empty($req['record_id']) ? (int) $req['record_id'] : '' ?>">
            <p>Only a record with the request's sacrament type can be linked. The person's identity is taken from that record and is not edited here.</p>
            <button type="submit" class="btn">Link record<?= $req['status'] === 'new' ? ' and mark Matched' : '' ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Processing actions</h2>
    <?php if ($req['status'] === 'matched'): ?>
        <form method="post" action="<?= e($detailUrl) ?>" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_for_signature">
            <label for="certificate_no">Certificate number</label>
            <input type="text" id="certificate_no" name="certificate_no" maxlength="30" required value="<?= e($req['certificate_no'] ?? '') ?>">
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
            <label for="representative_relationship">Representative relationship (if applicable)</label>
            <input type="text" id="representative_relationship" name="representative_relationship" maxlength="120">
            <label for="claimant_notes">Pickup notes (optional)</label>
            <textarea id="claimant_notes" name="claimant_notes" rows="2"></textarea>
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
