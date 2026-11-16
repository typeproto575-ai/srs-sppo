<?php

declare(strict_types=1);

/**
 * Read scheduled-backup settings from environment variables, then server-only config.
 *
 * @return array<string, string>
 */
function srs_scheduled_backup_settings(): array
{
    $localConfig = [];
    $localConfigPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config'
        . DIRECTORY_SEPARATOR . 'local.php';
    if (is_file($localConfigPath)) {
        $loaded = require $localConfigPath;
        if (!is_array($loaded)) {
            throw new RuntimeException('config/local.php must return an array.');
        }
        $localConfig = $loaded;
    }

    $settings = [];
    foreach ([
        'SRS_SCHEDULED_BACKUPS_ENABLED',
        'SRS_BACKUP_PASSPHRASE',
        'SRS_GOOGLE_CLIENT_ID',
        'SRS_GOOGLE_CLIENT_SECRET',
        'SRS_GOOGLE_REFRESH_TOKEN',
        'SRS_GOOGLE_DRIVE_FOLDER_ID',
    ] as $key) {
        $environmentValue = getenv($key);
        $value = $environmentValue !== false
            ? $environmentValue
            : ($localConfig[$key] ?? '');
        if (!is_string($value)) {
            throw new RuntimeException("Scheduled backup setting {$key} must be a string.");
        }
        $settings[$key] = $value;
    }

    return $settings;
}

function srs_scheduled_backups_enabled(string $setting): bool
{
    $value = strtolower(trim($setting));
    if ($value === '') {
        return false;
    }
    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    throw new RuntimeException(
        'SRS_SCHEDULED_BACKUPS_ENABLED must be set to true or false.'
    );
}
