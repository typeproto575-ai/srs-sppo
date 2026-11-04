<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../includes/scheduled-backup-config.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/google-drive-backup.php';

/**
 * Keep the newest six monthly backups and remove older matching files.
 *
 * @return int Number of deleted backups.
 */
function prune_monthly_backups(string $accessToken, string $folderId): int
{
    $files = srs_google_drive_list_files(
        $accessToken,
        "'" . $folderId . "' in parents"
    );
    $deleted = 0;
    foreach (srs_monthly_backups_to_delete($files) as $oldBackup) {
        srs_google_drive_delete_file($accessToken, $oldBackup['id']);
        $deleted++;
    }

    return $deleted;
}

try {
    $settings = srs_scheduled_backup_settings();
    if (!srs_scheduled_backups_enabled($settings['SRS_SCHEDULED_BACKUPS_ENABLED'])) {
        fwrite(STDOUT, "Scheduled backups are disabled; no backup was created.\n");
        exit(0);
    }

    foreach ([
        'SRS_BACKUP_PASSPHRASE',
        'SRS_GOOGLE_CLIENT_ID',
        'SRS_GOOGLE_CLIENT_SECRET',
        'SRS_GOOGLE_REFRESH_TOKEN',
        'SRS_GOOGLE_DRIVE_FOLDER_ID',
    ] as $key) {
        if ($settings[$key] === '') {
            throw new RuntimeException("Set {$key} before running the scheduled backup.");
        }
    }
    if (strlen($settings['SRS_BACKUP_PASSPHRASE']) < 32) {
        throw new RuntimeException('SRS_BACKUP_PASSPHRASE must contain at least 32 characters.');
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $settings['SRS_GOOGLE_DRIVE_FOLDER_ID'])) {
        throw new RuntimeException('SRS_GOOGLE_DRIVE_FOLDER_ID is not a valid Drive folder ID.');
    }

    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';

    $accessToken = srs_google_drive_access_token(
        $settings['SRS_GOOGLE_CLIENT_ID'],
        $settings['SRS_GOOGLE_CLIENT_SECRET'],
        $settings['SRS_GOOGLE_REFRESH_TOKEN']
    );
    $folderId = $settings['SRS_GOOGLE_DRIVE_FOLDER_ID'];
    $month = date('Y-m');
    $fileName = 'srs-sppo-monthly-' . $month . '.sql.gz.enc';
    $files = srs_google_drive_list_files($accessToken, "'" . $folderId . "' in parents");
    $alreadyExists = false;
    foreach ($files as $file) {
        if ($file['name'] === $fileName) {
            $alreadyExists = true;
            break;
        }
    }

    if ($alreadyExists) {
        $deleted = prune_monthly_backups($accessToken, $folderId);
        fwrite(STDOUT, "Monthly backup for {$month} already exists; no duplicate was uploaded. Removed {$deleted} expired backup(s).\n");
        exit(0);
    }

    $export = export_database_sql($pdo);
    $encrypted = encrypt_scheduled_backup($export['sql'], $settings['SRS_BACKUP_PASSPHRASE']);
    srs_google_drive_upload($accessToken, $folderId, $fileName, $encrypted['payload']);
    $deleted = prune_monthly_backups($accessToken, $folderId);

    log_activity(
        $pdo,
        null,
        'scheduled_database_backup_uploaded',
        null,
        null,
        sprintf(
            'file=%s tables=%d bytes=%d compressed_bytes=%d expired_backups_removed=%d',
            $fileName,
            $export['table_count'],
            $encrypted['sql_bytes'],
            $encrypted['compressed_bytes'],
            $deleted
        )
    );

    fwrite(
        STDOUT,
        sprintf(
            "Uploaded %s to Google Drive (%d tables, %d SQL bytes, %d compressed bytes). Removed %d expired backup(s).\n",
            $fileName,
            $export['table_count'],
            $encrypted['sql_bytes'],
            $encrypted['compressed_bytes'],
            $deleted
        )
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'Scheduled backup failed: ' . $exception->getMessage() . "\n");
    error_log('SRS-SPPO scheduled backup failed: ' . $exception->getMessage());
    exit(1);
}
