<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

final class MarriageApplicationTest extends TestCase
{
    private const BLOCK_MESSAGE = 'Based on the marital status you entered, this application cannot continue online. Please contact the Parish Office.';
    private const DATE_MESSAGE = 'The target wedding date cannot be earlier than today.';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            'mysql:host=localhost;dbname=srs_sppo_db;charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    private function runPage(array $post, int $startStep): array
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = 'test-csrf-token';
        $_SESSION['marriage_app'] = [
            'current_step' => $startStep,
            'form_data' => $post,
        ];

        // marriage.php references the global $pdo without a `global`
        // declaration; bind it into this method's scope so the included page
        // can query the database (e.g. the schedule-events conflict check).
        global $pdo;
        $pdo = $this->pdo;

        $_POST = $post;
        $_POST['_action'] = $post['_action'] ?? 'next';
        $_POST['csrf_token'] = 'test-csrf-token';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $errors = [];
        ob_start();
        include __DIR__ . '/../public/sacrament-applications/marriage.php';
        $output = ob_get_clean();

        return [
            'output' => $output,
            'errors' => $errors,
            'step' => $_SESSION['marriage_app']['current_step'] ?? null,
            'form_data' => $_SESSION['marriage_app']['form_data'] ?? [],
        ];
    }

    private function validPartyData(string $prefix, string $maritalStatus, string $previousMarriage): array
    {
        $first = $prefix === 'groom' ? 'Juan' : 'Maria';
        return [
            $prefix . '_first' => $first,
            $prefix . '_middle' => '',
            $prefix . '_last' => $prefix === 'groom' ? 'Dela Cruz' : 'Santos',
            $prefix . '_suffix' => '',
            $prefix . '_birthdate' => '1990-01-01',
            $prefix . '_birthplace' => 'Dingalan',
            $prefix . '_current_address' => '123 Main St',
            $prefix . '_contact_number' => '09123456789',
            $prefix . '_email' => $prefix . '.one@example.com',
            $prefix . '_father_first' => 'Father',
            $prefix . '_father_last' => $prefix === 'groom' ? 'Dela Cruz' : 'Santos',
            $prefix . '_mother_first' => 'Mother',
            $prefix . '_mother_maiden_last' => 'Reyes',
            $prefix . '_marital_status' => $maritalStatus,
            $prefix . '_previous_marriage' => $previousMarriage,
            $prefix . '_baptized' => 'yes',
            $prefix . '_confirmed' => 'yes',
        ];
    }

    private function baseStepData(array $overrides = []): array
    {
        return array_merge([
            'preferred_wedding_date' => (new DateTime('today'))->format('Y-m-d'),
            'preferred_wedding_time' => '14:00',
            'preferred_wedding_venue' => 'church',
            'privacy_consent' => '1',
        ], $overrides);
    }

    /** @dataProvider provideBlockedParties */
    public function testBlockedPartyStaysOnTheSameStepAndKeepsEnteredValues(string $party, string $maritalStatus, string $previousMarriage): void
    {
        $step = $party === 'groom' ? 2 : 3;
        $post = $this->baseStepData();
        $post = array_merge($post, $this->validPartyData('groom', 'single', 'no'));
        $post = array_merge($post, $this->validPartyData('bride', 'single', 'no'));
        $post = array_merge($post, $this->validPartyData($party, $maritalStatus, $previousMarriage));

        $result = $this->runPage($post, $step);

        self::assertSame($step, $result['step'], 'A blocked party must not advance past this step.');
        $this->assertTrue(in_array(self::BLOCK_MESSAGE, $result['errors'], true));
        // Entered values are kept so the applicant does not have to retype.
        self::assertSame($maritalStatus, $result['form_data'][$party . '_marital_status'] ?? '');
        self::assertSame($previousMarriage, $result['form_data'][$party . '_previous_marriage'] ?? '');
    }

    public static function provideBlockedParties(): array
    {
        return [
            'groom married' => ['groom', 'married', 'no'],
            'groom single + previous church' => ['groom', 'single', 'church'],
            'groom single + previous civil' => ['groom', 'single', 'civil'],
            'bride married' => ['bride', 'married', 'no'],
            'bride single + previous church' => ['bride', 'single', 'church'],
            'bride single + previous civil' => ['bride', 'single', 'civil'],
        ];
    }

    public function testAllowedWidowedPartyIsNotBlocked(): void
    {
        $post = $this->baseStepData();
        $post = array_merge($post, $this->validPartyData('groom', 'widowed', 'church'));
        $post = array_merge($post, $this->validPartyData('bride', 'single', 'no'));
        $post['groom_email'] = ''; // unrelated required error keeps the wizard on step 2 without exiting

        $result = $this->runPage($post, 2);

        self::assertFalse(in_array(self::BLOCK_MESSAGE, $result['errors'], true), 'A widowed party with a previous church marriage must not be blocked.');
    }

    public function testAllowedAnnulledPartyIsNotBlocked(): void
    {
        $post = $this->baseStepData();
        $post = array_merge($post, $this->validPartyData('bride', 'annulled', 'church'));
        $post = array_merge($post, $this->validPartyData('groom', 'single', 'no'));
        $post['bride_email'] = ''; // unrelated required error keeps the wizard on step 3 without exiting

        $result = $this->runPage($post, 3);

        self::assertFalse(in_array(self::BLOCK_MESSAGE, $result['errors'], true), 'An annulled party with a previous church marriage must not be blocked.');
    }
    public function testWeddingDateEarlierThanTodayIsBlockedOnStepOne(): void
    {
        $post = $this->baseStepData(['preferred_wedding_date' => (new DateTime('yesterday'))->format('Y-m-d')]);
        $post = array_merge($post, $this->validPartyData('groom', 'single', 'no'));
        $post = array_merge($post, $this->validPartyData('bride', 'single', 'no'));

        $result = $this->runPage($post, 1);

        $this->assertTrue(in_array(self::DATE_MESSAGE, $result['errors'], true));
        self::assertSame(1, $result['step'], 'A past wedding date must not advance past step 1.');
    }

    public function testWeddingDateTodayIsAllowedOnStepOne(): void
    {
        $post = $this->baseStepData(['preferred_wedding_date' => (new DateTime('today'))->format('Y-m-d'), 'preferred_wedding_venue' => '']);
        $post = array_merge($post, $this->validPartyData('groom', 'single', 'no'));
        $post = array_merge($post, $this->validPartyData('bride', 'single', 'no'));

        $result = $this->runPage($post, 1);

        self::assertFalse(in_array(self::DATE_MESSAGE, $result['errors'], true), "Today's date must not be flagged as too early.");
        self::assertFalse(in_array(self::BLOCK_MESSAGE, $result['errors'], true));
    }

    public function testCraftedFinalSubmitWithBlockedPartyIsRejectedAndSavesNothing(): void
    {
        $beforeApps = (int) $this->pdo->query('SELECT COUNT(*) FROM sacrament_applications')->fetchColumn();
        $beforePersons = (int) $this->pdo->query('SELECT COUNT(*) FROM persons')->fetchColumn();

        $post = $this->baseStepData();
        $post = array_merge($post, $this->validPartyData('groom', 'single', 'church')); // blocked party
        $post = array_merge($post, $this->validPartyData('bride', 'single', 'no'));
        $post['_action'] = 'final_submit';

        $result = $this->runPage($post, 5);

        $this->assertTrue(in_array(self::BLOCK_MESSAGE, $result['errors'], true), 'The final-submit validation must apply the same rule.');
        self::assertSame(5, $result['step'], 'A blocked final submit must not complete the application.');

        $afterApps = (int) $this->pdo->query('SELECT COUNT(*) FROM sacrament_applications')->fetchColumn();
        $afterPersons = (int) $this->pdo->query('SELECT COUNT(*) FROM persons')->fetchColumn();
        self::assertSame($beforeApps, $afterApps, 'No application row may be created for a blocked attempt.');
        self::assertSame($beforePersons, $afterPersons, 'No person record may be created for a blocked attempt.');
    }

    public function testReviewScreenShowsPreviousMarriageRows(): void
    {
        $post = $this->baseStepData();
        $post = array_merge($post, $this->validPartyData('groom', 'widowed', 'church'));
        $post = array_merge($post, $this->validPartyData('bride', 'annulled', 'civil'));
        $post['_action'] = ''; // not a navigation action, so the wizard does not advance/exit

        $result = $this->runPage($post, 5);

        self::assertSame(5, $result['step']);
        self::assertStringContainsString('Yes — church', $result['output']);
        self::assertStringContainsString('Yes — civil', $result['output']);
    }
}

