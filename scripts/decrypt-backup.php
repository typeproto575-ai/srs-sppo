<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/backup.php';

if ($argc !== 2 && $argc !== 3) {
    fwrite(STDERR, "Usage: set SRS_BACKUP_PASSPHRASE in the environment, then run php scripts/decrypt-backup.php <backup.sql[.gz].enc>\n");
    exit(1);
}

$inputPath = $argv[1];
$passphrase = $argc === 3 ? $argv[2] : getenv('SRS_BACKUP_PASSPHRASE');
if ($passphrase === false || $passphrase === '') {
    fwrite(STDERR, "Set SRS_BACKUP_PASSPHRASE or provide the legacy passphrase argument.\n");
    exit(1);
}
if (!is_file($inputPath) || !is_readable($inputPath)) {
    fwrite(STDERR, "Backup file is not readable.\n");
    exit(1);
}

$contents = file_get_contents($inputPath);
if ($contents === false) {
    fwrite(STDERR, "Could not read the backup file.\n");
    exit(1);
}

try {
    $sql = decrypt_backup_payload($contents, $passphrase);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$outputPath = preg_replace('/\.sql(?:\.gz)?\.enc$/i', '.sql', $inputPath);
if ($outputPath === null || $outputPath === $inputPath || file_exists($outputPath)) {
    fwrite(STDERR, "The decrypted SQL path is invalid or already exists.\n");
    exit(1);
}
if (file_put_contents($outputPath, $sql, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write the decrypted SQL file.\n");
    exit(1);
}

fwrite(STDOUT, "Decrypted backup written to {$outputPath}\n");
