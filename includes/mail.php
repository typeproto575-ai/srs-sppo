<?php
/**
 * ============================================================================
 * SRS-SPPO SHARED MAIL HELPERS
 * ============================================================================
 * Purpose: One place for the email notifications used across the system:
 *   - send_mail()               : low-level plain-text sender (safe no-op)
 *   - mail_request_received()   : "we got your request — here is the tracking #"
 *   - mail_status_update()      : "your request status changed"
 * Every call is safe when email is disabled: send_mail() returns false and the
 * caller never breaks. All settings live in config/mail.php.
 *
 * Requires includes/functions.php to already be loaded (for base_path()).
 *
 * @package     SRS-SPPO
 * @subpackage  Includes
 * @filesource  includes/mail.php
 */

require_once __DIR__ . '/../config/mail.php';

/**
 * Send a plain-text email via PHP's built-in mail().
 *
 * Safe no-op: returns false (without sending) when mail is disabled, the
 * address is blank/invalid, or the local mailer fails. Never throws.
 *
 * @param string $to        Recipient email address.
 * @param string $subject   Subject line (MAIL_SUBJECT_PREFIX is prepended).
 * @param string $bodyText  Plain-text message body.
 * @return bool             True only when the message was handed to the mailer.
 */
function send_mail(string $to, string $subject, string $bodyText): bool
{
    // Master switch off — behave like the demo (no MTA installed).
    if (MAIL_ENABLED !== true) {
        return false;
    }

    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = MAIL_SUBJECT_PREFIX . $subject;

    // Minimal, robust headers for a plain-text UTF-8 message.
    $headers = implode("\r\n", [
        'From: ' . MAIL_FROM_ADDRESS,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);

    // @ suppresses mailer warnings when no MTA is configured; mail() returns
    // false on failure instead of throwing.
    return @mail($to, $subject, $bodyText, $headers);
}

/**
 * Confirmation email sent after a public form is successfully submitted.
 * Tells the parishioner their tracking number and how to check status.
 *
 * @param string $to            Recipient email address.
 * @param string $trackingNumber The generated tracking number to show.
 * @param string $kind           Human request type, e.g. "Baptismal Certificate Request".
 * @return bool                 True only when the message was sent.
 */
function mail_request_received(string $to, string $trackingNumber, string $kind): bool
{
    // Build the tracking-page link (subfolder-safe via base_path()).
    $trackUrl = rtrim(MAIL_SITE_URL, '/') . base_path() . '/public/track-request.php';

    $body = "Dear Parishioner,\r\n\r\n"
        . "Thank you for submitting your " . $kind . " to St. Patrick's Parish Office.\r\n\r\n"
        . "Your tracking number is: " . $trackingNumber . "\r\n\r\n"
        . "You can check the status of your request any time at:\r\n"
        . $trackUrl . "\r\n\r\n"
        . "Please keep this tracking number — it is how you look up your request. "
        . "Final processing and pickup happen in person at the parish office, "
        . "where you must present a valid ID. Certificates are never emailed "
        . "or downloaded; they are printed and released only at the counter.\r\n\r\n"
        . "God bless,\r\n"
        . "St. Patrick's Parish Office — Dingalan, Aurora";

    return send_mail($to, $kind . " — Received — " . $trackingNumber, $body);
}

/**
 * Status-change notification sent by the secretary (e.g. when a certificate
 * becomes ready for pickup).
 *
 * @param string $to            Recipient email address.
 * @param string $trackingNumber The request's tracking number.
 * @param string $humaneStatus   Human-readable status, e.g. "Ready For Pickup".
 * @param string $kind           Human request type, e.g. "Baptismal Certificate".
 * @return bool                 True only when the message was sent.
 */
function mail_status_update(string $to, string $trackingNumber, string $humaneStatus, string $kind): bool
{
    $trackUrl = rtrim(MAIL_SITE_URL, '/') . base_path() . '/public/track-request.php';

    $body = "Dear Parishioner,\r\n\r\n"
        . "This is an update on your " . $kind . " (tracking number: " . $trackingNumber . ").\r\n\r\n"
        . "Current status: " . $humaneStatus . "\r\n\r\n"
        . "For full details, check your request at:\r\n"
        . $trackUrl . "\r\n\r\n"
        . "If your certificate is ready for pickup, please visit the parish office "
        . "with a valid ID. Certificates are released only in person.\r\n\r\n"
        . "God bless,\r\n"
        . "St. Patrick's Parish Office — Dingalan, Aurora";

    return send_mail($to, "Update on " . $trackingNumber . ": " . $humaneStatus, $body);
}