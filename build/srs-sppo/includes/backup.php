<?php

declare(strict_types=1);

/**
 * Export a safe, portable SQL dump without credential or rate-limit data.
 *
 * @return array{sql: string, table_count: int}
 */
function export_database_sql(PDO $pdo): array
{
    $excludedTables = [
        'password_reset_tokens',
        'submission_rate_limits',
        'email_delivery_log',
    ];
    $safeUserColumns = [
        'id',
        'full_name',
        'username',
        'email',
        'role',
        'is_active',
        'auth_version',
        'created_at',
        'updated_at',
    ];

    $output = "-- SRS-SPPO database export\n"
        . "-- Generated " . date('Y-m-d H:i:s') . "\n\n"
        . "SET FOREIGN_KEY_CHECKS=0;\n\n";
    $tableCount = 0;

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $table = (string) $table;
        if (in_array($table, $excludedTables, true)) {
            continue;
        }

        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $columns = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(
            static fn(array $column): string => (string) $column['Field'],
            $columns
        );
        $unsafeColumns = array_values(array_filter(
            $columnNames,
            static fn(string $column): bool => preg_match('/(?:password|passwd|passphrase|hash)/i', $column) === 1
        ));
        if ($table === 'users') {
            $columnNames = array_values(array_intersect($safeUserColumns, $columnNames));
            if (count($columnNames) !== count($safeUserColumns)) {
                throw new RuntimeException('The users table is missing a required safe backup column.');
            }
        } else {
            $columnNames = array_values(array_diff($columnNames, $unsafeColumns));
        }
        if ($columnNames === []) {
            continue;
        }

        $createRow = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch();
        if (!$createRow || !isset($createRow['Create Table'])) {
            throw new RuntimeException("Could not read the definition for table '{$table}'.");
        }
        $createSql = (string) $createRow['Create Table'];
        foreach ($unsafeColumns as $unsafeColumn) {
            $createSql = preg_replace(
                '/^\s*`?' . preg_quote($unsafeColumn, '/') . '`?\s+[^,\r\n]+,?\r?\n/im',
                '',
                $createSql
            ) ?? $createSql;
        }

        $output .= "-- ----------------------------\n"
            . "-- Table: {$table}\n"
            . "-- ----------------------------\n"
            . "DROP TABLE IF EXISTS {$quotedTable};\n"
            . $createSql . ";\n\n";

        $quotedColumns = array_map(
            static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
            $columnNames
        );
        $rows = $pdo->query(
            "SELECT " . implode(', ', $quotedColumns) . " FROM {$quotedTable}"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $values = array_map(
                static fn($value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                array_values($row)
            );
            $output .= "INSERT INTO {$quotedTable} (" . implode(', ', $quotedColumns)
                . ") VALUES (" . implode(', ', $values) . ");\n";
        }
        $output .= "\n";
        $tableCount++;
    }

    return [
        'sql' => $output . "SET FOREIGN_KEY_CHECKS=1;\n",
        'table_count' => $tableCount,
    ];
}

/**
 * Compress and encrypt a scheduled database export.
 *
 * @return array{payload: string, sql_bytes: int, compressed_bytes: int}
 */
function encrypt_scheduled_backup(string $sql, string $passphrase): array
{
    if (strlen($passphrase) < 32) {
        throw new InvalidArgumentException('The scheduled backup passphrase must be at least 32 characters.');
    }
    if (!function_exists('gzencode') || !function_exists('openssl_encrypt')) {
        throw new RuntimeException('Gzip and OpenSSL support are required for scheduled backups.');
    }

    $compressed = gzencode($sql, 9, FORCE_GZIP);
    if ($compressed === false) {
        throw new RuntimeException('Could not compress the database export.');
    }

    $cipher = 'aes-256-gcm';
    $salt = random_bytes(16);
    $nonce = random_bytes(openssl_cipher_iv_length($cipher));
    $iterations = 210000;
    $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
    $tag = '';
    $ciphertext = openssl_encrypt($compressed, $cipher, $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Could not encrypt the scheduled database backup.');
    }

    $header = json_encode([
        'format' => 'srs-sppo-backup-v2',
        'algorithm' => 'AES-256-GCM',
        'kdf' => 'PBKDF2-SHA256',
        'iterations' => $iterations,
        'compression' => 'gzip',
        'salt' => base64_encode($salt),
        'nonce' => base64_encode($nonce),
        'tag_length' => strlen($tag),
    ], JSON_THROW_ON_ERROR) . "\n";

    return [
        'payload' => $header . $ciphertext . $tag,
        'sql_bytes' => strlen($sql),
        'compressed_bytes' => strlen($compressed),
    ];
}

/**
 * Decrypt both legacy v1 exports and compressed v2 scheduled exports.
 */
function decrypt_backup_payload(string $payload, string $passphrase): string
{
    if (!function_exists('openssl_decrypt') || !function_exists('openssl_cipher_iv_length')) {
        throw new RuntimeException('OpenSSL support is required to decrypt backups.');
    }

    $headerEnd = strpos($payload, "\n");
    if ($headerEnd === false) {
        throw new RuntimeException('Invalid backup header.');
    }

    try {
        $header = json_decode(substr($payload, 0, $headerEnd), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Invalid backup metadata.', 0, $exception);
    }

    if (!is_array($header)
        || !in_array($header['format'] ?? '', ['srs-sppo-backup-v1', 'srs-sppo-backup-v2'], true)
        || ($header['algorithm'] ?? '') !== 'AES-256-GCM'
        || ($header['kdf'] ?? '') !== 'PBKDF2-SHA256'
        || !isset($header['iterations'], $header['salt'], $header['nonce'], $header['tag_length'])) {
        throw new RuntimeException('Unsupported backup format.');
    }

    $iterations = filter_var($header['iterations'], FILTER_VALIDATE_INT);
    $tagLength = filter_var($header['tag_length'], FILTER_VALIDATE_INT);
    $salt = is_string($header['salt']) ? base64_decode($header['salt'], true) : false;
    $nonce = is_string($header['nonce']) ? base64_decode($header['nonce'], true) : false;
    $encrypted = substr($payload, $headerEnd + 1);
    if ($iterations === false
        || $iterations < 100000
        || $iterations > 1000000
        || $tagLength !== 16
        || $salt === false
        || strlen($salt) !== 16
        || $nonce === false
        || strlen($nonce) !== openssl_cipher_iv_length('aes-256-gcm')
        || strlen($encrypted) <= $tagLength) {
        throw new RuntimeException('Invalid backup encryption metadata.');
    }

    $tag = substr($encrypted, -$tagLength);
    $ciphertext = substr($encrypted, 0, -$tagLength);
    $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plaintext === false) {
        throw new RuntimeException('Decryption failed; check the file and passphrase.');
    }

    if ($header['format'] === 'srs-sppo-backup-v2') {
        if (($header['compression'] ?? '') !== 'gzip' || !function_exists('gzdecode')) {
            throw new RuntimeException('Unsupported backup compression.');
        }
        $sql = gzdecode($plaintext);
        if ($sql === false) {
            throw new RuntimeException('The decrypted backup is not valid gzip data.');
        }
        return $sql;
    }

    return $plaintext;
}
