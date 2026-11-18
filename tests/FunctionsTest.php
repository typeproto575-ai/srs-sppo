<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/certificates/baptismal-certificate.php';
require_once __DIR__ . '/../admin/certificates/sacramental-certificate.php';
require_once __DIR__ . '/../includes/person-linking.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/public-person-lookup.php';
require_once __DIR__ . '/../includes/record-search.php';
require_once __DIR__ . '/../includes/sacrament-data.php';
require_once __DIR__ . '/../includes/funeral-data.php';

final class FunctionsTest extends TestCase
{
    public function testParentsUnionTypesNormalizeLegacyValuesAndPreserveCurrentValues(): void
    {
        self::assertSame('canonical', normalize_parents_union_type(' legitimate '));
        self::assertSame('unmarried', normalize_parents_union_type('natural'));
        self::assertSame('civil', normalize_parents_union_type('civil'));
        self::assertSame('common_law', normalize_parents_union_type('common_law'));
        self::assertSame('', normalize_parents_union_type(''));
        self::assertSame([
            '' => '-- Not specified --',
            'canonical' => 'Canonical Marriage (Catholic Church)',
            'civil' => 'Civil Marriage Only',
            'common_law' => 'Living Together / Common-Law',
            'unmarried' => 'Single Parent / Unmarried',
        ], parents_union_type_options());
        self::assertSame(
            'Canonical Marriage (Catholic Church)',
            parents_union_type_label('legitimate')
        );
        self::assertSame('Not recorded', parents_union_type_label(null));
    }

    public function testBaptismDetailsIgnoreLegacyParentBirthplacesButPreserveChildBirthplace(): void
    {
        $record = sd_decode_record([
            'sacrament_type' => 'baptism',
            'details' => json_encode([
                'father_birthplace' => 'Oldtown',
                'mother_birth_place' => 'Oldtown',
                'child_birthplace' => 'Dingalan',
                'nested' => ['father-place-of-birth' => 'Oldtown', 'note' => 'Kept'],
            ]),
        ]);

        self::assertSame([
            'child_birthplace' => 'Dingalan',
            'nested' => ['note' => 'Kept'],
        ], $record['details']);
        self::assertSame(
            ['father_birthplace' => 'Oldtown'],
            sd_decode_record([
                'sacrament_type' => 'confirmation',
                'details' => json_encode(['father_birthplace' => 'Oldtown']),
            ])['details']
        );
    }

    public function testPlaceOfBirthNormalizesUnknownPlaceholdersAndIdentifiesMissingValues(): void
    {
        foreach (['n/a', ' NA ', 'N.A.', 'none', 'Unknown', '-'] as $placeholder) {
            self::assertSame('N/A', normalize_place_of_birth($placeholder));
            self::assertTrue(is_unknown_place_of_birth($placeholder));
        }

        self::assertSame('', normalize_place_of_birth(" \t "));
        self::assertTrue(is_unknown_place_of_birth(null));
        self::assertSame('Dingalan, Aurora', normalize_place_of_birth(' Dingalan, Aurora '));
        self::assertFalse(is_unknown_place_of_birth('Dingalan, Aurora'));
    }

    public function testFirstCommunionFormationValidationFollowsTheSelectedPathway(): void
    {
        self::assertNull(first_communion_formation_input_error('school_based', 'grade_3', '2026-2027'));
        self::assertNotNull(first_communion_formation_input_error('school_based', 'grade_3', ''));
        self::assertNotNull(first_communion_formation_input_error('school_based', '', '2026-2027'));
        self::assertNotNull(first_communion_formation_input_error('school_based', 'not_applicable', '2026-2027'));
        self::assertNotNull(first_communion_formation_input_error('school_based', 'not_enrolled', '2026-2027'));

        self::assertNull(first_communion_formation_input_error('parish_based', 'not_enrolled', ''));
        self::assertNull(first_communion_formation_input_error('parish_based', 'grade_3', '2026-2027'));
        self::assertNotNull(first_communion_formation_input_error('parish_based', 'grade_3', 'N/A'));
        self::assertNotNull(first_communion_formation_input_error('parish_based', '', ''));

        self::assertNull(first_communion_formation_input_error('individual_catchup', '', 'invalid'));
        self::assertNull(first_communion_formation_input_error('individual_catchup', 'N/A', '2026-2027'));
        self::assertNull(first_communion_formation_input_error('individual_catchup', 'not_applicable', '2026-2027'));
        self::assertSame(
            'Out-of-School Youth (OSY)',
            first_communion_grade_options()['not_enrolled']
        );
    }

    public function testReportPeriodValidationAcceptsValidValuesAndFallsBackForInvalidValues(): void
    {
        $currentDate = new DateTimeImmutable('2026-11-14');

        self::assertSame([12, 2027], validated_report_period('12', '2027', $currentDate));
        self::assertSame([11, 2026], validated_report_period('13', '2026', $currentDate));
        self::assertSame([11, 2026], validated_report_period('11', 'abc', $currentDate));
        self::assertSame([11, 2026], validated_report_period('11', '0', $currentDate));
        self::assertSame([11, 2026], validated_report_period('11', '2101', $currentDate));
    }

    public function testUnifiedRecordSearchParsesRegisterReferences(): void
    {
        self::assertSame(
            ['book_no' => '12', 'page_no' => '1', 'line_no' => '4'],
            unified_record_search_reference('b12, p1, l4')
        );
        self::assertSame(
            ['book_no' => 'II', 'page_no' => '1', 'line_no' => '4'],
            unified_record_search_reference('Book II / Page: 1 / Line #4')
        );
        self::assertSame(['page_no' => '12'], unified_record_search_reference('p12'));
        self::assertSame(['line_no' => '4'], unified_record_search_reference('line 4'));
        self::assertSame(['book_no' => '12'], unified_record_search_reference('b 12'));
    }

    public function testUnifiedRecordSearchLeavesOrdinaryOrInvalidQueriesAsGeneralSearches(): void
    {
        self::assertNull(unified_record_search_reference('Ricardo Mendoza'));
        self::assertNull(unified_record_search_reference('12'));
        self::assertNull(unified_record_search_reference('baptism'));
        self::assertNull(unified_record_search_reference('b12 p1 extra'));
        self::assertNull(unified_record_search_reference('b12 b13'));
    }

