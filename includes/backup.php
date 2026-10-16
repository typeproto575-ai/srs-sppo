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
