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
        '034_philippine_application_template_fields' => [
            'title' => 'Philippine sacrament application fields',
            'description' => 'Adds the First Communion and Holy Matrimony details required by the application forms.',
        ],
        '038_data_retention_support' => [
            'title' => 'Data-retention support',
            'description' => 'Adds anonymization tracking fields and indexes used by retention tools.',
        ],
        '041_requester_mailing_address' => [
            'title' => 'Certificate request mailing address',
            'description' => 'Adds an optional postal mailing address to onsite certificate requests so the parish can print and mail the tracking slip.',
        ],
        '042_confirmation_baptism_details' => [
            'title' => 'Confirmation application baptism details',
            'description' => 'Adds baptism date and parish details collected by the walk-in Confirmation application form.',
        ],
        '043_marriage_marital_status' => [
            'title' => 'Marriage application marital status',
            'description' => 'Allows applicants to report Married as a current marital status.',
        ],
        '044_public_calendar_event_details' => [
            'title' => 'Public calendar event details',
            'description' => 'Adds a separate optional public description to schedule events without exposing staff notes.',
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
        '034_philippine_application_template_fields' => [
            'first_communion_applications' => [
                'application_id',
                'applicant_full_name',
                'applicant_birthdate',
                'parent_guardian_name',
                'email',
            ],
            'marriage_applications' => [
                'application_id',
                'groom_full_name',
                'groom_birthdate',
                'groom_birthplace',
                'groom_email',
                'bride_full_name',
                'bride_birthdate',
                'bride_birthplace',
                'bride_email',
                'preferred_wedding_date',
            ],
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
        '041_requester_mailing_address' => [
            'certificate_requests' => ['requester_email'],
        ],
        '042_confirmation_baptism_details' => [
            'confirmation_applications' => [
                'application_id',
                'applicant_full_name',
                'applicant_birthdate',
                'parent_guardian_name',
                'email',
            ],
        ],
        '043_marriage_marital_status' => [
            'marriage_applications' => [
                'groom_marital_status',
                'bride_marital_status',
            ],
        ],
        '044_public_calendar_event_details' => [
            'schedule_events' => ['title', 'notes'],
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
        '034_philippine_application_template_fields' => srs_upgrade_application_template_fields_complete($pdo),
        '038_data_retention_support' => srs_upgrade_retention_complete($pdo),
        '041_requester_mailing_address' => srs_upgrade_column_exists($pdo, 'certificate_requests', 'requester_mailing_address'),
        '042_confirmation_baptism_details' => srs_upgrade_confirmation_baptism_details_complete($pdo),
        '043_marriage_marital_status' => srs_upgrade_marriage_marital_status_complete($pdo),
        '044_public_calendar_event_details' => srs_upgrade_column_exists($pdo, 'schedule_events', 'public_details'),
        default => throw new InvalidArgumentException('Unknown database upgrade.'),
    };
}

function srs_upgrade_marriage_marital_status_complete(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        'SELECT column_name, column_type FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN (?, ?)'
    );
    $statement->execute(['marriage_applications', 'groom_marital_status', 'bride_marital_status']);
    $columns = $statement->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach (['groom_marital_status', 'bride_marital_status'] as $column) {
        if (!isset($columns[$column]) || strpos((string) $columns[$column], "'married'") === false) {
            return false;
        }
    }

    return true;
}

function srs_upgrade_confirmation_baptism_detail_columns(): array
{
    return [
        'baptism_date' => 'DATE NULL',
        'baptism_parish_name' => 'VARCHAR(150) NULL',
        'baptism_parish_address' => 'VARCHAR(255) NULL',
    ];
}

function srs_upgrade_confirmation_baptism_details_complete(PDO $pdo): bool
{
    foreach (array_keys(srs_upgrade_confirmation_baptism_detail_columns()) as $column) {
        if (!srs_upgrade_column_exists($pdo, 'confirmation_applications', $column)) {
            return false;
        }
    }

    return true;
}

