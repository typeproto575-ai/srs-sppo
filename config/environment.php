<?php
/**
 * ============================================================================
 * SRS-SPPO — Environment / deployment switches
 * ============================================================================
 * Loaded by includes/functions.php. Defaults are correct for a local XAMPP
 * demo over plain HTTP; non-local deployments enforce HTTPS automatically.
 *
 * SRS_FORCE_HTTPS:
 *   true  — explicitly enforce HTTPS, including when SRS_APP_URL is local.
 *   unset/false — HTTPS is still enforced automatically when SRS_APP_URL
 *                 points to a non-local host.
 * ============================================================================
 */

// Public base URL of this installation, WITHOUT trailing slash.
// It is encoded into the certificate verification QR (absolute URL to
// public/verify.php?token=...). A phone that scans the QR opens that URL,
// so this must be reachable from the phone, not just from this computer.
//   XAMPP dev : 'http://localhost/srs-sppo' (QR scans only on this computer)
//   Parish    : set SRS_APP_URL in the server environment to the real public
//               address, e.g. 'https://parish.example.org' (no subfolder) or
//               'https://example.org/srs-sppo' (subfolder install).
// The admin certificate preview warns whenever this host is localhost,
// 127.0.0.1, ::1, or unset.
define(
    'SRS_APP_URL',
    rtrim(getenv('SRS_APP_URL') ?: 'http://localhost/srs-sppo', '/')
);

$appHost = strtolower((string) parse_url(SRS_APP_URL, PHP_URL_HOST));
$localHosts = ['localhost', '127.0.0.1', '::1'];
$forceHttps = getenv('SRS_FORCE_HTTPS');
$explicitHttps = $forceHttps !== false && filter_var($forceHttps, FILTER_VALIDATE_BOOLEAN);
define(
    'SRS_FORCE_HTTPS',
    $explicitHttps || ($appHost !== '' && !in_array($appHost, $localHosts, true))
);

// Data-retention periods for the retention purge
// (admin/system/retention-purge.php + scripts/purge-retention.php).
// Sacramental register entries (*_records, persons) are permanent parish
// records and are never purged. Only finished submissions (released /
// completed / rejected / cancelled) older than the submissions period are
// anonymized; open rows are never touched. Audit rows are scrubbed (IP and
// email removed), never deleted. Rate-limit rows are deleted.
// Defaults are proposals only — confirm the periods with the parish.
//   SRS_RETENTION_SUBMISSIONS_MONTHS : 60  (5 years — certificates get
//       re-requested years later; the tracking number still resolves, but
//       names/phones need not survive that long)
//   SRS_RETENTION_AUDIT_MONTHS : 36  (3 years — accountability needs history,
//       but IPs and tracking|email strings are the highest-risk fields)
//   SRS_RETENTION_RATELIMIT_DAYS : 30  (caps are 24h rolling; 30 days is
//       margin plus debugging, then delete)
define('SRS_RETENTION_SUBMISSIONS_MONTHS', (int) (getenv('SRS_RETENTION_SUBMISSIONS_MONTHS') ?: 60));
define('SRS_RETENTION_AUDIT_MONTHS', (int) (getenv('SRS_RETENTION_AUDIT_MONTHS') ?: 36));
define('SRS_RETENTION_RATELIMIT_DAYS', (int) (getenv('SRS_RETENTION_RATELIMIT_DAYS') ?: 30));
