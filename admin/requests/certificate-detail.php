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

function certificate_detail_date_label(?string $value, bool $includeTime = false): string
{
    if ($value === null || $value === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);
    return $timestamp === false
        ? $value
        : date($includeTime ? 'M j, Y g:i A' : 'M j, Y', $timestamp);
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
            $lock = $pdo->prepare('SELECT id, status, sacrament_type, channel, person_id, record_id, paid_at, printed_at FROM certificate_requests WHERE id = ? FOR UPDATE');
            $lock->execute([$requestId]);
            $current = $lock->fetch();
            if (!$current) {
                throw new DomainException('This certificate request no longer exists.');
            }

            $action = certificate_detail_post_string('action');
            $userId = (int) current_user()['id'];
            $message = '';
            $redirectToDetail = false;

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
                $paymentGate = cert_request_payment_gate($current, 'for_signature');
                if (!$paymentGate['ok']) {
                    throw new DomainException($paymentGate['reason'] ?? 'Record payment before processing the certificate.');
                }
                if (empty($current['printed_at'])) {
                    throw new DomainException('Print the certificate before marking it as awaiting the priest signature.');
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
                $repDocsReceived = certificate_detail_post_string('rep_docs_received') === '1';
                if (!cert_request_transition_valid($current['status'], 'released')) {
                    throw new DomainException('Only a Ready request can be released.');
                }
                if (!in_array($claimantType, ['self', 'representative'], true)
                    || ($claimantType === 'representative' && $representativeName === '')) {
                    throw new DomainException('Select a valid claimant and provide representative details when applicable.');
                }
                if (empty($current['paid_at'])) {
                    throw new DomainException('Payment has not been recorded.');
                }
                if ($claimantType === 'representative' && !$repDocsReceived) {
                    throw new DomainException('Confirm that the signed authorization letter was received.');
                }
                $setParts = [
                    "status = 'released'",
                    'claimant_type = ?',
                    'representative_name = ?',
                    'representative_relationship = ?',
                    'claimant_notes = ?',
                    'id_verified = 0',
                ];
                $params = [
                    $claimantType,
                    $claimantType === 'representative' ? $representativeName : null,
                    $claimantType === 'representative' ? ($representativeRelationship !== '' ? $representativeRelationship : null) : null,
                    $claimantNotes !== '' ? $claimantNotes : null,
                ];

                $setParts[] = 'released_by = ?';
                $setParts[] = 'released_at = NOW()';
                $params[] = $userId;
                $params[] = $requestId;
                $update = $pdo->prepare(
                    'UPDATE certificate_requests SET ' . implode(', ', $setParts) . ' WHERE id = ?'
                );
                $update->execute($params);
                $claimantDescription = $claimantType === 'representative' ? 'an authorized representative' : 'the requester/subject';
                $releaseEvidence = $claimantType === 'representative'
                    ? 'Recorded payment verified; signed authorization letter confirmed.'
                    : 'Recorded payment verified.';
                log_activity($pdo, $userId, 'certificate.release', 'certificate_requests', $requestId, 'Certificate released to ' . $claimantDescription . '. ' . $releaseEvidence);
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
                $update = $pdo->prepare('UPDATE certificate_requests SET paid_at = NOW(), paid_by = ? WHERE id = ? AND paid_at IS NULL');
                $update->execute([$userId, $requestId]);
                log_activity($pdo, $userId, 'certificate.payment', 'certificate_requests', $requestId, 'Payment received and recorded.');
                $message = 'Payment has been recorded.';
                $redirectToDetail = true;
            } else {
                throw new DomainException('The requested action is not supported.');
            }

            $pdo->commit();
            if ($redirectToDetail) {
                if ($action === 'mark_paid' && !empty($req['requester_email'])) {
                    mail_status_update(
                        (string) $req['requester_email'],
                        (string) $req['request_no'],
                        'Payment received — Under Processing',
                        'certificate_request',
                        $pdo,
                        'certificate_request',
                        $requestId
                    );
                }
                redirect_to('/admin/requests/certificate-detail.php?id=' . $requestId);
            }
            if ($action === 'reject' && !empty($req['requester_email'])) {
                mail_status_update(
                    (string) $req['requester_email'],
                    (string) $req['request_no'],
                    'Rejected',
                    'certificate_request',
                    $pdo,
                    'certificate_request',
                    $requestId,
                    $reason
                );
            }
            if ($action === 'mark_for_signature' && !empty($req['requester_email'])) {
                mail_status_update(
                    (string) $req['requester_email'],
                    (string) $req['request_no'],
                    'Under Processing — Pending Priest Signature',
                    'certificate_request',
                    $pdo,
                    'certificate_request',
                    $requestId
                );
            }
            if ($action === 'mark_ready' && !empty($req['requester_email'])) {
                $sacramentLabel = sd_type_label($req['sacrament_type']);
                $body = "Your " . $sacramentLabel . " certificate for " . $req['subject_name'] . " is ready for pickup at the parish office.\n"
                    . "Bring your request reference number and any claim stub or payment slip the secretary gave you. If the slip is missing, provide your reference number and certificate name so the office can locate the request.\n"
                    . "The secretary verifies the recorded payment before release. The Acknowledgment Receipt is placed with the completed certificate in its folder.\n"
                    . "If someone else will claim it for you, they must bring a signed authorization letter from the person named on the certificate.";
                try {
                    send_mail($req['requester_email'], 'Your certificate is ready for pickup', $body, $pdo, 'certificate_ready', 'certificate_requests', $requestId);
                } catch (Throwable $e) {
                    error_log('SRS-SPPO ready email failed: ' . $e->getMessage());
                }
            }
            if ($action === 'release' && !empty($req['requester_email'])) {
                mail_status_update(
                    (string) $req['requester_email'],
                    (string) $req['request_no'],
                    'Released',
                    'certificate_request',
                    $pdo,
                    'certificate_request',
                    $requestId
                );
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
$statusLabel = status_label('certificate', (string) $req['status']);
$processingPaymentGate = cert_request_payment_gate($req, 'for_signature');
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
        <?php if (!empty($req['record_id'])
            && in_array((string) $req['status'], ['matched', 'for_signature', 'ready', 'released'], true)
            && ((string) $req['status'] !== 'matched' || $processingPaymentGate['ok'])): ?>
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
        <dt>Channel</dt><dd><?= e(($req['channel'] === 'onsite') ? 'Submitted: At the office' : 'Submitted: Online') ?></dd>
        <dt>Submitted</dt><dd><?= e(certificate_detail_date_label((string) $req['created_at'], true)) ?></dd>
        <dt>Requested use</dt><dd><?= e($req['purpose'] ? certificate_detail_label((string) $req['purpose']) : 'Not specified') ?><?= $req['purpose_other'] ? ' — ' . e($req['purpose_other']) : '' ?><br><small>This is the requester's stated use, not an official certificate annotation.</small></dd>
        <dt>Requester</dt><dd><?= e($requesterName) ?></dd>
        <dt>Relationship</dt><dd><?= e(certificate_detail_label((string) $req['requester_relationship'])) ?><?= $req['requester_relationship_other'] ? ' — ' . e($req['requester_relationship_other']) : '' ?></dd>
        <dt>Email</dt><dd><?= e($req['requester_email']) ?></dd>
        <dt>Contact</dt><dd><?= e($req['requester_contact_number'] ?: 'Not provided') ?></dd>
        <dt>Payment</dt><dd><?= $req['paid_at'] ? 'Payment: recorded on ' . e(certificate_detail_date_label((string) $req['paid_at'], true)) : 'Payment: not yet recorded' ?></dd>
        <?php if (!empty($req['certificate_no'])): ?>
            <dt>Certificate number</dt><dd><?= e($req['certificate_no']) ?></dd>
        <?php endif; ?>
        <?php if (!empty($req['printed_at'])): ?>
            <dt>Printed</dt><dd><?= e(certificate_detail_date_label((string) $req['printed_at'], true)) ?></dd>
        <?php endif; ?>
        <?php if (!empty($req['signed_at'])): ?>
            <dt>Signed</dt><dd><?= e(certificate_detail_date_label((string) $req['signed_at'], true)) ?></dd>
        <?php endif; ?>
        <?php if (!empty($req['released_at'])): ?>
            <dt>Released</dt><dd><?= e(certificate_detail_date_label((string) $req['released_at'], true)) ?></dd>
            <dt>Claimant</dt><dd><?= e(certificate_detail_label((string) $req['claimant_type'])) ?></dd>
            <?php if (!empty($req['representative_name'])): ?>
                <dt>Representative</dt><dd><?= e($req['representative_name']) ?> (<?= e($req['representative_relationship']) ?>)</dd>
            <?php endif; ?>
            <dt>ID verification</dt><dd><?= (int) $req['id_verified'] === 1 ? 'Previously verified' : 'Not required' ?></dd>
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
            <?php
            $detailValue = is_scalar($value)
                ? (string) $value
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (
                is_string($value)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
                && strpos(strtolower((string) $key), 'date') !== false
            ) {
                $detailValue = certificate_detail_date_label($value);
            }
            ?>
            <dt><?= e(certificate_detail_label((string) $key)) ?></dt>
            <dd><?= e($detailValue) ?></dd>
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
            <dt>Event date</dt><dd><?= e(certificate_detail_date_label($record['event_date'] ?: null)) ?></dd>
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
            <input type="hidden" name="id" value="<?= (int) $requestId ?>">
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
            <p>Print the certificate first, then mark it as awaiting the priest's signature.</p>
            <?php if (!$processingPaymentGate['ok']): ?>
                <p><?= e($processingPaymentGate['reason'] ?? 'Record payment before processing.') ?></p>
            <?php elseif (empty($req['printed_at'])): ?>
                <p>Use Print certificate above before continuing.</p>
            <?php endif; ?>
            <button type="submit" class="btn" <?= !$processingPaymentGate['ok'] || empty($req['printed_at']) ? 'disabled' : '' ?>>Mark For Signature</button>
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
            <p id="claimant-guidance" class="field-hint">Verify the request and recorded payment. A claim stub can help locate the request but is not required; the Acknowledgment Receipt goes in the certificate folder.</p>
            <div id="representative-fields">
                <label for="representative_name">Representative name</label>
                <input type="text" id="representative_name" name="representative_name" maxlength="150">
                <label for="representative_relationship">Representative relationship (optional)</label>
                <input type="text" id="representative_relationship" name="representative_relationship" maxlength="120">
                <p class="field-hint">A representative must bring a signed authorization letter from the person named on the certificate.</p>
                <label><input type="checkbox" id="rep_docs_received" name="rep_docs_received" value="1"> I received the signed authorization letter.</label>
            </div>
            <label for="claimant_notes">Pickup notes (optional)</label>
            <textarea id="claimant_notes" name="claimant_notes" rows="2"></textarea>
            <?php if (!empty($req['paid_at'])): ?>
                <p class="field-hint">Payment recorded on <?= e($req['paid_at']) ?></p>
            <?php endif; ?>
            <button type="submit" class="btn">Confirm Payment and Release</button>
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

<script>
(() => {
    const claimantType = document.getElementById('claimant_type');
    const representativeFields = document.getElementById('representative-fields');
    const representativeName = document.getElementById('representative_name');
    const representativeDocs = document.getElementById('rep_docs_received');
    const claimantGuidance = document.getElementById('claimant-guidance');
    if (!claimantType || !representativeFields || !representativeName || !representativeDocs || !claimantGuidance) return;

    const updateClaimantFields = () => {
        const isRepresentative = claimantType.value === 'representative';
        representativeFields.hidden = !isRepresentative;
        representativeName.required = isRepresentative;
        representativeDocs.required = isRepresentative;
        claimantGuidance.textContent = isRepresentative
            ? "Verify the request and recorded payment; receive the signed authorization letter. A claim stub is helpful but not required."
            : "Verify the request and recorded payment. A claim stub can help locate the request but is not required; the Acknowledgment Receipt goes in the certificate folder.";
    };

    claimantType.addEventListener('change', updateClaimantFields);
    updateClaimantFields();
})();
</script>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
