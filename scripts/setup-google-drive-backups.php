<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/scheduled-backup-config.php';
require_once __DIR__ . '/../includes/google-drive-backup.php';

try {
    $settings = srs_scheduled_backup_settings();
    foreach ([
        'SRS_GOOGLE_CLIENT_ID',
        'SRS_GOOGLE_CLIENT_SECRET',
        'SRS_GOOGLE_REFRESH_TOKEN',
    ] as $key) {
        if ($settings[$key] === '') {
            throw new RuntimeException("Set {$key} before creating the Google Drive folder.");
        }
    }

    $accessToken = srs_google_drive_access_token(
        $settings['SRS_GOOGLE_CLIENT_ID'],
        $settings['SRS_GOOGLE_CLIENT_SECRET'],
        $settings['SRS_GOOGLE_REFRESH_TOKEN']
    );
    $folderName = 'SRS-SPPO Encrypted Backups';
    $folders = srs_google_drive_list_files(
        $accessToken,
        "'root' in parents and name = '{$folderName}' and mimeType = 'application/vnd.google-apps.folder'"
    );
    if (count($folders) > 1) {
        throw new RuntimeException(
            'More than one app-created backup folder was found. Ask the administrator to choose one and set SRS_GOOGLE_DRIVE_FOLDER_ID.'
        );
    }

    $folderId = $folders[0]['id'] ?? srs_google_drive_create_folder($accessToken, $folderName);
    fwrite(STDOUT, "Google Drive backup folder is ready.\n");
    fwrite(STDOUT, "Set SRS_GOOGLE_DRIVE_FOLDER_ID to: {$folderId}\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Google Drive backup setup failed: ' . $exception->getMessage() . "\n");
    error_log('SRS-SPPO Google Drive backup setup failed: ' . $exception->getMessage());
    exit(1);
}
