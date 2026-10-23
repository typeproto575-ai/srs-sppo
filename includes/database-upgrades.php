<?php

declare(strict_types=1);

function srs_database_upgrade_definitions(): array
{
    return [
        '007_site_settings' => [
            'title' => 'Public page settings',
            'description' => 'Creates the editable public-page settings table and inserts missing default values.',
        ],
        '012_request_review_fields' => [
            'title' => 'Certificate request review fields',
            'description' => 'Adds private review notes and rejection reason fields to certificate requests.',
        ],
        '038_data_retention_support' => [
            'title' => 'Data-retention support',
            'description' => 'Adds anonymization tracking fields and indexes used by retention tools.',
        ],
    ];
}

function srs_upgrade_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $statement->execute([$table]);
    return (bool) $statement->fetchColumn();
}

function srs_upgrade_column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $statement->execute([$table, $column]);
    return (bool) $statement->fetchColumn();
}

function srs_upgrade_index_columns(PDO $pdo, string $table, string $index): array
{
    $statement = $pdo->prepare(
        'SELECT column_name FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
         ORDER BY seq_in_index'
    );
    $statement->execute([$table, $index]);
    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function srs_upgrade_has_index_prefix(PDO $pdo, string $table, array $columns): bool
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT index_name FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $statement->execute([$table]);

    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $indexName) {
        $existing = srs_upgrade_index_columns($pdo, $table, (string) $indexName);
        if (array_slice($existing, 0, count($columns)) === $columns) {
            return true;
        }
    }

    return false;
}

function srs_upgrade_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!srs_upgrade_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function srs_upgrade_add_index(PDO $pdo, string $table, string $index, array $columns): void
{
    $existingNamedIndex = srs_upgrade_index_columns($pdo, $table, $index);
    if ($existingNamedIndex !== []) {
        if ($existingNamedIndex !== $columns) {
            throw new RuntimeException(
                "Index {$table}.{$index} already exists with a different definition; inspect it before upgrading."
            );
        }
        return;
    }

    if (srs_upgrade_has_index_prefix($pdo, $table, $columns)) {
        return;
    }

    $quotedColumns = implode(', ', array_map(
        static fn(string $column): string => "`{$column}`",
        $columns
    ));
    $pdo->exec("CREATE INDEX `{$index}` ON `{$table}` ({$quotedColumns})");
}

function srs_upgrade_prerequisite_errors(PDO $pdo, string $migrationKey): array
{
    $required = match ($migrationKey) {
        '007_site_settings' => [],
        '012_request_review_fields' => [
            'certificate_requests' => ['notes'],
        ],
        '038_data_retention_support' => [
            'certificate_requests' => ['status', 'date_processed', 'date_submitted'],
            'sacrament_applications' => ['status', 'date_processed', 'date_submitted'],
            'correction_requests' => ['status', 'updated_at'],
            'audit_logs' => ['created_at'],
            'email_delivery_log' => ['created_at'],
            'notifications' => ['created_at'],
            'submission_rate_limits' => ['submitted_at'],
        ],
        default => throw new InvalidArgumentException('Unknown database upgrade.'),
    };

    $errors = [];
    if ($migrationKey === '007_site_settings' && srs_upgrade_table_exists($pdo, 'site_settings')) {
        foreach (['setting_id', 'setting_key', 'setting_value', 'updated_at', 'updated_by'] as $column) {
            if (!srs_upgrade_column_exists($pdo, 'site_settings', $column)) {
                $errors[] = "Existing site_settings table is missing required column {$column}; inspect it before upgrading.";
            }
        }
    }

    foreach ($required as $table => $columns) {
        if (!srs_upgrade_table_exists($pdo, $table)) {
            $errors[] = "Required table {$table} is missing.";
            continue;
        }
        foreach ($columns as $column) {
            if (!srs_upgrade_column_exists($pdo, $table, $column)) {
                $errors[] = "Required column {$table}.{$column} is missing.";
            }
        }
    }

    return $errors;
}

function srs_upgrade_is_complete(PDO $pdo, string $migrationKey): bool
{
    return match ($migrationKey) {
        '007_site_settings' => srs_upgrade_table_exists($pdo, 'site_settings')
            && (int) $pdo->query(
                "SELECT COUNT(*) FROM site_settings
                 WHERE setting_key IN ('hero_eyebrow_text', 'hero_subtitle_text', 'office_hours_text', 'office_location_text')"
            )->fetchColumn() === 4,
        '012_request_review_fields' => srs_upgrade_table_exists($pdo, 'certificate_requests')
            && srs_upgrade_column_exists($pdo, 'certificate_requests', 'review_notes')
            && srs_upgrade_column_exists($pdo, 'certificate_requests', 'rejection_reason'),
        '038_data_retention_support' => srs_upgrade_retention_complete($pdo),
        default => throw new InvalidArgumentException('Unknown database upgrade.'),
    };
}

