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

// The server-only local config is useful on shared hosts without environment
// variable controls. Environment variables take precedence when both exist.
$environmentLocalConfig = [];
$environmentLocalConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'local.php';
if (is_file($environmentLocalConfigPath)) {
    $environmentLocalConfig = require $environmentLocalConfigPath;
    if (!is_array($environmentLocalConfig)) {
        throw new RuntimeException('Setup error: config/local.php must return an array.');
    }
}

// Public base URL of this installation, WITHOUT trailing slash. It is also
// used to decide whether HTTPS is enforced.
$configuredAppUrl = getenv('SRS_APP_URL');
$configuredAppUrl = $configuredAppUrl !== false && $configuredAppUrl !== ''
    ? $configuredAppUrl
    : ($environmentLocalConfig['SRS_APP_URL'] ?? 'http://localhost/srs-sppo');
define(
    'SRS_APP_URL',
    rtrim((string) $configuredAppUrl, '/')
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
