<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/certificates/baptismal-certificate.php';

final class FunctionsTest extends TestCase
{
    public function testCertificateRequestTransitionRules(): void
    {
        self::assertTrue(cert_request_transition_valid('pending', 'under_review'));
        self::assertFalse(cert_request_transition_valid('released', 'approved'));
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

    public function testSafeReturnUrls(): void
    {
        self::assertNotNull(safe_internal_return_url('/admin/requests/queue.php?status=pending'));
        self::assertNull(safe_internal_return_url('https://example.com/admin/queue.php'));
        self::assertNull(safe_internal_return_url('/admin/../../outside.php'));
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

    public function testUnspecifiedBaptismalCertificatePurposeIsBlank(): void
    {
        $values = baptismal_certificate_values(
            ['child_full_name' => 'Mikaela Therese Rivera Dela Cruz'],
            ['purpose' => 'unspecified']
        );

        self::assertSame('', $values['purpose']);
    }

    public function testBaptismalCertificatePurposeKeepsSuppliedValue(): void
    {
        $values = baptismal_certificate_values(
            ['child_full_name' => 'Mikaela Therese Rivera Dela Cruz'],
            ['purpose' => 'other', 'purpose_other' => 'School enrollment']
        );

        self::assertSame('School enrollment', $values['purpose']);
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

    public function testScheduleOverlapRules(): void
    {
        self::assertTrue(event_times_overlap('09:00:00', '10:00:00', '09:30:00', '10:30:00'));
        self::assertFalse(event_times_overlap('09:00:00', '10:00:00', '10:00:00', '11:00:00'));
    }
}
