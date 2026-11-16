<?php

declare(strict_types=1);

$mailLocalConfig = [];
$mailLocalConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'local.php';
if (is_file($mailLocalConfigPath)) {
    $mailLocalConfig = require $mailLocalConfigPath;
    if (!is_array($mailLocalConfig)) {
        throw new RuntimeException('Database setup error: config/local.php must return an array.');
    }
}

$mailSetting = static function (string $environmentName, string $configName, mixed $default = '') use ($mailLocalConfig): mixed {
    $environmentValue = getenv($environmentName);
    return $environmentValue !== false
        ? $environmentValue
        : ($mailLocalConfig[$configName] ?? $default);
};

$appUrl = defined('SRS_APP_URL')
    ? SRS_APP_URL
    : (getenv('SRS_APP_URL') ?: 'http://localhost/srs-sppo');
$appParts = parse_url((string) $appUrl);
$mailOrigin = is_array($appParts) && isset($appParts['scheme'], $appParts['host'])
    ? $appParts['scheme'] . '://' . $appParts['host']
        . (isset($appParts['port']) ? ':' . $appParts['port'] : '')
    : 'http://localhost';

define('MAIL_ENABLED', filter_var($mailSetting('SRS_MAIL_ENABLED', 'MAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN));
define('MAIL_FROM_ADDRESS', (string) $mailSetting('SRS_MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS'));
define('MAIL_FROM_NAME', (string) $mailSetting('SRS_MAIL_FROM_NAME', 'MAIL_FROM_NAME', "St. Patrick's Parish Office"));
define('MAIL_SUBJECT_PREFIX', '[SRS-SPPO] ');
define('MAIL_SITE_URL', rtrim((string) $mailSetting('SRS_MAIL_SITE_URL', 'MAIL_SITE_URL', $mailOrigin), '/'));
define('MAIL_SMTP_HOST', (string) $mailSetting('SRS_MAIL_SMTP_HOST', 'MAIL_SMTP_HOST'));
define('MAIL_SMTP_PORT', (int) $mailSetting('SRS_MAIL_SMTP_PORT', 'MAIL_SMTP_PORT', 587));
define('MAIL_SMTP_USER', (string) $mailSetting('SRS_MAIL_SMTP_USER', 'MAIL_SMTP_USER'));
define('MAIL_SMTP_PASS', (string) $mailSetting('SRS_MAIL_SMTP_PASS', 'MAIL_SMTP_PASS'));
define('MAIL_SMTP_ENCRYPT', strtolower((string) $mailSetting('SRS_MAIL_SMTP_ENCRYPT', 'MAIL_SMTP_ENCRYPT', 'tls')));
