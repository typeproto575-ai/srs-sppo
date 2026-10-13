<?php

declare(strict_types=1);

/**
 * Export the connected database as a portable SQL dump.
 *
 * Table and column names come from the database metadata, while values are
 * quoted through PDO. The dump can therefore be restored with multi-statements
 * enabled on a separate database connection.
 */
function export_database_sql(PDO $pdo): string
{
    $output = "-- SRS-SPPO database export\n"
        . "-- Generated " . date('Y-m-d H:i:s') . "\n\n"
        . "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $table = (string) $table;
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $createRow = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch();
        if (!$createRow || !isset($createRow['Create Table'])) {
            throw new RuntimeException("Could not read the definition for table '{$table}'.");
        }

        $output .= "-- ----------------------------\n"
            . "-- Table: {$table}\n"
            . "-- ----------------------------\n"
            . "DROP TABLE IF EXISTS {$quotedTable};\n"
            . $createRow['Create Table'] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $columns = array_map(
                static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
                array_keys($row)
            );
            $values = array_map(
                static fn($value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                array_values($row)
            );
            $output .= "INSERT INTO {$quotedTable} (" . implode(', ', $columns)
                . ") VALUES (" . implode(', ', $values) . ");\n";
        }
        $output .= "\n";
    }

    return $output . "SET FOREIGN_KEY_CHECKS=1;\n";
}