    public function testUnifiedRecordBookSearchMatchesEquivalentArabicAndRomanValues(): void
    {
        self::assertSame(['3', 'III'], unified_record_search_book_values('3'));
        self::assertSame(['III', '3'], unified_record_search_book_values('III'));
        self::assertSame(['03', '3', 'III'], unified_record_search_book_values('03'));
        self::assertSame(['IIII'], unified_record_search_book_values('IIII'));
        self::assertSame(['4000'], unified_record_search_book_values('4000'));
    }

    public function testPersonSearchRecognizesDottedAndUndottedMiddleInitials(): void
    {
        self::assertSame('R', search_middle_initial('R.'));
        self::assertSame('R', search_middle_initial('R'));
        self::assertSame('É', search_middle_initial('É.'));
        self::assertNull(search_middle_initial('Ramos'));
    }

    public function testAdminNotificationLinksStayInsideTheAdminApplication(): void
    {
        self::assertSame(
            '/srs-sppo/admin/requests/queue.php?status=new',
            admin_notification_url('/admin/requests/queue.php?status=new', '/srs-sppo')
        );
        self::assertSame(
            '/srs-sppo/admin/system/person-profile.php?id=12',
            admin_notification_url('/srs-sppo/admin/system/person-profile.php?id=12', '/srs-sppo')
        );
        self::assertSame(
            '/srs-sppo/admin/notifications.php',
            admin_notification_url(null, '/srs-sppo')
        );
        self::assertSame(
            '/srs-sppo/admin/notifications.php',
            admin_notification_url('https://example.com/admin/requests/queue.php', '/srs-sppo')
        );
        self::assertSame(
            '/srs-sppo/admin/notifications.php',
            admin_notification_url('/admin/../public/index.php', '/srs-sppo')
        );
    }

    public function testCertificateRequestTransitionRules(): void
    {
        self::assertTrue(cert_request_transition_valid('pending', 'under_review'));
        self::assertFalse(cert_request_transition_valid('released', 'approved'));
        self::assertTrue(cert_request_transition_valid('new', 'matched'));
        self::assertFalse(cert_request_transition_valid('new', 'no_record_found'));
        self::assertTrue(cert_request_transition_valid('matched', 'for_signature'));
        self::assertTrue(cert_request_transition_valid('for_signature', 'ready'));
        self::assertTrue(cert_request_transition_valid('ready', 'released'));
        self::assertTrue(cert_request_transition_valid('ready_for_pickup', 'released'));
        self::assertTrue(cert_request_transition_valid('new', 'rejected'));
        self::assertTrue(cert_request_transition_valid('new', 'cancelled'));
        self::assertFalse(cert_request_transition_valid('matched', 'cancelled'));
        self::assertFalse(cert_request_transition_valid('new', 'for_signature'));
        self::assertFalse(cert_request_transition_valid('released', 'ready'));
    }

    public function testCertificateRequestReceiptCanUseRequestNumberWithoutPersonReference(): void
    {
        $referenceBlock = mail_reference_block_html('', 'CR-2026-0042', '/public/track-request.php');

        self::assertStringContainsString('Request number', $referenceBlock);
        self::assertStringContainsString('CR-2026-0042', $referenceBlock);
        self::assertStringNotContainsString('SPPO-P-', $referenceBlock);
    }

    public function testMailPublicUrlIncludesTheInstallationSubdirectory(): void
    {
        $trackingUrl = mail_public_url('/public/track-request.php', '/srs-sppo');
        $siteParts = parse_url(MAIL_SITE_URL);

        self::assertIsArray($siteParts);
        self::assertSame(
            SRS_FORCE_HTTPS ? 'https' : ($siteParts['scheme'] ?? 'https'),
            parse_url($trackingUrl, PHP_URL_SCHEME)
        );
        self::assertSame($siteParts['host'], parse_url($trackingUrl, PHP_URL_HOST));
        self::assertSame('/srs-sppo/public/track-request.php', parse_url($trackingUrl, PHP_URL_PATH));
    }

    public function testPublicPersonLookupReturnsOnlySafeSacramentRecordDetails(): void
    {
        $summary = public_person_lookup_record_summary([
            'baptism' => [
                'id' => 19,
                'sacrament_type' => 'baptism',
                'event_date' => '2008-04-12',
                'place' => 'St. Patrick Parish',
                'source' => 'this_parish',
                'entry_no' => '12',
                'book_no' => '3',
                'page_no' => '21',
                'line_no' => '4',
                'book_verified_at' => '2024-01-02 03:04:05',
                'remarks' => 'internal note',
                'details' => ['private' => 'value'],
            ],
            'first_communion' => null,
            'confirmation' => null,
            'marriages' => [[
                'id' => 24,
                'sacrament_type' => 'marriage',
                'event_date' => '2020-06-08',
                'place' => 'St. Patrick Parish',
                'source' => 'other_parish',
                'partner_name' => 'Other Person',
                'book_verified_at' => null,
            ]],
        ]);

        self::assertSame([
            [
                'type' => 'baptism',
                'event_date' => '2008-04-12',
                'place' => 'St. Patrick Parish',
                'source' => 'this_parish',
                'entry_no' => '12',
                'book_no' => '3',
                'page_no' => '21',
                'line_no' => '4',
                'is_verified' => true,
            ],
            [
                'type' => 'marriage',
                'event_date' => '2020-06-08',
                'place' => 'St. Patrick Parish',
                'source' => 'other_parish',
                'entry_no' => null,
                'book_no' => null,
                'page_no' => null,
                'line_no' => null,
                'is_verified' => false,
            ],
        ], $summary);
    }

