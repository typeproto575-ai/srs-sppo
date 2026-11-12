<?php
/**
 * SRS-SPPO mail helpers. All delivery attempts are recorded when the
 * email_delivery_log table is available. Email failures never break parish
 * workflows.
 */
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/application-requirements.php';

function email_delivery_log(PDO $pdo, string $to, string $subject, string $eventType, string $status, ?string $error = null, ?string $entityType = null, ?int $entityId = null, ?string $trackingNumber = null): void
{
    $allowed = ['sent', 'failed', 'disabled', 'invalid'];
    if (!in_array($status, $allowed, true)) {
        $status = 'failed';
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (channel, type, title, recipient, delivery_status, error_message, entity_type, entity_id)
             VALUES ('email', ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            substr($eventType, 0, 60),
            substr($subject, 0, 150),
            substr($to, 0, 150),
            $status,
            substr($error ?? '', 0, 500),
            $entityType,
            $entityId,
        ]);
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
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)
        || !filter_var(MAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL)) {
        if ($pdo instanceof PDO) email_delivery_log($pdo, $to, $fullSubject, $eventType, 'invalid', 'Recipient or sender email is invalid.', $entityType, $entityId, $trackingNumber);
        return false;
    }
    if (MAIL_SMTP_HOST === '' || MAIL_SMTP_USER === '' || MAIL_SMTP_PASS === ''
        || MAIL_SMTP_PORT < 1 || MAIL_SMTP_PORT > 65535
        || !in_array(MAIL_SMTP_ENCRYPT, ['tls', 'ssl'], true)) {
        $error = 'SMTP settings are incomplete or invalid.';
        error_log('SRS-SPPO email delivery failed: ' . $error);
        if ($pdo instanceof PDO) email_delivery_log($pdo, $to, $fullSubject, $eventType, 'failed', $error, $entityType, $entityId, $trackingNumber);
        return false;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        $error = 'PHPMailer dependency is missing.';
        error_log('SRS-SPPO email delivery failed: ' . $error);
        if ($pdo instanceof PDO) email_delivery_log($pdo, $to, $fullSubject, $eventType, 'failed', $error, $entityType, $entityId, $trackingNumber);
        return false;
    }

    try {
        require_once $autoloadPath;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            $phpMailerSource = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
            foreach (['Exception.php', 'SMTP.php', 'PHPMailer.php'] as $sourceFile) {
                $sourcePath = $phpMailerSource . $sourceFile;
                if (!is_file($sourcePath)) {
                    throw new RuntimeException('PHPMailer source file is missing: ' . $sourceFile);
                }
                require_once $sourcePath;
            }
        }
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = MAIL_SMTP_HOST;
        $mailer->SMTPAuth = true;
        $mailer->Username = MAIL_SMTP_USER;
        $mailer->Password = MAIL_SMTP_PASS;
        $mailer->Port = MAIL_SMTP_PORT;
        $mailer->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mailer->SMTPSecure = match (MAIL_SMTP_ENCRYPT) {
            'tls' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
            'ssl' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
            default => throw new RuntimeException('Unsupported SMTP encryption mode.'),
        };
        $mailer->SMTPAutoTLS = true;
        $mailer->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mailer->addReplyTo(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mailer->addAddress($to);
        $mailer->Subject = $fullSubject;
        $mailer->Body = $bodyText;
        $mailer->isHTML(false);
        $mailer->send();
    } catch (Throwable $e) {
        error_log('SRS-SPPO SMTP delivery failed: ' . $e->getMessage());
        if ($pdo instanceof PDO) email_delivery_log($pdo, $to, $fullSubject, $eventType, 'failed', $e->getMessage(), $entityType, $entityId, $trackingNumber);
        return false;
    }

    if ($pdo instanceof PDO) email_delivery_log($pdo, $to, $fullSubject, $eventType, 'sent', null, $entityType, $entityId, $trackingNumber);
    return true;
}

function mail_request_documents(string $kind): array
{
    return match ($kind) {
        'Baptism Application',
        'Baptismal Application' => sacrament_application_requirements('baptism')['before'],
        'First Communion Application' => sacrament_application_requirements('first_communion')['before'],
        'Confirmation Application' => sacrament_application_requirements('confirmation')['before'],
        'Marriage Application',
        'Holy Matrimony Application' => sacrament_application_requirements('marriage')['before'],
        default => [
            'Valid ID matching the person or applicant record.',
            'For an authorized representative, a signed authorization letter and the required IDs.',
            'Original supporting documents and origin parish transfer or permission letters when requested.',
        ],
    };
}

