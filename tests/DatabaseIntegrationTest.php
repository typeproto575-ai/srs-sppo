<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/retention-purge.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/backup.php';

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
    }

    public function testRequiredWorkflowTablesExist(): void
    {
        foreach (['users', 'certificate_requests', 'sacrament_applications', 'audit_logs'] as $table) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $statement->execute([$table]);
            self::assertSame(1, (int) $statement->fetchColumn(), "Missing required table: {$table}");
        }
    }

    public function testCertificateRequestCanBeCreatedAndRolledBack(): void
    {
        $this->pdo->beginTransaction();
        try {
            $tracking = 'TEST-' . bin2hex(random_bytes(8));
            $statement = $this->pdo->prepare(
                'INSERT INTO certificate_requests
                    (tracking_number, submission_channel, sacrament_type, requester_name,
                     requester_relationship, requester_email, purpose)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $tracking,
                'online',
                'baptismal',
                'Integration Test',
                'self',
                'integration-test@example.invalid',
                'personal',
            ]);

            $check = $this->pdo->prepare(
                'SELECT status, submission_channel FROM certificate_requests WHERE tracking_number = ?'
            );
            $check->execute([$tracking]);
            $row = $check->fetch();

            self::assertSame('pending', $row['status']);
            self::assertSame('online', $row['submission_channel']);
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testBaptismalCertificateLifecycleThroughRelease(): void
    {
        $userId = 0;
        $requestId = 0;
        $generationId = 0;
        $tracking = 'TEST-LIFE-' . bin2hex(random_bytes(8));

        try {
            $this->pdo->beginTransaction();

            $user = $this->pdo->prepare(
                'INSERT INTO users (full_name, username, email, password_hash, role)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $user->execute([
                'Integration Secretary',
                'integration_' . bin2hex(random_bytes(4)),
                'integration-secretary-' . bin2hex(random_bytes(4)) . '@example.invalid',
                password_hash('integration-only-password', PASSWORD_DEFAULT),
                'secretary',
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $request = $this->pdo->prepare(
                'INSERT INTO certificate_requests
                    (tracking_number, submission_channel, sacrament_type, requester_name,
                     requester_relationship, requester_email, purpose)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $request->execute([
                $tracking,
                'online',
                'baptismal',
                'Maria Integration',
                'parent',
                'maria-integration@example.invalid',
                'school',
            ]);
            $requestId = (int) $this->pdo->lastInsertId();

            $detail = $this->pdo->prepare(
                'INSERT INTO baptismal_certificate_requests
                    (request_id, child_full_name, father_full_name, mother_full_name, birthdate)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $detail->execute([
                $requestId,
                'Ana Integration',
                'Juan Integration',
                'Maria Integration',
                '2015-04-12',
            ]);

            $this->pdo->prepare(
                'UPDATE certificate_requests
                 SET status = "awaiting_payment", match_confirmed_at = NOW(), match_confirmed_by = ?
                 WHERE id = ?'
            )->execute([$userId, $requestId]);

            self::assertFalse(cert_request_payment_gate([
                'submission_channel' => 'online',
                'status' => 'awaiting_payment',
                'paid_at' => null,
            ], 'approved')['ok']);

            $this->pdo->prepare(
                'UPDATE certificate_requests
                 SET paid_at = NOW(), paid_by = ?, status = "approved", processed_by = ?
                 WHERE id = ?'
            )->execute([$userId, $userId, $requestId]);
            $this->pdo->prepare(
                'UPDATE certificate_requests SET status = "processing" WHERE id = ?'
            )->execute([$requestId]);

            $generation = $this->pdo->prepare(
                'INSERT INTO certificate_generation_log
                    (certificate_request_id, generated_by, print_status)
                 VALUES (?, ?, "generated")'
            );
            $generation->execute([$requestId, $userId]);
            $generationId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                'UPDATE certificate_requests SET status = "ready_for_pickup" WHERE id = ?'
            )->execute([$requestId]);
            $this->pdo->commit();

            $printed = log_certificate_printed($this->pdo, $requestId, $userId);
            self::assertTrue($printed['ok']);
            self::assertSame('printed', cert_current_generation_log($this->pdo, $requestId)['print_status']);

            $notFinalized = log_certificate_released(
                $this->pdo,
                $requestId,
                $generationId,
                $userId,
                'Maria Integration',
                'Parent',
                'Integration release before finalization',
                true
            );
            self::assertFalse($notFinalized['ok']);

            $this->pdo->prepare(
                'UPDATE certificate_generation_log
                 SET finalized_by = ?, finalized_at = NOW()
                 WHERE id = ?'
            )->execute([$userId, $generationId]);

            $released = log_certificate_released(
                $this->pdo,
                $requestId,
                $generationId,
                $userId,
                'Maria Integration',
                'Parent',
                'Integration test release',
                true
            );
            self::assertTrue($released['ok'], $released['reason'] ?? 'Release failed.');

            $result = $this->pdo->prepare(
                'SELECT cr.status, gl.print_status, gl.claimant_name, gl.id_verified
                 FROM certificate_requests cr
                 JOIN certificate_generation_log gl ON gl.certificate_request_id = cr.id
                 WHERE cr.id = ? AND gl.id = ?'
            );
            $result->execute([$requestId, $generationId]);
            $row = $result->fetch();

            self::assertSame('released', $row['status']);
            self::assertSame('released', $row['print_status']);
            self::assertSame('Maria Integration', $row['claimant_name']);
            self::assertSame(1, (int) $row['id_verified']);

            $audit = $this->pdo->prepare(
                'SELECT COUNT(*) FROM audit_logs
                 WHERE action = "certificate_released" AND target_id = ?'
            );
            $audit->execute([$requestId]);
            self::assertSame(1, (int) $audit->fetchColumn());

            $notification = $this->pdo->prepare(
                'SELECT COUNT(*) FROM notifications
                 WHERE type = "certificate_picked_up"
                   AND message LIKE ?
                   AND link = ?'
            );
            $notification->execute([
                '%Maria Integration%',
                '/admin/requests/queue.php?status=released',
            ]);
            self::assertSame(1, (int) $notification->fetchColumn());

            $email = $this->pdo->prepare(
                'SELECT recipient, event_type, entity_type, entity_id,
                        tracking_number, delivery_status, error_message
                 FROM email_delivery_log
                 WHERE entity_type = ? AND entity_id = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $email->execute(['certificate_request', $requestId]);
            $emailRow = $email->fetch();
            self::assertSame('maria-integration@example.invalid', $emailRow['recipient']);
            self::assertSame('status_update', $emailRow['event_type']);
            self::assertSame('certificate_request', $emailRow['entity_type']);
            self::assertSame($requestId, (int) $emailRow['entity_id']);
            self::assertSame($tracking, $emailRow['tracking_number']);
            self::assertSame('disabled', $emailRow['delivery_status']);
            self::assertSame('Email is disabled.', $emailRow['error_message']);
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($requestId > 0) {
                $this->pdo->prepare('DELETE FROM certificate_requests WHERE id = ?')->execute([$requestId]);
            }
            if ($userId > 0) {
                $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            }
            $this->pdo->exec('DELETE FROM notifications WHERE type = "certificate_picked_up"');
            $this->pdo->prepare(
                'DELETE FROM audit_logs WHERE action = "certificate_released" AND target_id = ?'
            )->execute([$requestId]);
            $this->pdo->prepare(
                'DELETE FROM email_delivery_log WHERE entity_type = "certificate_request" AND entity_id = ?'
            )->execute([$requestId]);
        }
    }

    public function testBaptismApplicationApprovalPrerequisites(): void
    {
        $applicationId = 0;
        $tracking = 'TEST-BAPTISM-' . bin2hex(random_bytes(8));

        try {
            $this->pdo->beginTransaction();

            $application = $this->pdo->prepare(
                'INSERT INTO sacrament_applications
                    (tracking_number, submission_channel, sacrament_type, status, notes)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $application->execute([
                $tracking,
                'online',
                'baptismal',
                'awaiting_payment',
                'Integration baptism application',
            ]);
            $applicationId = (int) $this->pdo->lastInsertId();

            $detail = $this->pdo->prepare(
                'INSERT INTO baptismal_applications
                    (application_id, child_full_name, child_birthdate, child_birthplace,
                     parent_guardian_name, email)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $detail->execute([
                $applicationId,
                'Luis Integration',
                '2018-06-15',
                'Dingalan, Aurora',
                'Parent Integration',
                'parent-integration-' . bin2hex(random_bytes(4)) . '@example.invalid',
            ]);
            $baptismalDetailId = (int) $this->pdo->lastInsertId();

            self::assertFalse(sacrament_application_payment_gate([
                'submission_channel' => 'online',
                'status' => 'awaiting_payment',
                'paid_at' => null,
            ], 'requirements_pending')['ok']);

            $blocked = can_approve_sacrament_application($this->pdo, $applicationId, 'baptismal');
            self::assertFalse($blocked['ok']);
            self::assertContains('Required documents not yet verified.', $blocked['missing']);
            self::assertContains('Parents/godparents seminar not yet completed.', $blocked['missing']);
            self::assertContains('At least one godfather (ninong) must be recorded.', $blocked['missing']);
            self::assertContains('At least one godmother (ninang) must be recorded.', $blocked['missing']);

            $this->pdo->prepare(
                'UPDATE sacrament_applications
                 SET paid_at = NOW(), paid_by = ?, status = "ready_for_approval"
                 WHERE id = ?'
            )->execute([null, $applicationId]);
            $this->pdo->prepare(
                'UPDATE baptismal_applications
                 SET documents_verified = 1, seminar_attended = 1
                 WHERE id = ?'
            )->execute([$baptismalDetailId]);

            $godparent = $this->pdo->prepare(
                'INSERT INTO baptismal_application_godparents
                    (baptismal_application_id, godparent_name, godparent_type)
                 VALUES (?, ?, ?)'
            );
            $godparent->execute([$baptismalDetailId, 'Godfather Integration', 'godfather']);
            $godparent->execute([$baptismalDetailId, 'Godmother Integration', 'godmother']);

            $ready = can_approve_sacrament_application($this->pdo, $applicationId, 'baptismal');
            self::assertTrue($ready['ok'], implode(' ', $ready['missing']));
            self::assertSame([], $ready['missing']);

            $this->pdo->prepare(
                'UPDATE sacrament_applications
                 SET status = "approved", date_processed = NOW()
                 WHERE id = ?'
            )->execute([$applicationId]);

            $status = $this->pdo->prepare(
                'SELECT status, paid_at FROM sacrament_applications WHERE id = ?'
            );
            $status->execute([$applicationId]);
            $row = $status->fetch();
            self::assertSame('approved', $row['status']);
            self::assertNotNull($row['paid_at']);

            $this->pdo->commit();
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($applicationId > 0) {
                $this->pdo->prepare('DELETE FROM sacrament_applications WHERE id = ?')->execute([$applicationId]);
            }
        }
    }

    public function testMarriageApplicationApprovalPrerequisites(): void
        {
            $applicationId = 0;
            $tracking = 'TEST-MARRIAGE-' . bin2hex(random_bytes(8));

            try {
                $this->pdo->beginTransaction();

                $application = $this->pdo->prepare(
                    'INSERT INTO sacrament_applications
                        (tracking_number, submission_channel, sacrament_type, status, notes)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $application->execute([
                    $tracking,
                    'online',
                    'marriage',
                    'awaiting_payment',
                    'Integration marriage application',
                ]);
                $applicationId = (int) $this->pdo->lastInsertId();

                $detail = $this->pdo->prepare(
                    'INSERT INTO marriage_applications
                        (application_id, groom_full_name, groom_birthdate, groom_birthplace,
                         groom_email, groom_marital_status, bride_full_name, bride_birthdate,
                         bride_birthplace, bride_email, bride_marital_status, preferred_wedding_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $detail->execute([
                    $applicationId,
                    'Groom Integration',
                    '1995-02-10',
                    'Dingalan, Aurora',
                    'groom-' . bin2hex(random_bytes(4)) . '@example.invalid',
                    'single',
                    'Bride Integration',
                    '1996-08-21',
                    'Dingalan, Aurora',
                    'bride-' . bin2hex(random_bytes(4)) . '@example.invalid',
                    'single',
                    '2027-06-12',
                ]);

                self::assertFalse(sacrament_application_payment_gate([
                    'submission_channel' => 'online',
                    'status' => 'awaiting_payment',
                    'paid_at' => null,
                ], 'requirements_pending')['ok']);

                $blocked = can_approve_sacrament_application($this->pdo, $applicationId, 'marriage');
                self::assertFalse($blocked['ok']);
                self::assertCount(7, $blocked['missing']);
                self::assertContains('Canonical (pastoral) interview not completed.', $blocked['missing']);
                self::assertContains('Pre-Cana / marriage seminar not completed.', $blocked['missing']);
                self::assertContains('Banns must be posted on all three dates (Tawag sa Kasal).', $blocked['missing']);
                self::assertContains("Groom's baptismal certificate not submitted.", $blocked['missing']);
                self::assertContains("Bride's baptismal certificate not submitted.", $blocked['missing']);
                self::assertContains("Groom's confirmation certificate not submitted.", $blocked['missing']);
                self::assertContains("Bride's confirmation certificate not submitted.", $blocked['missing']);

                $this->pdo->prepare(
                    'UPDATE sacrament_applications
                     SET paid_at = NOW(), paid_by = ?, status = "ready_for_approval"
                     WHERE id = ?'
                )->execute([null, $applicationId]);

                $this->pdo->prepare(
                    'UPDATE marriage_applications
                     SET canonical_interview_completed = 1,
                         pre_cana_seminar_completed = 1,
                         banns_date_1 = "2027-05-22",
                         banns_date_2 = "2027-05-29",
                         banns_date_3 = "2027-06-05",
                         groom_baptismal_cert_status = "submitted",
                         bride_baptismal_cert_status = "submitted",
                         groom_confirmation_cert_status = "submitted",
                         bride_confirmation_cert_status = "submitted"
                     WHERE application_id = ?'
                )->execute([$applicationId]);

                $ready = can_approve_sacrament_application($this->pdo, $applicationId, 'marriage');
                self::assertTrue($ready['ok'], implode(' ', $ready['missing']));
                self::assertSame([], $ready['missing']);

                $this->pdo->prepare(
                    'UPDATE sacrament_applications
                     SET status = "approved", date_processed = NOW()
                     WHERE id = ?'
                )->execute([$applicationId]);

                $status = $this->pdo->prepare(
                    'SELECT status, paid_at FROM sacrament_applications WHERE id = ?'
                );
                $status->execute([$applicationId]);
                $row = $status->fetch();
                self::assertSame('approved', $row['status']);
                self::assertNotNull($row['paid_at']);

                $this->pdo->commit();
            } finally {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($applicationId > 0) {
                    $this->pdo->prepare('DELETE FROM sacrament_applications WHERE id = ?')->execute([$applicationId]);
                }
            }
        }

    public function testConfirmationAndFirstCommunionApprovalPrerequisites(): void
    {
                $applicationIds = [];

                try {
                    $this->pdo->beginTransaction();

                    $insertApplication = $this->pdo->prepare(
                        'INSERT INTO sacrament_applications
                            (tracking_number, submission_channel, sacrament_type, status)
                         VALUES (?, ?, ?, ?)'
                    );
                    $insertApplication->execute([
                        'TEST-CONFIRM-' . bin2hex(random_bytes(8)),
                        'online',
                        'confirmation',
                        'awaiting_payment',
                    ]);
                    $confirmationId = (int) $this->pdo->lastInsertId();
                    $applicationIds[] = $confirmationId;

                    $confirmation = $this->pdo->prepare(
                        'INSERT INTO confirmation_applications
                            (application_id, applicant_full_name, applicant_birthdate,
                             parent_guardian_name, email)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $confirmation->execute([
                        $confirmationId,
                        'Confirmand Integration',
                        '2012-03-10',
                        'Guardian Integration',
                        'confirmand-' . bin2hex(random_bytes(4)) . '@example.invalid',
                    ]);

                    $blockedConfirmation = can_approve_sacrament_application(
                        $this->pdo,
                        $confirmationId,
                        'confirmation'
                    );
                    self::assertFalse($blockedConfirmation['ok']);
                    self::assertContains(
                        'Baptismal certificate not submitted (baptism is required first).',
                        $blockedConfirmation['missing']
                    );
                    self::assertContains('Seminar not yet completed.', $blockedConfirmation['missing']);
                    self::assertSame(
                        'Age-eligible',
                        sacrament_age_status($this->pdo, 'confirmation', 14)
                    );

                    $this->pdo->prepare(
                        'UPDATE sacrament_applications
                         SET paid_at = NOW(), status = "ready_for_approval"
                         WHERE id = ?'
                    )->execute([$confirmationId]);
                    $this->pdo->prepare(
                        'UPDATE confirmation_applications
                         SET baptismal_cert_status = "submitted", seminar_attended = 1,
                             age_eligibility_flag = 1
                         WHERE application_id = ?'
                    )->execute([$confirmationId]);

                    $readyConfirmation = can_approve_sacrament_application(
                        $this->pdo,
                        $confirmationId,
                        'confirmation'
                    );
                    self::assertTrue($readyConfirmation['ok'], implode(' ', $readyConfirmation['missing']));
                    self::assertSame([], $readyConfirmation['missing']);

                    $insertApplication->execute([
                        'TEST-COMM-' . bin2hex(random_bytes(8)),
                        'online',
                        'first_communion',
                        'awaiting_payment',
                    ]);
                    $communionId = (int) $this->pdo->lastInsertId();
                    $applicationIds[] = $communionId;

                    $communion = $this->pdo->prepare(
                        'INSERT INTO first_communion_applications
                            (application_id, applicant_full_name, applicant_birthdate,
                             parent_guardian_name, email)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $communion->execute([
                        $communionId,
                        'Communicant Integration',
                        '2018-09-04',
                        'Guardian Integration',
                        'communicant-' . bin2hex(random_bytes(4)) . '@example.invalid',
                    ]);

                    $blockedCommunion = can_approve_sacrament_application(
                        $this->pdo,
                        $communionId,
                        'first_communion'
                    );
                    self::assertFalse($blockedCommunion['ok']);
                    self::assertContains(
                        'Baptismal certificate not submitted (baptism is required first).',
                        $blockedCommunion['missing']
                    );
                    self::assertContains('Seminar not yet completed.', $blockedCommunion['missing']);
                    self::assertSame(
                        'Age-eligible',
                        sacrament_age_status($this->pdo, 'first_communion', 8)
                    );

                    $this->pdo->prepare(
                        'UPDATE sacrament_applications
                         SET paid_at = NOW(), status = "ready_for_approval"
                         WHERE id = ?'
                    )->execute([$communionId]);
                    $this->pdo->prepare(
                        'UPDATE first_communion_applications
                         SET baptismal_cert_status = "submitted", seminar_attended = 1,
                             age_eligibility_flag = 1
                         WHERE application_id = ?'
                    )->execute([$communionId]);

                    $readyCommunion = can_approve_sacrament_application(
                        $this->pdo,
                        $communionId,
                        'first_communion'
                    );
                    self::assertTrue($readyCommunion['ok'], implode(' ', $readyCommunion['missing']));
                    self::assertSame([], $readyCommunion['missing']);

                    $this->pdo->prepare(
                        'UPDATE sacrament_applications
                         SET status = "approved", date_processed = NOW()
                         WHERE id IN (?, ?)'
                    )->execute([$confirmationId, $communionId]);

                    $approved = $this->pdo->query(
                        'SELECT COUNT(*) FROM sacrament_applications
                         WHERE id IN (' . $confirmationId . ', ' . $communionId . ')
                           AND status = "approved"'
                    )->fetchColumn();
                    self::assertSame(2, (int) $approved);

                    $this->pdo->commit();
                } finally {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    foreach ($applicationIds as $applicationId) {
                        $this->pdo->prepare('DELETE FROM sacrament_applications WHERE id = ?')
                            ->execute([$applicationId]);
                    }
                }
    }

    public function testScheduleEventsPersistAndDetectTimeConflicts(): void
    {
        $eventIds = [];

                    try {
                        $this->pdo->beginTransaction();

                        $insert = $this->pdo->prepare(
                            'INSERT INTO schedule_events
                                (event_type, title, event_date, start_time, end_time, notes)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        );
                        $insert->execute([
                            'wedding',
                            'Integration Wedding',
                            '2027-06-12',
                            '10:00:00',
                            '11:00:00',
                            'Integration schedule event',
                        ]);
                        $eventIds[] = (int) $this->pdo->lastInsertId();

                        $insert->execute([
                            'mass',
                            'Integration Mass',
                            '2027-06-12',
                            '10:30:00',
                            '11:30:00',
                            'Overlapping integration event',
                        ]);
                        $eventIds[] = (int) $this->pdo->lastInsertId();

                        $insert->execute([
                            'other',
                            'Integration Later Event',
                            '2027-06-12',
                            '11:00:00',
                            '12:00:00',
                            'Back-to-back integration event',
                        ]);
                        $eventIds[] = (int) $this->pdo->lastInsertId();

                        $events = $this->pdo->prepare(
                            'SELECT start_time, end_time FROM schedule_events
                             WHERE id IN (?, ?, ?) ORDER BY id'
                        );
                        $events->execute($eventIds);
                        $rows = $events->fetchAll();

                        self::assertCount(3, $rows);
                        self::assertTrue(
                            event_times_overlap(
                                $rows[0]['start_time'],
                                $rows[0]['end_time'],
                                $rows[1]['start_time'],
                                $rows[1]['end_time']
                            )
                        );
                        self::assertFalse(
                            event_times_overlap(
                                $rows[0]['start_time'],
                                $rows[0]['end_time'],
                                $rows[2]['start_time'],
                                $rows[2]['end_time']
                            )
                        );
                        self::assertTrue(event_times_overlap('10:00:00', null, '10:45:00', '11:45:00'));
                        self::assertTrue(event_times_overlap(null, null, '23:00:00', '23:30:00'));

                        $sameDay = $this->pdo->prepare(
                            'SELECT COUNT(*) FROM schedule_events
                             WHERE event_date = ? AND event_type IN ("wedding", "mass")'
                        );
                        $sameDay->execute(['2027-06-12']);
                        self::assertSame(2, (int) $sameDay->fetchColumn());

                        $this->pdo->commit();
                    } finally {
                        if ($this->pdo->inTransaction()) {
                            $this->pdo->rollBack();
                        }
                        foreach ($eventIds as $eventId) {
                            $this->pdo->prepare('DELETE FROM schedule_events WHERE id = ?')
                                ->execute([$eventId]);
                        }
                    }
        }

    public function testPublicTrackingAndCertificateVerificationData(): void
    {
        $requestId = 0;
        $tracking = 'SPPO-2027-' . bin2hex(random_bytes(3));
        $token = 'verification-' . bin2hex(random_bytes(16));
        $email = 'public-tracking-' . bin2hex(random_bytes(4)) . '@example.invalid';

        try {
            $this->pdo->beginTransaction();
            $request = $this->pdo->prepare(
                'INSERT INTO certificate_requests
                    (tracking_number, submission_channel, sacrament_type, requester_name,
                     requester_relationship, requester_email, purpose, status, verification_code)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $request->execute([
                $tracking, 'online', 'baptismal', 'Public Integration', 'self',
                $email, 'personal', 'ready_for_pickup', $token,
            ]);
            $requestId = (int) $this->pdo->lastInsertId();

            $detail = $this->pdo->prepare(
                'INSERT INTO baptismal_certificate_requests
                    (request_id, child_full_name, father_full_name, mother_full_name, birthdate)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $detail->execute([
                $requestId, 'Tracked Child', 'Tracked Father', 'Tracked Mother', '2015-01-02',
            ]);

            $trackingLookup = $this->pdo->prepare(
                'SELECT tracking_number, sacrament_type, status, requester_email
                 FROM certificate_requests WHERE tracking_number = ?'
            );
            $trackingLookup->execute([$tracking]);
            $tracked = $trackingLookup->fetch();
            self::assertSame($tracking, $tracked['tracking_number']);
            self::assertSame('baptismal', $tracked['sacrament_type']);
            self::assertSame('ready_for_pickup', $tracked['status']);
            self::assertSame(0, strcasecmp($email, $tracked['requester_email']));
            self::assertNotSame(0, strcasecmp('wrong@example.invalid', $tracked['requester_email']));

            $verification = $this->pdo->prepare(
                'SELECT cr.tracking_number, cr.sacrament_type, cr.requester_name,
                        cr.status, cr.anonymized_at, ext.matched_record_id
                 FROM certificate_requests cr
                 LEFT JOIN (
                     SELECT request_id, matched_record_id FROM baptismal_certificate_requests
                     UNION ALL SELECT request_id, matched_record_id FROM confirmation_certificate_requests
                     UNION ALL SELECT request_id, matched_record_id FROM first_communion_certificate_requests
                     UNION ALL SELECT request_id, matched_record_id FROM marriage_certificate_requests
                 ) ext ON ext.request_id = cr.id
                 WHERE cr.verification_code = ?'
            );
            $verification->execute([$token]);
            $verified = $verification->fetch();
            self::assertSame($tracking, $verified['tracking_number']);
            self::assertSame('baptismal', $verified['sacrament_type']);
            self::assertSame('Public Integration', $verified['requester_name']);
            self::assertSame('ready_for_pickup', $verified['status']);
            self::assertNull($verified['anonymized_at']);
            self::assertNull($verified['matched_record_id']);

            $verification->execute(['invalid-' . bin2hex(random_bytes(8))]);
            self::assertFalse($verification->fetch());
            $this->pdo->commit();
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($requestId > 0) {
                $this->pdo->prepare('DELETE FROM certificate_requests WHERE id = ?')
                    ->execute([$requestId]);
            }
        }
    }

    public function testRetentionAnonymizesFinishedOldCertificateRequests(): void
    {
        $requestId = 0;
        $tracking = 'TEST-RETAIN-' . bin2hex(random_bytes(8));

        try {
            $request = $this->pdo->prepare(
                'INSERT INTO certificate_requests
                    (tracking_number, submission_channel, sacrament_type, requester_name,
                     requester_relationship, requester_email, purpose, status, date_processed)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 61 MONTH))'
            );
            $request->execute([
                $tracking,
                'online',
                'baptismal',
                'Retention Integration',
                'parent',
                'retention-integration@example.invalid',
                'personal',
                'released',
            ]);
            $requestId = (int) $this->pdo->lastInsertId();

            $detail = $this->pdo->prepare(
                'INSERT INTO baptismal_certificate_requests
                    (request_id, child_full_name, father_full_name, mother_full_name, birthdate)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $detail->execute([
                $requestId,
                'Retention Child',
                'Retention Father',
                'Retention Mother',
                '2010-01-01',
            ]);

            $preview = retention_dry_run($this->pdo);
            self::assertGreaterThanOrEqual(1, $preview['certificate_requests']);

            $affected = retention_execute($this->pdo, null);
            self::assertGreaterThanOrEqual(1, $affected['certificate_requests']);

            $result = $this->pdo->prepare(
                'SELECT requester_name, requester_email, anonymized_at
                 FROM certificate_requests WHERE id = ?'
            );
            $result->execute([$requestId]);
            $requestRow = $result->fetch();
            self::assertSame(RETENTION_ANON_NAME, $requestRow['requester_name']);
            self::assertSame(RETENTION_ANON_EMAIL, $requestRow['requester_email']);
            self::assertNotNull($requestRow['anonymized_at']);

            $detailResult = $this->pdo->prepare(
                'SELECT child_full_name, father_full_name, mother_full_name
                 FROM baptismal_certificate_requests WHERE request_id = ?'
            );
            $detailResult->execute([$requestId]);
            $detailRow = $detailResult->fetch();
            self::assertSame(RETENTION_ANON_NAME, $detailRow['child_full_name']);
            self::assertSame(RETENTION_ANON_NAME, $detailRow['father_full_name']);
            self::assertSame(RETENTION_ANON_NAME, $detailRow['mother_full_name']);
        } finally {
            if ($requestId > 0) {
                $this->pdo->prepare('DELETE FROM certificate_requests WHERE id = ?')
                    ->execute([$requestId]);
            }
            $this->pdo->exec("DELETE FROM audit_logs WHERE action = 'retention_purged'");
        }
    }

    public function testRetentionAnonymizesFinishedOldCorrectionRequests(): void
    {
        $requestId = 0;

        try {
            $request = $this->pdo->prepare(
                'INSERT INTO correction_requests
                    (tracking_number, requester_name, requester_email, certificate_id,
                     requested_changes, document_path, status, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 61 MONTH))'
            );
            $request->execute([
                'TEST-CORRECTION-' . bin2hex(random_bytes(8)),
                'Correction Integration',
                'correction-integration@example.invalid',
                0,
                'Correct the recorded spelling.',
                'private-document.pdf',
                'completed',
            ]);
            $requestId = (int) $this->pdo->lastInsertId();

            $preview = retention_dry_run($this->pdo);
            self::assertGreaterThanOrEqual(1, $preview['correction_requests']);

            $affected = retention_execute($this->pdo, null);
            self::assertGreaterThanOrEqual(1, $affected['correction_requests']);

            $result = $this->pdo->prepare(
                'SELECT requester_name, requester_email, requested_changes,
                        document_path, anonymized_at
                 FROM correction_requests WHERE id = ?'
            );
            $result->execute([$requestId]);
            $row = $result->fetch();

            self::assertSame(RETENTION_ANON_NAME, $row['requester_name']);
            self::assertSame(RETENTION_ANON_EMAIL, $row['requester_email']);
            self::assertSame(RETENTION_ANON_NAME, $row['requested_changes']);
            self::assertNull($row['document_path']);
            self::assertNotNull($row['anonymized_at']);
        } finally {
            if ($requestId > 0) {
                $this->pdo->prepare('DELETE FROM correction_requests WHERE id = ?')
                    ->execute([$requestId]);
            }
            $this->pdo->exec("DELETE FROM audit_logs WHERE action = 'retention_purged'");
        }
    }

    public function testDatabaseBackupRestoresIntoIsolatedDatabase(): void
    {
        $restoreName = (getenv('SRS_TEST_DB_NAME') ?: 'srs_sppo_test_db') . '_restore';
        $restoreName = preg_replace('/[^A-Za-z0-9_]/', '', $restoreName);
        $host = getenv('SRS_TEST_DB_HOST') ?: '127.0.0.1';
        $user = getenv('SRS_TEST_DB_USER') ?: 'root';
        $password = getenv('SRS_TEST_DB_PASS') !== false ? (string) getenv('SRS_TEST_DB_PASS') : '';

        if ($restoreName === 'srs_sppo_db' || $restoreName === (getenv('SRS_DB_NAME') ?: '')) {
            self::fail('Refusing to use the application database as a restore target.');
        }

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

            $server->exec("DROP DATABASE IF EXISTS {$quotedRestore}");
            $server->exec("CREATE DATABASE {$quotedRestore} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
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
            $server->exec("DROP DATABASE IF EXISTS {$quotedRestore}");
            $this->pdo->exec("DELETE FROM announcements WHERE content = 'Integration backup marker'");
        }
    }
}
