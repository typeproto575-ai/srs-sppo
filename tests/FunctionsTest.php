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
require_once __DIR__ . '/../includes/audit-history.php';

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

    public function testLateBaptismPathwayStartsOnSeventhBirthdayAndRejectsInvalidDates(): void
    {
        $today = new DateTimeImmutable('today');
        $seventhBirthday = $today->modify('-7 years')->format('Y-m-d');
        $dayBeforeSeventhBirthday = $today->modify('-7 years')->modify('+1 day')->format('Y-m-d');

        self::assertSame(7, baptism_application_age($seventhBirthday));
        self::assertTrue(baptism_application_is_late($seventhBirthday));
        self::assertSame(6, baptism_application_age($dayBeforeSeventhBirthday));
        self::assertFalse(baptism_application_is_late($dayBeforeSeventhBirthday));
        self::assertNull(baptism_application_age('2023-02-29'));
        self::assertNull(baptism_application_is_late('2023-02-29'));
    }

    public function testFirstCommunionFormationValidationFollowsTheSelectedPathway(): void
    {
        self::assertNull(first_communion_formation_input_error('school_based', 'grade_4', '2026'));
        self::assertSame(
            'First Holy Communion applications require Grade 4 or above.',
            first_communion_formation_input_error('school_based', 'grade_3', '2026')
        );
        self::assertNull(first_communion_formation_input_error('school_based', 'grade_4', ''));
        self::assertNotNull(first_communion_formation_input_error('school_based', 'grade_4', '2026-2027'));
        self::assertNotNull(first_communion_formation_input_error('school_based', 'grade_4', '26'));
        self::assertNotNull(first_communion_formation_input_error('school_based', '', '2026'));
        self::assertNotNull(first_communion_formation_input_error('school_based', 'not_applicable', '2026'));
        self::assertNotNull(first_communion_formation_input_error('school_based', 'not_enrolled', '2026'));

        self::assertNull(first_communion_formation_input_error('parish_based', 'not_enrolled', ''));
        self::assertNull(first_communion_formation_input_error('parish_based', 'grade_4', '2026'));
        self::assertNotNull(first_communion_formation_input_error('parish_based', 'grade_3', ''));
        self::assertNotNull(first_communion_formation_input_error('parish_based', '', ''));

        self::assertNull(first_communion_formation_input_error('individual_catchup', '', 'invalid'));
        self::assertNull(first_communion_formation_input_error('individual_catchup', 'N/A', '2026'));
        self::assertSame(
            'First Holy Communion applications require Grade 4 or above.',
            first_communion_formation_input_error('individual_catchup', 'grade_2', '')
        );
        self::assertNull(first_communion_formation_input_error('individual_catchup', 'not_applicable', '2026-2027'));
        self::assertArrayNotHasKey('kindergarten', first_communion_grade_options());
        self::assertArrayNotHasKey('grade_3', first_communion_grade_options());
        self::assertArrayHasKey('grade_4', first_communion_grade_options());
        self::assertArrayHasKey('not_applicable', first_communion_grade_options());
        self::assertSame('Adult / Not in school', first_communion_grade_options()['not_applicable']);
        self::assertSame('Out-of-School Youth (OSY)', first_communion_grade_options()['not_enrolled']);
        self::assertSame('Other', first_communion_grade_select_options()['other']);
        self::assertArrayNotHasKey('not_enrolled', first_communion_grade_select_options());
        self::assertArrayNotHasKey('not_applicable', first_communion_grade_select_options());
        self::assertSame(
            'Out-of-School Youth (OSY)',
            first_communion_grade_options()['not_enrolled']
        );
    }

    public function testFirstCommunionPlaceRequiresSpecificationForOther(): void
    {
        self::assertNull(first_communion_place_input_error('parish_church', ''));
        self::assertNull(first_communion_place_input_error('other', 'Barangay Chapel'));
        self::assertNotNull(first_communion_place_input_error('other', ''));
        self::assertNotNull(first_communion_place_input_error('invalid', 'Parish Hall'));
        self::assertNotNull(first_communion_place_input_error('other', str_repeat('x', 151)));
    }

    public function testFirstCommunionSponsorNamesHaveNoApplicationCountLimit(): void
    {
        $sponsors = array_map(
            static fn(int $index): string => 'Sponsor Name ' . $index,
            range(1, 20)
        );
        $values = ['communion_sponsor_full_name' => $sponsors];

        self::assertSame([], validate_person_name_rows($values, 'communion_sponsor', 'Godparent/sponsor'));
        self::assertCount(20, compose_person_name_rows($values, 'communion_sponsor'));
    }

    public function testMarriageAgeReviewUsesExactAgeBoundaries(): void
    {
        $underage = marriage_party_age_eligibility('2008-03-16', '2026-03-15');
        self::assertSame(17, $underage['age']);
        self::assertSame('underage', $underage['status']);
        self::assertSame('error', $underage['tone']);

        $requiresConsent = marriage_party_age_eligibility('2008-03-15', '2026-03-15');
        self::assertSame(18, $requiresConsent['age']);
        self::assertSame('parental_consent', $requiresConsent['status']);

        $requiresAdvice = marriage_party_age_eligibility('2005-03-15', '2026-03-15');
        self::assertSame(21, $requiresAdvice['age']);
        self::assertSame('parental_advice', $requiresAdvice['status']);

        $eligible = marriage_party_age_eligibility('2001-03-15', '2026-03-15');
        self::assertSame(25, $eligible['age']);
        self::assertSame('eligible', $eligible['status']);

        self::assertSame('unknown', marriage_party_age_eligibility('2023-02-29', '2026-03-15')['status']);
        self::assertSame('unknown', marriage_party_age_eligibility('2000-01-01', '2026-02-30')['status']);
    }

    public function testActiveMarriageAgeReviewsUseScheduleDateAndLinkedPersonDetails(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for the marriage age review data test.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE persons (
            id INTEGER PRIMARY KEY,
            first_name TEXT,
            middle_name TEXT,
            last_name TEXT,
            suffix TEXT,
            birth_date TEXT
        )');
        $pdo->exec('CREATE TABLE sacrament_applications (
            id INTEGER PRIMARY KEY,
            request_no TEXT,
            status TEXT,
            sacrament_type TEXT,
            person_id INTEGER,
            spouse_person_id INTEGER,
            details TEXT,
            anonymized_at TEXT,
            created_at TEXT
        )');
        $pdo->exec('CREATE TABLE schedule_events (
            id INTEGER PRIMARY KEY,
            application_id INTEGER,
            event_date TEXT
        )');
        $pdo->exec("INSERT INTO persons VALUES
            (1, 'Groom', NULL, 'Linked', NULL, '2008-03-16'),
            (2, 'Bride', NULL, 'Linked', NULL, '2005-03-15')");
        $pdo->exec("INSERT INTO sacrament_applications VALUES
            (1, 'MR-1', 'scheduled', 'marriage', 1, 2, '{\"groom\":{},\"bride\":{}}', NULL, '2026-01-01'),
            (2, 'MR-2', 'new', 'marriage', NULL, NULL, '{\"groom\":{\"birth_date\":\"2000-01-01\"},\"bride\":{\"birth_date\":\"2000-01-01\"}}', NULL, '2026-01-02'),
            (3, 'MR-3', 'reviewing', 'marriage', NULL, NULL, '{\"groom\":{},\"bride\":{}}', NULL, '2026-01-03'),
            (4, 'MR-4', 'completed', 'marriage', NULL, NULL, '{}', NULL, '2026-01-04')");
        $pdo->exec("INSERT INTO schedule_events VALUES (1, 1, '2026-03-15')");

        $applications = sd_get_active_marriage_age_reviews($pdo);
        $byRequestNo = [];
        foreach ($applications as $application) {
            $byRequestNo[$application['request_no']] = $application;
        }

        self::assertSame(['MR-3', 'MR-2', 'MR-1'], array_column($applications, 'request_no'));
        self::assertSame('Today (no confirmed schedule)', $byRequestNo['MR-2']['date_source']);
        self::assertSame('Confirmed marriage schedule', $byRequestNo['MR-1']['date_source']);
        self::assertSame(17, $byRequestNo['MR-1']['groom']['age_review']['age']);
        self::assertSame('parental_advice', $byRequestNo['MR-1']['bride']['age_review']['status']);
        self::assertSame('Groom Linked', $byRequestNo['MR-1']['groom']['name']);
        self::assertSame('Bride Linked', $byRequestNo['MR-1']['bride']['name']);
        self::assertSame('unknown', $byRequestNo['MR-3']['groom']['age_review']['status']);
    }

    public function testMarriageMinimumAgeFilterAppliesToBothPartiesAndAllKeepsUnknown(): void
    {
        $applications = [
            [
                'id' => 1,
                'groom' => ['age_review' => ['age' => 17]],
                'bride' => ['age_review' => ['age' => 25]],
            ],
            [
                'id' => 2,
                'groom' => ['age_review' => ['age' => 21]],
                'bride' => ['age_review' => ['age' => 24]],
            ],
            [
                'id' => 3,
                'groom' => ['age_review' => ['age' => null]],
                'bride' => ['age_review' => ['age' => 25]],
            ],
        ];

        self::assertSame([1, 2, 3], array_column(
            sd_filter_marriage_age_review_applications($applications, 'all'),
            'id'
        ));
        self::assertSame([2], array_column(
            sd_filter_marriage_age_review_applications($applications, 'custom', 18),
            'id'
        ));
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

    public function testMarriageScheduleProposalEmailRequiresSixtyMinuteDuration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        mail_marriage_schedule_proposal(
            'couple@example.com',
            'REQ-2026-0042',
            'Groom',
            'Bride',
            '',
            '2026-12-10',
            '09:00',
            '10:30'
        );
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

    public function testCertificatePaymentRequiresMatchingSacramentRecord(): void
    {
        $request = [
            'record_id' => null,
            'sacrament_type' => 'baptism',
        ];

        self::assertFalse(cert_request_payment_record_gate($request, null)['ok']);
        self::assertFalse(cert_request_payment_record_gate($request, [
            'id' => 12,
            'sacrament_type' => 'baptism',
        ])['ok']);

        $request['record_id'] = 12;
        self::assertFalse(cert_request_payment_record_gate($request, [
            'id' => 13,
            'sacrament_type' => 'baptism',
        ])['ok']);
        self::assertFalse(cert_request_payment_record_gate($request, [
            'id' => 12,
            'sacrament_type' => 'confirmation',
        ])['ok']);
        self::assertTrue(cert_request_payment_record_gate($request, [
            'id' => 12,
            'sacrament_type' => 'baptism',
        ])['ok']);
    }

    public function testCertificateReleaseRequiresPaymentAndAuthorizationForRepresentatives(): void
    {
        $request = ['status' => 'ready', 'paid_at' => null];
        self::assertSame(
            'Payment has not been recorded.',
            cert_request_release_gate($request, 'self', '', false)['reason']
        );

        $request['paid_at'] = '2026-10-14 05:00:00';
        self::assertTrue(cert_request_release_gate($request, 'self', '', false)['ok']);
        self::assertSame(
            'Select a valid claimant and provide representative details when applicable.',
            cert_request_release_gate($request, 'representative', '', true)['reason']
        );
        self::assertSame(
            'Confirm that the signed authorization letter was received.',
            cert_request_release_gate($request, 'representative', 'Representative', false)['reason']
        );
        self::assertTrue(
            cert_request_release_gate($request, 'representative', 'Representative', true)['ok']
        );
        self::assertFalse(
            cert_request_release_gate(['status' => 'matched', 'paid_at' => 'now'], 'self', '', false)['ok']
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
            'first_name' => 'maria',
            'middle_name' => 'teresa',
            'last_name' => 'santos',
            'birth_date' => '2000-01-02',
            'birth_place' => 'Dingalan',
            'father_name' => 'juan santos',
            'mother_maiden_name' => 'rosa cruz',
        ];

        $personId = save_person_identity($pdo, null, $identity);
        $samePersonId = save_person_identity($pdo, $personId, $identity);

        self::assertSame($personId, $samePersonId);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM persons')->fetchColumn());
        $profile = $pdo->query('SELECT * FROM persons')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('Maria', $profile['first_name']);
        self::assertSame('Teresa', $profile['middle_name']);
        self::assertSame('Santos', $profile['last_name']);
        self::assertSame('Juan Santos', $profile['father_name']);
        self::assertSame('Rosa Cruz', $profile['mother_maiden_name']);
        self::assertMatchesRegularExpression('/^SPPO-P-[A-F0-9]{16}$/', $profile['public_reference']);

        $otherPersonId = sd_save_person($pdo, [
            'first_name' => 'naruto',
            'middle_name' => 'McDonald',
            'last_name' => "o'neil",
            'suffix' => 'iii',
        ]);
        $otherProfile = $pdo->query('SELECT * FROM persons WHERE id = ' . (int) $otherPersonId)->fetch(PDO::FETCH_ASSOC);
        self::assertSame("Naruto McDonald O'Neil III", sd_full_name($otherProfile));
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

    public function testNameCapitalizationPreservesMixedCaseAndFormatsExistingDisplays(): void
    {
        self::assertSame(
            "Naruto McDonald O'Neil III",
            format_full_name("naruto McDonald o'neil iii")
        );
        self::assertSame(
            "Naruto McDonald O'Neil III",
            sd_full_name([
                'first_name' => 'naruto',
                'middle_name' => 'McDonald',
                'last_name' => "o'neil",
                'suffix' => 'iii',
            ])
        );
        self::assertSame(
            [0 => "Naruto McDonald O'Neil III"],
            compose_person_name_rows_by_index([
                'godparent_full_name' => ["naruto McDonald o'neil iii"],
            ], 'godparent')
        );
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

    public function testParentNameCanRequireARealName(): void
    {
        self::assertNotNull(validate_parent_name_or_na([
            'mother_first' => 'N/A',
            'mother_last' => '',
        ], 'mother', "Mother's maiden name", false));
        self::assertNotNull(validate_parent_name_or_na([
            'mother_first' => 'N/A',
            'mother_last' => 'Santos',
        ], 'mother', "Mother's maiden name", false));
        self::assertNull(validate_parent_name_or_na([
            'mother_first' => 'Maria',
            'mother_last' => 'Santos',
        ], 'mother', "Mother's maiden name", false));
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
    }

    public function testParishScheduleSlotsExcludeBookedAndUnconfirmedTimes(): void
    {
        $slots = parish_schedule_available_slots([
            ['start_time' => '08:00:00', 'end_time' => '09:00:00'],
            ['start_time' => '10:30:00', 'end_time' => '11:30:00'],
        ]);
        $slotStarts = array_column($slots, 'start_time');

        self::assertNotContains('08:00', $slotStarts);
        self::assertContains('09:00', $slotStarts);
        self::assertNotContains('10:00', $slotStarts);
        self::assertNotContains('11:00', $slotStarts);
        self::assertContains('12:00', $slotStarts);
        self::assertNotContains('17:00', $slotStarts);
        self::assertSame([], parish_schedule_available_slots([
            ['start_time' => null, 'end_time' => null],
        ]));
    }

    public function testParishAvailabilityCombinesCalendarAndDeathSchedules(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE schedule_events (id INTEGER PRIMARY KEY, title TEXT, event_date TEXT, start_time TEXT, end_time TEXT)');
        $pdo->exec('CREATE TABLE funeral_records (id INTEGER PRIMARY KEY, service_date TEXT, service_time TEXT)');
        $date = (new DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d');
        $calendarInsert = $pdo->prepare(
            'INSERT INTO schedule_events (id, title, event_date, start_time, end_time) VALUES (1, ?, ?, ?, ?)'
        );
        $calendarInsert->execute(['Baptism schedule', $date, '09:00:00', '10:00:00']);
        $deathInsert = $pdo->prepare(
            'INSERT INTO funeral_records (id, service_date, service_time) VALUES (1, ?, ?)'
        );
        $deathInsert->execute([$date, '11:00:00']);

        $events = parish_schedule_day_events($pdo, $date);
        $slotStarts = array_column(parish_schedule_available_slots($events), 'start_time');
        self::assertCount(2, $events);
        self::assertNotContains('09:00', $slotStarts);
        self::assertNotContains('11:00', $slotStarts);
        self::assertContains('10:00', $slotStarts);

        $withoutCurrentBookings = parish_schedule_day_events($pdo, $date, 1, 1);
        $openStarts = array_column(parish_schedule_available_slots($withoutCurrentBookings), 'start_time');
        self::assertContains('09:00', $openStarts);
        self::assertContains('11:00', $openStarts);
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

    public function testAuditCorrectionRowsFlattenNestedRecordValues(): void
    {
        $correctionData = [
            'format' => 'record-correction-v1',
            'changes' => [
                'record' => [
                    'before' => [
                        'event_date' => '2026-01-01',
                        'details' => ['place' => 'Old chapel'],
                    ],
                    'after' => [
                        'event_date' => '2026-01-02',
                        'details' => ['place' => 'New chapel'],
                    ],
                ],
            ],
        ];

        self::assertSame([
            ['field' => 'Record · Event date', 'before' => '2026-01-01', 'after' => '2026-01-02'],
            ['field' => 'Record · Additional details · Place', 'before' => 'Old chapel', 'after' => 'New chapel'],
        ], audit_correction_rows($correctionData));
        self::assertSame(
            'Record · Event date: 2026-01-01 -> 2026-01-02; Record · Additional details · Place: Old chapel -> New chapel',
            audit_correction_summary($correctionData)
        );
        self::assertSame(
            audit_correction_summary($correctionData),
            audit_correction_full_summary($correctionData)
        );
        self::assertNull(audit_correction_data('not correction JSON'));
    }

    public function testRestoreSqlValidationRejectsUnsafeDatabaseCommands(): void
    {
        $sql = "-- SRS-SPPO database export\n"
            . "DROP DATABASE srs_sppo_db;\n"
            . "CREATE TABLE `persons` (id INT PRIMARY KEY);\n"
            . "CREATE TABLE `sacrament_records` (id INT PRIMARY KEY);\n"
            . "CREATE TABLE `sacrament_applications` (id INT PRIMARY KEY);\n"
            . "CREATE TABLE `audit_logs` (id INT PRIMARY KEY);\n";

        $this->expectException(RuntimeException::class);
        validate_restore_sql($sql);
    }

    public function testRestoreSqlValidationAllowsNormalDataTextContainingUse(): void
    {
        $sql = "-- SRS-SPPO database export\n"
            . "CREATE TABLE `persons` (id INT PRIMARY KEY);\n"
            . "CREATE TABLE `sacrament_records` (id INT PRIMARY KEY);\n"
            . "CREATE TABLE `sacrament_applications` (id INT PRIMARY KEY);\n"
            . "CREATE TABLE `audit_logs` (id INT PRIMARY KEY);\n"
            . "INSERT INTO `persons` (id) VALUES (1); -- the parish uses this record\n";

        $summary = validate_restore_sql($sql);
        self::assertSame(4, $summary['create_table_count']);
    }

    public function testRestoreSqlValidationAndUsersPreservation(): void
    {
        $sql = "-- SRS-SPPO database export\n\n"
            . "-- Table: persons\n"
            . "CREATE TABLE `persons` (id INT PRIMARY KEY);\n"
            . "INSERT INTO `persons` (id) VALUES (1);\n\n"
            . "-- Table: sacrament_records\n"
            . "CREATE TABLE `sacrament_records` (id INT PRIMARY KEY);\n\n"
            . "-- Table: sacrament_applications\n"
            . "CREATE TABLE `sacrament_applications` (id INT PRIMARY KEY);\n\n"
            . "-- Table: users\n"
            . "CREATE TABLE `users` (id INT PRIMARY KEY);\n"
            . "INSERT INTO `users` (id) VALUES (1);\n\n"
            . "-- Table: audit_logs\n"
            . "CREATE TABLE `audit_logs` (id INT PRIMARY KEY);\n";

        $summary = validate_restore_sql($sql);
        self::assertSame(5, $summary['create_table_count']);
        self::assertSame(2, $summary['insert_count']);
        $prepared = prepare_live_restore_sql($sql);
        self::assertStringNotContainsString('Table: users', $prepared);
        self::assertStringNotContainsString('INSERT INTO `users`', $prepared);
        self::assertStringContainsString('Table: audit_logs', $prepared);
    }

    public function testApplicationAuditCorrectionRowsUseBeforeAndAfterValues(): void
    {
        $correctionData = [
            'format' => 'application-correction-v1',
            'changes' => [
                'applicant_email' => [
                    'before' => 'old@example.com',
                    'after' => 'correct@example.com',
                ],
                'submitted_details' => [
                    'groom.full_name' => [
                        'before' => 'Jon Doe',
                        'after' => 'John Doe',
                    ],
                ],
            ],
        ];

        self::assertSame([
            ['field' => 'Applicant / submitter email', 'before' => 'old@example.com', 'after' => 'correct@example.com'],
            ['field' => 'Submitted form details · Groom · Full name', 'before' => 'Jon Doe', 'after' => 'John Doe'],
        ], audit_correction_rows($correctionData));
        self::assertNotNull(audit_correction_data(json_encode($correctionData, JSON_THROW_ON_ERROR)));
    }

    public function testFuneralAuditSnapshotTracksCorrectionsWithoutCauseOfDeath(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE persons (
                id INTEGER PRIMARY KEY,
                first_name TEXT,
                middle_name TEXT,
                last_name TEXT,
                suffix TEXT,
                is_deceased INTEGER,
                death_date TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE funeral_records (
                id INTEGER PRIMARY KEY,
                person_id INTEGER,
                date_of_death TEXT,
                date_of_burial TEXT,
                age_at_death INTEGER,
                civil_status TEXT,
                spouse_name TEXT,
                parents_name TEXT,
                sacraments_received TEXT,
                cause_of_death TEXT,
                place_of_burial TEXT,
                service_type TEXT,
                service_date TEXT,
                service_time TEXT,
                minister_name TEXT,
                book_no TEXT,
                page_no TEXT,
                line_no TEXT,
                contact_number TEXT,
                contact_email TEXT
            )'
        );
        $pdo->exec(
            "INSERT INTO persons VALUES (1, 'Maria', '', 'Santos', '', 1, '2026-01-01')"
        );
        $pdo->exec(
            "INSERT INTO funeral_records VALUES (
                7, 1, '2026-01-01', NULL, 80, 'widowed', NULL, 'Juan Santos',
                '[\"baptism\"]', 'Private cause', 'Parish cemetery', 'mass',
                '2026-01-03', '10:00:00', 'Fr. Peter', '1', '2', '3',
                '09170000000', 'family@example.test'
            )"
        );

        $before = funeral_record_audit_snapshot($pdo, 7);
        $pdo->exec(
            "UPDATE funeral_records
             SET date_of_death = '2026-01-02', contact_email = 'updated@example.test',
                 cause_of_death = 'Changed private cause'
             WHERE id = 7"
        );
        $pdo->exec("UPDATE persons SET death_date = '2026-01-02' WHERE id = 1");
        $after = funeral_record_audit_snapshot($pdo, 7);
        $changes = funeral_record_audit_changes($before, $after);

        self::assertSame('Maria Santos', $before['person_name']);
        self::assertSame(
            ['before' => '2026-01-01', 'after' => '2026-01-02'],
            $changes['date_of_death']
        );
        self::assertSame(
            ['before' => 'family@example.test', 'after' => 'updated@example.test'],
            $changes['contact_email']
        );
        self::assertArrayNotHasKey('cause_of_death', $before);
        self::assertArrayNotHasKey('cause_of_death', $changes);

        $pdo->exec("INSERT INTO persons VALUES (2, 'Juan', '', 'Cruz', '', 0, NULL)");
        $beforeReassignment = funeral_record_audit_snapshot($pdo, 7, 1);
        $pdo->exec('UPDATE funeral_records SET person_id = 2 WHERE id = 7');
        $pdo->exec("UPDATE persons SET is_deceased = 0, death_date = NULL WHERE id = 1");
        $pdo->exec("UPDATE persons SET is_deceased = 1, death_date = '2026-01-02' WHERE id = 2");
        $afterReassignment = funeral_record_audit_snapshot($pdo, 7, 1);
        $reassignmentChanges = funeral_record_audit_changes($beforeReassignment, $afterReassignment);

        self::assertSame(
            ['before' => 'Maria Santos', 'after' => 'Juan Cruz'],
            $reassignmentChanges['person_name']
        );
        self::assertSame(
            ['before' => true, 'after' => false],
            $reassignmentChanges['previous_person_is_deceased']
        );
        self::assertSame(
            ['before' => '2026-01-02', 'after' => null],
            $reassignmentChanges['previous_person_death_date']
        );
    }

    public function testOutstandingRequirementKeys(): void
    {
        $json = '{"catechism_completion":{"status":"not_received","note":""},"baptismal_certificate":{"status":"not_applicable"},"x":{"status":"verified"}}';
        self::assertSame(['catechism_completion'], application_outstanding_requirement_keys($json));
        self::assertSame([], application_outstanding_requirement_keys(null));
        self::assertSame([], application_outstanding_requirement_keys('not json'));
        self::assertSame(
            ['psa_birth_certificate', 'godparent_eligibility'],
            application_outstanding_requirement_keys(null, ['psa_birth_certificate', 'godparent_eligibility'])
        );
        self::assertSame(
            ['psa_birth_certificate'],
            application_outstanding_requirement_keys('not json', ['psa_birth_certificate'])
        );
        self::assertSame(
            ['godparent_eligibility'],
            application_outstanding_requirement_keys(
                '{"psa_birth_certificate":{"status":"received"}}',
                ['psa_birth_certificate', 'godparent_eligibility']
            )
        );
        self::assertSame(
            [],
            application_outstanding_requirement_keys(
                '{"psa_birth_certificate":{"status":"verified"},"godparent_eligibility":{"status":"received"}}',
                ['psa_birth_certificate', 'godparent_eligibility']
            )
        );
    }

    public function testSacramentFormationIntakeAcceptsBatchAndCatchUpChoices(): void
    {
        self::assertNull(sacrament_formation_input_error('batch', 'grade_3', '2026-2027'));
        self::assertNull(sacrament_formation_input_error('individual_catchup', 'grade_6', '2026-2027'));
        self::assertNull(sacrament_formation_input_error('individual_catchup', 'not_enrolled', 'N/A'));
    }

    public function testSacramentFormationIntakeRejectsInvalidGradeOrSchoolYear(): void
    {
        self::assertSame(
            'Choose a formation pathway.',
            sacrament_formation_input_error('not_sure', 'not_enrolled', '2026-2027')
        );
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

    public function testConfirmationAgeGateBlocksUnderageApplicantsAndAcceptsEligibleApplicants(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (setting_key TEXT, setting_value TEXT)');
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
        $stmt->execute(['confirmation_min_age', '12']);

        $today = new DateTimeImmutable('today');
        $underageBirthdate = $today->modify('-11 years')->format('Y-m-d');
        $eligibleBirthdate = $today->modify('-12 years')->format('Y-m-d');

        self::assertStringContainsString(
            'not yet age-eligible for Confirmation',
            sacrament_age_submission_error($pdo, 'confirmation', $underageBirthdate)
        );
        self::assertNull(sacrament_age_submission_error($pdo, 'confirmation', $eligibleBirthdate));
        self::assertSame(
            'Enter a valid date of birth.',
            sacrament_age_submission_error($pdo, 'confirmation', '2023-02-29')
        );
        self::assertSame(
            "The applicant's date of birth cannot be in the future.",
            sacrament_age_submission_error($pdo, 'confirmation', $today->modify('+1 day')->format('Y-m-d'))
        );
    }

    public function testConfirmationCertificateTransactionQuestionRequiresYesOrNo(): void
    {
        self::assertNull(confirmation_certificate_transaction_error('confirmation', 'yes'));
        self::assertNull(confirmation_certificate_transaction_error('confirmation', 'no'));
        self::assertNotNull(confirmation_certificate_transaction_error('confirmation', ''));
        self::assertNull(confirmation_certificate_transaction_error('baptism', ''));
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

    public function testFuneralMarriageSuggestionFindsLinkedSpouseInEitherRecordRole(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE persons (
            id INTEGER PRIMARY KEY,
            first_name TEXT,
            middle_name TEXT,
            last_name TEXT,
            suffix TEXT
        )');
        $pdo->exec('CREATE TABLE sacrament_records (
            id INTEGER PRIMARY KEY,
            sacrament_type TEXT,
            person_id INTEGER,
            spouse_person_id INTEGER,
            event_date TEXT
        )');
        $pdo->exec("INSERT INTO persons VALUES
            (1, 'Mara', NULL, 'Santos', NULL),
            (2, 'Leo', 'R.', 'Reyes', 'Jr.'),
            (3, 'Una', NULL, 'Cruz', NULL)");
        $pdo->exec("INSERT INTO sacrament_records VALUES
            (1, 'marriage', 1, 2, '2000-01-01')");

        self::assertSame(
            ['has_marriage_record' => true, 'spouse_name' => 'Leo R. Reyes Jr.'],
            funeral_person_marriage_suggestion($pdo, 1)
        );
        self::assertSame(
            ['has_marriage_record' => true, 'spouse_name' => 'Mara Santos'],
            funeral_person_marriage_suggestion($pdo, 2)
        );
        self::assertSame(
            ['has_marriage_record' => false, 'spouse_name' => ''],
            funeral_person_marriage_suggestion($pdo, 3)
        );
    }

    public function testFuneralContactEmailIsOptionalButValidatedWhenProvided(): void
    {
        self::assertNull(funeral_contact_email_error(''));
        self::assertNull(funeral_contact_email_error('family@example.com'));
        self::assertSame(
            'Enter a valid family contact email address.',
            funeral_contact_email_error('not-an-email')
        );
        self::assertSame(
            'Enter a valid family contact email address.',
            funeral_contact_email_error(str_repeat('a', 250) . '@example.com')
        );
    }

    public function testFuneralScheduleEmailIsDueForNewOrChangedConfirmedSchedule(): void
    {
        $existing = [
            'service_type' => 'mass',
            'service_date' => '2026-12-10',
            'service_time' => '09:30:00',
            'contact_email' => 'family@example.com',
        ];
        $sameSchedule = [
            'service_type' => 'mass',
            'service_date' => '2026-12-10',
            'service_time' => '09:30',
            'contact_email' => 'family@example.com',
        ];

        self::assertFalse(funeral_schedule_notification_due($existing, $sameSchedule));
        self::assertTrue(funeral_schedule_notification_due(null, $sameSchedule));
        self::assertTrue(funeral_schedule_notification_due($existing, array_merge(
            $sameSchedule,
            ['service_date' => '2026-12-11']
        )));
        self::assertFalse(funeral_schedule_notification_due($existing, array_merge(
            $sameSchedule,
            ['contact_email' => '']
        )));
    }

}
