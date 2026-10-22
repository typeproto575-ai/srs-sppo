<?php
/**
 * SRS-SPPO PUBLIC CORRECTION REQUEST FORM
 * Allows users to request corrections on issued certificates.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php'; // start a session before any CSRF checks or HTML output
$basePath = $basePath ?? base_path();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $message = 'Invalid CSRF token. Please try again.';
        $messageType = 'error';
    } else {
        $trackingNumber = strtoupper(trim($_POST['tracking_number'] ?? ''));
        $requesterName  = trim($_POST['requester_name'] ?? '');
        $requesterEmail = trim($_POST['requester_email'] ?? '');
        $requestedChanges = trim($_POST['requested_changes'] ?? '');

        if (!$trackingNumber || !$requesterName || !$requesterEmail || !$requestedChanges) {
            $message = 'Please fill in all required fields.';
            $messageType = 'error';
        } elseif (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } elseif (($e = validate_full_name($requesterName)) !== null) {
            $message = $e;
            $messageType = 'error';
        } elseif (($_POST['privacy_consent'] ?? '') !== '1') {
            $message = 'You must accept the Privacy Notice before submitting.';
            $messageType = 'error';
        } elseif (lookup_is_throttled($pdo, 'lookup_correction', $trackingNumber . '|' . $requesterEmail, 3, 10, 60)) {
            $message = 'Too many correction requests were sent from your connection. Please wait an hour, or visit the parish office.';
            $messageType = 'error';
        } else {
            try {
                // Every attempt counts, so tracking numbers cannot be probed one by one.
                lookup_record_attempt($pdo, 'lookup_correction', $trackingNumber . '|' . $requesterEmail);

                // The tracking number AND the email used at submission must both match.
                $stmt = $pdo->prepare('SELECT id FROM certificate_requests WHERE tracking_number = ? AND LOWER(requester_email) = LOWER(?) AND anonymized_at IS NULL');
                $stmt->execute([$trackingNumber, $requesterEmail]);
                $request = $stmt->fetch();

                if ($request) {
                    $stmt = $pdo->prepare('SELECT id FROM certificate_generation_log WHERE certificate_request_id = ? ORDER BY id DESC LIMIT 1');
                    $stmt->execute([$request['id']]);
                    $certLog = $stmt->fetch();
                    $certificateId = $certLog ? $certLog['id'] : 0;

                    $stmt = $pdo->prepare('INSERT INTO correction_requests (tracking_number, requester_name, requester_email, certificate_id, requested_changes, document_path) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$trackingNumber, $requesterName, $requesterEmail, $certificateId, $requestedChanges, null]);
                }

                // Same answer whether or not anything matched, so this form cannot be
                // used to find out which tracking numbers exist.
                $message = 'Thank you. If the tracking number and email match a request on file, your correction was sent to the parish office. We will contact you by email.';
                $messageType = 'success';
            } catch (Throwable $e) {
                error_log('Correction request submission error: ' . $e->getMessage());
                $message = 'A system error occurred. Please try again later.';
                $messageType = 'error';
            }
        }
    }
}

$pageTitle = 'Request Correction';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="portal-container">
    <div class="portal-header">
        <h1>Certificate Correction Request</h1>
        <p>Found an error in your issued certificate? Submit a request for correction here.</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="portal-card">
        <form method="POST" class="portal-form">
            <?= csrf_field() ?>
            
            <div class="form-group">
                <label for="tracking_number">Certificate Tracking Number <span class="required">*</span></label>
                <input type="text" id="tracking_number" name="tracking_number" placeholder="e.g. SPPO-2026-000123" required value="<?= e($_POST['tracking_number'] ?? '') ?>">
                <small>This is the tracking number provided when you requested the certificate.</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="requester_name">Full Name <span class="required">*</span></label>
                    <input type="text" id="requester_name" name="requester_name" placeholder="Juan Dela Cruz" required value="<?= e($_POST['requester_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="requester_email">Email Address <span class="required">*</span></label>
                    <input type="email" id="requester_email" name="requester_email" placeholder="juan@example.com" required value="<?= e($_POST['requester_email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="requested_changes">Details of Correction <span class="required">*</span></label>
                <textarea id="requested_changes" name="requested_changes" rows="5" placeholder="Please describe the error and the correct information..." required><?= e($_POST['requested_changes'] ?? '') ?></textarea>
            </div>

            <p class="field-hint">Do not upload IDs or certificates here. If the secretary needs supporting documents, show them in person at the Parish Office. The system never receives or stores documents — please do not email or message them.</p>

            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" name="privacy_consent" value="1" <?= ($_POST['privacy_consent'] ?? '') === '1' ? 'checked' : '' ?> required> I have read and agree to the <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a>.</label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Submit Request</button>
                <a href="<?= $basePath ?>/public/index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