    public function testCertificatePaymentGate(): void
    {
        self::assertFalse(cert_request_payment_gate([
            'submission_channel' => 'online',
            'status' => 'awaiting_payment',
            'paid_at' => null,
        ], 'approved')['ok']);

        self::assertTrue(cert_request_payment_gate([
            'submission_channel' => 'online',
            'status' => 'awaiting_payment',
            'paid_at' => '2026-10-14 05:00:00',
        ], 'approved')['ok']);

        self::assertFalse(cert_request_payment_gate([
            'channel' => 'online',
            'status' => 'matched',
            'paid_at' => null,
        ], 'for_signature')['ok']);

        self::assertTrue(cert_request_payment_gate([
            'channel' => 'online',
            'status' => 'matched',
            'paid_at' => '2026-10-14 05:00:00',
        ], 'for_signature')['ok']);

        self::assertTrue(cert_request_payment_gate([
            'channel' => 'onsite',
            'status' => 'matched',
            'paid_at' => null,
        ], 'for_signature')['ok']);
    }

    public function testCertificateReleaseRequiresPaymentAndCounterVerification(): void
    {
        $request = ['status' => 'ready', 'paid_at' => null];
        self::assertSame(
            'Payment has not been recorded.',
            cert_request_release_gate($request, 'self', '', true, false)['reason']
        );

        $request['paid_at'] = '2026-10-14 05:00:00';
        self::assertSame(
            'Confirm that the claimant presented a valid ID at the counter.',
            cert_request_release_gate($request, 'self', '', false, false)['reason']
        );
        self::assertTrue(cert_request_release_gate($request, 'self', '', true, false)['ok']);
        self::assertSame(
            'Select a valid claimant and provide representative details when applicable.',
            cert_request_release_gate($request, 'representative', '', true, true)['reason']
        );
        self::assertSame(
            'Confirm that the signed authorization letter was received.',
            cert_request_release_gate($request, 'representative', 'Representative', true, false)['reason']
        );
        self::assertTrue(
            cert_request_release_gate($request, 'representative', 'Representative', true, true)['ok']
        );
        self::assertFalse(
            cert_request_release_gate(['status' => 'matched', 'paid_at' => 'now'], 'self', '', true, false)['ok']
        );
    }

    public function testSacramentApplicationRules(): void
    {
        self::assertTrue(
            sacrament_application_transition_valid('submitted', 'requirements_pending', 'baptismal')
        );
        self::assertFalse(
            sacrament_application_transition_valid('completed', 'approved', 'marriage')
        );
        self::assertFalse(sacrament_application_payment_gate([
            'submission_channel' => 'online',
            'status' => 'awaiting_payment',
            'paid_at' => null,
        ], 'requirements_pending')['ok']);
    }

    public function testRequirementStatusChangeStartsReviewOnlyFromNewApplications(): void
    {
        self::assertSame(
            'reviewing',
            application_queue_requirement_status_transition('new', 'not_received', 'received')
        );
        self::assertNull(
            application_queue_requirement_status_transition('new', 'received', 'received')
        );

        foreach (['reviewing', 'requirements_pending', 'approved', 'scheduled', 'completed', 'rejected'] as $status) {
            self::assertNull(
                application_queue_requirement_status_transition($status, 'not_received', 'received'),
                "A requirement change must not automatically transition an application from {$status}."
            );
        }
    }

    public function testConfiguredCommunionAndConfirmationAgeRangesAreAdvisory(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        self::markTestSkipped('PDO SQLite is required for the in-memory age eligibility test.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)');

        self::assertSame('Not yet age-eligible', sacrament_age_status($pdo, 'first_communion', 6));
        self::assertSame('Age-eligible', sacrament_age_status($pdo, 'first_communion', 8));
        self::assertSame('Age-eligible', sacrament_age_status($pdo, 'first_communion', 12));

        self::assertSame('Not yet age-eligible', sacrament_age_status($pdo, 'confirmation', 11));
        self::assertSame('Age-eligible', sacrament_age_status($pdo, 'confirmation', 12));
        self::assertSame('Age-eligible', sacrament_age_status($pdo, 'confirmation', 18));

        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('first_communion_min_age', '8')");
        self::assertSame('Not yet age-eligible', sacrament_age_status($pdo, 'first_communion', 7));
        self::assertSame('Age-eligible', sacrament_age_status($pdo, 'first_communion', 8));
        self::assertSame('Age-eligible', sacrament_age_status($pdo, 'first_communion', 12));
    }

    public function testDashboardNotifiesNewlyEligibleBaptizedPersonsOnlyOnce(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for the in-memory age notification test.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)');
        $pdo->exec(
            'CREATE TABLE persons (
                id INTEGER PRIMARY KEY,
                first_name TEXT NOT NULL,
                middle_name TEXT,
                last_name TEXT NOT NULL,
                suffix TEXT,
                birth_date TEXT,
                is_deceased INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE sacrament_records (
                id INTEGER PRIMARY KEY,
                person_id INTEGER NOT NULL,
                sacrament_type TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT NOT NULL,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                message TEXT,
                link TEXT,
                entity_type TEXT,
                entity_id INTEGER,
                is_read INTEGER NOT NULL DEFAULT 0
            )'
        );

