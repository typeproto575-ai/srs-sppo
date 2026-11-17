<?php
/** Philippine parish First Holy Communion application. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';

$basePath = base_path();
$pageTitle = 'First Holy Communion Pre-Application';
$errors = [];
$trackingNumber = null;
$hasVerifiedBaptismRecord = false;
$scalarString = static function (array $source, string $key): string {
    $value = $source[$key] ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
};

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (($_GET['reset'] ?? '') === '1') {
    unset($_SESSION['first_communion_app']);
    header('Location: first-communion.php');
    exit;
}

$steps = [
    1 => 'Child information',
    2 => 'Family & contact',
    3 => 'Baptism & Communion details',
    4 => 'Consent',
    5 => 'Review & submit',
];
if (!isset($_SESSION['first_communion_app']) || !is_array($_SESSION['first_communion_app'])) {
    $_SESSION['first_communion_app'] = ['current_step' => 1, 'form_data' => []];
}
$wizard = &$_SESSION['first_communion_app'];
$formData = &$wizard['form_data'];
unset($formData['mother_na']);
if (($formData['formation_path'] ?? '') === 'batch') {
    $formData['formation_path'] = 'parish_based';
}
if (($_GET['start'] ?? '') === 'profile') {
    if ((current_user()['role'] ?? '') !== 'secretary') {
        http_response_code(403);
        exit('Only parish secretaries can start a profile-linked walk-in pre-application.');
    }
    $personId = filter_var(
        is_scalar($_GET['person_id'] ?? null) ? $_GET['person_id'] : null,
        FILTER_VALIDATE_INT
    );
    try {
        $person = $personId ? public_application_profile($pdo, (int) $personId) : null;
    } catch (Throwable $e) {
        error_log('First Communion profile prefill failed: ' . $e->getMessage());
        $person = null;
        $errors[] = 'The selected parishioner profile could not be loaded. Please try again or enter the details manually.';
    }
    if (!$person && !$errors) {
        $errors[] = 'The selected parishioner profile could not be found. Search for the person again.';
    } elseif ($person) {
        $formData = array_merge(public_application_profile_fields($person, 'applicant'), [
            'contact_number' => (string) ($person['contact_number'] ?? ''),
            'email' => (string) ($person['email'] ?? ''),
        ]);
        $wizard['current_step'] = 1;
        session_write_close();
        header('Location: first-communion.php');
        exit;
    }
}
$currentStep = (int) ($wizard['current_step'] ?? 1);
if ($currentStep < 1 || $currentStep > count($steps)) {
    $currentStep = 1;
    $wizard['current_step'] = 1;
}
$isPostRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$wizardAction = is_string($_POST['_action'] ?? null) ? trim($_POST['_action']) : '';
$isWizardNavigation = $isPostRequest && in_array($wizardAction, ['next', 'back'], true);
$hasMalformedPost = false;
$csrfValid = true;
if ($isPostRequest) {
    foreach ($_POST as $postedKey => $postedValue) {
        if ($postedKey === 'communion_sponsor_full_name') {
            if (!is_array($postedValue) || !array_is_list($postedValue)) {
                $hasMalformedPost = true;
                break;
            }
            foreach ($postedValue as $sponsorName) {
                if (!is_string($sponsorName)) {
                    $hasMalformedPost = true;
                    break 2;
                }
            }
            continue;
        }
        if (!is_scalar($postedValue)) {
            $hasMalformedPost = true;
            break;
        }
    }
    if ($hasMalformedPost) {
        $csrfValid = false;
        $errors[] = 'One or more form fields were submitted in an invalid format. Review your answers and try again.';
    } else {
        $csrfValid = csrf_verify();
        if (!$csrfValid) {
            $errors[] = 'Your session expired. Please submit the form again.';
        }
    }
}

if ($isWizardNavigation && $csrfValid && !$hasMalformedPost) {
    $stepData = $_POST;
    unset($stepData['_action'], $stepData['csrf_token']);
    foreach ($stepData as $key => $value) {
        if (is_array($value)) {
            $formData[$key] = array_values(array_filter($value, 'is_scalar'));
        } elseif (is_scalar($value)) {
            $formData[$key] = (string) $value;
        }
    }
    if ($currentStep === 1 && ($formData['formation_path'] ?? '') !== 'not_sure') {
        unset($formData['formation_path_note']);
    }
    if ($currentStep === 1 && ($formData['school_grade'] ?? '') !== 'other') {
        unset($formData['school_grade_other']);
    }
    if ($currentStep === 3 && ($formData['communion_place'] ?? '') !== 'other') {
        unset($formData['communion_place_other']);
    }
    if ($currentStep === 1 && ($formData['formation_path'] ?? '') === 'individual_catchup') {
        $formData['school_year'] = '';
    }
    public_prefill_person_parents($formData);
    if ($currentStep === 4) {
        $formData['privacy_consent'] = ($_POST['privacy_consent'] ?? '') === '1' ? '1' : '0';
    }

    if ($wizardAction === 'back') {
        $wizard['current_step'] = max(1, $currentStep - 1);
    } else {
        $value = static function (string $key) use ($scalarString): string {
            return $scalarString($_POST, $key);
        };
        if ($currentStep === 1) {
            $applicantName = compose_person_name($_POST, 'applicant');
            if ($value('applicant_first') === '') $errors[] = "Applicant's first name is required.";
            if ($value('applicant_last') === '') $errors[] = "Applicant's last name is required.";
            if (($e = validate_full_name($applicantName)) !== null) $errors[] = $e;
            if ($value('applicant_birthdate') === '') {
                $errors[] = "Applicant's birthdate is required.";
            } elseif (!parish_schedule_date_is_valid($value('applicant_birthdate'))) {
                $errors[] = 'Enter a valid date of birth.';
            } elseif ($value('applicant_birthdate') > date('Y-m-d')) {
                $errors[] = "Applicant's birthdate cannot be in the future.";
            }
            if (($e = first_communion_formation_input_error(
                $value('formation_path'),
                $value('school_grade'),
                $value('school_year')
            )) !== null) $errors[] = $e;
            if ($value('formation_path') === 'not_sure'
                && mb_strlen($value('formation_path_note')) > 500) {
                $errors[] = 'The preparation question must be 500 characters or fewer.';
            }
            if ($value('school_grade') === 'other' && $value('school_grade_other') === '') {
                $errors[] = 'Describe the applicant’s grade or school status.';
            } elseif ($value('school_grade') === 'other' && mb_strlen($value('school_grade_other')) > 150) {
                $errors[] = 'The current grade description must be 150 characters or fewer.';
            }
        } elseif ($currentStep === 2) {
            $parentName = compose_person_name($_POST, 'parent_guardian');
            if ($parentName === '') $errors[] = 'Parent / Legal Guardian name is required.';
            if (($e = validate_full_name($parentName)) !== null) $errors[] = $e;
            foreach (['father' => "Father's name", 'mother' => "Mother's maiden name"] as $prefix => $label) {
                if (($e = validate_parent_name_or_na($_POST, $prefix, $label, $prefix !== 'mother')) !== null) $errors[] = $e;
            }
            if ($value('contact_number') === '') $errors[] = 'Enter a contact number.';
            elseif (!is_valid_phone_number($value('contact_number'))) $errors[] = 'Enter a valid contact number (e.g. 09171234567 or +639171234567).';
            if (!filter_var($value('email'), FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        } elseif ($currentStep === 3) {
            if ($value('baptized_here') === 'not_baptized') {
                $errors[] = 'A candidate must be baptized in the Catholic Church before receiving First Communion. Please contact the Parish Office about Baptism or OCIA.';
            } elseif (!in_array($value('baptized_here'), ['this_parish', 'elsewhere'], true)) {
                $errors[] = 'Please answer where the applicant was baptized.';
            } elseif ($value('baptized_here') === 'elsewhere' && $value('baptism_parish') === '') {
                $errors[] = 'Enter the name and location of the parish or church where the applicant was baptized.';
            } elseif ($value('baptized_here') === 'elsewhere' && mb_strlen($value('baptism_parish')) > 255) {
                $errors[] = 'The baptism parish/church and location must be 255 characters or fewer.';
            }
            if (($e = first_communion_place_input_error(
                $value('communion_place'),
                $value('communion_place_other')
            )) !== null) $errors[] = $e;
            $errors = array_merge(
                $errors,
                validate_person_name_rows($_POST, 'communion_sponsor', 'Godparent/sponsor')
            );
        } elseif ($currentStep === 4) {
            if (($formData['privacy_consent'] ?? '') !== '1') $errors[] = 'You must accept the Privacy Notice before continuing.';
        }

        if (!$errors) {
            $wizard['current_step'] = min(count($steps), $currentStep + 1);
        }
    }
    if (!$errors) {
        session_write_close();
        header('Location: first-communion.php');
        exit;
    }
} elseif ($isPostRequest && $csrfValid && !$hasMalformedPost
    && $wizardAction === 'final_submit' && $currentStep === count($steps)) {
    $submittedReview = $_POST;
    unset($submittedReview['_action'], $submittedReview['csrf_token']);
    $formData = array_merge($formData, $submittedReview);
    $formData['certify_accuracy'] = ($_POST['certify_accuracy'] ?? '') === '1' ? '1' : '0';
    $_POST = array_merge($formData, $_POST);
} elseif ($isPostRequest && $csrfValid && !$hasMalformedPost) {
    $errors[] = 'This form action could not be processed. Refresh the page and use the form buttons to continue.';
}

if ($isPostRequest && !$isWizardNavigation && $csrfValid && !$hasMalformedPost
    && $wizardAction === 'final_submit' && $currentStep === count($steps)) {
    if (($formData['certify_accuracy'] ?? '') !== '1') {
        $errors[] = 'Please certify that the pre-application information is true and correct.';
    }
    $name = compose_person_name($formData, 'applicant');
    $birthdate = $scalarString($formData, 'applicant_birthdate');
    $parent = compose_person_name($formData, 'parent_guardian');
    $father = compose_person_name($formData, 'father');
    $mother = compose_person_name($formData, 'mother');
    $contact = $scalarString($formData, 'contact_number');
    $email = $scalarString($formData, 'email');
    $baptizedHere = $scalarString($formData, 'baptized_here');
    $baptismParish = $scalarString($formData, 'baptism_parish');
    $communionPlace = $scalarString($formData, 'communion_place');
    $communionPlaceOther = $scalarString($formData, 'communion_place_other');
    $formationPath = $scalarString($formData, 'formation_path');
    $formationPathNote = $scalarString($formData, 'formation_path_note');
    $schoolGrade = $scalarString($formData, 'school_grade');
    $schoolGradeOther = $scalarString($formData, 'school_grade_other');
    $schoolYearInput = $scalarString($formData, 'school_year');
    $schoolYear = $formationPath === 'individual_catchup' || $schoolYearInput === ''
        ? null
        : $schoolYearInput;
    $communionSponsorNames = compose_person_name_rows($formData, 'communion_sponsor');
    $personId = null;

    if (trim((string) ($formData['applicant_first'] ?? '')) === '') $errors[] = "Applicant's first name is required.";
    if (trim((string) ($formData['applicant_last'] ?? '')) === '') $errors[] = "Applicant's last name is required.";
    if (($e = validate_full_name($name)) !== null) $errors[] = $e;
    if ($birthdate === '') {
        $errors[] = "Applicant's birthdate is required.";
    } elseif (!parish_schedule_date_is_valid($birthdate)) {
        $errors[] = 'Enter a valid date of birth.';
    } elseif ($birthdate > date('Y-m-d')) {
        $errors[] = "Applicant's birthdate cannot be in the future.";
    }
    if ($parent === '') $errors[] = 'Parent/Guardian name is required.';
    if (($e = validate_full_name($parent)) !== null) $errors[] = $e;
    foreach (['father' => "Father's name", 'mother' => "Mother's maiden name"] as $prefix => $label) {
        if (($e = validate_parent_name_or_na($_POST, $prefix, $label, $prefix !== 'mother')) !== null) $errors[] = $e;
    }
    if ($contact === '') $errors[] = 'Enter a contact number.';
    elseif (!is_valid_phone_number($contact)) $errors[] = 'Enter a valid contact number (e.g. 09171234567 or +639171234567).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if ($baptizedHere === 'not_baptized') {
        $errors[] = 'A candidate must be baptized in the Catholic Church before receiving First Communion. Please contact the Parish Office about Baptism or OCIA.';
    } elseif (!in_array($baptizedHere, ['this_parish', 'elsewhere'], true)) {
        $errors[] = 'Please answer where the applicant was baptized.';
    } elseif ($baptizedHere === 'elsewhere' && $baptismParish === '') {
        $errors[] = 'Enter the name and location of the parish or church where the applicant was baptized.';
    } elseif ($baptizedHere === 'elsewhere' && mb_strlen($baptismParish) > 255) {
        $errors[] = 'The baptism parish/church and location must be 255 characters or fewer.';
    }
    if (($e = first_communion_place_input_error($communionPlace, $communionPlaceOther)) !== null) {
        $errors[] = $e;
    }
    $errors = array_merge(
        $errors,
        validate_person_name_rows($formData, 'communion_sponsor', 'Godparent/sponsor')
    );
    if (($e = first_communion_formation_input_error($formationPath, $schoolGrade, $schoolYearInput)) !== null) $errors[] = $e;
    if ($formationPath === 'not_sure' && mb_strlen($formationPathNote) > 500) {
        $errors[] = 'The preparation question must be 500 characters or fewer.';
    }
    if ($schoolGrade === 'other' && $schoolGradeOther === '') {
        $errors[] = 'Describe the applicant’s grade or school status.';
    } elseif ($schoolGrade === 'other' && mb_strlen($schoolGradeOther) > 150) {
        $errors[] = 'The current grade description must be 150 characters or fewer.';
    }
    if (($_POST['privacy_consent'] ?? '') !== '1') $errors[] = 'You must accept the Privacy Notice before submitting.';
    if ($scalarString($formData, 'applicant_person_id') !== '') {
        try {
            $personId = public_selected_person_id(
                $pdo,
                $scalarString($formData, 'applicant_person_id'),
                $scalarString($formData, 'applicant_first'),
                $scalarString($formData, 'applicant_middle'),
                $scalarString($formData, 'applicant_last'),
                $scalarString($formData, 'applicant_suffix'),
                $birthdate
            );
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('First Communion profile verification failed: ' . $e->getMessage());
            $errors[] = 'The selected parishioner profile could not be verified. Please try again or remove the profile reference.';
        }
    }

    if (!$errors) {
        try {
            $cap = public_submission_allowed($pdo, [$email]);
            if (!$cap['ok']) $errors[] = $cap['reason'];
        } catch (Throwable $e) {
            error_log('First Communion submission limit check failed: ' . $e->getMessage());
            $errors[] = 'The submission limit could not be checked. Please try again.';
        }
    }
    $isSecretary = (current_user()['role'] ?? '') === 'secretary';
    $channel     = $isSecretary ? 'onsite' : 'online';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $ageAssessment = sacrament_age_assessment($pdo, 'first_communion', calculate_age($birthdate));
            $requestNo = generate_request_no($pdo, 'sacrament_applications');
            $personProfile = public_person_reference($pdo, $personId, [
                'first_name' => (string) ($formData['applicant_first'] ?? ''),
                'middle_name' => (string) ($formData['applicant_middle'] ?? ''),
                'last_name' => (string) ($formData['applicant_last'] ?? ''),
                'suffix' => (string) ($formData['applicant_suffix'] ?? ''),
                'birth_date' => $birthdate,
                'father_name' => $father,
                'mother_maiden_name' => $mother,
            ]);
            $personId = $personProfile['person_id'];
            $baptismRecordStmt = $pdo->prepare(
                "SELECT 1
                 FROM sacrament_records
                 WHERE person_id = ? AND sacrament_type = 'baptism'
                   AND book_verified_at IS NOT NULL
                 LIMIT 1"
            );
            $baptismRecordStmt->execute([$personId]);
            $hasVerifiedBaptismRecord = $baptismRecordStmt->fetchColumn() !== false;
            public_assert_no_open_sacrament_application(
                $pdo,
                'first_communion',
                [$personId],
                $personProfile['reference']
            );
            $details = [
                'candidate_name' => $name,
                'candidate_birth_date' => $birthdate,
                'age_at_submission' => $ageAssessment['age'],
                'age_eligibility_status' => $ageAssessment['status'],
                'father_name' => $father,
                'mother_maiden_name' => $mother,
                'parent_guardian_name' => $parent,
                'baptized_here' => $baptizedHere,
                'baptism_parish' => $baptizedHere === 'elsewhere' ? $baptismParish : '',
                'communion_place' => $communionPlace,
                'communion_place_other' => $communionPlace === 'other' ? $communionPlaceOther : '',
                'formation_path' => $formationPath,
                'formation_path_note' => $formationPath === 'not_sure' ? $formationPathNote : '',
                'school_grade' => $schoolGrade,
                'school_grade_other' => $schoolGrade === 'other' ? $schoolGradeOther : '',
                'school_year' => $schoolYear,
            ];
            if ($communionSponsorNames !== []) {
                $details['godparent_sponsor_names'] = $communionSponsorNames;
            }
            $requirements = [
                'origin_baptismal_certificate' => [
                    'status' => $hasVerifiedBaptismRecord ? 'not_applicable' : 'not_received',
                    'note' => '',
                ],
                'first_reconciliation' => ['status' => 'not_received', 'note' => ''],
                'catechism_completion' => ['status' => 'not_received', 'note' => ''],
                'parent_guardian_commitment' => [
                    'status' => calculate_age($birthdate) < 18 ? 'not_received' : 'not_applicable',
                    'note' => '',
                ],
            ];
            $stmt = $pdo->prepare(
                "INSERT INTO sacrament_applications
                 (request_no, channel, sacrament_type, person_id, applicant_name, applicant_email,
                  applicant_contact, status, details, requirements, privacy_consent_at)
                 VALUES (?, ?, 'first_communion', ?, ?, ?, ?, 'new', ?, ?, NOW())"
            );
            $stmt->execute([
                $requestNo,
                $channel,
                $personId,
                $name,
                $email,
                $contact,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                json_encode($requirements, JSON_UNESCAPED_UNICODE),
            ]);
            $applicationId = (int) $pdo->lastInsertId();
            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, "First Communion pre-application submitted for {$name}");
            if ($channel === 'online') {
                notify($pdo, 'application_new', 'New sacrament pre-application', "First Communion pre-application for {$name} ({$requestNo})", '/admin/requests/applications-queue.php');
                record_public_submission($pdo, [$email], 'application_first_communion');
            }
            $pdo->commit();
            $trackingNumber = $personProfile['reference'];
            if ($isSecretary) {
                redirect_to('/admin/requests/applications-queue.php?added=' . rawurlencode($requestNo));
                exit;
            }
            if ($channel === 'online') {
                try {
                    if (mail_request_received($email, $trackingNumber, 'First Communion Application', $pdo, 'sacrament_applications', $applicationId, $requestNo)) {
                        log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
                    }
                } catch (Throwable $e) {
                    error_log('First Communion confirmation email failed: ' . $e->getMessage());
                }
            }
            unset($_SESSION['first_communion_app']);
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $trackingNumber = null;
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $trackingNumber = null;
            error_log('First Communion application failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while submitting your pre-application. Please try again.';
        }
    }
}
$ageAssessment = null;
$assessmentBirthdate = $scalarString($formData, 'applicant_birthdate');
if ($trackingNumber === null
    && $currentStep === count($steps)
    && $assessmentBirthdate !== ''
    && parish_schedule_date_is_valid($assessmentBirthdate)
    && $assessmentBirthdate <= date('Y-m-d')) {
    try {
        $ageAssessment = sacrament_age_assessment(
            $pdo,
            'first_communion',
            calculate_age($assessmentBirthdate)
        );
    } catch (Throwable $e) {
        error_log('First Communion age assessment failed: ' . $e->getMessage());
        $errors[] = 'Age guidance could not be loaded. Please try again.';
    }
}
require_once __DIR__ . '/../../includes/header.php';
function oldv(string $key): string { global $formData; return e(is_scalar($formData[$key] ?? null) ? (string) $formData[$key] : ''); }
function selectedv(string $key, string $value): string { global $formData; return (($formData[$key] ?? '') === $value) ? 'selected' : ''; }
?>
<section class="request-form">
    <div class="alert alert-info online-intake-notice">This form starts the parish review. The Parish Office reviews preparation needs and assigns reviewed candidates to a group or individual catch-up path. Submitting it does not confirm a group or schedule.</div>
    <h1>First Holy Communion Pre-Application</h1>
    <?php if ($trackingNumber): ?>
        <?php
        $successHeading = 'Your pre-application has been submitted';
        $successIsApplication = true;
        $successFormationGuidance = 'The Parish Office will review the submitted grade, target sacrament year, and requested pathway, then assign a group or individual catch-up path. First Reconciliation and parent/guardian formation commitment are follow-up preparation steps; this submission does not confirm a group or date.';
        $successRequestNumber = $requestNo;
        $successRequiredDocuments = sacrament_application_requirements('first_communion')['before'];
        if ($hasVerifiedBaptismRecord) {
            $successRequiredDocuments = array_values(array_filter(
                $successRequiredDocuments,
                static fn(string $document): bool => !str_contains($document, 'Baptismal Certificate')
            ));
        }
        require __DIR__ . '/../../includes/submission-success.php';
        ?>
    <?php else: ?>
        <?php if ($currentStep === 1): $applicationType = 'first_communion'; require __DIR__ . '/../../includes/public-requirements-notice.php'; endif; ?>
        <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <ol class="request-steps" aria-label="Pre-application progress">
                <?php foreach ($steps as $step => $label): ?>
                    <?php $state = $step === $currentStep ? 'is-current' : ($currentStep > $step ? 'is-done' : ''); ?>
                    <li class="<?= $state ?>"<?= $step === $currentStep ? ' aria-current="step"' : '' ?>><span><?= $step ?></span> <?= e($label) ?></li>
                <?php endforeach; ?>
            </ol>
            <h2 class="form-stage-heading">Pre-application form</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="<?= $currentStep < count($steps) ? 'next' : 'final_submit' ?>">
                <?php if ($currentStep === 1): ?>
                    <fieldset><legend>Phase 1 of 5 — Child's Information</legend>
                        <p class="field-hint">Your answers are kept in this browser only until you close it. Nothing is sent to the parish until you press Submit.</p>
                        <div class="public-person-lookup" data-public-person-lookup
                             data-lookup-context="preapplication"
                             data-endpoint="<?= e($basePath . '/public/certificate-request.php') ?>"
                             data-name-inputs="applicant_first,applicant_middle,applicant_last,applicant_suffix"
                             data-birth-field="applicant_birthdate" data-person-id-field="applicant_person_id"
                             data-father-value-field="selected_father_name" data-mother-value-field="selected_mother_maiden_name">
                            <h3 class="lookup-title">Already registered with the parish?</h3>
                            <p class="field-hint">Enter the child’s person reference to fill in recorded details and view linked sacrament records. Keep the reference private. Without a reference, fill in the form manually.</p>
                            <div data-person-results aria-live="polite"></div>
                        </div>
                        <h3 class="form-subheading">Child’s details</h3>
                        <div class="inline-fields">
                            <label>First Name * <input type="text" name="applicant_first" autocomplete="given-name" value="<?= oldv('applicant_first') ?>" required></label>
                            <label>Middle Name <span class="field-hint">(optional)</span> <input type="text" name="applicant_middle" autocomplete="additional-name" value="<?= oldv('applicant_middle') ?>"></label>
                        </div>
                        <div class="inline-fields">
                            <label>Last Name * <input type="text" name="applicant_last" autocomplete="family-name" value="<?= oldv('applicant_last') ?>" required></label>
                            <label>Suffix <span class="field-hint">(optional)</span> <input type="text" name="applicant_suffix" autocomplete="honorific-suffix" value="<?= oldv('applicant_suffix') ?>"></label>
                        </div>
                        <label>Date of birth * <span class="field-hint">A reference fills this from parish records when available; the date is needed for eligibility checks.</span>
                            <input type="date" name="applicant_birthdate" max="<?= date('Y-m-d') ?>" value="<?= oldv('applicant_birthdate') ?>" required>
                        </label>
                        <h3 class="form-subheading">School and preparation</h3>
                        <?php
                        $formationPath = (string) ($formData['formation_path'] ?? '');
                        $isIndividualPath = $formationPath === 'individual_catchup';
                        $hasFormationPath = in_array($formationPath, ['school_based', 'parish_based', 'individual_catchup', 'not_sure'], true);
                        $initialSchoolYear = (int) date('Y');
                        if ((int) date('n') < 6) $initialSchoolYear--;
                        $activeSchoolYear = (string) $initialSchoolYear;
                        ?>
                        <label>Formation pathway * <select name="formation_path" data-formation-pathway data-active-school-year="<?= e($activeSchoolYear) ?>" required>
                            <option value="">-- Select --</option>
                            <option value="school_based" <?= selectedv('formation_path', 'school_based') ?>>School-based group (school batch / parochial school)</option>
                            <option value="parish_based" <?= selectedv('formation_path', 'parish_based') ?>>Parish-based / Sunday Catechism group</option>
                            <option value="individual_catchup" <?= selectedv('formation_path', 'individual_catchup') ?>>Individual catch-up / adult / special preparation</option>
                            <option value="not_sure" <?= selectedv('formation_path', 'not_sure') ?>>Not sure — please advise</option>
                        </select></label>
                        <label data-show-if="formation_path=not_sure" data-show-if-required="false">What would you like help deciding? <span class="field-hint">(optional)</span>
                            <textarea name="formation_path_note" maxlength="500" rows="2" placeholder="Tell us what you are unsure about."><?= oldv('formation_path_note') ?></textarea>
                        </label>
                        <p class="field-hint">The Parish Office assigns the actual group after reviewing the pre-application. For numbered grades, select Grade 4 or above; lower grades are not accepted. Grade and target sacrament year help organize groups; neither alone determines a sacrament date.</p>
                        <div class="inline-fields">
                            <label>Current grade level <span data-grade-required-marker<?= !$hasFormationPath || $isIndividualPath ? ' hidden' : '' ?>>*</span>
                                <select name="school_grade" data-formation-grade<?= $hasFormationPath && !$isIndividualPath ? ' required' : '' ?>>
                                    <option value=""><?= $isIndividualPath ? '-- Select if applicable --' : '-- Select --' ?></option>
                                    <?php foreach (first_communion_grade_select_options() as $gradeValue => $gradeLabel): ?>
                                        <option value="<?= e($gradeValue) ?>" <?= selectedv('school_grade', $gradeValue) ?>><?= e($gradeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label data-school-year-container<?= $isIndividualPath ? ' hidden' : '' ?>>
                                <span data-school-year-label>Target sacrament year</span>
                                <span class="field-hint">Enter one year only (for example, <?= e($activeSchoolYear) ?>).</span>
                                <input type="text" name="school_year" data-formation-school-year
                                       pattern="[0-9]{4}" maxlength="4" inputmode="numeric"
                                       placeholder="<?= e($activeSchoolYear) ?>"
                                       value="<?= $isIndividualPath ? '' : oldv('school_year') ?>"
                                       <?= $isIndividualPath ? 'disabled' : '' ?>>
                            </label>
                        </div>
                        <label data-show-if="school_grade=other">Describe the applicant’s grade or school status *
                            <input type="text" name="school_grade_other" maxlength="150" value="<?= oldv('school_grade_other') ?>" placeholder="For example, adult, out of school, or unsure">
                        </label>
                        <input type="hidden" name="applicant_person_id" value="<?= oldv('applicant_person_id') ?>">
                        <input type="hidden" name="selected_father_name" value="<?= oldv('selected_father_name') ?>">
                        <input type="hidden" name="selected_mother_maiden_name" value="<?= oldv('selected_mother_maiden_name') ?>">
                    </fieldset>
                <?php elseif ($currentStep === 2): ?>
                    <fieldset><legend>Phase 2 of 5 — Family &amp; Contact</legend>
                        <?= person_name_fields('father', "Father's name", $formData, true, null, 'required: enter the name, or enter N/A if unknown', true) ?>
                        <?= person_name_fields('mother', "Mother's maiden name", $formData, true, null, 'required: enter the first and maiden last name', false) ?>
                        <?= person_name_fields('parent_guardian', 'Parent / Legal Guardian', $formData) ?>
                        <div class="inline-fields" style="margin-top: .5rem;">
                            <label class="checkbox-label" style="margin: 0;">
                                <input type="checkbox" data-guardian-quick-fill="father"> Same as Father
                            </label>
                            <label class="checkbox-label" style="margin: 0;">
                                <input type="checkbox" data-guardian-quick-fill="mother"> Same as Mother
                            </label>
                        </div>
                        <div class="inline-fields"><label>Contact Number * <input type="tel" name="contact_number" autocomplete="tel" value="<?= oldv('contact_number') ?>" required></label><label>Email * <input type="email" name="email" value="<?= oldv('email') ?>" required></label></div>
                    </fieldset>
                <?php elseif ($currentStep === 3): ?>
                    <fieldset><legend>Phase 3 of 5 — Baptism &amp; Communion Details</legend>
                        <label>Where was the applicant baptized? * <select name="baptized_here" required><option value="">-- Select --</option><option value="this_parish" <?= selectedv('baptized_here', 'this_parish') ?>>This parish</option><option value="elsewhere" <?= selectedv('baptized_here', 'elsewhere') ?>>Another parish/church</option><option value="not_baptized" <?= selectedv('baptized_here', 'not_baptized') ?>>Not yet baptized in the Catholic Church</option></select></label>
                        <label data-show-if="baptized_here=elsewhere">Parish/church name and location *
                            <input type="text" name="baptism_parish" maxlength="255" autocomplete="organization" placeholder="Parish/church, city or municipality, province" value="<?= oldv('baptism_parish') ?>">
                        </label>
                        <p class="field-hint">If the applicant was baptized elsewhere, provide the parish/church name and location. If you cannot identify it, contact the Parish Office before submitting.</p>
                        <label>Where will First Communion take place? *
                            <select name="communion_place" required>
                                <option value="">-- Select --</option>
                                <option value="parish_church" <?= selectedv('communion_place', 'parish_church') ?>>St. Patrick's Parish Church</option>
                                <option value="other" <?= selectedv('communion_place', 'other') ?>>Others</option>
                            </select>
                        </label>
                        <label data-show-if="communion_place=other">Specify the other place or venue *
                            <input type="text" name="communion_place_other" maxlength="150" value="<?= oldv('communion_place_other') ?>" placeholder="Enter the place or venue" required>
                        </label>
                        <?php
                        $communionSponsorRows = $formData['communion_sponsor_full_name'] ?? [];
                        $communionSponsorRows = is_array($communionSponsorRows) ? $communionSponsorRows : [];
                        $communionSponsorRowIndexes = range(0, max(0, count($communionSponsorRows) - 1));
                        ?>
                        <fieldset>
                            <legend>Godparents / Sponsors (optional)</legend>
                            <p class="field-hint">Add as many names as needed. Leave blank if no godparent or sponsor is being listed.</p>
                            <div data-repeat-group="communion-sponsors">
                                <?php foreach ($communionSponsorRowIndexes as $index): ?>
                                    <div class="godparent-row">
                                        <label>Godparent / Sponsor full name
                                            <input type="text" name="communion_sponsor_full_name[]" maxlength="150" value="<?= e(is_scalar($communionSponsorRows[$index] ?? null) ? (string) $communionSponsorRows[$index] : '') ?>" autocomplete="name">
                                        </label>
                                        <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $index === 0 ? ' hidden' : '' ?>>Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="communion-sponsors">Add another godparent / sponsor</button>
                        </fieldset>
                    </fieldset>
                <?php elseif ($currentStep === 4): ?>
                    <fieldset><legend>Phase 4 of 5 — Privacy Consent</legend>
                        <p class="field-hint">The Parish Office will discuss preparation, First Reconciliation, and parent/guardian commitment after reviewing this pre-application. These are follow-up steps, not conditions for submitting this form.</p>
                        <label class="checkbox-label"><input type="checkbox" name="privacy_consent" value="1" <?= ($formData['privacy_consent'] ?? '') === '1' ? 'checked' : '' ?> required> As the child's parent/guardian, I have read and agree to the <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a> covering the processing of my child's personal data.</label>
                    </fieldset>
                <?php else: ?>
                    <fieldset><legend>Phase 5 of 5 — Review Your Pre-Application</legend>
                        <table class="review-table">
                            <tr><th>Full name</th><td><?= e(compose_person_name($formData, 'applicant')) ?></td></tr>
                            <tr><th>Date of birth</th><td><?= oldv('applicant_birthdate') ?></td></tr>
                            <tr><th>Formation pathway</th><td><?= e(match ((string) ($formData['formation_path'] ?? '')) { 'school_based' => 'School-based group (school batch / parochial school)', 'parish_based' => 'Parish-based / Sunday Catechism group', 'individual_catchup' => 'Individual catch-up / adult / special preparation', default => 'Not sure — please advise' }) ?></td></tr>
                            <?php if (($formData['formation_path'] ?? '') === 'not_sure' && oldv('formation_path_note') !== ''): ?><tr><th>Preparation question</th><td><?= oldv('formation_path_note') ?></td></tr><?php endif; ?>
                            <tr><th>Current grade</th><td><?= e(first_communion_grade_options()[(string) ($formData['school_grade'] ?? '')] ?? '') ?></td></tr>
                            <?php if (($formData['school_grade'] ?? '') === 'other'): ?><tr><th>Grade details</th><td><?= oldv('school_grade_other') ?></td></tr><?php endif; ?>
                            <tr><th>Target sacrament year</th><td><?= oldv('school_year') !== '' ? oldv('school_year') : '—' ?></td></tr>
                            <tr><th>Parents</th><td><?= e(compose_person_name($formData, 'father')) ?> · <?= e(compose_person_name($formData, 'mother')) ?></td></tr>
                            <tr><th>Parent / Legal Guardian</th><td><?= e(compose_person_name($formData, 'parent_guardian')) ?></td></tr>
                            <tr><th>Contact</th><td><?= oldv('contact_number') ?> · <?= oldv('email') ?></td></tr>
                            <tr><th>Baptized at</th><td><?= e(match ((string) ($formData['baptized_here'] ?? '')) { 'this_parish' => 'This parish', 'elsewhere' => 'Another parish/church', 'not_baptized' => 'Not yet baptized in the Catholic Church', default => 'Not selected' }) ?></td></tr>
                            <?php if (($formData['baptized_here'] ?? '') === 'elsewhere'): ?><tr><th>Baptism parish/church and location</th><td><?= oldv('baptism_parish') ?></td></tr><?php endif; ?>
                            <tr><th>First Communion place</th><td><?= match ((string) ($formData['communion_place'] ?? '')) { 'other' => 'Other: ' . oldv('communion_place_other'), 'parish_church' => "St. Patrick's Parish Church", default => 'Not selected' } ?></td></tr>
                            <?php $reviewCommunionSponsors = compose_person_name_rows($formData, 'communion_sponsor'); ?>
                            <?php if ($reviewCommunionSponsors !== []): ?><tr><th>Godparents / Sponsors</th><td><?= e(implode(', ', $reviewCommunionSponsors)) ?></td></tr><?php endif; ?>
                        </table>
                        <?php if ($ageAssessment !== null): ?>
                            <p class="alert alert-info" role="note">
                                <strong>Age guidance:</strong> The applicant is <?= (int) $ageAssessment['age'] ?> years old.
                                Assessment: <strong><?= e($ageAssessment['status']) ?></strong>.
                                This is guidance only. The Parish Office decides eligibility.
                            </p>
                        <?php endif; ?>
                        <label class="checkbox-label"><input type="checkbox" name="certify_accuracy" value="1" <?= ($formData['certify_accuracy'] ?? '') === '1' ? 'checked' : '' ?> required> I certify that this information is true and correct.</label>
                    </fieldset>
                <?php endif; ?>
                <div class="form-actions">
                    <?php if ($currentStep > 1): ?><button type="submit" name="_action" value="back" onclick="this.form.querySelector('[name=_action]').value='back'" formnovalidate class="btn btn-secondary">Back</button><?php endif; ?>
                    <?php if ($currentStep < count($steps)): ?><button type="submit" name="_action" value="next" class="btn">Next</button><?php else: ?><button type="submit" name="_action" value="final_submit" class="btn">Submit Pre-Application</button><?php endif; ?>
                </div>
            </form>
            <script>
            (function () {
                var sourceTargets = {
                    father: ['father_first', 'father_middle', 'father_last', 'father_suffix', 'parent_guardian_first', 'parent_guardian_middle', 'parent_guardian_last', 'parent_guardian_suffix'],
                    mother: ['mother_first', 'mother_middle', 'mother_last', 'mother_suffix', 'parent_guardian_first', 'parent_guardian_middle', 'parent_guardian_last', 'parent_guardian_suffix']
                };
                document.querySelectorAll('[data-guardian-quick-fill]').forEach(function (checkbox) {
                    checkbox.addEventListener('change', function () {
                        if (!this.checked) return;
                        var role = this.getAttribute('data-guardian-quick-fill');
                        var source = sourceTargets[role];
                        if (!source) return;
                        var values = source.slice(0, 4).map(function (name) {
                            var field = document.querySelector('[name="' + name + '"]');
                            return field ? field.value : '';
                        });
                        var targets = source.slice(4);
                        targets.forEach(function (name, index) {
                            var field = document.querySelector('[name="' + name + '"]');
                            if (field) field.value = values[index] || '';
                        });
                    });
                });
            })();
            </script>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
