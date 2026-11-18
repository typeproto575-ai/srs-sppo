<?php
/** Philippine parish Holy Matrimony / pre-nuptial canonical application. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';

$basePath = base_path();
$pageTitle = 'Holy Matrimony Pre-Application';
$errors = [];
$trackingNumber = null;
$personReferences = [];
$dateConflictWarning = null;

// Configurable minimum lead time (days) between today and the target
// wedding date. Default 0 means any date from today onward is accepted.
// Change this single value if the parish wants, e.g., a 3-day minimum.
if (!defined('MARRIAGE_MIN_WEDDING_LEAD_DAYS')) {
    define('MARRIAGE_MIN_WEDDING_LEAD_DAYS', 0);
}

$steps = [
    1 => 'Wedding details',
    2 => 'Groom information',
    3 => 'Bride information',
    4 => 'Church & civil status',
    5 => 'Review & submit',
];
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (($_GET['reset'] ?? '') === '1') {
    unset($_SESSION['marriage_app']);
    header('Location: marriage.php');
    exit;
}
if (!isset($_SESSION['marriage_app']) || !is_array($_SESSION['marriage_app'])) {
    $_SESSION['marriage_app'] = ['current_step' => 1, 'form_data' => []];
}
$wizard = &$_SESSION['marriage_app'];
$currentStep = (int) ($wizard['current_step'] ?? 1);
if ($currentStep < 1 || $currentStep > count($steps)) {
    $currentStep = 1;
    $wizard['current_step'] = 1;
}
$formData = &$wizard['form_data'];
if (($_GET['start'] ?? '') === 'profile') {
    if ((current_user()['role'] ?? '') !== 'secretary') {
        http_response_code(403);
        exit('Only parish secretaries can start a profile-linked walk-in pre-application.');
    }
    $personId = filter_var($_GET['person_id'] ?? null, FILTER_VALIDATE_INT);
    $applicant = $_GET['applicant'] ?? '';
    $person = $personId ? public_application_profile($pdo, (int) $personId) : null;
    if (!$person) {
        $errors[] = 'The selected parishioner profile could not be found. Search for the person again.';
    } elseif (!in_array($applicant, ['groom', 'bride'], true)) {
        $errors[] = 'Choose whether this person is the groom or bride before starting the marriage pre-application.';
    } else {
        $formData = public_application_profile_fields($person, $applicant);
        $formData[$applicant . '_current_address'] = (string) ($person['address'] ?? '');
        $formData[$applicant . '_contact_number'] = (string) ($person['contact_number'] ?? '');
        $formData[$applicant . '_email'] = (string) ($person['email'] ?? '');
        foreach ([
            'father' => (string) ($person['father_name'] ?? ''),
            'mother' => (string) ($person['mother_maiden_name'] ?? ''),
        ] as $parent => $fullName) {
            foreach (structured_name_parts($fullName) as $index => $part) {
                $namePart = ['first', 'middle', 'last', 'suffix'][$index];
                $formData[$applicant . '_' . $parent . '_' . $namePart] = $part;
            }
        }
        $wizard['current_step'] = 1;
        session_write_close();
        header('Location: marriage.php');
        exit;
    }
}
if (($formData['preferred_wedding_venue'] ?? '') === 'barangay_chapel') {
    $formData['preferred_wedding_venue'] = 'other';
}
foreach (['groom', 'bride'] as $party) {
    if (($formData[$party . '_marital_status'] ?? '') === 'married') {
        $formData[$party . '_marital_status'] = 'married_elsewhere';
    }
}
$wizardAction = $_POST['_action'] ?? '';
$isWizardNavigation = $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($wizardAction, ['next', 'back'], true);
$principalSponsorErrors = static fn(array $values): array => validate_marriage_principal_sponsors($values);
$readPartyRecordHistory = static function (string $prefix, array $values) use ($pdo): array {
    $rawPersonId = $values[$prefix . '_person_id'] ?? '';
    if (!is_scalar($rawPersonId) || trim((string) $rawPersonId) === '') {
        return ['linked' => false, 'identity_mismatch' => false, 'records' => null];
    }

    try {
        $personId = public_selected_person_id(
            $pdo,
            $rawPersonId,
            (string) ($values[$prefix . '_first'] ?? ''),
            (string) ($values[$prefix . '_middle'] ?? ''),
            (string) ($values[$prefix . '_last'] ?? ''),
            (string) ($values[$prefix . '_suffix'] ?? ''),
            (string) ($values[$prefix . '_birthdate'] ?? '')
        );
    } catch (InvalidArgumentException) {
        return ['linked' => false, 'identity_mismatch' => true, 'records' => null];
    }

    if ($personId === null) {
        return ['linked' => false, 'identity_mismatch' => false, 'records' => null];
    }

    return [
        'linked' => true,
        'identity_mismatch' => false,
        'records' => sd_get_person_records($pdo, $personId),
    ];
};

if ($isWizardNavigation) {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        $stepData = $_POST;
        unset($stepData['_action'], $stepData['csrf_token']);
        foreach ($stepData as $key => $value) {
            if (is_array($value)) {
                $formData[$key] = array_values(array_filter($value, 'is_scalar'));
            } elseif (is_scalar($value)) {
                $formData[$key] = (string) $value;
            }
        }
        if ($currentStep === 4) {
            foreach (['groom', 'bride'] as $prefix) {
                $history = $readPartyRecordHistory($prefix, $formData);
                if (!$history['linked']) {
                    continue;
                }
                foreach (['baptism' => 'baptized', 'confirmation' => 'confirmed'] as $recordType => $answerType) {
                    $field = $prefix . '_' . $answerType;
                    if (($formData[$field] ?? '') === ''
                        && is_array($history['records'][$recordType] ?? null)) {
                        $formData[$field] = 'yes';
                        $_POST[$field] = 'yes';
                    }
                }
            }
        }
        if ($currentStep === 4) {
            $isCivillyMarriedPair = ($formData['groom_marital_status'] ?? '') === 'civilly_married'
                && ($formData['bride_marital_status'] ?? '') === 'civilly_married';
            $formData['civilly_married'] = $isCivillyMarriedPair ? 'yes' : 'no';
            if (!$isCivillyMarriedPair) {
                $formData['civil_marriage_date'] = '';
                $formData['civil_marriage_place'] = '';
            }
        }
        if ($currentStep === 5) {
            $formData['privacy_consent'] = ($_POST['privacy_consent'] ?? '') === '1' ? '1' : '0';
            $formData['marriage_eligibility_declaration'] = ($_POST['marriage_eligibility_declaration'] ?? '') === '1' ? '1' : '0';
        }

        if ($wizardAction === 'back') {
            $wizard['current_step'] = max(1, $currentStep - 1);
        } else {
            $value = static function (string $key): string {
                return is_scalar($_POST[$key] ?? null) ? trim((string) $_POST[$key]) : '';
            };
            if ($currentStep === 1) {
                $weddingDate = $value('preferred_wedding_date');
                if ($weddingDate === '') $errors[] = 'Target wedding date is required.';
                if ($weddingDate !== '') {
                    $earliest = (new DateTime('today'))->modify('+' . (int) MARRIAGE_MIN_WEDDING_LEAD_DAYS . ' days')->format('Y-m-d');
                    if ($weddingDate < $earliest) {
                        $lead = (int) MARRIAGE_MIN_WEDDING_LEAD_DAYS;
                        $errors[] = $lead === 0
                            ? 'The target wedding date cannot be earlier than today.'
                            : "The target wedding date must be at least $lead day(s) from today.";
                    }
                }
                $venue = $value('preferred_wedding_venue');
                if (!in_array($venue, ['church', 'other'], true)) $errors[] = 'Please select a valid wedding venue.';
                if ($venue === 'other' && $value('preferred_wedding_venue_other') === '') $errors[] = 'Enter the name of the other wedding venue.';
                if (strlen($value('preferred_wedding_venue_other')) > 150) $errors[] = 'The other venue name must be 150 characters or fewer.';
            } elseif ($currentStep === 2 || $currentStep === 3) {
                $prefix = $currentStep === 2 ? 'groom' : 'bride';
                $label = ucfirst($prefix);
                $name = compose_person_name($_POST, $prefix);
                if ($value($prefix . '_first') === '') $errors[] = "$label's first name is required.";
                if ($value($prefix . '_last') === '') $errors[] = "$label's last name is required.";
                if (($e = validate_full_name($name)) !== null) $errors[] = $e;
                $birthdate = $value($prefix . '_birthdate');
                if ($birthdate === '') $errors[] = "$label's date of birth is required.";
                if ($birthdate !== '' && is_future_date($birthdate)) $errors[] = "$label's date of birth cannot be in the future.";
                if ($birthdate !== '' && !is_future_date($birthdate)) {
                    $ageAssessment = sacrament_age_assessment($pdo, 'marriage', calculate_age($birthdate));
                    if ($ageAssessment['status'] !== 'Age-eligible') {
                        $errors[] = "$label is not yet age-eligible for Marriage. The minimum age is " . (int) $ageAssessment['range']['min_age'] . ' years.';
                    }
                }
                if ($value($prefix . '_birthplace') === '') $errors[] = "$label's place of birth is required.";
                if ($value($prefix . '_contact_number') === '') $errors[] = "$label contact number is required.";
                elseif (!is_valid_phone_number($value($prefix . '_contact_number'))) $errors[] = "Enter a valid $label contact number.";
                if (!filter_var($value($prefix . '_email'), FILTER_VALIDATE_EMAIL)) $errors[] = "$label's email address is required.";
                $validMaritalStatuses = ['single', 'civilly_married', 'widowed', 'annulled', 'married_elsewhere'];
                if (!in_array($value($prefix . '_marital_status'), $validMaritalStatuses, true)) $errors[] = "$label's marital status is required.";
                if (($e = validate_parent_name_or_na($_POST, $prefix . '_father', "$label's father's name")) !== null) $errors[] = $e;
                if (($e = validate_required_parent_name($_POST, $prefix . '_mother', "$label's mother's maiden name")) !== null) $errors[] = $e;
                $partyError = marriage_party_eligibility_error(
                    $value($prefix . '_marital_status'),
                    $value($prefix . '_previous_marriage') ?: 'no'
                );
                if ($partyError !== null) $errors[] = $partyError;
                $groomStatus = $currentStep === 2
                    ? $value('groom_marital_status')
                    : (string) ($formData['groom_marital_status'] ?? '');
                $brideStatus = $currentStep === 3
                    ? $value('bride_marital_status')
                    : (string) ($formData['bride_marital_status'] ?? '');
                if (($pairError = marriage_status_pair_error($groomStatus, $brideStatus)) !== null) {
                    $errors[] = $pairError;
                }
            } elseif ($currentStep === 4) {
                foreach (['groom_baptized' => 'Groom', 'bride_baptized' => 'Bride', 'groom_confirmed' => 'Groom', 'bride_confirmed' => 'Bride'] as $key => $label) {
                    if (!in_array($value($key), ['yes', 'no'], true)) $errors[] = "Please indicate whether the $label is baptized and confirmed Catholic.";
                }
                $errors = array_merge($errors, $principalSponsorErrors($_POST));
                $groomStatus = (string) ($formData['groom_marital_status'] ?? '');
                $brideStatus = (string) ($formData['bride_marital_status'] ?? '');
                if (($pairError = marriage_status_pair_error($groomStatus, $brideStatus)) !== null) {
                    $errors[] = $pairError;
                }
                if ($wizardAction === 'next'
                    && $groomStatus === 'civilly_married'
                    && $brideStatus === 'civilly_married') {
                    if ($value('civil_marriage_date') === '') $errors[] = 'Civil marriage date is required when both applicants are civilly married to each other.';
                    if ($value('civil_marriage_place') === '') $errors[] = 'Place of civil marriage is required when both applicants are civilly married to each other.';
                }
            } elseif ($currentStep === 5 && $wizardAction === 'next') {
                if ($value('privacy_consent') !== '1') $errors[] = 'You must accept the Privacy Notice before submitting.';
                if ($value('marriage_eligibility_declaration') !== '1') {
                    $errors[] = 'You must confirm that neither applicant has an existing, unannulled marriage to another person.';
                }
            }
        }

        if (!$errors) {
            $wizard['current_step'] = $wizardAction === 'back'
                ? max(1, $currentStep - 1)
                : min(count($steps), $currentStep + 1);
            session_write_close();
            header('Location: marriage.php');
            exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Your session expired. Please submit the form again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'final_submit' && $currentStep === count($steps) && csrf_verify()) {
    $submittedReview = $_POST;
    unset($submittedReview['_action'], $submittedReview['csrf_token']);
    $formData = array_merge($formData, $submittedReview);
    $formData['privacy_consent'] = ($_POST['privacy_consent'] ?? '') === '1' ? '1' : '0';
    $formData['marriage_eligibility_declaration'] = ($_POST['marriage_eligibility_declaration'] ?? '') === '1' ? '1' : '0';
    $_POST = array_merge($formData, $_POST);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isWizardNavigation && ($_POST['_action'] ?? '') === 'final_submit' && $currentStep === count($steps) && csrf_verify()) {
    $g = static function (string $key): string {
        return is_scalar($_POST[$key] ?? null) ? trim((string) $_POST[$key]) : '';
    };
    $gd = $_POST['groom_birthdate'] ?? '';
    $bd = $_POST['bride_birthdate'] ?? '';
    $groomName = compose_person_name($_POST, 'groom');
    $brideName = compose_person_name($_POST, 'bride');
    $weddingDate = $_POST['preferred_wedding_date'] ?? '';
    $weddingTime = $g('preferred_wedding_time') ?: null;
    $venue = $g('preferred_wedding_venue');
    $venueOther = $venue === 'other' ? $g('preferred_wedding_venue_other') : '';
    $groomEmail = $g('groom_email'); $brideEmail = $g('bride_email');
    $groomPersonId = null;
    $bridePersonId = null;

    foreach (['groom', 'bride'] as $prefix) {
        if ($g($prefix . '_person_id') === '') continue;
        try {
            $matchedPersonId = public_selected_person_id(
                $pdo,
                $g($prefix . '_person_id'),
                $g($prefix . '_first'),
                $g($prefix . '_middle'),
                $g($prefix . '_last'),
                $g($prefix . '_suffix'),
                $g($prefix . '_birthdate')
            );
            if ($prefix === 'groom') $groomPersonId = $matchedPersonId;
            else $bridePersonId = $matchedPersonId;
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    foreach ([['Groom', $groomName, $g('groom_first'), $g('groom_last'), $gd, $g('groom_birthplace'), $groomEmail], ['Bride', $brideName, $g('bride_first'), $g('bride_last'), $bd, $g('bride_birthplace'), $brideEmail]] as [$label, $name, $firstName, $lastName, $date, $place, $email]) {
        if ($firstName === '') $errors[] = "$label's first name is required.";
        if ($lastName === '') $errors[] = "$label's last name is required.";
        if (($e = validate_full_name($name)) !== null) $errors[] = $e;
        if ($date === '') $errors[] = "$label's date of birth is required.";
        if ($date !== '' && is_future_date($date)) $errors[] = "$label's date of birth cannot be in the future.";
        if ($date !== '' && !is_future_date($date)) {
            $ageAssessment = sacrament_age_assessment($pdo, 'marriage', calculate_age($date));
            if ($ageAssessment['status'] !== 'Age-eligible') {
                $errors[] = "$label is not yet age-eligible for Marriage. The minimum age is " . (int) $ageAssessment['range']['min_age'] . ' years.';
            }
        }
        if ($place === '') $errors[] = "$label's place of birth is required.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "$label's email address is required.";
    }
    foreach ([
        'groom_father' => "Groom's father's name",
        'bride_father' => "Bride's father's name",
    ] as $namePrefix => $nameLabel) {
        if (($e = validate_parent_name_or_na($_POST, $namePrefix, $nameLabel)) !== null) $errors[] = $e;
    }
    foreach ([
        'groom_mother' => "Groom's mother's maiden name",
        'bride_mother' => "Bride's mother's maiden name",
    ] as $namePrefix => $nameLabel) {
        if (($e = validate_required_parent_name($_POST, $namePrefix, $nameLabel)) !== null) $errors[] = $e;
    }
    $errors = array_merge($errors, $principalSponsorErrors($formData));
    if ($g('groom_contact_number') === '') $errors[] = 'Groom contact number is required.';
    elseif (!is_valid_phone_number($g('groom_contact_number'))) $errors[] = 'Enter a valid groom contact number.';
    if ($g('bride_contact_number') === '') $errors[] = 'Bride contact number is required.';
    elseif (!is_valid_phone_number($g('bride_contact_number'))) $errors[] = 'Enter a valid bride contact number.';
    $validMaritalStatuses = ['single', 'civilly_married', 'widowed', 'annulled', 'married_elsewhere'];
    $groomMaritalStatus = $g('groom_marital_status');
    $brideMaritalStatus = $g('bride_marital_status');
    if (!in_array($groomMaritalStatus, $validMaritalStatuses, true)) $errors[] = "Groom's marital status is required.";
    if (!in_array($brideMaritalStatus, $validMaritalStatuses, true)) $errors[] = "Bride's marital status is required.";
    $groomEligibility = marriage_party_eligibility_error($groomMaritalStatus, $g('groom_previous_marriage') ?: 'no');
    $brideEligibility = marriage_party_eligibility_error($brideMaritalStatus, $g('bride_previous_marriage') ?: 'no');
    if ($groomEligibility !== null) $errors[] = $groomEligibility;
    if ($brideEligibility !== null) $errors[] = $brideEligibility;
    if (($pairError = marriage_status_pair_error($groomMaritalStatus, $brideMaritalStatus)) !== null) {
        $errors[] = $pairError;
    }
    $isCivillyMarriedPair = $groomMaritalStatus === 'civilly_married' && $brideMaritalStatus === 'civilly_married';
    $marriageRequiredDocuments = marriage_preapplication_required_documents(
        $groomMaritalStatus,
        $brideMaritalStatus,
        $venue
    );
    if ($isCivillyMarriedPair && $g('civil_marriage_date') === '') {
        $errors[] = 'Civil marriage date is required when both applicants are civilly married to each other.';
    }
    if ($isCivillyMarriedPair && $g('civil_marriage_place') === '') {
        $errors[] = 'Place of civil marriage is required when both applicants are civilly married to each other.';
    }
    if ($g('marriage_eligibility_declaration') !== '1') {
        $errors[] = 'You must confirm that neither applicant has an existing, unannulled marriage to another person.';
    }
    if ($weddingDate === '') $errors[] = 'Target wedding date is required.';
    if ($weddingDate !== '') {
        $earliest = (new DateTime('today'))->modify('+' . (int) MARRIAGE_MIN_WEDDING_LEAD_DAYS . ' days')->format('Y-m-d');
        if ($weddingDate < $earliest) {
            $lead = (int) MARRIAGE_MIN_WEDDING_LEAD_DAYS;
            $errors[] = $lead === 0
                ? 'The target wedding date cannot be earlier than today.'
                : "The target wedding date must be at least $lead day(s) from today.";
        }
    }
    if (!in_array($venue, ['church', 'other'], true)) $errors[] = 'Please select a valid wedding venue.';
    if ($venue === 'other' && $venueOther === '') $errors[] = 'Enter the name of the other wedding venue.';
    if (strlen($venueOther) > 150) $errors[] = 'The other venue name must be 150 characters or fewer.';
    if (!in_array($g('groom_baptized'), ['yes', 'no'], true) || !in_array($g('bride_baptized'), ['yes', 'no'], true)) $errors[] = 'Please answer whether both parties are baptized Catholic.';
    if (!in_array($g('groom_confirmed'), ['yes', 'no'], true) || !in_array($g('bride_confirmed'), ['yes', 'no'], true)) $errors[] = 'Please answer whether both parties are confirmed Catholic.';
    if (($_POST['privacy_consent'] ?? '') !== '1') $errors[] = 'You must accept the Privacy Notice before submitting.';

    if ($weddingDate !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM schedule_events WHERE event_date = ? AND event_type IN ('wedding','mass')");
        $stmt->execute([$weddingDate]);
        if ((int) $stmt->fetchColumn() > 0) $dateConflictWarning = 'There is already an event on the target date. The parish office will review availability.';
    }
    if (!$errors) {
        $cap = public_submission_allowed($pdo, [$groomEmail, $brideEmail]);
        if (!$cap['ok']) $errors[] = $cap['reason'];
    }
    $isSecretary = (current_user()['role'] ?? '') === 'secretary';
    $channel     = $isSecretary ? 'onsite' : 'online';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $requestNo = generate_request_no($pdo, 'sacrament_applications');
            $groomProfile = public_person_reference($pdo, $groomPersonId, [
                'first_name' => $g('groom_first'),
                'middle_name' => $g('groom_middle'),
                'last_name' => $g('groom_last'),
                'suffix' => $g('groom_suffix'),
                'birth_date' => $gd,
                'birth_place' => $g('groom_birthplace'),
                'father_name' => compose_person_name($_POST, 'groom_father'),
                'mother_maiden_name' => compose_person_name($_POST, 'groom_mother'),
            ]);
            $brideProfile = public_person_reference($pdo, $bridePersonId, [
                'first_name' => $g('bride_first'),
                'middle_name' => $g('bride_middle'),
                'last_name' => $g('bride_last'),
                'suffix' => $g('bride_suffix'),
                'birth_date' => $bd,
                'birth_place' => $g('bride_birthplace'),
                'father_name' => compose_person_name($_POST, 'bride_father'),
                'mother_maiden_name' => compose_person_name($_POST, 'bride_mother'),
            ]);
            $groomPersonId = $groomProfile['person_id'];
            $bridePersonId = $brideProfile['person_id'];
            if ($groomPersonId === $bridePersonId) {
                throw new InvalidArgumentException('The groom and bride must have separate master person profiles.');
            }
            public_assert_no_open_sacrament_application(
                $pdo,
                'marriage',
                [$groomPersonId, $bridePersonId],
                $groomProfile['reference']
            );
            $personReferences = [
                ['name' => $groomName, 'reference' => $groomProfile['reference']],
                ['name' => $brideName, 'reference' => $brideProfile['reference']],
            ];
            $parishRecordCheckNotes = [];
            foreach ([
                'groom' => ['label' => 'Groom', 'person_id' => $groomPersonId],
                'bride' => ['label' => 'Bride', 'person_id' => $bridePersonId],
            ] as $prefix => $party) {
                $records = sd_get_person_records($pdo, (int) $party['person_id']);
                foreach (['baptism' => 'baptized', 'confirmation' => 'confirmed'] as $recordType => $answerType) {
                    $record = $records[$recordType] ?? null;
                    if (!is_array($record)) {
                        continue;
                    }
                    $assessment = marriage_sacrament_record_assessment($record, $g($prefix . '_' . $answerType));
                    if ($assessment['answer_conflicts']) {
                        $parishRecordCheckNotes[] = $party['label'] . ' answered No for ' . ucfirst($recordType)
                            . ', but a linked record is on file; verify the answer and record.';
                    }
                    if (empty($record['book_verified_at'])) {
                        $parishRecordCheckNotes[] = $party['label'] . ' has a linked ' . ucfirst($recordType)
                            . ' record that is not yet verified against the parish register.';
                    }
                }
                foreach ($records['marriages'] ?? [] as $marriageRecord) {
                    $partnerName = trim((string) ($marriageRecord['partner_name'] ?? ''));
                    $partnerDescription = $partnerName !== '' ? ' with ' . $partnerName : '';
                    $eventDate = trim((string) ($marriageRecord['event_date'] ?? ''));
                    $dateDescription = $eventDate !== '' ? ' (' . $eventDate . ')' : '';
                    $status = $g($prefix . '_marital_status');
                    $statusReview = in_array($status, ['single', 'civilly_married'], true)
                        ? ' This may conflict with the submitted ' . marriage_marital_status_label($status) . ' status.'
                        : '';
                    $parishRecordCheckNotes[] = 'Existing church marriage record for ' . $party['label']
                        . $partnerDescription . $dateDescription . ' needs Parish Office review; no marital status was inferred.'
                        . $statusReview;
                }
            }
            $parishRecordCheckNotes = array_values(array_unique($parishRecordCheckNotes));
            $godfatherNames = compose_person_name_rows($formData, 'godfather');
            $godmotherNames = compose_person_name_rows($formData, 'godmother');
            $details = [
                'groom' => [
                    'full_name' => $groomName,
                    'birth_date' => $gd,
                    'birthplace' => $g('groom_birthplace'),
                    'current_address' => $g('groom_current_address'),
                    'contact_number' => $g('groom_contact_number'),
                    'email' => $groomEmail,
                    'religion' => $g('groom_religion'),
                    'father_name' => compose_person_name($_POST, 'groom_father'),
                    'mother_maiden_name' => compose_person_name($_POST, 'groom_mother'),
                    'marital_status' => $groomMaritalStatus,
                    'baptized' => $g('groom_baptized'),
                    'confirmed' => $g('groom_confirmed'),
                    'previous_marriage' => $g('groom_previous_marriage') ?: 'no',
                ],
                'bride' => [
                    'full_name' => $brideName,
                    'birth_date' => $bd,
                    'birthplace' => $g('bride_birthplace'),
                    'current_address' => $g('bride_current_address'),
                    'contact_number' => $g('bride_contact_number'),
                    'email' => $brideEmail,
                    'religion' => $g('bride_religion'),
                    'father_name' => compose_person_name($_POST, 'bride_father'),
                    'mother_maiden_name' => compose_person_name($_POST, 'bride_mother'),
                    'marital_status' => $brideMaritalStatus,
                    'baptized' => $g('bride_baptized'),
                    'confirmed' => $g('bride_confirmed'),
                    'previous_marriage' => $g('bride_previous_marriage') ?: 'no',
                ],
                'preferred_wedding_date' => $weddingDate,
                'preferred_wedding_time' => $weddingTime,
                'preferred_wedding_venue' => $venue,
                'preferred_wedding_venue_other' => $venueOther,
                'civilly_married' => $isCivillyMarriedPair ? 'yes' : 'no',
                'civil_marriage_date' => $isCivillyMarriedPair ? $g('civil_marriage_date') : '',
                'civil_marriage_place' => $isCivillyMarriedPair ? $g('civil_marriage_place') : '',
                'marriage_eligibility_declaration' => true,
                'required_documents' => $marriageRequiredDocuments,
                'principal_sponsors' => [
                    'godfathers' => $godfatherNames,
                    'godmothers' => $godmotherNames,
                ],
            ];
            if ($parishRecordCheckNotes !== []) {
                $details['parish_record_check_notes'] = $parishRecordCheckNotes;
            }
            $requirements = marriage_application_requirement_states($groomMaritalStatus, $brideMaritalStatus, $venue);
            $stmt = $pdo->prepare(
                "INSERT INTO sacrament_applications
                 (request_no, channel, sacrament_type, person_id, spouse_person_id, applicant_name,
                  applicant_email, applicant_contact, preferred_date, preferred_time, status,
                  details, requirements, privacy_consent_at)
                 VALUES (?, ?, 'marriage', ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, NOW())"
            );
            $stmt->execute([
                $requestNo,
                $channel,
                $groomPersonId,
                $bridePersonId,
                $groomName,
                $groomEmail,
                $g('groom_contact_number'),
                $weddingDate,
                $weddingTime,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                json_encode($requirements, JSON_UNESCAPED_UNICODE),
            ]);
            $applicationId = (int) $pdo->lastInsertId();
            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, 'Holy Matrimony pre-application submitted for ' . $groomName . ' & ' . $brideName);
            if ($channel === 'online') {
                notify($pdo, 'application_new', 'New sacrament pre-application', "Holy Matrimony pre-application ({$requestNo})", '/admin/requests/applications-queue.php');
                record_public_submission($pdo, [$groomEmail, $brideEmail], 'application_marriage');
            }
            $pdo->commit();
            $trackingNumber = $groomProfile['reference'];
            unset($_SESSION['marriage_app']);
            if ($isSecretary) {
                redirect_to('/admin/requests/applications-queue.php?added=' . rawurlencode($requestNo));
                exit;
            }
            if ($channel === 'online') {
                if (mail_request_received($groomEmail, $groomProfile['reference'], 'Holy Matrimony Application', $pdo, 'sacrament_applications', $applicationId, $requestNo, $marriageRequiredDocuments)) log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, 'Confirmation email sent to groom.');
                if ($brideEmail !== $groomEmail && mail_request_received($brideEmail, $brideProfile['reference'], 'Holy Matrimony Application', $pdo, 'sacrament_applications', $applicationId, $requestNo, $marriageRequiredDocuments)) log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, 'Confirmation email sent to bride.');
            }
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $trackingNumber = null;
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $trackingNumber = null; error_log('Holy Matrimony application failed: ' . $e->getMessage()); $errors[] = 'Something went wrong while submitting your pre-application. Please try again.';
        }
    }
}
$recordChecks = [];
if ($trackingNumber === null && in_array($currentStep, [4, 5], true)) {
    foreach (['groom' => 'Groom', 'bride' => 'Bride'] as $prefix => $label) {
        $history = $readPartyRecordHistory($prefix, $formData);
        $records = $history['records'] ?? [];
        $partyCheck = [
            'label' => $label,
            'linked' => $history['linked'],
            'identity_mismatch' => $history['identity_mismatch'],
            'marital_status' => (string) ($formData[$prefix . '_marital_status'] ?? ''),
            'baptism' => null,
            'confirmation' => null,
            'marriages' => $history['linked'] ? ($records['marriages'] ?? []) : [],
        ];
        foreach (['baptism' => 'baptized', 'confirmation' => 'confirmed'] as $recordType => $answerType) {
            $field = $prefix . '_' . $answerType;
            $record = $history['linked'] && is_array($records[$recordType] ?? null)
                ? $records[$recordType]
                : null;
            if ($currentStep === 4 && $record !== null && ($formData[$field] ?? '') === '') {
                $formData[$field] = 'yes';
            }
            $partyCheck[$recordType] = [
                'record' => $record,
                'assessment' => marriage_sacrament_record_assessment(
                    $record,
                    (string) ($formData[$field] ?? '')
                ),
            ];
        }
        $recordChecks[$prefix] = $partyCheck;
    }
}
$groomMaritalStatus = $groomMaritalStatus ?? (string) ($formData['groom_marital_status'] ?? '');
$brideMaritalStatus = $brideMaritalStatus ?? (string) ($formData['bride_marital_status'] ?? '');
$isCivillyMarriedPair = $isCivillyMarriedPair
    ?? ($groomMaritalStatus === 'civilly_married' && $brideMaritalStatus === 'civilly_married');
if (!isset($marriageRequiredDocuments)) {
    $marriageRequiredDocuments = marriage_preapplication_required_documents(
        $groomMaritalStatus,
        $brideMaritalStatus,
        (string) ($formData['preferred_wedding_venue'] ?? '')
    );
}
require_once __DIR__ . '/../../includes/header.php';
// These page-local helpers are guarded so the page can be included more than
// once in a single process (e.g. repeated page simulation in tests) without
// "cannot redeclare" errors. All top-level request processing still runs on
// every include.
if (!function_exists('mv')) {
    function mv(string $key): string { global $formData; $v = $formData[$key] ?? ''; return e(is_string($v) ? $v : ''); }
}
if (!function_exists('msel')) {
    function msel(string $key, string $value): string { global $formData; return ($formData[$key] ?? '') === $value ? 'selected' : ''; }
}
?>
<?php
$recordCheckMessage = static function (array $partyCheck, string $recordType): string {
    if (!$partyCheck['linked']) {
        return $partyCheck['identity_mismatch']
            ? 'The selected profile no longer matches these details. Clear and select the profile again to check records.'
            : 'No parish profile is linked, so this answer cannot be checked against parish records.';
    }

    $record = $partyCheck[$recordType]['record'] ?? null;
    if (!is_array($record)) {
        return 'No linked record was found. This does not mean the sacrament did not happen; answer based on your records and provide an external certificate if applicable.';
    }

    $details = [];
    if (!empty($record['event_date'])) $details[] = 'Date: ' . $record['event_date'];
    if (!empty($record['place'])) $details[] = 'Place: ' . $record['place'];
    $details[] = match ($record['source'] ?? '') {
        'this_parish' => 'This parish',
        'other_parish' => 'Another parish',
        default => 'Parish source not specified',
    };
    $details[] = !empty($record['book_verified_at'])
        ? 'Verified against the parish register'
        : 'Not yet verified against the parish register';

    $message = 'A linked record suggests Yes (' . implode('; ', $details) . ').';
    if ($partyCheck[$recordType]['assessment']['answer_conflicts']) {
        $message .= ' Your answer is No, so the parish office will review this difference.';
    } elseif ($partyCheck[$recordType]['assessment']['selected_answer'] === 'yes') {
        $message .= ' This supports your Yes answer.';
    }
    return $message;
};
?>
<section class="request-form">
<h1>Holy Matrimony Pre-Application</h1><p>For couples planning a church wedding.</p>
<?php if ($trackingNumber): ?>
<?php
$successHeading = 'Your pre-application has been submitted';
$successIsApplication = true;
$successRequestNumber = $requestNo;
$successPersonReferences = $personReferences;
$successRequiredDocuments = $marriageRequiredDocuments;
require __DIR__ . '/../../includes/submission-success.php';
?>
<?php else: ?>
<?php if ($currentStep === 1): $applicationType = 'marriage'; require __DIR__ . '/../../includes/public-requirements-notice.php'; endif; ?>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($dateConflictWarning): ?><div class="alert alert-warning"><?= e($dateConflictWarning) ?></div><?php endif; ?>
<ol class="request-steps" aria-label="Pre-application progress">
<?php foreach ($steps as $step => $label): ?>
    <?php $state = $step === $currentStep ? 'is-current' : ($currentStep > $step ? 'is-done' : ''); ?>
    <li class="<?= $state ?>"<?= $step === $currentStep ? ' aria-current="step"' : '' ?>><span><?= $step ?></span> <?= e($label) ?></li>
<?php endforeach; ?>
</ol>
<h2 class="form-stage-heading">Pre-application form</h2>
<form method="post" data-marriage-form data-step="<?= (int) $currentStep ?>" data-groom-status="<?= e($groomMaritalStatus) ?>" data-bride-status="<?= e($brideMaritalStatus) ?>">
<?= csrf_field() ?>
<input type="hidden" name="_action" value="<?= $currentStep < count($steps) ? 'next' : 'final_submit' ?>">
<div class="alert alert-error" role="alert" aria-live="assertive" data-marriage-status-alert hidden style="display:none"></div>
<?php if ($currentStep === 1): ?>
<fieldset><legend>Phase 1 of 5 — Wedding Details</legend><p class="field-hint">Your answers are kept in this browser only until you close it. Nothing is sent to the parish until you press Submit.</p><div class="inline-fields"><label>Target Wedding Date * <input type="date" name="preferred_wedding_date" value="<?= mv('preferred_wedding_date') ?>" required></label><label>Time <input type="time" name="preferred_wedding_time" value="<?= mv('preferred_wedding_time') ?>"></label></div><label>Venue * <select name="preferred_wedding_venue" required><option value="">-- Select --</option><option value="church" <?= msel('preferred_wedding_venue','church') ?>>St. Patrick's Parish Church</option><option value="other" <?= msel('preferred_wedding_venue','other') ?>>Other</option></select></label><label data-show-if="preferred_wedding_venue=other">Other venue name * <input type="text" name="preferred_wedding_venue_other" maxlength="150" value="<?= mv('preferred_wedding_venue_other') ?>" placeholder="Enter the church or venue name"></label></fieldset>
<?php elseif ($currentStep === 2): ?>
<fieldset><legend>Phase 2 of 5 — Groom's Information</legend>
<input type="hidden" name="groom_person_id" value="<?= mv('groom_person_id') ?>">
<div class="public-person-lookup" data-public-person-lookup
     data-lookup-context="preapplication"
     data-endpoint="<?= e($basePath . '/public/certificate-request.php') ?>"
     data-name-inputs="groom_first,groom_middle,groom_last,groom_suffix"
     data-birth-field="groom_birthdate" data-birth-place-field="groom_birthplace" data-person-id-field="groom_person_id"
     data-father-inputs="groom_father_first,groom_father_middle,groom_father_last,groom_father_suffix"
     data-mother-inputs="groom_mother_first,groom_mother_middle,groom_mother_last,groom_mother_suffix">
    <p class="field-hint">Enter the groom’s person reference to fill in recorded details and view linked sacrament records. Keep the reference private. Without a reference, fill in the form manually.</p>
    <div data-person-results aria-live="polite"></div>
</div>
<div class="inline-fields">
<label>First Name * <input name="groom_first" autocomplete="given-name" value="<?= mv('groom_first') ?>" required></label>
<label>Middle Name <span class="field-hint">(optional)</span> <input name="groom_middle" autocomplete="additional-name" value="<?= mv('groom_middle') ?>"></label>
</div>
<div class="inline-fields">
<label>Last Name * <input name="groom_last" autocomplete="family-name" value="<?= mv('groom_last') ?>" required></label>
<label>Suffix <span class="field-hint">(optional)</span> <input name="groom_suffix" autocomplete="honorific-suffix" value="<?= mv('groom_suffix') ?>"></label>
</div>
<div class="inline-fields"><label>Date of Birth * <input type="date" name="groom_birthdate" value="<?= mv('groom_birthdate') ?>" required></label><label>Place of Birth * <span class="field-hint">(Enter N/A if unknown)</span><input name="groom_birthplace" value="<?= mv('groom_birthplace') ?>" required></label></div>
<label>Current Address <input name="groom_current_address" value="<?= mv('groom_current_address') ?>"></label><label>Telephone / Mobile * <input name="groom_contact_number" value="<?= mv('groom_contact_number') ?>" required></label><label>Religion / Sect <input name="groom_religion" value="<?= mv('groom_religion') ?>"></label><label>Email * <input type="email" name="groom_email" value="<?= mv('groom_email') ?>" required></label><?= person_name_fields('groom_father', "Groom's father's name", $formData, true, null, 'required: enter the name, or enter N/A if unknown', true) ?><?= person_name_fields('groom_mother', "Groom's mother's maiden name", $formData, true, null, 'required: enter the first and last name; N/A is not accepted', false, true) ?>
<div class="inline-fields">
    <label>Groom's marital status *
        <select name="groom_marital_status" required>
            <option value="" disabled <?= msel('groom_marital_status', '') ?>>-- Select --</option>
            <option value="single" <?= msel('groom_marital_status', 'single') ?>>Single</option>
            <option value="civilly_married" <?= msel('groom_marital_status', 'civilly_married') ?>>Civilly Married (to each other)</option>
            <option value="widowed" <?= msel('groom_marital_status', 'widowed') ?>>Widowed</option>
            <option value="annulled" <?= msel('groom_marital_status', 'annulled') ?>>Annulled / Declared Null</option>
            <option value="married_elsewhere" <?= msel('groom_marital_status', 'married_elsewhere') ?>>Married (to someone else)</option>
        </select>
    </label>
    <label>Groom's previous marriage
        <select name="groom_previous_marriage">
            <option value="no" <?= msel('groom_previous_marriage', 'no') ?>>No</option>
            <option value="church" <?= msel('groom_previous_marriage', 'church') ?>>Yes — Church</option>
            <option value="civil" <?= msel('groom_previous_marriage', 'civil') ?>>Yes — Civil</option>
        </select>
    </label>
</div></fieldset>
<?php elseif ($currentStep === 3): ?>
<fieldset><legend>Phase 3 of 5 — Bride's Information</legend>
<input type="hidden" name="bride_person_id" value="<?= mv('bride_person_id') ?>">
<div class="public-person-lookup" data-public-person-lookup
     data-lookup-context="preapplication"
     data-endpoint="<?= e($basePath . '/public/certificate-request.php') ?>"
     data-name-inputs="bride_first,bride_middle,bride_last,bride_suffix"
     data-birth-field="bride_birthdate" data-birth-place-field="bride_birthplace" data-person-id-field="bride_person_id"
     data-father-inputs="bride_father_first,bride_father_middle,bride_father_last,bride_father_suffix"
     data-mother-inputs="bride_mother_first,bride_mother_middle,bride_mother_last,bride_mother_suffix">
    <p class="field-hint">Enter the bride’s person reference to fill in recorded details and view linked sacrament records. Keep the reference private. Without a reference, fill in the form manually.</p>
    <div data-person-results aria-live="polite"></div>
</div>
<div class="inline-fields">
<label>First Name * <input name="bride_first" autocomplete="given-name" value="<?= mv('bride_first') ?>" required></label>
<label>Middle Name <span class="field-hint">(optional)</span> <input name="bride_middle" autocomplete="additional-name" value="<?= mv('bride_middle') ?>"></label>
</div>
<div class="inline-fields">
<label>Last Name * <input name="bride_last" autocomplete="family-name" value="<?= mv('bride_last') ?>" required></label>
<label>Suffix <span class="field-hint">(optional)</span> <input name="bride_suffix" autocomplete="honorific-suffix" value="<?= mv('bride_suffix') ?>"></label>
</div>
<div class="inline-fields"><label>Date of Birth * <input type="date" name="bride_birthdate" value="<?= mv('bride_birthdate') ?>" required></label><label>Place of Birth * <span class="field-hint">(Enter N/A if unknown)</span><input name="bride_birthplace" value="<?= mv('bride_birthplace') ?>" required></label></div>
<label>Current Address <input name="bride_current_address" value="<?= mv('bride_current_address') ?>"></label><label>Telephone / Mobile * <input name="bride_contact_number" value="<?= mv('bride_contact_number') ?>" required></label><label>Religion / Sect <input name="bride_religion" value="<?= mv('bride_religion') ?>"></label><label>Email * <input type="email" name="bride_email" value="<?= mv('bride_email') ?>" required></label><?= person_name_fields('bride_father', "Bride's father's name", $formData, true, null, 'required: enter the name, or enter N/A if unknown', true) ?><?= person_name_fields('bride_mother', "Bride's mother's maiden name", $formData, true, null, 'required: enter the first and last name; N/A is not accepted', false, true) ?>
<div class="inline-fields">
    <label>Bride's marital status *
        <select name="bride_marital_status" required>
            <option value="" disabled <?= msel('bride_marital_status', '') ?>>-- Select --</option>
            <option value="single" <?= msel('bride_marital_status', 'single') ?>>Single</option>
            <option value="civilly_married" <?= msel('bride_marital_status', 'civilly_married') ?>>Civilly Married (to each other)</option>
            <option value="widowed" <?= msel('bride_marital_status', 'widowed') ?>>Widowed</option>
            <option value="annulled" <?= msel('bride_marital_status', 'annulled') ?>>Annulled / Declared Null</option>
            <option value="married_elsewhere" <?= msel('bride_marital_status', 'married_elsewhere') ?>>Married (to someone else)</option>
        </select>
    </label>
    <label>Bride's previous marriage
        <select name="bride_previous_marriage">
            <option value="no" <?= msel('bride_previous_marriage', 'no') ?>>No</option>
            <option value="church" <?= msel('bride_previous_marriage', 'church') ?>>Yes — Church</option>
            <option value="civil" <?= msel('bride_previous_marriage', 'civil') ?>>Yes — Civil</option>
        </select>
    </label>
</div></fieldset>
<?php elseif ($currentStep === 4): ?>
<fieldset><legend>Phase 4 of 5 — Church &amp; Civil Status</legend>
    <div class="inline-fields">
        <label>Groom baptized Catholic? * <select name="groom_baptized" required><option value="">-- Select --</option><option value="yes" <?= msel('groom_baptized','yes') ?>>Yes</option><option value="no" <?= msel('groom_baptized','no') ?>>No</option></select></label>
        <label>Bride baptized Catholic? * <select name="bride_baptized" required><option value="">-- Select --</option><option value="yes" <?= msel('bride_baptized','yes') ?>>Yes</option><option value="no" <?= msel('bride_baptized','no') ?>>No</option></select></label>
    </div>
    <div class="inline-fields">
        <label>Groom confirmed Catholic? * <select name="groom_confirmed" required><option value="">-- Select --</option><option value="yes" <?= msel('groom_confirmed','yes') ?>>Yes</option><option value="no" <?= msel('groom_confirmed','no') ?>>No</option></select></label>
        <label>Bride confirmed Catholic? * <select name="bride_confirmed" required><option value="">-- Select --</option><option value="yes" <?= msel('bride_confirmed','yes') ?>>Yes</option><option value="no" <?= msel('bride_confirmed','no') ?>>No</option></select></label>
    </div>
    <section class="online-intake-notice requirements-notice" aria-label="Parish record check">
        <strong>Parish record check</strong>
        <p>Linked baptism and confirmation records can suggest an answer. Missing records do not mean a sacrament did not happen. Existing marriage records are shown for parish review and do not automatically determine either person’s current marital status.</p>
        <ul>
            <?php foreach (['groom' => 'Groom', 'bride' => 'Bride'] as $prefix => $label): ?>
                <?php $partyCheck = $recordChecks[$prefix]; ?>
                <?php if (!$partyCheck['linked']): ?>
                    <li><strong><?= e($label) ?>:</strong> <?= e($recordCheckMessage($partyCheck, 'baptism')) ?></li>
                <?php else: ?>
                    <li><strong><?= e($label . ' — Baptism:') ?></strong> <?= e($recordCheckMessage($partyCheck, 'baptism')) ?></li>
                    <li><strong><?= e($label . ' — Confirmation:') ?></strong> <?= e($recordCheckMessage($partyCheck, 'confirmation')) ?></li>
                    <?php foreach ($partyCheck['marriages'] as $marriageRecord): ?>
                        <?php
                        $marriageDetails = array_filter([
                            $marriageRecord['event_date'] ?? null,
                            $marriageRecord['place'] ?? null,
                            match ($marriageRecord['source'] ?? '') {
                                'this_parish' => 'This parish',
                                'other_parish' => 'Another parish',
                                default => null,
                            },
                            !empty($marriageRecord['book_verified_at']) ? 'verified' : 'not yet verified',
                        ], static fn($value): bool => is_scalar($value) && trim((string) $value) !== '');
                        $statusConflict = in_array($partyCheck['marital_status'], ['single', 'civilly_married'], true);
                        ?>
                        <li><strong><?= e($label) ?> — Existing church marriage record:</strong>
                            <?= e(implode(' · ', array_map('strval', $marriageDetails))) ?>.
                            <?php if ($statusConflict): ?>
                                This may conflict with the selected <?= e(marriage_marital_status_label($partyCheck['marital_status'])) ?> status; please have the Parish Office review it.
                            <?php else: ?>
                                Please have the Parish Office review the related documents.
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($partyCheck['marriages'] === []): ?>
                        <li><strong><?= e($label) ?> — Marriage history:</strong> No linked parish marriage record was found; this does not establish current marital status.</li>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php if ($isCivillyMarriedPair): ?>
        <div class="inline-fields" data-civil-marriage-details>
            <label>Civil Marriage Date * <input type="date" name="civil_marriage_date" value="<?= mv('civil_marriage_date') ?>" required></label>
            <label>Place of Civil Marriage * <input name="civil_marriage_place" value="<?= mv('civil_marriage_place') ?>" required></label>
        </div>
    <?php endif; ?>
    <section class="online-intake-notice requirements-notice" aria-label="Documents required for the selected marital statuses">
        <strong>Documents to prepare for the selected marital statuses</strong>
        <p>Bring applicable documents to the Parish Office. Confirm accepted copies and originals with the office; do not upload them here.</p>
        <ul><?php foreach ($marriageRequiredDocuments as $document): ?><li><?= e($document) ?></li><?php endforeach; ?></ul>
    </section>
</fieldset>
<?php endif; ?>

<script>
(function () {
    var form = document.querySelector('[data-marriage-form]');
    if (!form) return;

    var notes = {
        single: 'Prepare a PSA CENOMAR for this applicant. A Marriage License is also required unless both applicants are civilly married to each other.',
        civilly_married: 'Both applicants must select this status. Prepare a PSA Marriage Certificate and enter the civil marriage date and place.',
        widowed: 'Prepare the deceased spouse’s PSA Death Certificate and the prior PSA Marriage Certificate.',
        annulled: 'Prepare the Civil Annulment Decree / Certificate of Finality, annotated PSA Marriage Certificate, and Church Declaration of Nullity.'
    };
    var blockMessage = 'Applicants currently married (civilly or in church) to another person cannot apply for a Catholic wedding until the prior marriage is legally annulled and declared null by the Church.';
    var mismatchMessage = "If you are civilly married to each other, both the groom and bride must select 'Civilly Married (to each other)'.";
    var statusAlert = form.querySelector('[data-marriage-status-alert]');
    var step = Number(form.dataset.step || 0);

    function statusFor(party) {
        var select = form.querySelector('[name="' + party + '_marital_status"]');
        return select ? select.value : form.dataset[party + 'Status'] || '';
    }

    function syncEligibility() {
        var groomStatus = statusFor('groom');
        var brideStatus = statusFor('bride');
        var messages = [];
        if (['married_elsewhere', 'married'].indexOf(groomStatus) !== -1
            || ['married_elsewhere', 'married'].indexOf(brideStatus) !== -1) {
            messages.push(blockMessage);
        }
        var bothStatusesSelected = step >= 3 || (groomStatus !== '' && brideStatus !== '');
        if (bothStatusesSelected
            && ((groomStatus === 'civilly_married') !== (brideStatus === 'civilly_married'))) {
            messages.push(mismatchMessage);
        }
        statusAlert.textContent = messages.join(' ');
        statusAlert.hidden = messages.length === 0;
        statusAlert.style.display = messages.length > 0 ? '' : 'none';
        var nextButton = form.querySelector('button[name="_action"][value="next"]');
        var submitButton = form.querySelector('button[name="_action"][value="final_submit"]');
        if (nextButton) nextButton.disabled = messages.length > 0;
        if (submitButton) submitButton.disabled = messages.length > 0;
    }

    ['groom', 'bride'].forEach(function (party) {
        var status = form.querySelector('[name="' + party + '_marital_status"]');
        var previous = form.querySelector('[name="' + party + '_previous_marriage"]');
        var label = previous && previous.closest('label');
        if (status && previous && label) {
            var note = document.createElement('div');
            note.setAttribute('role', 'note');
            note.style.display = 'none';
            (status.closest('.inline-fields') || label.parentNode).insertAdjacentElement('afterend', note);

            function syncStatusNote() {
                var value = status.value;
                var showPreviousMarriage = value === 'widowed' || value === 'annulled';
                label.hidden = !showPreviousMarriage;
                label.style.display = showPreviousMarriage ? '' : 'none';
                if (!showPreviousMarriage) previous.value = 'no';
                var text = notes[value] || '';
                note.textContent = text;
                note.className = 'alert alert-info';
                note.style.display = text ? '' : 'none';
            }

            status.addEventListener('change', syncStatusNote);
            syncStatusNote();
        }
        if (status) status.addEventListener('change', syncEligibility);
    });

    syncEligibility();
    document.addEventListener('DOMContentLoaded', syncEligibility);
})();
</script>


<?php
$godfatherNameRowsByIndex = compose_person_name_rows_by_index($formData, 'godfather');
$godmotherNameRowsByIndex = compose_person_name_rows_by_index($formData, 'godmother');
$godfatherRows = range(0, min(3, max(1, person_name_row_count($formData, 'godfather')) - 1));
$godmotherRows = range(0, min(3, max(1, person_name_row_count($formData, 'godmother')) - 1));
?>
<?php if ($currentStep === 4): ?>
<fieldset>
    <legend>Principal Sponsors</legend>
    <p class="field-hint">At least one godfather and one godmother are required. Up to four principal sponsors may be entered in total.</p>
    <fieldset>
        <legend>Godfathers (Ninong) *</legend>
        <div data-repeat-group="godfather" data-repeat-max="4">
            <?php foreach ($godfatherRows as $i): ?>
                <div class="godparent-row">
                    <label>Godfather's full name
                        <input type="text" name="godfather_full_name[]" value="<?= e($godfatherNameRowsByIndex[$i] ?? '') ?>" autocomplete="name">
                    </label>
                    <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godfather">Add another godfather</button>
    </fieldset>
    <fieldset>
        <legend>Godmothers (Ninang) *</legend>
        <div data-repeat-group="godmother" data-repeat-max="4">
            <?php foreach ($godmotherRows as $i): ?>
                <div class="godparent-row">
                    <label>Godmother's full name
                        <input type="text" name="godmother_full_name[]" value="<?= e($godmotherNameRowsByIndex[$i] ?? '') ?>" autocomplete="name">
                    </label>
                    <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godmother">Add another godmother</button>
    </fieldset>
</fieldset>
<?php elseif ($currentStep === 5): ?>
<fieldset><legend>Phase 5 of 5 — Review Your Pre-Application</legend>
<table class="review-table">
<tr><th>Wedding date</th><td><?= mv('preferred_wedding_date') ?> <?= mv('preferred_wedding_time') ?></td></tr>
<tr><th>Venue</th><td><?= ($formData['preferred_wedding_venue'] ?? '') === 'other' ? 'Other: ' . mv('preferred_wedding_venue_other') : "St. Patrick's Parish Church" ?></td></tr>
<tr><th>Groom</th><td><?= e(compose_person_name($formData, 'groom')) ?> · <?= mv('groom_birthdate') ?> · <?= mv('groom_email') ?> · <?= mv('groom_contact_number') ?></td></tr>
<tr><th>Bride</th><td><?= e(compose_person_name($formData, 'bride')) ?> · <?= mv('bride_birthdate') ?> · <?= mv('bride_email') ?> · <?= mv('bride_contact_number') ?></td></tr>
<tr><th>Groom's marital status</th><td><?= e(marriage_marital_status_label($groomMaritalStatus)) ?></td></tr>
<tr><th>Bride's marital status</th><td><?= e(marriage_marital_status_label($brideMaritalStatus)) ?></td></tr>
<tr><th>Baptism / confirmation</th><td>Groom: <?= mv('groom_baptized') ?> / <?= mv('groom_confirmed') ?>; Bride: <?= mv('bride_baptized') ?> / <?= mv('bride_confirmed') ?></td></tr>
<tr><th>Civil marriage</th><td><?= mv('civilly_married') ?><?php if (($formData['civilly_married'] ?? '') === 'yes'): ?> <?= mv('civil_marriage_date') ?> <?= mv('civil_marriage_place') ?><?php endif; ?></td></tr>
<tr><th>Groom's place of birth</th><td><?= e(mv('groom_birthplace')) ?></td></tr>
<tr><th>Bride's place of birth</th><td><?= e(mv('bride_birthplace')) ?></td></tr>
<tr><th>Groom's religion</th><td><?= e(mv('groom_religion')) ?></td></tr>
<tr><th>Bride's religion</th><td><?= e(mv('bride_religion')) ?></td></tr>
<tr><th>Groom's current address</th><td><?= e(mv('groom_current_address')) ?></td></tr>
<tr><th>Bride's current address</th><td><?= e(mv('bride_current_address')) ?></td></tr>
<tr><th>Groom's father's name</th><td><?= e(compose_person_name($formData, 'groom_father')) ?></td></tr>
<tr><th>Groom's mother's maiden name</th><td><?= e(compose_person_name($formData, 'groom_mother')) ?></td></tr>
<tr><th>Bride's father's name</th><td><?= e(compose_person_name($formData, 'bride_father')) ?></td></tr>
<tr><th>Bride's mother's maiden name</th><td><?= e(compose_person_name($formData, 'bride_mother')) ?></td></tr>
<tr><th>Groom's previous marriage</th><td><?= e(marriage_previous_marriage_label($formData['groom_previous_marriage'] ?? '')) ?></td></tr>
<tr><th>Bride's previous marriage</th><td><?= e(marriage_previous_marriage_label($formData['bride_previous_marriage'] ?? '')) ?></td></tr>

<tr><th>Principal godfathers</th><td><?= e(implode('; ', array_values($godfatherNameRowsByIndex)) ?: '—') ?></td></tr>
<tr><th>Principal godmothers</th><td><?= e(implode('; ', array_values($godmotherNameRowsByIndex)) ?: '—') ?></td></tr>
</table>
<section class="online-intake-notice requirements-notice" aria-label="Parish record check">
    <strong>Parish record check</strong>
    <p>Review any linked record differences with the Parish Office. A missing linked record does not mean a sacrament did not happen.</p>
    <ul>
        <?php foreach (['groom' => 'Groom', 'bride' => 'Bride'] as $prefix => $label): ?>
            <?php $partyCheck = $recordChecks[$prefix]; ?>
            <?php if (!$partyCheck['linked']): ?>
                <li><strong><?= e($label) ?>:</strong> <?= e($recordCheckMessage($partyCheck, 'baptism')) ?></li>
            <?php else: ?>
                <li><strong><?= e($label . ' — Baptism:') ?></strong> <?= e($recordCheckMessage($partyCheck, 'baptism')) ?></li>
                <li><strong><?= e($label . ' — Confirmation:') ?></strong> <?= e($recordCheckMessage($partyCheck, 'confirmation')) ?></li>
                <?php foreach ($partyCheck['marriages'] as $marriageRecord): ?>
                    <?php
                    $marriageDetails = array_filter([
                        $marriageRecord['event_date'] ?? null,
                        $marriageRecord['place'] ?? null,
                        match ($marriageRecord['source'] ?? '') {
                            'this_parish' => 'This parish',
                            'other_parish' => 'Another parish',
                            default => null,
                        },
                        !empty($marriageRecord['book_verified_at']) ? 'verified' : 'not yet verified',
                    ], static fn($value): bool => is_scalar($value) && trim((string) $value) !== '');
                    $statusConflict = in_array($partyCheck['marital_status'], ['single', 'civilly_married'], true);
                    ?>
                    <li><strong><?= e($label) ?> — Existing church marriage record:</strong>
                        <?= e(implode(' · ', array_map('strval', $marriageDetails))) ?>.
                        <?php if ($statusConflict): ?>
                            This may conflict with the selected <?= e(marriage_marital_status_label($partyCheck['marital_status'])) ?> status; please have the Parish Office review it.
                        <?php else: ?>
                            Please have the Parish Office review the related documents.
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($partyCheck['marriages'] === []): ?>
                    <li><strong><?= e($label) ?> — Marriage history:</strong> No linked parish marriage record was found; this does not establish current marital status.</li>
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</section>
<section class="online-intake-notice requirements-notice" aria-label="Documents required for the selected marital statuses">
    <strong>Documents to prepare for the selected marital statuses</strong>
    <p>Bring applicable documents to the Parish Office. Confirm accepted copies and originals with the office; do not upload them here.</p>
    <ul><?php foreach ($marriageRequiredDocuments as $document): ?><li><?= e($document) ?></li><?php endforeach; ?></ul>
</section>
<?php $applicationType = 'marriage'; $requirementsNoticePart = 'after'; require __DIR__ . '/../../includes/public-requirements-notice.php'; unset($requirementsNoticePart); ?>
<p class="alert alert-info online-intake-notice" role="note">This form starts the parish review. The Parish Office will confirm documents, preparation, and the wedding schedule. Submitting it does not confirm a wedding date.</p>
</fieldset>
<label class="checkbox-label"><input type="checkbox" name="marriage_eligibility_declaration" value="1" <?= ($formData['marriage_eligibility_declaration'] ?? '') === '1' ? 'checked' : '' ?> required> I confirm that neither the groom nor the bride has an existing, unannulled marriage (civil or church) to any other person.</label>
<label class="checkbox-label"><input type="checkbox" name="privacy_consent" value="1" <?= ($formData['privacy_consent'] ?? '') === '1' ? 'checked' : '' ?> required> I have read and agree to the <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a>.</label>
<?php endif; ?>
<div class="form-actions">
<?php if ($currentStep > 1): ?><button type="submit" name="_action" value="back" onclick="this.form.querySelector('[name=_action]').value='back'" formnovalidate class="btn btn-secondary">Back</button><?php endif; ?>
<?php if ($currentStep < count($steps)): ?><button type="submit" name="_action" value="next" class="btn">Next</button><?php else: ?><button type="submit" name="_action" value="final_submit" class="btn">Submit Pre-Application</button><?php endif; ?>
</div>
</form>
<?php endif; ?></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
