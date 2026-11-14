<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';

final class MarriageEligibilityTest extends TestCase
{
    private const BLOCK_MESSAGE = 'Based on the marital status you entered, this application cannot continue online. Please contact the Parish Office.';

    public function testBlockedPartyWhoseMaritalStatusIsMarried(): void
    {
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('married', 'no'));
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('married', 'church'));
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('married', 'civil'));
    }

    public function testBlockedSinglePartyWhoAlsoClaimsAPreviousChurchMarriage(): void
    {
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('single', 'church'));
    }

    public function testBlockedSinglePartyWhoAlsoClaimsAPreviousCivilMarriage(): void
    {
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('single', 'civil'));
    }

    public function testAllowedWidowedPartyWithPreviousChurchMarriage(): void
    {
        self::assertNull(marriage_party_eligibility_error('widowed', 'church'));
    }

    public function testAllowedAnnulledPartyWithPreviousChurchMarriage(): void
    {
        self::assertNull(marriage_party_eligibility_error('annulled', 'church'));
    }

    public function testAllowedWidowedPartyWithPreviousCivilMarriage(): void
    {
        self::assertNull(marriage_party_eligibility_error('widowed', 'civil'));
    }

    public function testAllowedSinglePartyWithNoPreviousMarriage(): void
    {
        self::assertNull(marriage_party_eligibility_error('single', 'no'));
    }

    public function testCivillyMarriedYesIsNeverConsultedByTheRule(): void
    {
        // civilly_married is a different question; the rule never sees it.
        self::assertNull(marriage_party_eligibility_error('single', 'no'));
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('single', 'civil'));
    }

    public function testPreviousMarriageLabels(): void
    {
        self::assertSame('No', marriage_previous_marriage_label('no'));
        self::assertSame('Yes — church', marriage_previous_marriage_label('church'));
        self::assertSame('Yes — civil', marriage_previous_marriage_label('civil'));
        self::assertSame('No', marriage_previous_marriage_label(''));
        self::assertSame('No', marriage_previous_marriage_label('unknown'));
    }
}
