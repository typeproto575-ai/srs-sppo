<?php
/**
 * SRS-SPPO mail helpers. All delivery attempts are recorded when the
 * email_delivery_log table is available. Email failures never break parish
 * workflows.
 */
require_once __DIR__ . '/../config/mail.php';

function email_delivery_log(PDO $pdo, string $to, string $subject, string $eventType, string $status, ?string $error = null, ?string $entityType = null, ?int $entityId = null, ?string $trackingNumber = null): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO email_delivery_log (recipient, subject_line, event_type, entity_type, entity_id, tracking_number, delivery_status, error_message, sent_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $user = function_exists('current_user') ? current_user() : null;
        $stmt->execute([$to, $subject, $eventType, $entityType, $entityId, $trackingNumber, $status, $error, $user['id'] ?? null]);
    } catch (Throwable $e) {
        error_log('SRS-SPPO email delivery log failed: ' . $e->getMessage());
    }
}

function send_mail(string $to, string $subject, string $bodyText, ?PDO $pdo = null, string $eventType = 'generic', ?string $entityType = null, ?int $entityId = null, ?string $trackingNumber = null): bool
{
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    $to = trim($to);
    $fullSubject = MAIL_SUBJECT_PREFIX . $subject;
    if (MAIL_ENABLED !== true) {
        if ($pdo instanceof PDO) email_delivery_log($pdo, $to, $fullSubject, $eventType, 'disabled', 'Email is disabled.', $entityType, $entityId, $trackingNumber);
        return false;
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        if ($pdo instanceof PDO) email_delivery_log($pdo, $to, $fullSubject, $eventType, 'invalid', 'Recipient email is invalid.', $entityType, $entityId, $trackingNumber);
        return false;
    }
    $headers = implode("\r\n", [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
        'Reply-To: ' . MAIL_FROM_ADDRESS,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $sent = @mail($to, $fullSubject, $bodyText, $headers);
    if ($pdo instanceof PDO) email_delivery_log($pdo, $to, $fullSubject, $eventType, $sent ? 'sent' : 'failed', $sent ? null : 'The configured mail transport did not accept the message.', $entityType, $entityId, $trackingNumber);
    return $sent;
}

function mail_request_received(string $to, string $trackingNumber, string $kind, ?PDO $pdo = null, ?string $entityType = null, ?int $entityId = null): bool
{
    $trackUrl = rtrim(MAIL_SITE_URL, '/') . base_path() . '/public/track-request.php';
    $body = "Dear Parishioner,\r\n\r\n"
        . "The parish office has recorded your " . $kind . ".\r\n\r\n"
        . "Your tracking number is: " . $trackingNumber . "\r\n\r\n"
        . "Track your request at:\r\n" . $trackUrl . "\r\n\r\n"
        . "Please keep this tracking number. Processing and pickup happen in person at the parish office. Certificates are never emailed or downloaded. Please bring your supporting documents to the Parish Office. Do not email or message them.\r\n\r\n"
        . "God bless,\r\nSt. Patrick's Parish Office — Dingalan, Aurora";
    return send_mail($to, $kind . " — Received — " . $trackingNumber, $body, $pdo, 'request_received', $entityType, $entityId, $trackingNumber);
}

function mail_status_update(string $to, string $trackingNumber, string $humaneStatus, string $kind, ?PDO $pdo = null, ?string $entityType = null, ?int $entityId = null): bool
{
    $trackUrl = rtrim(MAIL_SITE_URL, '/') . base_path() . '/public/track-request.php';
    // Payment happens outside the system. When the stage is "Awaiting Payment",
    // explain the offline options plainly with no amounts — the parish confirms
    // the fee directly.
    $paymentNote = '';
    if (strcasecmp($humaneStatus, 'Awaiting Payment') === 0) {
        $paymentNote = "\r\n\r\n"
            . "Payment is made outside this website; the office never takes payment online. You may pay at the parish office, contact the parish by email, phone, or the parish Facebook page/Messenger, or ask someone near the parish to pay on your behalf. The parish confirms the fee directly. Processing continues once your payment is recorded.";
    }
    $body = "Dear Parishioner,\r\n\r\n"
        . "This is an update on your " . $kind . " (tracking number: " . $trackingNumber . ").\r\n\r\n"
        . "Current stage: " . $humaneStatus . "\r\n"
        . $paymentNote . "\r\n\r\n"
        . "For details, visit:\r\n" . $trackUrl . "\r\n\r\n"
        . "If your certificate is ready for pickup, please visit the parish office with your tracking number and valid identification. Certificates are released only in person. Please bring your supporting documents to the Parish Office. Do not email or message them.\r\n\r\n"
        . "God bless,\r\nSt. Patrick's Parish Office — Dingalan, Aurora";
    return send_mail($to, "Update on " . $trackingNumber . ": " . $humaneStatus, $body, $pdo, 'status_update', $entityType, $entityId, $trackingNumber);
}

function mail_password_reset(string $to, string $resetUrl, ?PDO $pdo = null, ?int $userId = null): bool
{
    $body = "Dear Parish Office Staff Member,\r\n\r\n"
        . "A password reset was requested for your SRS-SPPO staff account.\r\n\r\n"
        . "Use this one-time link within 30 minutes:\r\n" . $resetUrl . "\r\n\r\n"
        . "If you did not request this, you may ignore this message. Your password will not change unless the link is used.\r\n\r\n"
        . "For security, do not forward this link.\r\n\r\n"
        . "St. Patrick's Parish Office - Dingalan, Aurora";
    return send_mail($to, 'Secretary password reset', $body, $pdo, 'password_reset', 'users', $userId);
}