function mail_is_sacrament_application(string $kind): bool
{
    return in_array($kind, [
        'Baptismal Application',
        'Baptism Application',
        'First Communion Application',
        'Confirmation Application',
        'Marriage Application',
        'Holy Matrimony Application',
    ], true);
}

function mail_request_documents_heading(string $kind): string
{
    return mail_is_sacrament_application($kind)
        ? 'Documents to prepare for parish review (the Parish Office will confirm which apply):'
        : 'Documents to prepare:';
}

function mail_request_display_name(string $kind): string
{
    return mail_is_sacrament_application($kind)
        ? str_replace(' Application', ' Pre-Application', $kind)
        : $kind;
}

function mail_request_received(string $to, string $trackingNumber, string $kind, ?PDO $pdo = null, ?string $entityType = null, ?int $entityId = null): bool
{
    $displayKind = mail_request_display_name($kind);
    $documentList = implode("\r\n", array_map(static fn($document) => '- ' . $document, mail_request_documents($kind)));
    $documentHeading = mail_request_documents_heading($kind);
    $trackingUrl = rtrim(SRS_APP_URL, '/') . '/public/track-request.php';
    if (mail_is_sacrament_application($kind)) {
        $body = "Dear Parishioner,\r\n\r\n"
            . "The Parish Office received your " . $displayKind . " for review.\r\n\r\n"
            . "Your person reference is: " . $trackingNumber . "\r\n"
            . "Use this same reference for requests linked to your person profile. To view linked requests, enter this reference and the email used on the request at " . $trackingUrl . ".\r\n\r\n"
            . $documentHeading . "\r\n" . $documentList . "\r\n\r\n"
            . "The Parish Office will contact you about the next steps, preparation, approval, and scheduling. Submitting this pre-application does not confirm approval or a sacrament date. Do not send personal documents by email or Messenger; contact the Parish Office if you have questions.\r\n\r\n"
            . "God bless,\r\nSt. Patrick's Parish Office — Dingalan, Aurora";
    } else {
        $body = "Dear Parishioner,\r\n\r\n"
            . "The parish office has recorded your " . $kind . ".\r\n\r\n"
            . "Your person reference is: " . $trackingNumber . "\r\n"
            . "Use this same reference for requests linked to your person profile. To view linked requests, enter this reference and the email used on the request at " . $trackingUrl . ".\r\n\r\n"
            . $documentHeading . "\r\n" . $documentList . "\r\n\r\n"
            . "Processing and pickup happen in person at the parish office. Certificates are never emailed or downloaded. Do not send documents by email or Messenger; you may still email or message the Parish Office if you have questions.\r\n\r\n"
            . "God bless,\r\nSt. Patrick's Parish Office — Dingalan, Aurora";
    }
    return send_mail($to, $displayKind . " — Received — " . $trackingNumber, $body, $pdo, 'request_received', $entityType, $entityId, $trackingNumber);
}

