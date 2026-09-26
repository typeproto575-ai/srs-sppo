<?php
/**
 * ============================================================================
 * SRS-SPPO — Email / Mail configuration
 * ============================================================================
 * Role: Central settings for the email notifications sent by the system.
 * Loaded by includes/mail.php, which is required by the public forms and the
 * admin status-change paths.
 *
 * Default state — DISABLED:
 *   $MAIL * is set via define() below. For the local XAMPP demo (no mail
 *   server installed), the master switch MAIL_ENABLED is false, so every mail
 *   call is a silent no-op that returns false. The forms keep working exactly
 *   as before — nothing breaks when a relay is absent.
 *
 * Enabling for a real parish:
 *   1. Set MAIL_ENABLED to true.
 *   2. Confirm PHP can send mail: either a local MTA is configured, or PHP's
 *      sendmail setting in php.ini points to XAMPP's sendmail.exe.
 *   3. Set MAIL_FROM_ADDRESS / MAIL_FROM_NAME / MAIL_SITE_URL to the parish's
 *      real details. MAIL_SITE_URL is used to build the "Track My Request"
 *      link inside the email body.
 *
 * Plain-PHP rule: this uses PHP's built-in mail() function — no Composer
 * package (like PHPMailer) is required. The SMTP_* placeholders below document
 * where relay credentials would go if a PHPMailer/SMTP upgrade is added later.
 *
 * @package     SRS-SPPO
 * @subpackage  Config
 * @filesource  config/mail.php
 */

// Master switch: false = all mail calls are silent no-ops; true = send mail.
// Enabled via XAMPP's sendmail relay pointing at Gmail SMTP (see
// C:\xampp\sendmail\sendmail.ini — auth_username/auth_password must hold a
// Gmail App Password for the account below). Requires Apache restart.
define('MAIL_ENABLED', false);

// "From" address and display name shown to recipients.
// NOTE: must be the same Gmail account configured in sendmail.ini, or Gmail
// will reject the relayed message.
define('MAIL_FROM_ADDRESS', 'yourparishgmail@gmail.com'); // TODO: real parish Gmail
define('MAIL_FROM_NAME',    "St. Patrick's Parish Office");

// Prefix added to every subject line (lets parishioners filter our mail).
define('MAIL_SUBJECT_PREFIX', '[SRS-SPPO] ');

// Public base URL used to build the tracking link in email bodies.
// base_path() from includes/functions.php appends the subfolder (e.g.
// /srs-sppo), so this should be just the scheme + host.
define('MAIL_SITE_URL', 'http://localhost');

// ---------------------------------------------------------------------------
// SMTP relay placeholders (unused while MAIL_ENABLED is false and mail() is
// used). Provided for a future PHPMailer/SMTP upgrade only.
// ---------------------------------------------------------------------------
define('MAIL_SMTP_HOST',    '');
define('MAIL_SMTP_PORT',    587);
define('MAIL_SMTP_USER',    '');
define('MAIL_SMTP_PASS',    '');
define('MAIL_SMTP_ENCRYPT', 'tls'); // 'tls' | 'ssl' | 'none'