function srs_upgrade_retention_complete(PDO $pdo): bool
{
    foreach ([
        'certificate_requests',
        'sacrament_applications',
        'correction_requests',
    ] as $table) {
        if (!srs_upgrade_column_exists($pdo, $table, 'anonymized_at')) {
            return false;
        }
    }

    foreach ([
        ['certificate_requests', 'idx_cert_purge', ['status', 'date_processed', 'date_submitted', 'anonymized_at']],
        ['sacrament_applications', 'idx_sacr_purge', ['status', 'date_processed', 'date_submitted', 'anonymized_at']],
        ['correction_requests', 'idx_corr_purge', ['status', 'updated_at', 'anonymized_at']],
        ['email_delivery_log', 'idx_delivery_purge', ['created_at']],
        ['notifications', 'idx_notif_purge', ['created_at']],
        ['submission_rate_limits', 'idx_ratelimit_purge', ['submitted_at']],
    ] as [$table, $index, $columns]) {
        if (!srs_upgrade_has_index_prefix($pdo, $table, $columns)) {
            return false;
        }
    }

    return srs_upgrade_has_index_prefix($pdo, 'audit_logs', ['created_at']);
}

function srs_database_upgrade_status(PDO $pdo): array
{
    $definitions = srs_database_upgrade_definitions();
    $hasTrackingTable = srs_upgrade_table_exists($pdo, 'srs_schema_migrations');
    $recorded = [];

    if ($hasTrackingTable) {
        $recorded = $pdo->query('SELECT migration_key FROM srs_schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN);
        $recorded = array_fill_keys(array_map('strval', $recorded), true);
    }

    $status = [];
    foreach ($definitions as $key => $definition) {
        $blockers = srs_upgrade_prerequisite_errors($pdo, $key);
        $complete = $blockers === [] && srs_upgrade_is_complete($pdo, $key);
        $status[$key] = $definition + [
            'complete' => $complete,
            'recorded' => isset($recorded[$key]),
            'blockers' => $blockers,
        ];
    }

    return $status;
}

function srs_apply_database_upgrade(PDO $pdo, string $migrationKey, int $userId): void
{
    $definitions = srs_database_upgrade_definitions();
    if (!isset($definitions[$migrationKey])) {
        throw new InvalidArgumentException('Unknown database upgrade.');
    }

    $blockers = srs_upgrade_prerequisite_errors($pdo, $migrationKey);
    if ($blockers !== []) {
        throw new RuntimeException(implode(' ', $blockers));
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS srs_schema_migrations (
            migration_key VARCHAR(80) PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_by INT UNSIGNED NULL
        ) ENGINE=InnoDB'
    );

    if ($migrationKey === '007_site_settings') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS site_settings (
                setting_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(64) NOT NULL UNIQUE,
                setting_value TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by INT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $defaults = [
            ['hero_eyebrow_text', 'Parish Office Online'],
            ['hero_subtitle_text', 'Dingalan, Aurora - the parish\'s sacrament records and certificates, kept faithfully for generations, now easier to reach.'],
            ['office_hours_text', "Monday - Saturday: 8:00 AM - 12:00 NN\nMonday - Saturday: 2:00 PM - 5:00 PM\nSunday: Closed"],
            ['office_location_text', "St. Patrick's Parish Office\nDingalan, Aurora, Philippines"],
        ];
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)'
        );
        foreach ($defaults as [$key, $value]) {
            $insert->execute([$key, $value]);
        }
    } elseif ($migrationKey === '012_request_review_fields') {
        srs_upgrade_add_column($pdo, 'certificate_requests', 'review_notes', 'TEXT NULL');
        srs_upgrade_add_column($pdo, 'certificate_requests', 'rejection_reason', 'TEXT NULL');
    } elseif ($migrationKey === '038_data_retention_support') {
        srs_upgrade_add_column($pdo, 'certificate_requests', 'anonymized_at', 'DATETIME NULL');
        srs_upgrade_add_column($pdo, 'sacrament_applications', 'anonymized_at', 'DATETIME NULL');
        srs_upgrade_add_column($pdo, 'correction_requests', 'anonymized_at', 'DATETIME NULL');

        srs_upgrade_add_index(
            $pdo,
            'certificate_requests',
            'idx_cert_purge',
            ['status', 'date_processed', 'date_submitted', 'anonymized_at']
        );
        srs_upgrade_add_index(
            $pdo,
            'sacrament_applications',
            'idx_sacr_purge',
            ['status', 'date_processed', 'date_submitted', 'anonymized_at']
        );
        srs_upgrade_add_index(
            $pdo,
            'correction_requests',
            'idx_corr_purge',
            ['status', 'updated_at', 'anonymized_at']
        );
        srs_upgrade_add_index($pdo, 'email_delivery_log', 'idx_delivery_purge', ['created_at']);
        srs_upgrade_add_index($pdo, 'notifications', 'idx_notif_purge', ['created_at']);
        srs_upgrade_add_index($pdo, 'submission_rate_limits', 'idx_ratelimit_purge', ['submitted_at']);
    }

    if (!srs_upgrade_is_complete($pdo, $migrationKey)) {
        throw new RuntimeException('The upgrade ran but its expected database objects are still missing.');
    }

    $record = $pdo->prepare(
        'INSERT IGNORE INTO srs_schema_migrations (migration_key, applied_by) VALUES (?, ?)'
    );
    $record->execute([$migrationKey, $userId]);
}
