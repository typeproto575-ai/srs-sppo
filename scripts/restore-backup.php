<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/backup.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/restore-backup.php <backup.sql.gz.enc|backup.sql.enc> [--allow-live-restore=<exact-database-name>]\n");
    exit(1);
}

$inputPath = $argv[1];
$allowLive = null;
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--allow-live-restore=')) {
        $allowLive = substr($arg, strlen('--allow-live-restore='));
    }
}

$passphrase = getenv('SRS_BACKUP_PASSPHRASE');
if ($passphrase === false || $passphrase === '') {
    fwrite(STDERR, "Set SRS_BACKUP_PASSPHRASE before restoring a backup.\n");
    exit(1);
}
if (!is_file($inputPath) || !is_readable($inputPath)) {
    fwrite(STDERR, "Backup file is not readable.\n");
    exit(1);
}

$payload = file_get_contents($inputPath);
if ($payload === false) {
    fwrite(STDERR, "Could not read the backup file.\n");
    exit(1);
}

try {
    $sql = decrypt_backup_payload($payload, $passphrase);
    $sql = prepare_live_restore_sql($sql);
    $validation = validate_restore_sql($sql);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$dsn = $allowLive !== null
    ? getenv('SRS_DB_DSN')
    : getenv('SRS_RESTORE_DB_DSN');
$host = getenv('SRS_DB_HOST') ?: '127.0.0.1';
$databaseName = $allowLive !== null
    ? (getenv('SRS_DB_NAME') ?: '')
    : (getenv('SRS_RESTORE_DB_NAME') ?: '');
$user = $allowLive !== null
    ? (getenv('SRS_DB_USER') ?: '')
    : (getenv('SRS_RESTORE_DB_USER') ?: '');
$password = $allowLive !== null
    ? (getenv('SRS_DB_PASS') ?: '')
    : (getenv('SRS_RESTORE_DB_PASS') ?: '');

if ($dsn !== false && $dsn !== '') {
    $targetDsn = $dsn;
} else {
    $targetDsn = "mysql:host={$host};dbname={$databaseName};charset=utf8mb4";
}

if ($databaseName === '' && $allowLive === null) {
    fwrite(STDERR, "Set SRS_RESTORE_DB_NAME and SRS_RESTORE_DB_DSN before a staging restore.\n");
    exit(1);
}
if ($allowLive !== null && $allowLive !== $databaseName) {
    fwrite(STDERR, "Live restore refused: the exact database name confirmation did not match.\n");
    exit(1);
}
if ($allowLive === null && $databaseName !== '' && $databaseName === (getenv('SRS_DB_NAME') ?: '')) {
    fwrite(STDERR, "Refusing staging restore into the application database.\n");
    exit(1);
}
if ($allowLive !== null && $databaseName === '') {
    fwrite(STDERR, "Live restore requires SRS_DB_NAME.\n");
    exit(1);
}
if ($user === '') {
    fwrite(STDERR, "Set the restore database user credentials before restoring.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        $targetDsn,
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );
    $connectedDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($connectedDatabase !== $databaseName) {
        throw new RuntimeException('The restore connection does not resolve to the requested database.');
    }

    if ($allowLive !== null) {
        $snapshot = export_database_sql($pdo);
        $snapshotPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'backups'
            . DIRECTORY_SEPARATOR . 'pre-restore-' . date('Ymd-His') . '.sql';
        if (file_put_contents($snapshotPath, $snapshot['sql'], LOCK_EX) === false) {
            throw new RuntimeException('Could not create the pre-restore snapshot; restore was not started.');
        }
        fwrite(STDOUT, "Pre-restore snapshot: {$snapshotPath}\n");
    }

    $pdo->exec($sql);

    foreach (['persons', 'sacrament_records', 'sacrament_applications', 'audit_logs'] as $table) {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $pdo->query("SELECT 1 FROM {$quotedTable} LIMIT 1");
    }

    $pdo->exec(
        "INSERT INTO audit_logs (user_id, actor_name, action, target_table, target_id, description, ip_address, user_agent)
         VALUES (NULL, 'System restore', 'database_restore_completed', NULL, NULL, "
        . $pdo->quote(
            'Restored backup with ' . $validation['create_table_count'] . ' tables and '
            . $validation['insert_count'] . ' inserted rows; staff accounts were preserved.'
        )
        . ", NULL, 'CLI')"
    );

    fwrite(STDOUT, "Restore completed in {$connectedDatabase}.\n");
    fwrite(STDOUT, "Validated {$validation['create_table_count']} tables and {$validation['insert_count']} inserted rows.\n");
    fwrite(STDOUT, "Staff accounts and password hashes were preserved.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Restore failed: " . $exception->getMessage() . "\n");
    exit(1);
}
