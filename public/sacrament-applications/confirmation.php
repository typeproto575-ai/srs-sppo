<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email
require_once __DIR__ . '/../../includes/application-requirements.php';

// Subfolder-safe URL prefix for internal links.
$basePath = $basePath ?? base_path();

$pageTitle = 'Confirmation Pre-Application';
$errors    = [];
$trackingNumber = null;
$requestNumber = null;

// --- Wizard state (mirrors baptismal.php phased flow) ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (($_GET['reset'] ?? '') === '1') {
    unset($_SESSION['confirmation_app']);
    header('Location: confirmation.php');
    exit;
}
if (($_GET['submitted'] ?? '') === '1' && is_string($_SESSION['confirmation_success'] ?? null)) {
    $trackingNumber = $_SESSION['confirmation_success'];
    $requestNumber = is_string($_SESSION['confirmation_success_request_no'] ?? null)
        ? $_SESSION['confirmation_success_request_no']
        : '';
    unset($_SESSION['confirmation_success'], $_SESSION['confirmation_success_request_no'], $_SESSION['confirmation_app']);
}

if (!defined('CONF_STEP_DETAILS')) {
    define('CONF_STEP_DETAILS',     1);
    define('CONF_STEP_BAPTISM',     2);
    define('CONF_STEP_CONTACT',     3);
    define('CONF_STEP_VERIFY',      4);
    define('CONF_STEP_COUNT',       4);
}
if (!isset($_SESSION['confirmation_app']) || !is_array($_SESSION['confirmation_app'])) {
    $_SESSION['confirmation_app'] = ['current_step' => 1, 'form_data' => []];
}
$app = &$_SESSION['confirmation_app'];
$currentStep = (int) ($app['current_step'] ?? 1);
if ($currentStep < 1 || $currentStep > CONF_STEP_COUNT) { $currentStep = 1; $app['current_step'] = 1; }
if (!is_array($app['form_data'] ?? null)) { $app['form_data'] = []; }
$formData = &$app['form_data'];
if (!in_array($formData['formation_path'] ?? '', ['batch', 'individual_catchup'], true)) {
    $formData['formation_path'] = 'batch';
}
if (($formData['formation_path'] ?? '') === 'batch') {
    $formData['school_grade'] = 'general';
    if (preg_match('/^\d{4}$/D', (string) ($formData['school_year'] ?? '')) !== 1) {
        $formData['school_year'] = date('Y');
    }
}
if (($_GET['start'] ?? '') === 'profile') {
    if ((current_user()['role'] ?? '') !== 'secretary') {
        http_response_code(403);
        exit('Only parish secretaries can start a profile-linked walk-in pre-application.');
    }
    $personId = filter_var($_GET['person_id'] ?? null, FILTER_VALIDATE_INT);
    $person = $personId ? public_application_profile($pdo, (int) $personId) : null;
    if (!$person) {
        $errors[] = 'The selected parishioner profile could not be found. Search for the person again.';
    } else {
        $formData = array_merge(public_application_profile_fields($person, 'applicant'), [
            'contact_number' => (string) ($person['contact_number'] ?? ''),
            'email' => (string) ($person['email'] ?? ''),
        ]);
        public_prefill_person_parents($formData);
        $app['current_step'] = CONF_STEP_DETAILS;
        session_write_close();
        header('Location: confirmation.php');
        exit;
    }
}
if (!function_exists('cfd')) {
    function cfd(string $k, array $d): string
    {
        $value = $d[$k] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
if (!function_exists('conf_baptism_error')) {
    function conf_baptism_error(array $d): ?string
    {
        $answer = cfd('baptized_here', $d);
        if ($answer === 'not_baptized') {
            return 'A candidate must be baptized in the Catholic Church before receiving Confirmation. Please visit the Parish Office to inquire about Baptism or RCIA.';
        }
        if (!in_array($answer, ['this_parish', 'elsewhere'], true)) {
            return 'Please answer where the applicant was baptized.';
        }
        $parish = cfd('baptism_parish', $d);
        if ($answer === 'elsewhere' && $parish === '') {
            return 'Enter the name and location of the parish or church where the applicant was baptized.';
        }
        if (mb_strlen($parish) > 150) {
            return 'The baptism parish or church name and location must be 150 characters or fewer.';
        }
        return null;
    }
}
if (!function_exists('conf_guardian_relationship_error')) {
    function conf_guardian_relationship_error(array $d): ?string
    {
        $relationship = cfd('guardian_relationship', $d);
        $otherRelationship = cfd('guardian_relationship_other', $d);
        if ($relationship === 'other' && $otherRelationship === '') {
            return 'Describe the guardian relationship.';
        }
        if (mb_strlen($otherRelationship) > 100) {
            return 'The guardian relationship description must be 100 characters or fewer.';
        }
        return null;
    }
}
if (!function_exists('conf_sponsor_errors')) {
    function conf_sponsor_errors(array $d): array
    {
        $errors = [];
        $normalize = static fn(string $name): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
        $sponsors = [];
        foreach (['sponsor1' => 'Sponsor', 'sponsor2' => 'Second sponsor'] as $prefix => $label) {
            $name = cfd($prefix . '_full_name', $d);
            if ($name === '') {
                $name = compose_person_name($d, $prefix);
            }
            if ($prefix === 'sponsor1' && $name === '') {
                $errors[] = "Sponsor's full name is required.";
                continue;
            }
            if ($name === '') {
                continue;
            }
            if (($e = validate_full_name($name)) !== null) {
                $errors[] = $label . ': ' . $e;
                continue;
            }
            $sponsors[$label] = $normalize($name);
        }
        $parents = array_filter([
            $normalize(compose_person_name($d, 'father')),
            $normalize(compose_person_name($d, 'mother')),
        ]);
        foreach ($sponsors as $label => $name) {
            if (in_array($name, $parents, true)) {
                $errors[] = $label . " cannot be the applicant's father or mother.";
            }
        }
        return $errors;
    }
}
if (!function_exists('conf_na_name')) {
    // A parent entered as N/A (first and last name both "N/A") is stored as the single word "N/A".
    function conf_na_name(array $d, string $prefix): string
    {
        $isNa = static fn(string $v): bool => strtoupper(trim($v)) === 'N/A';
        if ($isNa(cfd($prefix . '_first', $d)) && $isNa(cfd($prefix . '_last', $d))) {
            return 'N/A';
        }
        return compose_person_name($d, $prefix);
    }
}
if (!function_exists('conf_parent_errors')) {
    function conf_parent_errors(array $d): array
    {
        return array_values(array_filter([
            validate_parent_name_or_na($d, 'father', "Father's name"),
            validate_required_parent_name($d, 'mother', "Mother's maiden name"),
        ]));
    }
}
$confLabels = [
    CONF_STEP_DETAILS     => 'Applicant information',
    CONF_STEP_BAPTISM     => 'Baptism & sponsor',
    CONF_STEP_CONTACT     => 'Contact & consent',
    CONF_STEP_VERIFY      => 'Review & submit',
];
$confOrder = [CONF_STEP_DETAILS, CONF_STEP_BAPTISM, CONF_STEP_CONTACT, CONF_STEP_VERIFY];
$wv = static function (string $k) use (&$formData): string { return e($formData[$k] ?? ''); };

// =====================================================================
// POST HANDLING — Navigation and Final Submit
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedAction = $_POST['wizard_action'] ?? '';
    $fallbackAction = $_POST['_wizard_action'] ?? '';
    $action = is_string($submittedAction) && in_array($submittedAction, ['next', 'back', 'final_submit'], true)
        ? $submittedAction
        : (is_string($fallbackAction) ? $fallbackAction : '');

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } elseif ($action === 'next' || $action === 'back') {
        $stepFields = [
            CONF_STEP_DETAILS => [
                'applicant_first', 'applicant_middle', 'applicant_last', 'applicant_suffix',
                'applicant_birthdate', 'applicant_birthplace', 'applicant_person_id',
                'formation_path', 'group_label', 'school_year',
                'father_first', 'father_middle', 'father_last', 'father_suffix',
                'mother_first', 'mother_middle', 'mother_last', 'mother_suffix',
                'father_na', 'mother_na',
                'parent_guardian_first', 'parent_guardian_middle', 'parent_guardian_last', 'parent_guardian_suffix',
                'guardian_relationship', 'guardian_relationship_other',
            ],
            CONF_STEP_BAPTISM => [
                'baptized_here', 'baptism_parish',
                'sponsor1_full_name', 'sponsor2_full_name',
            ],
            CONF_STEP_CONTACT => ['contact_number', 'email'],
        ];
        foreach ($stepFields[$currentStep] ?? [] as $field) {
            $formData[$field] = cfd($field, $_POST);
        }
        if ($currentStep === CONF_STEP_DETAILS) {
            if (($formData['formation_path'] ?? '') === 'batch') {
                $formData['school_grade'] = 'general';
            } else {
                $formData['group_label'] = '';
                $formData['school_year'] = '';
            }
        }
        if ($currentStep === CONF_STEP_CONTACT) {
            $formData['privacy_consent'] = ($_POST['privacy_consent'] ?? '') === '1' ? '1' : '0';
        }

        if ($action === 'back') {
            $app['current_step'] = max(CONF_STEP_DETAILS, $currentStep - 1);
        } else {
            if ($currentStep === CONF_STEP_DETAILS) {
                $applicantName = compose_person_name($formData, 'applicant');
                if (cfd('applicant_first', $formData) === '') $errors[] = "Applicant's first name is required.";
                if (cfd('applicant_last', $formData) === '') $errors[] = "Applicant's last name is required.";
                if (($e = validate_full_name($applicantName)) !== null) $errors[] = $e;
                if ($formData['applicant_birthdate'] === '') $errors[] = "Applicant's birthdate is required.";
                if (cfd('applicant_birthplace', $formData) === '') $errors[] = "Applicant's place of birth is required.";
                if ($formData['applicant_birthdate'] !== '' && is_future_date($formData['applicant_birthdate'])) $errors[] = "Applicant's birthdate cannot be in the future.";
                if ($formData['applicant_birthdate'] !== '' && !is_future_date($formData['applicant_birthdate'])) {
                    $ageAssessment = sacrament_age_assessment($pdo, 'confirmation', calculate_age($formData['applicant_birthdate']));
                    if ($ageAssessment['status'] !== 'Age-eligible') {
                        $errors[] = 'The applicant is not yet age-eligible for Confirmation. The minimum age is ' . (int) $ageAssessment['range']['min_age'] . ' years.';
                    }
                }
                if (($e = sacrament_formation_input_error(
                    cfd('formation_path', $formData),
                    cfd('formation_path', $formData) === 'batch' ? 'general' : '',
                    cfd('school_year', $formData)
                )) !== null) $errors[] = $e;
                if (mb_strlen(cfd('group_label', $formData), 'UTF-8') > 80) $errors[] = 'The group or organization name must be 80 characters or fewer.';
                $errors = array_merge($errors, conf_parent_errors($formData));
                $guardianName = compose_person_name($formData, 'parent_guardian');
                if ($guardianName !== '' && ($e = validate_full_name($guardianName)) !== null) $errors[] = $e;
                if (($e = conf_guardian_relationship_error($formData)) !== null) $errors[] = $e;
            } elseif ($currentStep === CONF_STEP_BAPTISM) {
                if (($e = conf_baptism_error($formData)) !== null) {
                    $errors[] = $e;
                } else {
                    $errors = array_merge($errors, conf_sponsor_errors($formData));
                }
            } elseif ($currentStep === CONF_STEP_CONTACT) {
                if ($formData['contact_number'] === '') {
                    $errors[] = 'Contact number is required.';
                } elseif (!is_valid_phone_number($formData['contact_number'])) {
                    $errors[] = 'Please enter a valid Philippine mobile number, such as 09171234567 or +639171234567.';
                }
                if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
                if ($formData['privacy_consent'] !== '1') $errors[] = 'Please agree to the Privacy Notice before continuing.';
            }

            if (!$errors) {
                $app['current_step'] = min($currentStep + 1, CONF_STEP_COUNT);
            }
        }

        if (!$errors) {
            session_write_close();
            header('Location: confirmation.php');
            exit;
        }
    } elseif ($action === 'final_submit' && $currentStep === CONF_STEP_VERIFY) {
        $formData['certify_accuracy'] = ($_POST['certify_accuracy'] ?? '') === '1' ? '1' : '0';

        $applicantName = compose_person_name($formData, 'applicant');
        $applicantBirthdate = cfd('applicant_birthdate', $formData);
        $parentGuardian = compose_person_name($formData, 'parent_guardian');
        $contactNumber = cfd('contact_number', $formData);
        $email = cfd('email', $formData);
        $baptizedHere = $formData['baptized_here'] ?? '';

        if ($formData['certify_accuracy'] !== '1') $errors[] = 'You must certify that all information is true and correct before submitting.';
        if (cfd('applicant_first', $formData) === '') $errors[] = "Applicant's first name is required.";
        if (cfd('applicant_last', $formData) === '') $errors[] = "Applicant's last name is required.";
        if (($e = validate_full_name($applicantName)) !== null) $errors[] = $e;
        if ($applicantBirthdate === '') $errors[] = "Applicant's birthdate is required.";
        if (cfd('applicant_birthplace', $formData) === '') $errors[] = "Applicant's place of birth is required.";
        if ($applicantBirthdate !== '' && is_future_date($applicantBirthdate)) $errors[] = "Applicant's birthdate cannot be in the future.";
        if ($applicantBirthdate !== '' && !is_future_date($applicantBirthdate)) {
            $ageAssessment = sacrament_age_assessment($pdo, 'confirmation', calculate_age($applicantBirthdate));
            if ($ageAssessment['status'] !== 'Age-eligible') {
                $errors[] = 'The applicant is not yet age-eligible for Confirmation. The minimum age is ' . (int) $ageAssessment['range']['min_age'] . ' years.';
            }
        }
        if (($e = sacrament_formation_input_error(
            cfd('formation_path', $formData),
            cfd('formation_path', $formData) === 'batch' ? 'general' : '',
            cfd('school_year', $formData)
        )) !== null) $errors[] = $e;
        if (mb_strlen(cfd('group_label', $formData), 'UTF-8') > 80) $errors[] = 'The group or organization name must be 80 characters or fewer.';
        $errors = array_merge($errors, conf_parent_errors($formData));
        if ($parentGuardian !== '' && ($e = validate_full_name($parentGuardian)) !== null) $errors[] = $e;
        if (($e = conf_guardian_relationship_error($formData)) !== null) $errors[] = $e;
        if ($contactNumber === '') {
            $errors[] = 'Contact number is required.';
        } elseif (!is_valid_phone_number($contactNumber)) {
            $errors[] = 'Please enter a valid Philippine mobile number, such as 09171234567 or +639171234567.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if (($e = conf_baptism_error($formData)) !== null) {
            $errors[] = $e;
        } else {
            $errors = array_merge($errors, conf_sponsor_errors($formData));
        }
        if (($formData['privacy_consent'] ?? '') !== '1') $errors[] = 'Please agree to the Privacy Notice before submitting.';
        $personId = null;
        if (!empty($formData['applicant_person_id'])) {
            try {
                $personId = public_selected_person_id(
                    $pdo,
                    $formData['applicant_person_id'],
                    cfd('applicant_first', $formData),
                    cfd('applicant_middle', $formData),
                    cfd('applicant_last', $formData),
                    cfd('applicant_suffix', $formData),
                    $applicantBirthdate
                );
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!$errors) {
            $submissionCap = public_submission_allowed($pdo, [$email]);
            if (!$submissionCap['ok']) {
                $errors[] = 'You have reached the submission limit for this email address. Please try again later.';
            }
        }

        $isSecretary = (current_user()['role'] ?? '') === 'secretary';
        $channel     = $isSecretary ? 'onsite' : 'online';

        if (!$errors) {
            try {
                $applicationId = null;
                $personReference = '';
                $inputPersonId = $personId;
                $ageAssessment = sacrament_age_assessment(
                    $pdo,
                    'confirmation',
                    calculate_age($applicantBirthdate)
                );
                $fatherName = compose_person_name($formData, 'father');
                $motherName = conf_na_name($formData, 'mother');
                $requestNo = retry_on_duplicate($pdo, function () use (
                    $pdo,
                    $applicantName,
                    $applicantBirthdate,
                    $parentGuardian,
                    $contactNumber,
                    $email,
                    $baptizedHere,
                    $inputPersonId,
                    &$personId,
                    $channel,
                    $ageAssessment,
                    $fatherName,
                    $motherName,
                    $formData,
                    &$applicationId,
                    &$personReference
                     
                ): string {
                    $requestNo = generate_request_no($pdo, 'sacrament_applications');
                    $personProfile = public_person_reference($pdo, $inputPersonId, [
                        'first_name' => cfd('applicant_first', $formData),
                        'middle_name' => cfd('applicant_middle', $formData),
                        'last_name' => cfd('applicant_last', $formData),
                        'suffix' => cfd('applicant_suffix', $formData),
                        'birth_date' => $applicantBirthdate,
                        'birth_place' => cfd('applicant_birthplace', $formData),
                        'father_name' => $fatherName,
                        'mother_maiden_name' => $motherName,
                    ]);
                    $personId = $personProfile['person_id'];
                    $personReference = $personProfile['reference'];
                    public_assert_no_open_sacrament_application(
                        $pdo,
                        'confirmation',
                        [$personId],
                        $personReference
                    );
                    $details = [
                        'applicant_full_name' => $applicantName,
                        'birth_date' => $applicantBirthdate,
                        'age_at_submission' => $ageAssessment['age'],
                        'age_eligibility_status' => $ageAssessment['status'],
                        'father_name' => $fatherName,
                        'mother_maiden_name' => $motherName,
                        'birth_place' => cfd('applicant_birthplace', $formData),
                        'parent_guardian_name' => $parentGuardian,
                        'guardian_relationship' => cfd('guardian_relationship', $formData),
                        'guardian_relationship_other' => cfd('guardian_relationship', $formData) === 'other'
                            ? cfd('guardian_relationship_other', $formData)
                            : '',
                        'baptized_here' => $baptizedHere,
                        'baptism_parish' => $baptizedHere === 'elsewhere' ? cfd('baptism_parish', $formData) : '',
                        'formation_path' => cfd('formation_path', $formData),
                        'school_grade' => cfd('formation_path', $formData) === 'batch' ? 'general' : '',
                        'group_label' => cfd('group_label', $formData),
                        'school_year' => cfd('formation_path', $formData) === 'batch'
                            ? cfd('school_year', $formData)
                            : null,
                        'sponsor_names' => array_values(array_filter([
                            cfd('sponsor1_full_name', $formData) ?: compose_person_name($formData, 'sponsor1'),
                            cfd('sponsor2_full_name', $formData) ?: compose_person_name($formData, 'sponsor2'),
                        ])),
                    ];
                    $baptismRequirement = verified_parish_record_requirement($pdo, $personId, 'baptism');
                    $firstCommunionRequirement = verified_parish_record_requirement($pdo, $personId, 'first_communion');
                    $requirements = [
                        'origin_baptismal_certificate' => $baptismRequirement
                            ?? ['status' => 'not_received', 'note' => ''],
                        'confirmation_sponsor_details' => ['status' => 'not_received', 'note' => ''],
                        'confirmation_seminar' => ['status' => 'not_received', 'note' => ''],
                        'psa_birth_certificate' => ['status' => 'not_received', 'note' => ''],
                        'first_communion_verified' => $firstCommunionRequirement
                            ?? ['status' => 'not_received', 'note' => ''],
                        'parent_guardian_commitment' => [
                            'status' => calculate_age($applicantBirthdate) < 18 ? 'not_received' : 'not_applicable',
                            'note' => '',
                        ],
                    ];
                    $stmt = $pdo->prepare(
                        "INSERT INTO sacrament_applications
                         (request_no, channel, sacrament_type, person_id, applicant_name, applicant_email,
                          applicant_contact, status, details, requirements, privacy_consent_at)
                         VALUES (?, ?, 'confirmation', ?, ?, ?, ?, 'new', ?, ?, NOW())"
                    );
                    $stmt->execute([
                        $requestNo,
                        $channel,  
                        $personId,
                        $applicantName,
                        $email,
                        $contactNumber,
                        json_encode($details, JSON_UNESCAPED_UNICODE),
                        json_encode($requirements, JSON_UNESCAPED_UNICODE),
                    ]);
                    $applicationId = (int) $pdo->lastInsertId();
                    return $requestNo;
                });

                $trackingNumber = $personReference;
                log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, "Confirmation pre-application submitted for {$applicantName}");
                if ($channel === 'online') {
                    notify($pdo, 'application_new', 'New sacrament pre-application', "Confirmation pre-application for {$applicantName} ({$requestNo})", '/admin/requests/applications-queue.php');
                    record_public_submission($pdo, [$email], 'application_confirmation');
                }

                if ($isSecretary) {
                    redirect_to('/admin/requests/applications-queue.php?added=' . rawurlencode($requestNo));
                    exit;
                }

                try {
                    if ($channel === 'online' && mail_request_received($email, $trackingNumber, 'Confirmation Application', $pdo, 'sacrament_applications', $applicationId, $requestNo)) {
                        log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
                    }
                } catch (InvalidArgumentException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = $e->getMessage();
                } catch (Throwable $e) {
                    error_log('Confirmation application confirmation email failed: ' . $e->getMessage());
                }

                $_SESSION['confirmation_success'] = $trackingNumber;
                $_SESSION['confirmation_success_request_no'] = $requestNo;
                unset($_SESSION['confirmation_app']);
                session_write_close();
                header('Location: confirmation.php?submitted=1');
                exit;
            } catch (Throwable $e) {
                error_log('Confirmation application failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while submitting your pre-application. Please try again.';
            }
        }
    }
}
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<section class="request-form">
<?php if ($trackingNumber !== null): ?>
    <div class="success-panel">
        <h1>Confirmation Pre-Application Submitted</h1>
        <?php
        $successHeading = 'Keep this reference number';
        $successIsApplication = true;
        $successFormationGuidance = 'The Parish Office will review the requested group or individual preparation. Calendar year and group label help organize group applications. Sponsor eligibility and parent/guardian formation commitment are follow-up checks; this submission does not confirm a group or date.';
        $successRequestNumber = $requestNumber ?? '';
        $successRequiredDocuments = sacrament_application_requirements('confirmation')['before'];
        require __DIR__ . '/../../includes/submission-success.php';
        ?>
        <a class="btn" href="confirmation.php?reset=1">Start a New Pre-Application</a>
    </div>
<?php else: ?>
    <h1><?= e($pageTitle) ?></h1>
    <?php if ($currentStep === CONF_STEP_DETAILS): $applicationType = 'confirmation'; require __DIR__ . '/../../includes/public-requirements-notice.php'; endif; ?>
    <ol class="request-steps" aria-label="Progress">
        <?php foreach ($confOrder as $i => $key): ?>
            <?php $state = ($key === $currentStep) ? 'is-current' : (($currentStep > $key) ? 'is-done' : ''); ?>
            <li class="<?= $state ?>"<?= ($key === $currentStep) ? ' aria-current="step"' : '' ?>><span><?= $i + 1 ?></span> <?= e($confLabels[$key]) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php
    $ageAssessment = $currentStep === CONF_STEP_VERIFY && !empty($formData['applicant_birthdate'])
        ? sacrament_age_assessment($pdo, 'confirmation', calculate_age((string) $formData['applicant_birthdate']))
        : null;
    $reviewBirthDate = trim((string) ($formData['applicant_birthdate'] ?? ''));
    $reviewBirthDateTimestamp = $reviewBirthDate !== '' ? strtotime($reviewBirthDate) : false;
    $guardianRelationship = cfd('guardian_relationship', $formData);
    $guardianRelationshipLabels = [
        'grandparent' => 'Grandparent',
        'aunt_uncle' => 'Aunt / Uncle',
        'sibling' => 'Sibling',
        'legal_guardian' => 'Legal guardian',
    ];
    $reviewGuardianRelationship = $guardianRelationship === 'other'
        ? cfd('guardian_relationship_other', $formData)
        : ($guardianRelationshipLabels[$guardianRelationship] ?? '');
    $reviewBaptismStatus = match (cfd('baptized_here', $formData)) {
        'this_parish' => 'At this parish',
        'elsewhere' => 'At another parish or church',
        'not_baptized' => 'Not yet baptized in the Catholic Church',
        default => '—',
    };
    $reviewSponsor1 = cfd('sponsor1_full_name', $formData) ?: compose_person_name($formData, 'sponsor1');
    $reviewSponsor2 = cfd('sponsor2_full_name', $formData) ?: compose_person_name($formData, 'sponsor2');
    ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <h2 class="form-stage-heading">Pre-application form</h2>
    <form method="post" data-preserve-scroll="false">
        <?= csrf_field() ?>
        <input type="hidden" name="_wizard_action" value="<?= $currentStep === CONF_STEP_VERIFY ? 'final_submit' : 'next' ?>">
        <?php if ($currentStep === CONF_STEP_DETAILS): ?>
        <fieldset>
            <legend>Phase 1 of 4 — Applicant Information</legend>
            <p class="field-hint">Your answers are kept in this browser only until you close it. Nothing is sent to the parish until you press Submit.</p>
            <div class="public-person-lookup" data-public-person-lookup
                 data-lookup-context="preapplication"
                 data-endpoint="<?= e($basePath . '/public/certificate-request.php') ?>"
                 data-name-inputs="applicant_first,applicant_middle,applicant_last,applicant_suffix"
                 data-birth-field="applicant_birthdate" data-birth-place-field="applicant_birthplace" data-person-id-field="applicant_person_id"
                 data-contact-field="contact_number" data-email-field="email" data-baptized-here-field="baptized_here"
                 data-father-inputs="father_first,father_middle,father_last,father_suffix"
                 data-mother-inputs="mother_first,mother_middle,mother_last,mother_suffix">
                <h3 class="lookup-title">Already registered with the parish?</h3>
                <p class="field-hint">Enter your person reference to fill in recorded details and view linked sacrament records. Keep the reference private. Without a reference, fill in the form manually.</p>
                <div data-person-results aria-live="polite"></div>
            </div>
            <h3 class="form-subheading">Applicant information</h3>
            <div class="inline-fields">
                <label>First Name *
                    <input type="text" name="applicant_first" autocomplete="given-name" value="<?= $wv('applicant_first') ?>" required>
                </label>
                <label>Middle Name <span class="field-hint">(optional)</span>
                    <input type="text" name="applicant_middle" autocomplete="additional-name" value="<?= $wv('applicant_middle') ?>">
                </label>
            </div>
            <div class="inline-fields">
                <label>Last Name *
                    <input type="text" name="applicant_last" autocomplete="family-name" value="<?= $wv('applicant_last') ?>" required>
                </label>
                <label>Suffix <span class="field-hint">(optional)</span>
                    <input type="text" name="applicant_suffix" autocomplete="honorific-suffix" value="<?= $wv('applicant_suffix') ?>">
                </label>
            </div>
            <label>Date of birth *
                <input type="date" name="applicant_birthdate" max="<?= e(date('Y-m-d')) ?>" value="<?= $wv('applicant_birthdate') ?>" required>
            </label>
            <label>Place of birth * <span class="field-hint">(City/Municipality, Province; enter N/A if unknown)</span>
                <input type="text" name="applicant_birthplace" autocomplete="off" value="<?= $wv('applicant_birthplace') ?>" required>
            </label>
            <h3 class="form-subheading">Preparation group</h3>
            <?php $formationPath = cfd('formation_path', $formData); ?>
            <label>Preparation type *
                <select name="formation_path" data-confirmation-formation required>
                    <option value="batch" <?= $formationPath === 'batch' ? 'selected' : '' ?>>Group preparation</option>
                    <option value="individual_catchup" <?= $formationPath === 'individual_catchup' ? 'selected' : '' ?>>Individual / catch-up preparation</option>
                </select>
            </label>
            <div data-confirmation-group-fields<?= $formationPath === 'batch' ? '' : ' hidden' ?>>
                <label>Group or organization name <span class="field-hint">(optional)</span>
                    <input type="text" name="group_label" maxlength="80" value="<?= $wv('group_label') ?>">
                </label>
                <label>Calendar year *
                    <input type="text" name="school_year" inputmode="numeric" pattern="\d{4}" maxlength="4" value="<?= $formationPath === 'batch' ? $wv('school_year') : e(date('Y')) ?>" required>
                </label>
            </div>
            <p class="field-hint">The Parish Office reviews preparation needs and assigns candidates to group or individual / catch-up preparation. Calendar year helps organize group applications; it does not confirm eligibility or a date.</p>
            <input type="hidden" name="applicant_person_id" value="<?= $wv('applicant_person_id') ?>">
            <input type="hidden" name="contact_number" value="<?= $wv('contact_number') ?>">
            <input type="hidden" name="email" value="<?= $wv('email') ?>">
            <input type="hidden" name="baptized_here" value="<?= $wv('baptized_here') ?>">
            <?= person_name_fields('father', "Father's name", $formData, true, null, 'required: enter the name, or enter N/A if unknown', true) ?>
            <?= person_name_fields('mother', "Mother's maiden name", $formData, true, null, 'required: enter the first and last name; N/A is not accepted', false, true) ?>
            <p class="field-hint">Parents are recorded as they appear on the birth certificate. A guardian is never entered as a parent.</p>
            <?= person_name_fields('parent_guardian', 'Guardian / authorized representative', $formData, false, null, 'optional; only if the parents are unavailable') ?>
            <label>Relationship to applicant <span class="field-hint">(optional)</span>
                <select name="guardian_relationship">
                    <option value="">-- Select --</option>
                    <?php foreach (['grandparent' => 'Grandparent', 'aunt_uncle' => 'Aunt / Uncle', 'sibling' => 'Sibling', 'legal_guardian' => 'Legal guardian', 'other' => 'Other'] as $relValue => $relLabel): ?>
                        <option value="<?= e($relValue) ?>" <?= ($formData['guardian_relationship'] ?? '') === $relValue ? 'selected' : '' ?>><?= e($relLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label data-show-if="guardian_relationship=other">
                    Describe the relationship *
                    <input type="text" name="guardian_relationship_other" maxlength="100" value="<?= $wv('guardian_relationship_other') ?>" placeholder="Enter the relationship">
            </label>
        </fieldset>
        <?php elseif ($currentStep === CONF_STEP_BAPTISM): ?>
            <fieldset>
                <legend>Phase 2 of 4 — Baptism Record and Sponsor</legend>
                <label>Baptism status *
                    <select name="baptized_here" required>
                        <option value="">-- Select --</option>
                        <option value="this_parish" <?= ($formData['baptized_here'] ?? '') === 'this_parish' ? 'selected' : '' ?>>At this parish</option>
                        <option value="elsewhere" <?= ($formData['baptized_here'] ?? '') === 'elsewhere' ? 'selected' : '' ?>>At another parish or church</option>
                        <option value="not_baptized" <?= ($formData['baptized_here'] ?? '') === 'not_baptized' ? 'selected' : '' ?>>Not yet baptized in the Catholic Church</option>
                    </select>
                </label>
                <label data-show-if="baptized_here=elsewhere">
                    Parish/church name and location <span class="field-hint">(if known)</span>
                    <input type="text" name="baptism_parish" maxlength="150" value="<?= $wv('baptism_parish') ?>" placeholder="Parish/church, city or municipality, province">
                </label>
                <p class="field-hint">Baptism is required before Confirmation. The Parish Office checks this parish’s register or requests the origin-parish certificate. <a href="<?= e($basePath . '/public/certificate-request.php?type=baptism') ?>" target="_blank" rel="noopener noreferrer">Request a Baptismal Certificate</a>.</p>
            </fieldset>
            <p class="alert alert-info" role="note">Reminder: bring the Baptismal Certificate listed in the preparation checklist, marked “For Confirmation Purposes” and issued within the last 6 months.</p>
            <fieldset>
                <legend>Proposed Confirmation Sponsor</legend>
                <p class="field-hint">Provide the proposed sponsor’s name if known. The Parish Office will verify sponsor eligibility during the follow-up review; this form does not certify or approve a sponsor. One sponsor is required; a second sponsor is optional.</p>
                <label>Sponsor's full name *
                    <input type="text" name="sponsor1_full_name" autocomplete="name" value="<?= e(cfd('sponsor1_full_name', $formData) ?: compose_person_name($formData, 'sponsor1')) ?>" required>
                </label>
                <label>Second sponsor's full name (optional)
                    <input type="text" name="sponsor2_full_name" autocomplete="name" value="<?= e(cfd('sponsor2_full_name', $formData) ?: compose_person_name($formData, 'sponsor2')) ?>">
                </label>
            </fieldset>
        <?php elseif ($currentStep === CONF_STEP_CONTACT): ?>
            <fieldset>
                <legend>Phase 3 of 4 — Contact &amp; Consent</legend>
                <label>Contact Number *
                    <input type="tel" name="contact_number" autocomplete="tel" value="<?= $wv('contact_number') ?>" required>
                </label>
                <label>Email Address *
                    <input type="email" name="email" autocomplete="email" value="<?= $wv('email') ?>" required>
                </label>
                <label class="checkbox-label">Privacy Consent * <span class="field-hint">(agree to the <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a> and parish use of these details for sacrament preparation)</span>
                    <input type="checkbox" name="privacy_consent" value="1" <?= (($formData['privacy_consent'] ?? '') === '1') ? 'checked' : '' ?> required>
                </label>
            </fieldset>
        <?php elseif ($currentStep === CONF_STEP_VERIFY): ?>
            <fieldset>
                <legend>Phase 4 of 4 — Review &amp; Submit</legend>
                <div class="confirmation-review-grid">
                    <section class="confirmation-review-section">
                        <h3>Applicant &amp; preparation</h3>
                        <dl class="confirmation-review-details">
                            <div><dt>Applicant</dt><dd><?= e(compose_person_name($formData, 'applicant')) ?></dd></div>
                            <div><dt>Date of birth</dt><dd><?= $reviewBirthDateTimestamp !== false ? e(date('F j, Y', $reviewBirthDateTimestamp)) : '—' ?></dd></div>
                            <div><dt>Place of birth</dt><dd><?= $wv('applicant_birthplace') !== '' ? $wv('applicant_birthplace') : '—' ?></dd></div>
                            <div><dt>Preparation type</dt><dd><?= cfd('formation_path', $formData) === 'batch' ? 'Group preparation' : 'Individual / catch-up preparation' ?></dd></div>
                            <?php if (cfd('formation_path', $formData) === 'batch'): ?>
                                <div><dt>Group / organization</dt><dd><?= $wv('group_label') !== '' ? $wv('group_label') : '—' ?></dd></div>
                                <div><dt>Calendar year</dt><dd><?= $wv('school_year') !== '' ? $wv('school_year') : '—' ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    </section>
                    <section class="confirmation-review-section">
                        <h3>Family &amp; guardian</h3>
                        <dl class="confirmation-review-details">
                            <div><dt>Mother (maiden name)</dt><dd><?= e(conf_na_name($formData, 'mother')) ?></dd></div>
                            <div><dt>Father</dt><dd><?= e(compose_person_name($formData, 'father')) ?: '—' ?></dd></div>
                            <div><dt>Guardian / representative</dt><dd><?= e(compose_person_name($formData, 'parent_guardian')) ?: '—' ?></dd></div>
                            <div><dt>Guardian relationship</dt><dd><?= $reviewGuardianRelationship !== '' ? e($reviewGuardianRelationship) : '—' ?></dd></div>
                        </dl>
                    </section>
                    <section class="confirmation-review-section">
                        <h3>Baptism &amp; sponsors</h3>
                        <dl class="confirmation-review-details">
                            <div><dt>Baptism record source</dt><dd><?= e($reviewBaptismStatus) ?></dd></div>
                            <div><dt>Baptism parish / church</dt><dd><?= cfd('baptized_here', $formData) === 'elsewhere' && cfd('baptism_parish', $formData) !== '' ? $wv('baptism_parish') : '—' ?></dd></div>
                            <div><dt>Proposed sponsor</dt><dd><?= $reviewSponsor1 !== '' ? e($reviewSponsor1) : '—' ?></dd></div>
                            <div><dt>Second sponsor</dt><dd><?= $reviewSponsor2 !== '' ? e($reviewSponsor2) : '—' ?></dd></div>
                        </dl>
                    </section>
                    <section class="confirmation-review-section">
                        <h3>Contact</h3>
                        <dl class="confirmation-review-details">
                            <div><dt>Contact number</dt><dd><?= $wv('contact_number') !== '' ? $wv('contact_number') : '—' ?></dd></div>
                            <div><dt>Email address</dt><dd><?= $wv('email') !== '' ? $wv('email') : '—' ?></dd></div>
                        </dl>
                    </section>
                </div>
                <?php $applicationType = 'confirmation'; $requirementsNoticePart = 'after'; require __DIR__ . '/../../includes/public-requirements-notice.php'; unset($requirementsNoticePart); ?>
                <p class="alert alert-info online-intake-notice" role="note">This form starts the Parish Office review. The office reviews preparation needs and assigns reviewed candidates to a group or individual catch-up path. Submitting it does not confirm a group or schedule.</p>
                <?php if ($ageAssessment !== null): ?>
                    <p class="alert alert-info" role="note">
                        <strong>Age guidance:</strong> The applicant is <?= (int) $ageAssessment['age'] ?> years old.
                        The minimum age is <?= (int) $ageAssessment['range']['min_age'] ?> years;
                        assessment: <strong><?= e($ageAssessment['status']) ?></strong>.
                        Applicants below the minimum age cannot continue with this pre-application.
                    </p>
                <?php endif; ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="certify_accuracy" value="1" <?= (($formData['certify_accuracy'] ?? '') === '1') ? 'checked' : '' ?> required>
                    I certify that the information provided is true and correct.
                </label>
            </fieldset>
        <?php endif; ?>
        <div class="form-actions">
            <?php if ($currentStep > CONF_STEP_DETAILS): ?>
                <button type="submit" name="wizard_action" value="back" onclick="this.form.elements['_wizard_action'].value='back'" formnovalidate class="btn btn-secondary">Back</button>
            <?php endif; ?>
            <?php if ($currentStep < CONF_STEP_COUNT): ?>
                <button type="submit" name="wizard_action" value="next" class="btn btn-primary"><?= $currentStep === CONF_STEP_CONTACT ? 'Review Pre-Application' : 'Next' ?></button>
            <?php else: ?>
                <button type="submit" name="wizard_action" value="final_submit" onclick="this.form.elements['_wizard_action'].value='final_submit'" class="btn btn-primary">Submit Pre-Application</button>
            <?php endif; ?>
        </div>
    </form>
    <hr>
    <p class="form-footer">Fields marked with * are required. Submitting creates a confirmation pre-application for parish office review.</p>
<?php endif; ?>
</section>
<script>
(function () {
    var form = document.querySelector('form');
    var pathway = form && form.querySelector('[data-confirmation-formation]');
    var fields = form && form.querySelector('[data-confirmation-group-fields]');
    if (!pathway || !fields) return;
    var sync = function () {
        var group = pathway.value === 'batch';
        fields.hidden = !group;
        fields.querySelectorAll('input').forEach(function (input) {
            input.disabled = !group;
            input.required = group && input.name === 'school_year';
        });
    };
    pathway.addEventListener('change', sync);
    sync();
}());
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