        $firstCommunionBirthday = (new DateTimeImmutable('today'))->modify('-8 years')->format('Y-m-d');
        $alreadyAgeEligible = (new DateTimeImmutable('today'))->modify('-9 years')->format('Y-m-d');
        $confirmationBirthday = (new DateTimeImmutable('today'))->modify('-12 years')->format('Y-m-d');
        $insertPerson = $pdo->prepare(
            'INSERT INTO persons (id, first_name, last_name, birth_date, is_deceased)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertPerson->execute([1, 'Communion', 'Candidate', $firstCommunionBirthday, 0]);
        $insertPerson->execute([2, 'Confirmation', 'Candidate', $confirmationBirthday, 0]);
        $insertPerson->execute([3, 'Not', 'Baptized', $firstCommunionBirthday, 0]);
        $insertPerson->execute([4, 'Deceased', 'Person', $firstCommunionBirthday, 1]);
        $insertPerson->execute([5, 'Already', 'Communicant', $firstCommunionBirthday, 0]);
        $insertPerson->execute([6, 'Previously', 'Eligible', $alreadyAgeEligible, 0]);
        $pdo->exec(
            "INSERT INTO sacrament_records (person_id, sacrament_type) VALUES
                (1, 'baptism'),
                (2, 'baptism'),
                (4, 'baptism'),
                (5, 'baptism'),
                (6, 'baptism'),
                (5, 'first_communion')"
        );

        self::assertSame(
            [2, 6, 1],
            array_column(sd_get_age_eligible_persons($pdo, 'first_communion'), 'id')
        );
        self::assertSame(
            [2],
            array_column(sd_get_age_eligible_persons($pdo, 'confirmation'), 'id')
        );
        self::assertSame(4, notify_newly_age_eligible_persons($pdo));
        self::assertSame(0, notify_newly_age_eligible_persons($pdo));
        self::assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
        self::assertSame(
            [
                'age_eligible_confirmation',
                'age_eligible_first_communion',
                'age_eligible_first_communion',
                'age_eligible_first_communion',
            ],
            $pdo->query('SELECT type FROM notifications ORDER BY type')->fetchAll(PDO::FETCH_COLUMN)
        );
        self::assertSame(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM notifications
                 WHERE entity_type = 'persons' AND entity_id = 1
                   AND link = '/admin/system/person-profile.php?id=1'"
            )->fetchColumn()
        );
        self::assertSame(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM notifications
                 WHERE entity_type = 'persons' AND entity_id = 6
                   AND type = 'age_eligible_first_communion'"
            )->fetchColumn()
        );
    }

    public function testAgeEligibilityFiltersAreDisplayOnlyAndSupportCustomRanges(): void
    {
        $people = [
            ['id' => 1, 'age' => 8],
            ['id' => 2, 'age' => 11],
            ['id' => 3, 'age' => 16],
            ['id' => 4, 'age' => 22],
        ];

        self::assertSame([1, 2, 3, 4], array_column(sd_filter_age_eligible_persons($people, 'all'), 'id'));
        self::assertSame([2, 3, 4], array_column(sd_filter_age_eligible_persons($people, 'custom', 10), 'id'));
        self::assertSame([1, 2, 3, 4], array_column(sd_filter_age_eligible_persons($people, 'custom', 8), 'id'));
        self::assertSame([4], array_column(sd_filter_age_eligible_persons($people, 'custom', 20), 'id'));
    }

    public function testCustomAgeEligibilityFilterRequiresValidBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        sd_filter_age_eligible_persons([['age' => 8]], 'custom', 150);
    }

    public function testCustomAgeEligibilityFilterRejectsInvalidGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        sd_filter_age_eligible_persons([['age' => 8]], '7_9');
    }

    public function testSafeReturnUrls(): void
    {
        self::assertNotNull(safe_internal_return_url('/admin/requests/queue.php?status=pending'));
        self::assertNull(safe_internal_return_url('https://example.com/admin/queue.php'));
        self::assertNull(safe_internal_return_url('/admin/../../outside.php'));
    }

    public function testSavingRegisterIdentityCreatesAndReusesMasterPerson(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for the in-memory person profile test.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE persons (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT NOT NULL,
                middle_name TEXT,
                last_name TEXT,
                suffix TEXT,
                maiden_name TEXT,
                sex TEXT,
                birth_date TEXT,
                birth_place TEXT,
                father_name TEXT,
                mother_maiden_name TEXT,
                public_reference TEXT
            )'
        );
        $identity = [
            'first_name' => 'Maria',
            'middle_name' => 'Teresa',
            'last_name' => 'Santos',
            'birth_date' => '2000-01-02',
            'birth_place' => 'Dingalan',
            'father_name' => 'Juan Santos',
            'mother_maiden_name' => 'Rosa Cruz',
        ];

        $personId = save_person_identity($pdo, null, $identity);
        $samePersonId = save_person_identity($pdo, $personId, $identity);

        self::assertSame($personId, $samePersonId);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM persons')->fetchColumn());
        $profile = $pdo->query('SELECT * FROM persons')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('Juan Santos', $profile['father_name']);
        self::assertSame('Rosa Cruz', $profile['mother_maiden_name']);
        self::assertMatchesRegularExpression('/^SPPO-P-[A-F0-9]{16}$/', $profile['public_reference']);
    }

    public function testTrackingTokenUsesRandomLookingUnambiguousFormat(): void
    {
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{16}$/', generate_tracking_token());
    }

    public function testPhilippineMobileNumberFormats(): void
    {
        self::assertTrue(is_valid_phone_number('09171234567'));
        self::assertTrue(is_valid_phone_number('+639171234567'));
        self::assertTrue(is_valid_phone_number('+63 917-123-4567'));
        self::assertFalse(is_valid_phone_number('+63 917-123'));
    }

    public function testNamePartsAllowAnAbsentMiddleName(): void
    {
        self::assertSame(
            'Maria Santos',
            compose_person_name([
                'applicant_first' => ' Maria ',
                'applicant_middle' => '',
                'applicant_last' => 'Santos',
                'applicant_suffix' => '',
            ], 'applicant')
        );
        self::assertNull(validate_person_name_fields([
            'applicant_first' => 'Maria',
            'applicant_middle' => '',
            'applicant_last' => 'Santos',
            'applicant_suffix' => '',
        ], 'applicant', 'Applicant'));
        self::assertNull(validate_full_name('Maria Santos'));
        self::assertSame('J. Santos', compose_person_name([
            'applicant_first' => 'J.',
            'applicant_last' => 'Santos',
        ], 'applicant'));
        self::assertNotNull(validate_full_name('J. Santos'));
    }

    public function testNamePartsAllowMissingFirstOrLastName(): void
    {
        self::assertSame('Madonna', compose_person_name([
            'applicant_first' => 'Madonna',
            'applicant_middle' => '',
            'applicant_last' => '',
            'applicant_suffix' => '',
        ], 'applicant'));
        self::assertNull(validate_person_name_fields([
            'applicant_first' => 'Madonna',
            'applicant_middle' => '',
            'applicant_last' => '',
            'applicant_suffix' => '',
        ], 'applicant', 'Applicant'));
        self::assertNull(validate_full_name('Madonna'));
        self::assertNotNull(validate_person_name_fields([
            'applicant_first' => '',
            'applicant_middle' => '',
            'applicant_last' => '',
            'applicant_suffix' => '',
        ], 'applicant', 'Applicant'));
        self::assertNotNull(validate_person_name_fields([
            'applicant_first' => '',
            'applicant_middle' => 'Angela',
            'applicant_last' => '',
            'applicant_suffix' => '',
        ], 'applicant', 'Applicant'));
    }

    public function testParentNameCanUseNATextWhenUnknown(): void
    {
        $unknownName = [
            'father_first' => 'N/A',
            'father_middle' => '',
            'father_last' => '',
            'father_suffix' => '',
        ];

        self::assertNull(validate_parent_name_or_na($unknownName, 'father', "Father's name"));
        self::assertSame('N/A', compose_person_name($unknownName, 'father'));
        self::assertNotNull(validate_parent_name_or_na([
            'father_first' => 'N/A',
            'father_middle' => '',
            'father_last' => 'Santos',
            'father_suffix' => '',
        ], 'father', "Father's name"));
    }

    public function testRecordListNameUsesStructuredMiddleName(): void
    {
        self::assertSame(
            'Mikaela Therese R. Dela Cruz',
            display_name_with_middle_initial('Mikaela Therese Rivera Dela Cruz', 'Mikaela Therese', 'Rivera', 'Dela Cruz')
        );
    }

    public function testRecordListNameFallsBackToFullNameWhenStructuredPartsAreMissing(): void
    {
        self::assertSame(
            'Mikaela T. Rivera',
            display_name_with_middle_initial('Mikaela Therese Rivera')
        );
    }

    public function testBaptismalCertificateDoesNotPrintRequesterPurposeAsAnOfficialAnnotation(): void
    {
        $values = baptismal_certificate_values(
            ['child_full_name' => 'Mikaela Therese Rivera Dela Cruz'],
            ['purpose' => 'other', 'purpose_other' => 'School enrollment']
        );

        self::assertSame('', $values['purpose']);
    }

    public function testMarriagePurposeCertificatesHaveNoProjectDefinedExpiryDate(): void
    {
        $baptismalValues = baptismal_certificate_values(
            ['child_full_name' => 'Mikaela Therese Rivera Dela Cruz'],
            ['purpose' => 'Marriage', 'printed_at' => '2026-11-07 10:00:00']
        );
        self::assertSame('For Marriage Purposes', $baptismalValues['purpose']);

        $confirmationCertificate = sacramental_certificate_svg(
            'confirmation',
            ['confirmand_full_name' => 'Mikaela Therese Rivera Dela Cruz'],
            ['purpose' => 'Marriage', 'printed_at' => '2026-11-07 10:00:00']
        );
        self::assertStringContainsString('For Marriage Purposes', $confirmationCertificate);
        self::assertStringNotContainsString('valid through', $confirmationCertificate);

        $firstCommunionCertificate = sacramental_certificate_svg(
            'first_communion',
            ['communicant_full_name' => 'Mikaela Therese Rivera Dela Cruz'],
            ['purpose' => 'Marriage', 'printed_at' => '2026-11-07 10:00:00']
        );
        self::assertStringNotContainsString('For Marriage Purposes', $firstCommunionCertificate);
    }

    public function testStructuredNameRowsComposeAndRejectIncompleteRows(): void
    {
        $names = [
            'sponsor_first' => ['Maria', 'Juan', ''],
            'sponsor_middle' => ['', 'Pablo', ''],
            'sponsor_last' => ['Santos', 'Dela Cruz', ''],
            'sponsor_suffix' => ['', 'Jr.', ''],
        ];

        self::assertSame(['Maria Santos', 'Juan Pablo Dela Cruz Jr.'], compose_person_name_rows($names, 'sponsor'));
        self::assertSame([], validate_person_name_rows($names, 'sponsor', 'Sponsor'));
        self::assertSame(
            ['Please enter a first name or last name for Sponsor.'],
            validate_person_name_rows([
                'sponsor_middle' => ['Pablo'],
                'sponsor_first' => [''],
                'sponsor_last' => [''],
            ], 'sponsor', 'Sponsor')
        );
    }

    public function testStructuredNameRowsCanPreserveRelatedFieldIndexes(): void
    {
        $names = [
            'witness_first' => ['', 'Juan'],
            'witness_middle' => ['', 'Pablo'],
            'witness_last' => ['', 'Dela Cruz'],
            'witness_suffix' => ['', 'Jr.'],
        ];

        self::assertSame([1 => 'Juan Pablo Dela Cruz Jr.'], compose_person_name_rows_by_index($names, 'witness'));
    }

    public function testFullNameParticipantRowsComposeValidateAndPreserveIndexes(): void
    {
        $names = ['witness_full_name' => ['', 'Juan Dela Cruz', 'J. Santos']];

        self::assertSame([1 => 'Juan Dela Cruz', 2 => 'J. Santos'], compose_person_name_rows_by_index($names, 'witness'));
        self::assertSame(['Juan Dela Cruz', 'J. Santos'], compose_person_name_rows($names, 'witness'));
        self::assertSame(
            ['Witness: Please spell out every name in full — initials (e.g. "J.", "D") are not allowed.'],
            validate_person_name_rows($names, 'witness', 'Witness')
        );
    }

    public function testPublicRecordLookupAcceptsOneOrBothParents(): void
    {
        self::assertNull(record_lookup_parent_search_error('', 'Maria Santos'));
        self::assertNull(record_lookup_parent_search_error('Juan Cruz', 'Maria Santos'));
        self::assertNotNull(record_lookup_parent_search_error('', ''));
        self::assertNull(record_lookup_parent_search_error('Juan', ''));
        self::assertNotNull(record_lookup_parent_search_error('Jr.', ''));

        self::assertTrue(record_lookup_parents_match([
            ['input' => 'Juan Cruz', 'candidates' => ['Juan Cruz']],
            ['input' => '', 'candidates' => ['']],
        ]));
        self::assertTrue(record_lookup_parents_match([
            ['input' => 'Juan Cruz', 'candidates' => ['Juan Cruz']],
            ['input' => 'Maria Santos', 'candidates' => ['Maria Santos']],
        ]));
        self::assertFalse(record_lookup_parents_match([
            ['input' => 'Juan Cruz', 'candidates' => ['Juan Cruz']],
            ['input' => 'Maria Wrong', 'candidates' => ['Maria Santos']],
        ]));
    }

    public function testPublicRecordLookupMatchesANameWithOneMissingPart(): void
    {
        self::assertTrue(record_lookup_name_matches('Maria', '', 'Maria Santos', 'Maria', 'Santos'));
        self::assertTrue(record_lookup_name_matches('', 'Santos', 'Maria Santos', 'Maria', 'Santos'));
        self::assertFalse(record_lookup_name_matches('', '', 'Maria Santos', 'Maria', 'Santos'));
    }

    public function testPublicApplicationCanLoadAProfileForPrefill(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE persons (
                id INTEGER PRIMARY KEY,
                first_name TEXT, middle_name TEXT, last_name TEXT, suffix TEXT,
                birth_date TEXT, birth_place TEXT, father_name TEXT, mother_maiden_name TEXT,
                address TEXT, contact_number TEXT, email TEXT, sex TEXT,
                is_deceased INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            "INSERT INTO persons VALUES
                (7, 'Maria', 'Therese', 'Santos', '', '2010-03-12', 'Quezon City',
                 'Juan Santos', 'Ana Cruz', 'Parish address', '09171234567', 'maria@example.com', 'female', 0),
                (8, 'Dead', 'Person', 'Example', '', '2010-03-12', 'Quezon City',
                 NULL, NULL, NULL, NULL, NULL, 'female', 1)"
        );

        $person = public_application_profile($pdo, 7);
        self::assertNotNull($person);
        self::assertSame('Maria', $person['first_name']);
        self::assertSame('maria@example.com', $person['email']);
        self::assertNull(public_application_profile($pdo, 0));
        self::assertNull(public_application_profile($pdo, 999));
        self::assertNull(public_application_profile($pdo, 8));
    }

    public function testPublicApplicationProfileFieldsMapIdentityWithoutInventingValues(): void
    {
        self::assertSame([
            'applicant_first' => 'Maria',
            'applicant_middle' => '',
            'applicant_last' => 'Santos',
            'applicant_suffix' => '',
            'applicant_birthdate' => '',
            'applicant_birthplace' => '',
            'applicant_person_id' => '7',
            'selected_father_name' => '',
            'selected_mother_maiden_name' => '',
        ], public_application_profile_fields([
            'id' => 7,
            'first_name' => 'Maria',
            'middle_name' => null,
            'last_name' => 'Santos',
            'suffix' => null,
            'birth_date' => null,
            'birth_place' => null,
            'father_name' => null,
            'mother_maiden_name' => null,
        ], 'applicant'));
    }

    public function testPublicApplicationCanKeepAProfileLinkedWhenItsBirthDateIsUnknown(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE persons (
                id INTEGER PRIMARY KEY,
                first_name TEXT, middle_name TEXT, last_name TEXT, suffix TEXT, birth_date TEXT,
                is_deceased INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            "INSERT INTO persons VALUES
                (7, 'Maria', NULL, 'Santos', NULL, NULL, 0),
                (8, 'Ana', NULL, 'Cruz', NULL, '2010-03-12', 0),
                (9, 'Dead', NULL, 'Person', NULL, '2010-03-12', 1)"
        );

        self::assertSame(7, public_selected_person_id($pdo, '7', 'Maria', '', 'Santos', '', '2010-03-12'));
        try {
            public_selected_person_id($pdo, '9', 'Dead', '', 'Person', '', '2010-03-12');
            self::fail('A deceased person must not be linked to a public sacrament application.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'The selected person no longer matches these details. Search again.',
                $exception->getMessage()
            );
        }
        $this->expectException(InvalidArgumentException::class);
        public_selected_person_id($pdo, '8', 'Ana', '', 'Cruz', '', '2011-03-12');
    }

    public function testScheduleOverlapRules(): void
    {
        self::assertTrue(event_times_overlap('09:00:00', '10:00:00', '09:30:00', '10:30:00'));
        self::assertFalse(event_times_overlap('09:00:00', '10:00:00', '10:00:00', '11:00:00'));
        self::assertFalse(schedule_events_overlap_as_conflict(
            ['event_type' => 'baptism', 'start_time' => '09:00:00', 'end_time' => '10:00:00'],
            ['event_type' => 'baptism', 'start_time' => '09:30:00', 'end_time' => '10:30:00']
        ));
        self::assertTrue(schedule_events_overlap_as_conflict(
            ['event_type' => 'wedding', 'start_time' => '09:00:00', 'end_time' => '10:00:00'],
            ['event_type' => 'baptism', 'start_time' => '09:30:00', 'end_time' => '10:30:00']
        ));
    }

    public function testConfirmedApplicationScheduleValidation(): void
    {
        $today = new DateTimeImmutable('2026-11-16');

        self::assertNull(application_schedule_input_error('2026-11-16', '', '', $today));
        self::assertNull(application_schedule_input_error('2026-11-17', '09:00', '10:00', $today));
        self::assertSame(
            'Enter a valid confirmed date.',
            application_schedule_input_error('2026-02-30', '', '', $today)
        );
        self::assertSame(
            'The confirmed date cannot be in the past.',
            application_schedule_input_error('2026-11-15', '', '', $today)
        );
        self::assertSame(
            'Enter a valid start time.',
            application_schedule_input_error('2026-11-17', '24:00', '', $today)
        );
        self::assertSame(
            'Enter a start time before an end time.',
            application_schedule_input_error('2026-11-17', '', '10:00', $today)
        );
        self::assertSame(
            'The end time must be after the start time.',
            application_schedule_input_error('2026-11-17', '10:00', '09:00', $today)
        );
    }

    public function testStatusLabel(): void
    {
        self::assertSame("Waiting for priest's signature", status_label('certificate', 'for_signature'));
        self::assertSame('Needs action', status_label('certificate', 'pending'));
        self::assertSame('New', status_label('application', 'new'));
        self::assertSame('Pending: missing documents', status_label('application', 'requirements_pending'));
        self::assertSame('Unknown Value', status_label('certificate', 'unknown_value'));
    }
    public function testRecordChanges(): void
    {
        $old = ['event_date' => '2026-01-01 ', 'details' => ['b' => '2', 'a' => '1'], 'participants' => [['role' => 'godparent', 'full_name' => ' Juan ', 'residence' => '', 'sort_order' => 1]], 'remarks' => ''];
        $new = ['event_date' => '2026-01-01', 'details' => ['a' => '1', 'b' => '2'], 'participants' => [['role' => 'godparent', 'full_name' => 'Juan', 'residence' => null, 'sort_order' => 1]], 'remarks' => null];
        self::assertSame([], sd_record_changes($old, $new));
        $new2 = ['event_date' => '2026-01-02', 'details' => ['a' => '1', 'b' => '3'], 'participants' => [['role' => 'godparent', 'full_name' => 'Juan', 'residence' => null, 'sort_order' => 2]], 'remarks' => 'x'];
        $changes = sd_record_changes($old, $new2);
        self::assertContains('event_date', $changes);
        self::assertContains('details', $changes);
        self::assertContains('participants', $changes);
        self::assertContains('remarks', $changes);
    }

    public function testRecordAuditChangesPreserveBeforeAndAfterValues(): void
    {
        self::assertSame(
            [
                'record' => [
                    'before' => ['event_date' => '2026-01-01'],
                    'after' => ['event_date' => '2026-01-02'],
                ],
            ],
            sd_record_audit_changes(
                ['record' => ['event_date' => '2026-01-01']],
                ['record' => ['event_date' => '2026-01-02']]
            )
        );
        self::assertSame(
            [],
            sd_record_audit_changes(
                ['record' => ['event_date' => '2026-01-01']],
                ['record' => ['event_date' => '2026-01-01']]
            )
        );
    }

    public function testPersonAuditChangesPreserveChangedFieldBeforeAndAfterValues(): void
    {
        self::assertSame(
            [
                'first_name' => ['before' => 'Maria', 'after' => 'Mary'],
                'birth_place' => ['before' => null, 'after' => 'Taguig'],
                'observanda' => ['before' => null, 'after' => 'Profile note'],
            ],
            sd_person_audit_changes(
                ['first_name' => 'Maria', 'middle_name' => null, 'birth_place' => '', 'observanda' => ''],
                ['first_name' => 'Mary', 'middle_name' => null, 'birth_place' => 'Taguig', 'observanda' => 'Profile note']
            )
        );
        self::assertSame(
            [],
            sd_person_audit_changes(
                ['first_name' => 'Maria', 'middle_name' => ''],
                ['first_name' => ' Maria ', 'middle_name' => null]
            )
        );
    }
    public function testOutstandingRequirementKeys(): void
    {
        $json = '{"catechism_completion":{"status":"not_received","note":""},"baptismal_certificate":{"status":"not_applicable"},"x":{"status":"verified"}}';
        self::assertSame(['catechism_completion'], application_outstanding_requirement_keys($json));
        self::assertSame([], application_outstanding_requirement_keys(null));
        self::assertSame([], application_outstanding_requirement_keys('not json'));
    }

    public function testSacramentFormationIntakeAcceptsBatchAndCatchUpChoices(): void
    {
        self::assertNull(sacrament_formation_input_error('batch', 'grade_3', '2026-2027'));
        self::assertNull(sacrament_formation_input_error('individual_catchup', 'grade_6', '2026-2027'));
        self::assertNull(sacrament_formation_input_error('not_sure', 'not_enrolled', '2026-2027'));
        self::assertNull(sacrament_formation_input_error('individual_catchup', 'not_enrolled', 'N/A'));
    }

    public function testSacramentFormationIntakeRejectsInvalidGradeOrSchoolYear(): void
    {
        self::assertSame(
            'Choose a valid current grade level.',
            sacrament_formation_input_error('batch', 'grade_13', '2026-2027')
        );
        self::assertSame(
            'Enter the school year as two consecutive years, for example 2026-2027.',
            sacrament_formation_input_error('batch', 'grade_3', '2026-2028')
        );
        self::assertSame(
            'A school year is required for a group request, for example 2026-2027.',
            sacrament_formation_input_error('batch', 'grade_3', '')
        );
    }

    public function testFuneralValidationAcceptsAValidRecord(): void
    {
        $today = new DateTimeImmutable('today');
        $death = $today->modify('-2 days')->format('Y-m-d');
        $burial = $today->modify('+3 days')->format('Y-m-d');

        self::assertSame([], funeral_validate([
            'date_of_death' => $death,
            'date_of_burial' => $burial,
            'age_at_death' => '42',
            'civil_status' => 'married',
            'spouse_name' => 'Avery Example',
            'service_type' => 'mass',
            'service_date' => $death,
            'service_time' => '09:30',
        ], '1984-05-10'));
    }

    public function testFuneralValidationRejectsFutureDeathDate(): void
    {
        $future = (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');

        self::assertContains('Date of death cannot be in the future.', funeral_validate([
            'date_of_death' => $future,
            'service_type' => 'mass',
        ], null));
    }

    public function testFuneralValidationRejectsDeathBeforeBirth(): void
    {
        self::assertContains('Date of death cannot be before the person\'s date of birth.', funeral_validate([
            'date_of_death' => '2000-01-01',
            'service_type' => 'mass',
        ], '2000-01-02'));
    }

    public function testFuneralValidationRejectsBurialBeforeDeath(): void
    {
        self::assertContains('Date of burial cannot be before date of death.', funeral_validate([
            'date_of_death' => '2020-01-02',
            'date_of_burial' => '2020-01-01',
            'service_type' => 'blessing',
        ], null));
    }

    public function testFuneralValidationAllowsFutureBurial(): void
    {
        $today = new DateTimeImmutable('today');

        self::assertSame([], funeral_validate([
            'date_of_death' => $today->format('Y-m-d'),
            'date_of_burial' => $today->modify('+1 day')->format('Y-m-d'),
            'service_type' => 'blessing',
        ], null));
    }

    public function testFuneralValidationRejectsSpouseNameForSinglePerson(): void
    {
        self::assertContains('A single person cannot have a spouse name.', funeral_validate([
            'date_of_death' => '2020-01-02',
            'civil_status' => 'single',
            'spouse_name' => 'Avery Example',
            'service_type' => 'mass',
        ], null));
    }

    public function testFuneralValidationRejectsInvalidCivilStatus(): void
    {
        self::assertContains('Choose a valid civil status.', funeral_validate([
            'date_of_death' => '2020-01-02',
            'civil_status' => 'divorced',
            'service_type' => 'mass',
        ], null));
    }

    public function testFuneralValidationRejectsBadServiceTypeAndAgeOver130(): void
    {
        $errors = funeral_validate([
            'date_of_death' => '2020-01-02',
            'service_type' => 'procession',
            'age_at_death' => '200',
        ], null);

        self::assertContains('Choose Mass or Blessing for the service type.', $errors);
        self::assertContains('Age at death must be a whole number from 0 to 130.', $errors);
    }

    public function testFuneralValidationRejectsNonScalarInput(): void
    {
        $errors = funeral_validate([
            'date_of_death' => '2020-01-02',
            'service_type' => 'mass',
            'age_at_death' => ['200'],
        ], null);

        self::assertContains('Enter a valid age at death.', $errors);
    }

    public function testFuneralCalculatedAge(): void
    {
        self::assertSame(45, funeral_calculated_age('1980-11-15', '2026-11-14'));
        self::assertSame(46, funeral_calculated_age('1980-11-14', '2026-11-14'));
        self::assertNull(funeral_calculated_age(null, '2026-11-14'));
        self::assertNull(funeral_calculated_age('2026-11-15', '2026-11-14'));
    }

    public function testFuneralCertificationLinesIncludeFullRecordDetails(): void
    {
        self::assertSame([
            'This is to certify that, according to the Liber Defunctorum of this parish, Ramon Elias Santos, 76 years old, Widowed, spouse of Lucia Santos, son of Pedro Santos and Ana Cruz, died on March 4, 2026 of pneumonia.',
            'A Funeral Mass was held on March 5, 2026 at 9:30 AM, officiated by Rev. Tomas, and the remains were laid to rest on March 6, 2026 at St. Patrick\'s Cemetery.',
            'This is recorded in the Liber Defunctorum, Book 4, Page 18, Line 6.',
            'Issued this March 7, 2026 at St. Patrick\'s Parish Office, Dingalan, Aurora, upon request of Maria Santos for SSS claim purposes.',
        ], funeral_certification_lines([
            'date_of_death' => '2026-03-04',
            'age_at_death' => 76,
            'civil_status' => 'widowed',
            'spouse_name' => 'Lucia Santos',
            'parents_name' => 'Pedro Santos and Ana Cruz',
            'cause_of_death' => 'pneumonia',
            'service_type' => 'mass',
            'service_date' => '2026-03-05',
            'service_time' => '09:30:00',
            'minister_name' => 'Rev. Tomas',
            'date_of_burial' => '2026-03-06',
            'place_of_burial' => 'St. Patrick\'s Cemetery',
            'book_no' => '4',
            'page_no' => '18',
            'line_no' => '6',
        ], [
            'first_name' => 'Ramon',
            'middle_name' => 'Elias',
            'last_name' => 'Santos',
            'sex' => 'male',
        ], 'SSS claim', 'Maria Santos', '2026-03-07'));
    }

    public function testFuneralCertificationLinesOmitEmptyOptionalClauses(): void
    {
        $lines = funeral_certification_lines([
            'date_of_death' => '2026-03-04',
            'service_type' => 'mass',
        ], [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ], 'Insurance', '', '2026-03-05');

        self::assertSame([
            'This is to certify that, according to the Liber Defunctorum of this parish, Jane Doe died on March 4, 2026.',
            'A Funeral Mass was held.',
            'This is recorded in the Liber Defunctorum.',
            'Issued this March 5, 2026 at St. Patrick\'s Parish Office, Dingalan, Aurora for Insurance purposes.',
        ], $lines);
        self::assertStringNotContainsString('—', implode(' ', $lines));
        self::assertStringNotContainsString('[]', implode(' ', $lines));
        self::assertStringNotContainsString('years old', implode(' ', $lines));
        self::assertStringNotContainsString('officiated by', implode(' ', $lines));
        self::assertStringNotContainsString('laid to rest', implode(' ', $lines));
    }

    public function testFuneralCertificationLinesOmitRequesterAndPurposeWhenEmpty(): void
    {
        $lines = funeral_certification_lines([
            'date_of_death' => '2026-03-04',
            'service_type' => 'mass',
        ], [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ], '', '', '2026-03-05');

        self::assertSame(
            'Issued this March 5, 2026 at St. Patrick\'s Parish Office, Dingalan, Aurora.',
            $lines[3]
        );
        self::assertStringNotContainsString('upon request', $lines[3]);
        self::assertStringNotContainsString('purposes', $lines[3]);
    }

    public function testFuneralCertificationLinesDistinguishMassAndBlessing(): void
    {
        $record = ['date_of_death' => '2026-03-04', 'service_type' => 'mass'];
        $person = ['first_name' => 'Jane', 'last_name' => 'Doe'];

        self::assertSame('A Funeral Mass was held.', funeral_certification_lines(
            $record,
            $person,
            'Legal claim',
            '',
            '2026-03-05'
        )[1]);
        $record['service_type'] = 'blessing';
        self::assertSame('A Funeral Blessing was held.', funeral_certification_lines(
            $record,
            $person,
            'Legal claim',
            '',
            '2026-03-05'
        )[1]);
    }

    public function testFuneralCertificationLinesReturnRawTextForPageEscaping(): void
    {
        $lines = funeral_certification_lines([
            'date_of_death' => '2026-03-04',
            'cause_of_death' => '<script>alert(1)</script>',
            'service_type' => 'blessing',
        ], [
            'first_name' => '<b>Jane</b>',
            'last_name' => 'Doe',
        ], 'Other <em>claim</em>', '', '2026-03-05');

        self::assertStringContainsString('<b>Jane</b>', $lines[0]);
        self::assertStringContainsString('<script>alert(1)</script>', $lines[0]);
        self::assertStringContainsString('Other <em>claim</em>', $lines[3]);
    }

}
