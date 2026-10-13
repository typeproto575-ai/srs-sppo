<?php

declare(strict_types=1);

/**
 * Rebuild the explicitly configured integration-test database.
 *
 * Required environment variables:
 *   SRS_TEST_DB_NAME, SRS_TEST_DB_USER, SRS_TEST_DB_PASS
 * Optional:
 *   SRS_TEST_DB_HOST (default: 127.0.0.1)
 *
 * This script refuses the application database name and never uses the
 * application's SRS_DB_* credentials.
 */

$databaseName = getenv('SRS_TEST_DB_NAME') ?: '';
$host = getenv('SRS_TEST_DB_HOST') ?: '127.0.0.1';
$user = getenv('SRS_TEST_DB_USER') !== false ? (string) getenv('SRS_TEST_DB_USER') : 'root';
$password = getenv('SRS_TEST_DB_PASS') !== false ? (string) getenv('SRS_TEST_DB_PASS') : '';

if ($databaseName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) {
    fwrite(STDERR, "SRS_TEST_DB_NAME must be a valid database identifier.\n");
    exit(1);
}
if (in_array($databaseName, ['srs_sppo_db', (string) (getenv('SRS_DB_NAME') ?: '')], true)) {
    fwrite(STDERR, "Refusing to rebuild the application database.\n");
    exit(1);
}

$root = dirname(__DIR__);
$schemaPath = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'srs_sppo_schema.sql';
$migrationDirectory = $root . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;
$migrationPaths = [
    $migrationDirectory . '012_add_review_and_rejection_columns.sql',
    $migrationDirectory . '035_payment_status.sql',
    $migrationDirectory . '036_add_awaiting_payment_notification.sql',
    $migrationDirectory . '037_add_register_match_confirmation.sql',
    $migrationDirectory . '038_data_retention_support.sql',
];

try {
    $server = new PDO(
        "mysql:host={$host};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );
    $quotedDatabase = '`' . str_replace('`', '``', $databaseName) . '`';
    $server->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
    $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $schema = file_get_contents($schemaPath);
    $migrations = array_map('file_get_contents', $migrationPaths);
    if ($schema === false || in_array(false, $migrations, true)) {
        throw new RuntimeException('Could not read the schema or retention migration.');
    }

    $schema = preg_replace('/^\s*USE\s+srs_sppo_db\s*;/mi', "USE {$quotedDatabase};", $schema);
    if ($schema === null) {
        throw new RuntimeException('Could not prepare the schema for the test database.');
    }

    $database = new PDO(
        "mysql:host={$host};dbname={$databaseName};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );
    $database->exec($schema);
    foreach ($migrations as $migration) {
        $database->exec($migration);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Test database setup failed: {$e->getMessage()}\n");
    exit(1);
}

echo "Initialized isolated test database: {$databaseName}\n";
