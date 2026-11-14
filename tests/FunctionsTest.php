<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/certificates/baptismal-certificate.php';
require_once __DIR__ . '/../admin/certificates/sacramental-certificate.php';
require_once __DIR__ . '/../includes/person-linking.php';
require_once __DIR__ . '/../includes/record-search.php';
require_once __DIR__ . '/../includes/sacrament-data.php';

final class FunctionsTest extends TestCase
{
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
        self::assertFalse(cert_request_transition_valid('new', 'for_signature'));
        self::assertFalse(cert_request_transition_valid('released', 'ready'));
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
                address TEXT, contact_number TEXT, email TEXT, sex TEXT
            )'
        );
        $pdo->exec(
            "INSERT INTO persons VALUES
                (7, 'Maria', 'Therese', 'Santos', '', '2010-03-12', 'Quezon City',
                 'Juan Santos', 'Ana Cruz', 'Parish address', '09171234567', 'maria@example.com', 'female')"
        );

        $person = public_application_profile($pdo, 7);
        self::assertNotNull($person);
        self::assertSame('Maria', $person['first_name']);
        self::assertSame('maria@example.com', $person['email']);
        self::assertNull(public_application_profile($pdo, 0));
        self::assertNull(public_application_profile($pdo, 999));
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
                first_name TEXT, middle_name TEXT, last_name TEXT, suffix TEXT, birth_date TEXT
            )'
        );
        $pdo->exec(
            "INSERT INTO persons VALUES
                (7, 'Maria', NULL, 'Santos', NULL, NULL),
                (8, 'Ana', NULL, 'Cruz', NULL, '2010-03-12')"
        );

        self::assertSame(7, public_selected_person_id($pdo, '7', 'Maria', '', 'Santos', '', '2010-03-12'));
        $this->expectException(InvalidArgumentException::class);
        public_selected_person_id($pdo, '8', 'Ana', '', 'Cruz', '', '2011-03-12');
    }

    public function testScheduleOverlapRules(): void
    {
        self::assertTrue(event_times_overlap('09:00:00', '10:00:00', '09:30:00', '10:30:00'));
        self::assertFalse(event_times_overlap('09:00:00', '10:00:00', '10:00:00', '11:00:00'));
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

}
