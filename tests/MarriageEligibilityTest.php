<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/application-requirements.php';
require_once __DIR__ . '/../includes/sacrament-data.php';
require_once __DIR__ . '/../includes/walk-in-intake.php';

final class MarriageEligibilityTest extends TestCase
{
    private const BLOCK_MESSAGE = 'Applicants currently married (civilly or in church) to another person cannot apply for a Catholic wedding until the prior marriage is legally annulled and declared null by the Church.';

    public function testBlockedPartyWhoseMaritalStatusIsMarried(): void
    {
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('married', 'no'));
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('married', 'church'));
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('married', 'civil'));
        self::assertSame(self::BLOCK_MESSAGE, marriage_party_eligibility_error('married_elsewhere', 'no'));
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
        self::assertSame('No / No', marriage_previous_marriage_label('no/no'));
        self::assertSame('Yes — Church', marriage_previous_marriage_label('church'));
        self::assertSame('Yes — Civil', marriage_previous_marriage_label('civil'));
        self::assertSame('—', marriage_previous_marriage_label(''));
        self::assertSame('—', marriage_previous_marriage_label('unknown'));
    }

    public function testYesNoDisplayValuesAreNormalizedWithoutChangingOtherValues(): void
    {
        self::assertSame('Yes', format_yes_no_display_value('yes'));
        self::assertSame('No', format_yes_no_display_value('NO'));
        self::assertSame('No / No', format_yes_no_display_value(' no/no '));
        self::assertSame('Yes / No', format_yes_no_display_value('YES / no'));
        self::assertSame('Yes', format_yes_no_display_value(true));
        self::assertSame('No', format_yes_no_display_value(false));
        self::assertNull(format_yes_no_display_value(''));
        self::assertNull(format_yes_no_display_value('church'));
    }

    public function testCivillyMarriedStatusMustMatchForBothApplicants(): void
    {
        $message = "If you are civilly married to each other, both the groom and bride must select 'Civilly Married (to each other)'.";

        self::assertSame($message, marriage_status_pair_error('civilly_married', 'single'));
        self::assertSame($message, marriage_status_pair_error('widowed', 'civilly_married'));
        self::assertNull(marriage_status_pair_error('civilly_married', 'civilly_married'));
        self::assertNull(marriage_status_pair_error('single', 'widowed'));
        self::assertNull(marriage_status_pair_error('civilly_married', ''));
    }

    public function testSacramentRecordCanSuggestYesButDoesNotTurnMissingRecordsIntoNo(): void
    {
        $recordFound = marriage_sacrament_record_assessment(['id' => 12], '');
        self::assertTrue($recordFound['record_found']);
        self::assertSame('yes', $recordFound['suggested_answer']);
        self::assertFalse($recordFound['answer_conflicts']);

        $conflictingAnswer = marriage_sacrament_record_assessment(['id' => 12], 'no');
        self::assertTrue($conflictingAnswer['answer_conflicts']);

        $noRecord = marriage_sacrament_record_assessment(null, 'no');
        self::assertFalse($noRecord['record_found']);
        self::assertNull($noRecord['suggested_answer']);
        self::assertFalse($noRecord['answer_conflicts']);
    }

    public function testRequirementsFollowTheSelectedMaritalStatuses(): void
    {
        $singleDocuments = marriage_preapplication_required_documents('single', 'single', 'church');
        self::assertContains('Groom: PSA Certificate of No Marriage Record (CENOMAR).', $singleDocuments);
        self::assertContains('Bride: PSA Certificate of No Marriage Record (CENOMAR).', $singleDocuments);
        self::assertContains('Marriage License from the Municipal Civil Registrar; submit it at the Parish Office.', $singleDocuments);

        $civilDocuments = marriage_preapplication_required_documents('civilly_married', 'civilly_married', 'church');
        self::assertContains('Couple: PSA Marriage Certificate.', $civilDocuments);
        self::assertNotContains('Groom: PSA Certificate of No Marriage Record (CENOMAR).', $civilDocuments);
        self::assertNotContains('Marriage License from the Municipal Civil Registrar; submit it at the Parish Office.', $civilDocuments);

        $widowedDocuments = marriage_preapplication_required_documents('widowed', 'single', 'church');
        self::assertContains('Groom: deceased spouse’s PSA Death Certificate.', $widowedDocuments);
        self::assertContains('Groom: prior PSA Marriage Certificate.', $widowedDocuments);
        self::assertContains('Marriage License from the Municipal Civil Registrar; submit it at the Parish Office.', $widowedDocuments);

        $annulledDocuments = marriage_preapplication_required_documents('annulled', 'single', 'church');
        self::assertContains('Groom: Civil Annulment Decree / Certificate of Finality.', $annulledDocuments);
        self::assertContains('Groom: annotated PSA Marriage Certificate.', $annulledDocuments);
        self::assertContains('Groom: Church Declaration of Nullity.', $annulledDocuments);
    }

    public function testOfficeChecklistMarksStatusSpecificDocumentsAsRequiredOrNotApplicable(): void
    {
        $civilRequirements = marriage_application_requirement_states('civilly_married', 'civilly_married', 'church');
        self::assertSame('not_applicable', $civilRequirements['psa_cenomar_groom']['status']);
        self::assertSame('not_applicable', $civilRequirements['psa_cenomar_bride']['status']);
        self::assertSame('not_applicable', $civilRequirements['marriage_license_application']['status']);
        self::assertSame('not_received', $civilRequirements['psa_marriage_certificate_couple']['status']);

        $singleRequirements = marriage_application_requirement_states('single', 'widowed', 'church');
        self::assertSame('not_received', $singleRequirements['psa_cenomar_groom']['status']);
        self::assertSame('not_applicable', $singleRequirements['psa_cenomar_bride']['status']);
        self::assertSame('not_received', $singleRequirements['marriage_license_application']['status']);
        self::assertSame('not_received', $singleRequirements['psa_death_certificate_bride']['status']);
        self::assertSame('not_received', $singleRequirements['psa_prior_marriage_certificate_bride']['status']);

        $annulledRequirements = marriage_application_requirement_states('annulled', 'widowed', 'church');
        self::assertSame('not_received', $annulledRequirements['marriage_license_application']['status']);
    }

    public function testMarriageChecklistIncludesNewRequirementsAndUsesDingalanAddressRule(): void
    {
        $localParish = marriage_application_requirement_states(
            'single',
            'single',
            'church',
            null,
            null,
            null,
            'Dingalan, Aurora',
            'Poblacion, DINGALAN'
        );
        foreach ([
            'psa_birth_certificate_groom',
            'psa_birth_certificate_bride',
            'canonical_interview',
            'marriage_banns',
        ] as $key) {
            self::assertSame('not_received', $localParish[$key]['status']);
        }
        self::assertSame('not_applicable', $localParish['transfer_letter']['status']);

        $outsideDingalan = marriage_application_requirement_states(
            'single',
            'single',
            'church',
            null,
            null,
            null,
            'Dingalan, Aurora',
            'Baler, Aurora'
        );
        self::assertSame('not_received', $outsideDingalan['transfer_letter']['status']);
        self::assertStringContainsString('bride', $outsideDingalan['transfer_letter']['note']);

        $outsideVenue = marriage_application_requirement_states(
            'single',
            'single',
            'other',
            null,
            null,
            null,
            'Dingalan, Aurora',
            'Dingalan, Aurora'
        );
        self::assertSame('not_received', $outsideVenue['transfer_letter']['status']);
        self::assertContains('pre_jordan_seminar', sd_application_requirement_keys()['baptism']);
        self::assertContains('parents_marriage_certificate', sd_application_requirement_keys()['baptism']);
        self::assertContains('transfer_letter', sd_application_requirement_keys()['baptism']);
        self::assertContains('confirmation_seminar', sd_application_requirement_keys()['confirmation']);
        self::assertContains('canonical_interview', sd_application_requirement_keys()['marriage']);
        self::assertArrayHasKey('pre_jordan_seminar', walk_in_requirements('baptism'));
        self::assertArrayHasKey('confirmation_seminar', walk_in_requirements('confirmation'));
        self::assertArrayHasKey('canonical_interview', walk_in_requirements('marriage'));
        self::assertSame('Pre-Jordan Seminar', application_queue_requirement_label('baptism', 'pre_jordan_seminar'));
        self::assertSame('Confirmation Seminar', application_queue_requirement_label('confirmation', 'confirmation_seminar'));
        self::assertSame('Canonical Interview', application_queue_requirement_label('marriage', 'canonical_interview'));
    }
}