function srs_upgrade_application_template_columns(): array
{
    return [
        'first_communion_applications' => [
            'applicant_gender' => 'VARCHAR(20) NULL',
            'applicant_birthplace' => 'VARCHAR(150) NULL',
            'current_address' => 'VARCHAR(255) NULL',
            'school_name' => 'VARCHAR(150) NULL',
            'grade_level_section' => 'VARCHAR(100) NULL',
            'adviser_name' => 'VARCHAR(150) NULL',
            'father_full_name' => 'VARCHAR(150) NULL',
            'mother_maiden_name' => 'VARCHAR(150) NULL',
            'parents_catholic_married' => 'VARCHAR(30) NULL',
            'baptism_date' => 'DATE NULL',
            'baptism_parish_name' => 'VARCHAR(150) NULL',
            'baptism_parish_address' => 'VARCHAR(255) NULL',
            'psa_birth_certificate_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'catechetical_formation_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'confession_completed' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'parental_consent' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'marriage_applications' => [
            'groom_current_address' => 'VARCHAR(255) NULL',
            'groom_address_length' => 'VARCHAR(80) NULL',
            'groom_religion' => 'VARCHAR(100) NULL',
            'groom_occupation' => 'VARCHAR(150) NULL',
            'groom_father_name' => 'VARCHAR(150) NULL',
            'groom_mother_maiden_name' => 'VARCHAR(150) NULL',
            'groom_confirmed' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'groom_previous_marriage' => 'VARCHAR(30) NULL',
            'bride_current_address' => 'VARCHAR(255) NULL',
            'bride_address_length' => 'VARCHAR(80) NULL',
            'bride_religion' => 'VARCHAR(100) NULL',
            'bride_occupation' => 'VARCHAR(150) NULL',
            'bride_father_name' => 'VARCHAR(150) NULL',
            'bride_mother_maiden_name' => 'VARCHAR(150) NULL',
            'bride_confirmed' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'bride_previous_marriage' => 'VARCHAR(30) NULL',
            'assigned_priest_name' => 'VARCHAR(150) NULL',
            'civilly_married_to_each_other' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'civil_marriage_date' => 'DATE NULL',
            'civil_marriage_place' => 'VARCHAR(150) NULL',
            'groom_cenomar_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'bride_cenomar_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'marriage_license_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'psa_marriage_contract_status' => "VARCHAR(20) NOT NULL DEFAULT 'not_applicable'",
            'groom_baptismal_annotated' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'bride_baptismal_annotated' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'groom_confirmation_annotated' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'bride_confirmation_annotated' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'groom_banns_status' => "VARCHAR(30) NOT NULL DEFAULT 'pending'",
            'bride_banns_status' => "VARCHAR(30) NOT NULL DEFAULT 'pending'",
        ],
    ];
}

function srs_upgrade_application_template_fields_complete(PDO $pdo): bool
{
    foreach (srs_upgrade_application_template_columns() as $table => $columns) {
        foreach (array_keys($columns) as $column) {
            if (!srs_upgrade_column_exists($pdo, $table, $column)) {
                return false;
            }
        }
    }

    return true;
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
    } elseif ($migrationKey === '034_philippine_application_template_fields') {
        foreach (srs_upgrade_application_template_columns() as $table => $columns) {
            foreach ($columns as $column => $definition) {
                srs_upgrade_add_column($pdo, $table, $column, $definition);
            }
        }
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
    } elseif ($migrationKey === '041_requester_mailing_address') {
        srs_upgrade_add_column($pdo, 'certificate_requests', 'requester_mailing_address', 'VARCHAR(500) NULL AFTER requester_contact_number');
    } elseif ($migrationKey === '042_confirmation_baptism_details') {
        foreach (srs_upgrade_confirmation_baptism_detail_columns() as $column => $definition) {
            srs_upgrade_add_column($pdo, 'confirmation_applications', $column, $definition);
        }
    } elseif ($migrationKey === '043_marriage_marital_status') {
        $pdo->exec(
            "ALTER TABLE marriage_applications
             MODIFY groom_marital_status ENUM('single', 'widowed', 'annulled', 'married') NOT NULL,
             MODIFY bride_marital_status ENUM('single', 'widowed', 'annulled', 'married') NOT NULL"
        );
    } elseif ($migrationKey === '044_public_calendar_event_details') {
        srs_upgrade_add_column($pdo, 'schedule_events', 'public_details', 'TEXT NULL AFTER title');
    }

    if (!srs_upgrade_is_complete($pdo, $migrationKey)) {
        throw new RuntimeException('The upgrade ran but its expected database objects are still missing.');
    }

    $record = $pdo->prepare(
        'INSERT IGNORE INTO srs_schema_migrations (migration_key, applied_by) VALUES (?, ?)'
    );
    $record->execute([$migrationKey, $userId]);
}