function mail_status_update(string $to, string $trackingNumber, string $humaneStatus, string $kind, ?PDO $pdo = null, ?string $entityType = null, ?int $entityId = null, ?string $officeNote = null): bool
{
    $displayKind = mail_request_display_name($kind);
    $referenceLabel = str_starts_with($trackingNumber, 'SPPO-P-') ? 'person reference' : 'reference number';
    $trackingUrl = rtrim(SRS_APP_URL, '/') . '/public/track-request.php';
    $documentList = implode("\r\n", array_map(static fn($document) => '- ' . $document, mail_request_documents($kind)));
    $documentHeading = mail_request_documents_heading($kind);
    $officeNote = trim((string) $officeNote);
    $officeNoteText = $officeNote !== '' ? "\r\n\r\nParish Office note: " . $officeNote : '';
    $paymentNote = '';
    // Payment happens outside the system. When the stage is "Awaiting Payment",
    // explain the offline options plainly with no amounts — the parish confirms
    // the fee directly.
    if (!mail_is_sacrament_application($kind) && strcasecmp($humaneStatus, 'Awaiting Payment') === 0) {
        $paymentNote = "\r\n\r\n"
            . "Payment is made outside this website; the office never takes payment online. You may pay at the parish office, contact the parish by email, phone, or the parish Facebook page/Messenger, or ask someone near the parish to pay on your behalf. The parish confirms the fee directly. Processing continues once your payment is recorded.";
    }
    if (mail_is_sacrament_application($kind)) {
        $body = "Dear Parishioner,\r\n\r\n"
            . "This is an update on your " . $displayKind . " ({$referenceLabel}: " . $trackingNumber . ").\r\n"
            . "View linked requests at " . $trackingUrl . " using this reference and the email address on the request.\r\n\r\n"
            . "Current stage: " . $humaneStatus . "\r\n\r\n"
            . $officeNoteText
            . $documentHeading . "\r\n" . $documentList . "\r\n\r\n"
            . "The Parish Office will contact you about any documents, preparation, approval, and scheduling. The pre-application does not itself confirm approval or reserve a sacrament date. Do not send personal documents by email or Messenger; contact the Parish Office if you have questions.\r\n\r\n"
            . "God bless,\r\nSt. Patrick's Parish Office — Dingalan, Aurora";
    } else {
        $body = "Dear Parishioner,\r\n\r\n"
            . "This is an update on your " . $kind . " ({$referenceLabel}: " . $trackingNumber . ").\r\n"
            . "View linked requests at " . $trackingUrl . " using this reference and the email address on the request.\r\n\r\n"
            . "Current stage: " . $humaneStatus . "\r\n"
            . $paymentNote . "\r\n\r\n"
            . ($officeNote !== '' ? "Parish Office note: " . $officeNote . "\r\n\r\n" : '')
            . $documentHeading . "\r\n" . $documentList . "\r\n\r\n"
            . "If the Parish Office asks you to visit, bring your reference number and any requested supporting documents. If the secretary gave you a claim stub or payment slip, bring it; if it is missing, provide the reference number and certificate name so the office can locate the request. The secretary verifies the recorded payment before release. The Acknowledgment Receipt is placed with the completed certificate in its folder, not required as a document to bring. An authorized representative must also present a signed authorization letter from the person named on the certificate. Do not send documents by email or Messenger; you may still email or message the Parish Office if you have questions.\r\n\r\n"
            . "God bless,\r\nSt. Patrick's Parish Office — Dingalan, Aurora";
    }
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

function mail_certificate_request_received(
    string $toEmail,
    string $requesterName,
    string $certificateType,
    string $requestNo,
    ?PDO $pdo = null,
    ?int $requestId = null,
    bool $requesterIsSubject = false
): bool
{
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    $trackingUrl = rtrim(SRS_APP_URL, '/') . '/public/track-request.php';
    $selfPickupRequirement = $requesterIsSubject
        ? "- A valid ID with a name matching the person on the certificate.\r\n"
        : '';
    $body = "Dear " . $requesterName . ",\r\n\r\n"
        . "The parish office received your request for a " . $certificateType . " certificate.\r\n\r\n"
        . "Your person reference is: " . $requestNo . "\r\n"
        . "Use this same reference for requests linked to your person profile. To view linked requests, enter this reference and the email used on the request at " . $trackingUrl . ".\r\n\r\n"
        . "Next step: visit the parish office, give your full name at the counter, and pay when instructed. Processing begins only after payment is recorded. Keep any claim stub or payment slip the secretary gives you.\r\n\r\n"
        . "After that, please wait for our next email. It will tell you when the certificate is signed and ready for pickup. Please do not come back before then.\r\n\r\n"
        . "When the certificate is ready, collect it in person. The secretary verifies the request and recorded payment; the Acknowledgment Receipt is placed with the certificate in its folder. Bring any claim stub or payment slip if one was given to you. If it is missing, provide your reference number and certificate name.\r\n\r\n"
        . $selfPickupRequirement
        . "If someone else picks it up for you, they must bring:\r\n"
        . "- A signed authorization letter from the person named on the certificate.\r\n"
        . "- For a record from another parish, bring the original supporting document and transfer or permission letter when requested.\r\n\r\n"
        . "Nothing is uploaded or emailed. You show any requested documents at the counter.\r\n\r\n"
        . "God bless,\r\nSt. Patrick's Parish Office";
    return send_mail($toEmail, 'We received your certificate request', $body, $pdo, 'request_received', 'certificate_requests', $requestId, $requestNo);
}
