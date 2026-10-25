<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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
        self::assertNull(validate_full_name('Maria Santos'));
        self::assertSame('J. Santos', compose_person_name([
            'applicant_first' => 'J.',
            'applicant_last' => 'Santos',
        ], 'applicant'));
        self::assertNotNull(validate_full_name('J. Santos'));
    }

    public function testScheduleOverlapRules(): void
    {
        self::assertTrue(event_times_overlap('09:00:00', '10:00:00', '09:30:00', '10:30:00'));
        self::assertFalse(event_times_overlap('09:00:00', '10:00:00', '10:00:00', '11:00:00'));
    }
}
