<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/person-linking.php';
require_once __DIR__ . '/../includes/sacrament-data.php';

final class DatabaseIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('SRS_TEST_DB_DSN') ?: '';
        $databaseName = getenv('SRS_TEST_DB_NAME') ?: '';

        if ($dsn === '' || $databaseName === '') {
            self::markTestSkipped(
                'Set SRS_TEST_DB_DSN and SRS_TEST_DB_NAME to run database integration tests.'
            );
        }

        if ($databaseName === 'srs_sppo_db' || $databaseName === (getenv('SRS_DB_NAME') ?: '')) {
            self::fail('Refusing to run integration tests against the application database.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('SRS_TEST_DB_USER') ?: 'root',
            getenv('SRS_TEST_DB_PASS') !== false ? (string) getenv('SRS_TEST_DB_PASS') : '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $connectedDatabase = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($connectedDatabase !== $databaseName) {
            self::fail('SRS_TEST_DB_DSN must connect to SRS_TEST_DB_NAME exactly.');
        }
    }

    public function testV2SchemaContainsOnlyTheUnifiedWorkflowTables(): void
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $expected = [
            'announcements',
            'audit_logs',
            'certificate_requests',
            'notifications',
            'persons',
            'sacrament_applications',
            'sacrament_participants',
            'sacrament_records',
            'schedule_events',
            'settings',
            'users',
        ];
        sort($expected);

        self::assertSame($expected, $tables);
        $columns = $this->pdo->query('SHOW COLUMNS FROM persons')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('observanda', $columns);
        self::assertContains('public_reference', $columns);
    }

    public function testPublicPersonReferenceIsStableAcrossMatchingApplications(): void
    {
        $this->pdo->beginTransaction();
        try {
            $identity = [
                'first_name' => 'Reference',
                'middle_name' => 'Test',
                'last_name' => 'Parishioner',
                'suffix' => '',
                'birth_date' => '2001-02-03',
                'birth_place' => 'Dingalan',
            ];
            $first = public_person_reference($this->pdo, null, $identity);
            $second = public_person_reference($this->pdo, null, $identity);

            self::assertSame($first['person_id'], $second['person_id']);
            self::assertSame($first['reference'], $second['reference']);
            self::assertMatchesRegularExpression('/^SPPO-P-[A-Z2-9]{16}$/', $first['reference']);
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testPublicSubmissionCannotDuplicateAnOpenApplicationForTheSamePersonAndSacrament(): void
    {
        $this->pdo->beginTransaction();
        try {
            $person = public_person_reference($this->pdo, null, [
                'first_name' => 'Open',
                'last_name' => 'Application Test',
                'birth_date' => '2002-03-04',
            ]);
            $insert = $this->pdo->prepare(
                "INSERT INTO sacrament_applications
                    (request_no, sacrament_type, person_id, applicant_name, applicant_email)
                 VALUES (?, 'confirmation', ?, 'Open Application Test', 'open@example.test')"
            );
            $insert->execute([
                generate_request_no($this->pdo, 'sacrament_applications'),
                $person['person_id'],
            ]);

            try {
                public_assert_no_open_sacrament_application(
                    $this->pdo,
                    'confirmation',
                    [$person['person_id']],
                    $person['reference']
                );
                self::fail('An open application should prevent a duplicate submission.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($person['reference'], $e->getMessage());
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testUnifiedRegisterAndWorkflowTablesLinkToMasterPersons(): void
    {
        $links = [
            'sacrament_records' => ['person_id', 'spouse_person_id'],
            'sacrament_applications' => ['person_id', 'spouse_person_id'],
            'certificate_requests' => ['person_id', 'record_id'],
        ];

        foreach ($links as $table => $columns) {
            foreach ($columns as $column) {
                $statement = $this->pdo->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.key_column_usage
                     WHERE table_schema = DATABASE()
                       AND table_name = ?
                       AND column_name = ?
                       AND referenced_table_name = ?'
                );
                $expectedTable = $column === 'record_id' ? 'sacrament_records' : 'persons';
                $statement->execute([$table, $column, $expectedTable]);
                self::assertSame(
                    1,
                    (int) $statement->fetchColumn(),
                    "Missing foreign key: {$table}.{$column} -> {$expectedTable}"
                );
            }
        }
    }

    public function testSavingRegisterIdentityReusesMasterPerson(): void
    {
        $this->pdo->beginTransaction();
        try {
            $identity = [
                'first_name' => 'Integration',
                'middle_name' => 'Master',
                'last_name' => 'Person',
                'birth_date' => '2000-01-02',
                'birth_place' => 'Dingalan',
                'father_name' => 'Parent One',
                'mother_maiden_name' => 'Parent Two',
            ];
            $personId = save_person_identity($this->pdo, null, $identity);
            $samePersonId = save_person_identity($this->pdo, $personId, [
                'first_name' => 'Integration',
                'middle_name' => 'Master',
                'last_name' => 'Person',
                'birth_date' => '2000-01-02',
            ]);

            self::assertSame($personId, $samePersonId);
            $person = sd_get_person($this->pdo, $personId);
            self::assertSame('Integration Master Person', $person['full_name']);
            self::assertSame('Parent One', $person['father_name']);
            self::assertSame('Parent Two', $person['mother_maiden_name']);

            sd_save_person($this->pdo, ['observanda' => 'Profile-level integration note'], $personId);
            self::assertSame('Profile-level integration note', sd_get_person($this->pdo, $personId)['observanda']);
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testParentProfileLinksRejectSelfAndCyclesAndAppearOnFamilyProfiles(): void
    {
        $this->pdo->beginTransaction();
        try {
            $fatherId = sd_save_person($this->pdo, [
                'first_name' => 'Linked',
                'last_name' => 'Father',
            ]);
            $childId = sd_save_person($this->pdo, [
                'first_name' => 'Linked',
                'last_name' => 'Child',
                'father_person_id' => $fatherId,
            ]);

            self::assertNull(sd_validate_parent_profile_links(
                $this->pdo,
                $childId,
                [$fatherId]
            ));
            self::assertSame(
                'A person cannot be linked as their own parent.',
                sd_validate_parent_profile_links($this->pdo, $childId, [$childId])
            );
            self::assertSame(
                'This parent link would create a family cycle. Choose another profile.',
                sd_validate_parent_profile_links($this->pdo, $fatherId, [$childId])
            );
            self::assertSame($fatherId, (int) sd_get_family($this->pdo, $childId)['father']['id']);

            $snapshot = sd_person_audit_snapshot($this->pdo, $childId);
            self::assertSame($fatherId, (int) $snapshot['father_person_id']);
            self::assertArrayHasKey('mother_person_id', $snapshot);
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testCertificateRequestMovesThroughTheV2OfficeLifecycle(): void
    {
        $this->pdo->beginTransaction();
        try {
            $userId = $this->insertSecretary();
            $personId = save_person_identity($this->pdo, null, [
                'first_name' => 'Ana',
                'last_name' => 'Integration',
                'birth_date' => '2015-04-12',
            ]);
            $recordId = sd_save_record($this->pdo, [
                'sacrament_type' => 'baptism',
                'person_id' => $personId,
                'event_date' => '2015-05-10',
                'place' => 'Saint Patrick Parish',
                'minister_name' => 'Integration Priest',
                'entry_no' => '15-01',
                'book_no' => 'I',
                'page_no' => '1',
                'line_no' => '1',
            ], [], $userId);
            sd_verify_book_entry($this->pdo, $recordId, $userId);

            $requestNo = 'T' . bin2hex(random_bytes(7));
            $request = $this->pdo->prepare(
                'INSERT INTO certificate_requests
                    (request_no, channel, sacrament_type, subject_name, requester_name,
                     requester_relationship, requester_email, purpose)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $request->execute([
                $requestNo,
                'online',
                'baptism',
                'Ana Integration',
                'Parent Integration',
                'parent',
                'parent@example.invalid',
                'school',
            ]);
            $requestId = (int) $this->pdo->lastInsertId();

            self::assertSame('new', $this->certificateStatus($requestId));
            self::assertTrue(cert_request_transition_valid('new', 'matched'));

            $this->pdo->prepare(
                "UPDATE certificate_requests
                 SET person_id = ?, record_id = ?, matched_by = ?, matched_at = NOW(), status = 'matched'
                 WHERE id = ?"
            )->execute([$personId, $recordId, $userId, $requestId]);

            self::assertFalse(cert_request_payment_gate([
                'channel' => 'online',
                'status' => 'matched',
                'paid_at' => null,
            ], 'for_signature')['ok']);

            $this->pdo->prepare(
                'UPDATE certificate_requests SET paid_at = NOW(), paid_by = ? WHERE id = ?'
            )->execute([$userId, $requestId]);
            self::assertTrue(cert_request_payment_gate([
                'channel' => 'online',
                'status' => 'matched',
                'paid_at' => date('Y-m-d H:i:s'),
            ], 'for_signature')['ok']);

            self::assertTrue(sd_can_print_certificate(sd_get_record($this->pdo, $recordId) ?? []));
            self::assertTrue(cert_request_transition_valid('matched', 'for_signature'));
            $this->pdo->prepare(
                "UPDATE certificate_requests
                 SET status = 'for_signature', certificate_no = ?, printed_by = ?, printed_at = NOW()
                 WHERE id = ?"
            )->execute(['C-TEST-1', $userId, $requestId]);
            self::assertTrue(cert_request_transition_valid('for_signature', 'ready'));

            $this->pdo->prepare(
                "UPDATE certificate_requests SET status = 'ready', signed_at = NOW() WHERE id = ?"
            )->execute([$requestId]);
            self::assertTrue(cert_request_transition_valid('ready', 'released'));

            $this->pdo->prepare(
                "UPDATE certificate_requests
                 SET status = 'released', claimant_type = 'self', id_verified = 1,
                     released_by = ?, released_at = NOW()
                 WHERE id = ?"
            )->execute([$userId, $requestId]);
            $this->pdo->prepare(
                'INSERT INTO audit_logs (user_id, actor_name, action, target_table, target_id, description)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                'Integration Secretary',
                'certificate.release',
                'certificate_requests',
                $requestId,
                'Integration lifecycle release.',
            ]);
            $this->pdo->prepare(
                'INSERT INTO notifications
                    (channel, type, title, recipient, delivery_status, entity_type, entity_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                'email',
                'certificate_ready',
                'Certificate ready',
                'parent@example.invalid',
                'disabled',
                'certificate_request',
                $requestId,
            ]);

            $result = $this->pdo->prepare(
                'SELECT cr.status, cr.printed_at, cr.signed_at, cr.released_at,
                        cr.claimant_type, cr.id_verified, al.action, n.delivery_status
                 FROM certificate_requests cr
                 JOIN audit_logs al ON al.target_id = cr.id AND al.target_table = ?
                 JOIN notifications n ON n.entity_id = cr.id AND n.channel = ?
                 WHERE cr.id = ?'
            );
            $result->execute(['certificate_requests', 'email', $requestId]);
            $row = $result->fetch();

            self::assertSame('released', $row['status']);
            self::assertNotNull($row['printed_at']);
            self::assertNotNull($row['signed_at']);
            self::assertNotNull($row['released_at']);
            self::assertSame('self', $row['claimant_type']);
            self::assertSame(1, (int) $row['id_verified']);
            self::assertSame('certificate.release', $row['action']);
            self::assertSame('disabled', $row['delivery_status']);
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testSacramentApplicationAndRegisterRecordShareOnePerson(): void
    {
        $this->pdo->beginTransaction();
        try {
            $userId = $this->insertSecretary();
            $personId = save_person_identity($this->pdo, null, [
                'first_name' => 'Luis',
                'last_name' => 'Application',
                'birth_date' => '2018-06-15',
            ]);
            $requestNo = 'A' . bin2hex(random_bytes(7));
            $application = $this->pdo->prepare(
                'INSERT INTO sacrament_applications
                    (request_no, channel, sacrament_type, person_id, applicant_name, applicant_email,
                     preferred_date, details, requirements)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $application->execute([
                $requestNo,
                'online',
                'baptism',
                $personId,
                'Parent Application',
                'parent@example.invalid',
                '2027-06-15',
                json_encode(['child_name' => 'Luis Application'], JSON_THROW_ON_ERROR),
                json_encode(['birth_certificate' => 'pending'], JSON_THROW_ON_ERROR),
            ]);
            $applicationId = (int) $this->pdo->lastInsertId();

            $recordId = sd_save_record($this->pdo, [
                'sacrament_type' => 'baptism',
                'person_id' => $personId,
                'event_date' => '2027-06-15',
                'encoded_by' => $userId,
            ], [], $userId);
            $this->pdo->prepare(
                "UPDATE sacrament_applications
                 SET status = 'completed', record_id = ?, processed_by = ?
                 WHERE id = ?"
            )->execute([$recordId, $userId, $applicationId]);

            $check = $this->pdo->prepare(
                'SELECT a.status, a.person_id, a.record_id, r.person_id AS record_person_id,
                        a.details, a.requirements
                 FROM sacrament_applications a
                 JOIN sacrament_records r ON r.id = a.record_id
                 WHERE a.id = ?'
            );
            $check->execute([$applicationId]);
            $row = $check->fetch();

            self::assertSame('completed', $row['status']);
            self::assertSame($personId, (int) $row['person_id']);
            self::assertSame($personId, (int) $row['record_person_id']);
            self::assertSame($recordId, (int) $row['record_id']);
            self::assertSame(['child_name' => 'Luis Application'], json_decode($row['details'], true));
            self::assertSame(['birth_certificate' => 'pending'], json_decode($row['requirements'], true));
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testBaptismParentUnionAndMarriageParishPersistThroughSharedRecordSave(): void
    {
        $this->pdo->beginTransaction();
        try {
            $userId = $this->insertSecretary();
            $personId = save_person_identity($this->pdo, null, [
                'first_name' => 'Maria',
                'last_name' => 'Parents',
                'birth_date' => '2024-02-14',
            ]);

            $recordId = sd_save_record($this->pdo, [
                'sacrament_type' => 'baptism',
                'person_id' => $personId,
                'event_date' => '2024-03-10',
                'parents_union_type' => 'legitimate',
                'parents_marriage_parish' => 'Saint Joseph Parish',
            ], [], $userId);
            $record = sd_get_record($this->pdo, $recordId);
            self::assertNotNull($record);
            self::assertSame('canonical', $record['parents_union_type']);
            self::assertSame('Saint Joseph Parish', $record['parents_marriage_parish']);

            sd_save_record($this->pdo, [
                'id' => $recordId,
                'sacrament_type' => 'baptism',
                'person_id' => $personId,
                'event_date' => '2024-03-10',
                'parents_union_type' => 'civil',
            ], null, $userId);
            $record = sd_get_record($this->pdo, $recordId);
            self::assertNotNull($record);
            self::assertSame('civil', $record['parents_union_type']);
            self::assertNull($record['parents_marriage_parish']);
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testBaptismRegisterSaveMatchesSchemaAndPersistsGodparentRows(): void
    {
        $this->pdo->beginTransaction();
        try {
            $userId = $this->insertSecretary();
            $personId = save_person_identity($this->pdo, null, [
                'first_name' => 'Baptism',
                'last_name' => 'Register Save',
                'birth_date' => '2025-02-14',
            ]);

            $recordId = sd_save_baptismal_register_record($this->pdo, [
                'person_id' => $personId,
                'event_date' => '2025-03-10',
                'place' => 'Saint Patrick Parish',
                'minister_name' => 'Integration Priest',
                'entry_no' => 'BT-01',
                'book_no' => 'I',
                'page_no' => '1',
                'line_no' => '1',
                'parents_union_type' => 'canonical',
                'parents_marriage_parish' => 'Saint Joseph Parish',
                'details' => ['parents_residence' => 'Dingalan, Aurora'],
                'remarks' => 'Integration save',
            ], [[
                'role' => 'godfather',
                'full_name' => 'Integration Godparent',
                'residence' => str_repeat('R', 150),
            ]], $userId);

            $recordQuery = $this->pdo->prepare(
                'SELECT person_id, event_date, place, minister_name, entry_no, book_no, page_no, line_no,
                        parents_union_type, parents_marriage_parish, legitimacy
                 FROM sacrament_records WHERE id = ?'
            );
            $recordQuery->execute([$recordId]);
            self::assertSame([
                'person_id' => $personId,
                'event_date' => '2025-03-10',
                'place' => 'Saint Patrick Parish',
                'minister_name' => 'Integration Priest',
                'entry_no' => 'BT-01',
                'book_no' => 'I',
                'page_no' => '1',
                'line_no' => '1',
                'parents_union_type' => 'canonical',
                'parents_marriage_parish' => 'Saint Joseph Parish',
                'legitimacy' => null,
            ], $recordQuery->fetch(PDO::FETCH_ASSOC));

            $participantQuery = $this->pdo->prepare(
                'SELECT role, full_name, residence, sort_order
                 FROM sacrament_participants WHERE record_id = ?'
            );
            $participantQuery->execute([$recordId]);
            self::assertSame([[
                'role' => 'godfather',
                'full_name' => 'Integration Godparent',
                'residence' => str_repeat('R', 150),
                'sort_order' => 1,
            ]], $participantQuery->fetchAll(PDO::FETCH_ASSOC));

            try {
                sd_save_baptismal_register_record($this->pdo, [
                    'person_id' => $personId,
                    'event_date' => '2025-03-10',
                ], [[
                    'role' => 'godmother',
                    'full_name' => 'Too Long Residence',
                    'residence' => str_repeat('R', 151),
                ]], $userId);
                self::fail('An overlong godparent residence should be rejected before insertion.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Godparent residence must be 150 characters or fewer.',
                    $exception->getMessage()
                );
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testScheduleEventsAllowOverlappingBaptismsAndFlagOtherConflicts(): void
    {
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO schedule_events
                    (event_type, title, public_details, event_date, start_time, end_time, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                'wedding',
                'Integration Wedding',
                'Public event detail',
                '2027-06-12',
                '10:00:00',
                '11:00:00',
                'Internal scheduling note',
            ]);
            $firstId = (int) $this->pdo->lastInsertId();
            $insert->execute([
                'baptism',
                'Integration Baptism',
                null,
                '2027-06-12',
                '10:30:00',
                '11:30:00',
                null,
            ]);
            $secondId = (int) $this->pdo->lastInsertId();
            $insert->execute([
                'baptism',
                'Integration Baptism 2',
                null,
                '2027-06-12',
                '10:45:00',
                '11:15:00',
                null,
            ]);
            $thirdId = (int) $this->pdo->lastInsertId();

            $events = $this->pdo->prepare(
                'SELECT event_type, event_date, start_time, end_time, public_details, notes
                 FROM schedule_events WHERE id IN (?, ?, ?) ORDER BY id'
            );
            $events->execute([$firstId, $secondId, $thirdId]);
            $rows = $events->fetchAll();

            self::assertCount(3, $rows);
            self::assertSame('2027-06-12', $rows[1]['event_date']);
            self::assertTrue(event_times_overlap(
                $rows[1]['start_time'],
                $rows[1]['end_time'],
                $rows[2]['start_time'],
                $rows[2]['end_time']
            ));
            self::assertFalse(schedule_events_overlap_as_conflict($rows[1], $rows[2]));
            self::assertTrue(schedule_events_overlap_as_conflict($rows[0], $rows[1]));
            self::assertFalse(event_times_overlap('10:00:00', '11:00:00', '11:00:00', '12:00:00'));
            self::assertSame('Public event detail', $rows[0]['public_details']);
            self::assertSame('Internal scheduling note', $rows[0]['notes']);
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testDatabaseBackupCanBeRestoredIntoANewIsolatedDatabase(): void
    {
        $databaseName = (string) getenv('SRS_TEST_DB_NAME');
        $restoreName = $databaseName . '_restore_' . bin2hex(random_bytes(4));
        if (strlen($restoreName) > 64 || $restoreName === 'srs_sppo_db') {
            self::fail('The generated restore database name is invalid.');
        }

        $host = getenv('SRS_TEST_DB_HOST') ?: '127.0.0.1';
        $user = getenv('SRS_TEST_DB_USER') ?: 'root';
        $password = getenv('SRS_TEST_DB_PASS') !== false ? (string) getenv('SRS_TEST_DB_PASS') : '';
        $server = new PDO(
            "mysql:host={$host};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]
        );
        $quotedRestore = '`' . str_replace('`', '``', $restoreName) . '`';
        $restoreCreated = false;

        $this->pdo->beginTransaction();
        try {
            $marker = 'Backup restore ' . bin2hex(random_bytes(4));
            $insert = $this->pdo->prepare(
                'INSERT INTO announcements (title, content, is_published)
                 VALUES (?, ?, 0)'
            );
            $insert->execute([$marker, 'Integration backup marker']);

            $sourceCount = (int) $this->pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
            $dump = export_database_sql($this->pdo);
            self::assertStringContainsString('CREATE TABLE `announcements`', $dump['sql']);
            self::assertStringContainsString($this->pdo->quote($marker), $dump['sql']);
            self::assertGreaterThanOrEqual(11, $dump['table_count']);

            $server->exec("CREATE DATABASE {$quotedRestore} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $restoreCreated = true;
            $restored = new PDO(
                "mysql:host={$host};dbname={$restoreName};charset=utf8mb4",
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                ]
            );
            $restored->exec($dump['sql']);

            self::assertSame(
                $sourceCount,
                (int) $restored->query('SELECT COUNT(*) FROM announcements')->fetchColumn()
            );
            $check = $restored->prepare('SELECT content FROM announcements WHERE title = ?');
            $check->execute([$marker]);
            self::assertSame('Integration backup marker', $check->fetchColumn());
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($restoreCreated) {
                $server->exec("DROP DATABASE {$quotedRestore}");
            }
        }
    }

    private function insertSecretary(): int
    {
        $user = $this->pdo->prepare(
            'INSERT INTO users (full_name, username, email, password_hash, role)
             VALUES (?, ?, ?, ?, ?)'
        );
        $user->execute([
            'Integration Secretary',
            'integration_' . bin2hex(random_bytes(5)),
            'integration-' . bin2hex(random_bytes(5)) . '@example.invalid',
            password_hash('integration-only-password', PASSWORD_DEFAULT),
            'secretary',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function certificateStatus(int $requestId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM certificate_requests WHERE id = ?');
        $statement->execute([$requestId]);
        return (string) $statement->fetchColumn();
    }
}
