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
    3 => 'Baptism details',
    4 => 'Consent',
    5 => 'Review & submit',
];
if (!isset($_SESSION['first_communion_app']) || !is_array($_SESSION['first_communion_app'])) {
    $_SESSION['first_communion_app'] = ['current_step' => 1, 'form_data' => []];
}
$wizard = &$_SESSION['first_communion_app'];
$formData = &$wizard['form_data'];
if (($formData['formation_path'] ?? '') === 'batch') {
    $formData['formation_path'] = 'parish_based';
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
$wizardAction = $_POST['_action'] ?? '';
$isWizardNavigation = $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($wizardAction, ['next', 'back'], true);

if ($isWizardNavigation && csrf_verify()) {
    $stepData = $_POST;
    unset($stepData['_action'], $stepData['csrf_token']);
    foreach ($stepData as $key => $value) {
        if (is_array($value)) {
            $formData[$key] = array_values(array_filter($value, 'is_scalar'));
        } elseif (is_scalar($value)) {
            $formData[$key] = (string) $value;
        }
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
        $value = static function (string $key): string {
            return is_scalar($_POST[$key] ?? null) ? trim((string) $_POST[$key]) : '';
        };
        if ($currentStep === 1) {
            $applicantName = compose_person_name($_POST, 'applicant');
            if ($value('applicant_first') === '') $errors[] = "Applicant's first name is required.";
            if ($value('applicant_last') === '') $errors[] = "Applicant's last name is required.";
            if (($e = validate_full_name($applicantName)) !== null) $errors[] = $e;
            if ($value('applicant_birthdate') === '') $errors[] = "Applicant's birthdate is required.";
            if ($value('applicant_birthdate') !== '' && is_future_date($value('applicant_birthdate'))) $errors[] = "Applicant's birthdate cannot be in the future.";
            if (($e = first_communion_formation_input_error(
                $value('formation_path'),
                $value('school_grade'),
                $value('school_year')
            )) !== null) $errors[] = $e;
        } elseif ($currentStep === 2) {
            $parentName = compose_person_name($_POST, 'parent_guardian');
            if ($parentName === '') $errors[] = 'Parent/Guardian name is required.';
            if (($e = validate_full_name($parentName)) !== null) $errors[] = $e;
            foreach (['father' => "Father's name", 'mother' => "Mother's maiden name"] as $prefix => $label) {
                if (($e = validate_parent_name_or_na($_POST, $prefix, $label)) !== null) $errors[] = $e;
            }
            if ($value('contact_number') !== '' && !is_valid_phone_number($value('contact_number'))) $errors[] = 'Enter a valid contact number when provided.';
            if (!filter_var($value('email'), FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        } elseif ($currentStep === 3) {
            if ($value('baptized_here') === 'not_baptized') {
                $errors[] = 'A candidate must be baptized in the Catholic Church before receiving First Communion. Please contact the Parish Office about Baptism or OCIA.';
            } elseif (!in_array($value('baptized_here'), ['this_parish', 'elsewhere', 'not_sure'], true)) {
                $errors[] = 'Please answer where the applicant was baptized.';
            }
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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Your session expired. Please submit the form again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'final_submit' && $currentStep === count($steps) && csrf_verify()) {
    $submittedReview = $_POST;
    unset($submittedReview['_action'], $submittedReview['csrf_token']);
    $formData = array_merge($formData, $submittedReview);
    $formData['certify_accuracy'] = ($_POST['certify_accuracy'] ?? '') === '1' ? '1' : '0';
    $_POST = array_merge($formData, $_POST);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isWizardNavigation && csrf_verify() && ($_POST['_action'] ?? '') === 'final_submit' && $currentStep === count($steps)) {
    if (($formData['certify_accuracy'] ?? '') !== '1') {
        $errors[] = 'Please certify that the pre-application information is true and correct.';
    }
    $name = compose_person_name($formData, 'applicant');
    $birthdate = $_POST['applicant_birthdate'] ?? '';
    $parent = compose_person_name($_POST, 'parent_guardian');
    $father = compose_person_name($_POST, 'father');
    $mother = compose_person_name($_POST, 'mother');
    $contact = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $baptizedHere = $_POST['baptized_here'] ?? '';
    $formationPath = trim((string) ($formData['formation_path'] ?? ''));
    $schoolGrade = trim((string) ($formData['school_grade'] ?? ''));
    $schoolYearInput = trim((string) ($formData['school_year'] ?? ''));
    $schoolYear = $formationPath === 'individual_catchup' || $schoolYearInput === ''
        ? null
        : $schoolYearInput;
    $personId = null;

    if (trim((string) ($formData['applicant_first'] ?? '')) === '') $errors[] = "Applicant's first name is required.";
    if (trim((string) ($formData['applicant_last'] ?? '')) === '') $errors[] = "Applicant's last name is required.";
    if (($e = validate_full_name($name)) !== null) $errors[] = $e;
    if ($birthdate === '') $errors[] = "Applicant's birthdate is required.";
    if ($birthdate !== '' && is_future_date($birthdate)) $errors[] = "Applicant's birthdate cannot be in the future.";
    if ($parent === '') $errors[] = 'Parent/Guardian name is required.';
    if (($e = validate_full_name($parent)) !== null) $errors[] = $e;
    foreach (['father' => "Father's name", 'mother' => "Mother's maiden name"] as $prefix => $label) {
        if (($e = validate_parent_name_or_na($_POST, $prefix, $label)) !== null) $errors[] = $e;
    }
    if ($contact !== '' && !is_valid_phone_number($contact)) $errors[] = 'Enter a valid contact number when provided.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if ($baptizedHere === 'not_baptized') {
        $errors[] = 'A candidate must be baptized in the Catholic Church before receiving First Communion. Please contact the Parish Office about Baptism or OCIA.';
    } elseif (!in_array($baptizedHere, ['this_parish', 'elsewhere', 'not_sure'], true)) {
        $errors[] = 'Please answer where the applicant was baptized.';
    }
    if (($e = first_communion_formation_input_error($formationPath, $schoolGrade, $schoolYearInput)) !== null) $errors[] = $e;
    if (($_POST['privacy_consent'] ?? '') !== '1') $errors[] = 'You must accept the Privacy Notice before submitting.';
    if (!empty($formData['applicant_person_id'])) {
        try {
            $personId = public_selected_person_id(
                $pdo,
                $formData['applicant_person_id'],
                (string) ($formData['applicant_first'] ?? ''),
                (string) ($formData['applicant_middle'] ?? ''),
                (string) ($formData['applicant_last'] ?? ''),
                (string) ($formData['applicant_suffix'] ?? ''),
                $birthdate
            );
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $cap = public_submission_allowed($pdo, [$email]);
        if (!$cap['ok']) $errors[] = $cap['reason'];
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
                'formation_path' => $formationPath,
                'school_grade' => $schoolGrade,
                'school_year' => $schoolYear,
            ];
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
            if ($channel === 'online' && mail_request_received($email, $trackingNumber, 'First Communion Application', $pdo, 'sacrament_applications', $applicationId, $requestNo)) log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
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
$ageAssessment = $currentStep === count($steps) && !empty($formData['applicant_birthdate'])
    ? sacrament_age_assessment($pdo, 'first_communion', calculate_age((string) $formData['applicant_birthdate']))
    : null;
require_once __DIR__ . '/../../includes/header.php';
function oldv(string $key): string { global $formData; return e(is_scalar($formData[$key] ?? null) ? (string) $formData[$key] : ''); }
function selectedv(string $key, string $value): string { global $formData; return (($formData[$key] ?? '') === $value) ? 'selected' : ''; }
?>
<section class="request-form">
    <div class="alert alert-info online-intake-notice">This form starts the parish review. The Parish Office reviews preparation needs and assigns reviewed candidates to a group or individual catch-up path. Submitting it does not confirm a group or schedule.</div>
    <h1>Pre-Application for the Sacrament of First Holy Communion</h1>
    <?php if ($trackingNumber): ?>
        <?php
        $successHeading = 'Your pre-application has been submitted';
        $successIsApplication = true;
        $successFormationGuidance = 'The Parish Office will review the submitted grade, school year, and requested pathway, then assign a group or individual catch-up path. First Reconciliation and parent/guardian formation commitment are follow-up preparation steps; this submission does not confirm a group or date.';
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
                        <label>Date of birth * <input type="date" name="applicant_birthdate" max="<?= date('Y-m-d') ?>" value="<?= oldv('applicant_birthdate') ?>" required></label>
                        <div class="inline-fields">
                            <label>First Name * <input type="text" name="applicant_first" autocomplete="given-name" value="<?= oldv('applicant_first') ?>" required></label>
                            <label>Middle Name <span class="field-hint">(optional)</span> <input type="text" name="applicant_middle" autocomplete="additional-name" value="<?= oldv('applicant_middle') ?>"></label>
                        </div>
                        <div class="inline-fields">
                            <label>Last Name * <input type="text" name="applicant_last" autocomplete="family-name" value="<?= oldv('applicant_last') ?>" required></label>
                            <label>Suffix <span class="field-hint">(optional)</span> <input type="text" name="applicant_suffix" autocomplete="honorific-suffix" value="<?= oldv('applicant_suffix') ?>"></label>
                        </div>
                        <h3 class="form-subheading">School and preparation</h3>
                        <?php
                        $formationPath = (string) ($formData['formation_path'] ?? '');
                        $isIndividualPath = $formationPath === 'individual_catchup';
                        $isSchoolBasedPath = $formationPath === 'school_based';
                        $hasFormationPath = in_array($formationPath, ['school_based', 'parish_based', 'individual_catchup', 'not_sure'], true);
                        $initialSchoolYear = (int) date('Y');
                        if ((int) date('n') < 6) $initialSchoolYear--;
                        $activeSchoolYear = $initialSchoolYear . '-' . ($initialSchoolYear + 1);
                        ?>
                        <label>Formation pathway * <select name="formation_path" data-formation-pathway data-active-school-year="<?= e($activeSchoolYear) ?>" required>
                            <option value="">-- Select --</option>
                            <option value="school_based" <?= selectedv('formation_path', 'school_based') ?>>School-based group (school batch / parochial school)</option>
                            <option value="parish_based" <?= selectedv('formation_path', 'parish_based') ?>>Parish-based / Sunday Catechism group</option>
                            <option value="individual_catchup" <?= selectedv('formation_path', 'individual_catchup') ?>>Individual catch-up / adult / special preparation</option>
                            <option value="not_sure" <?= selectedv('formation_path', 'not_sure') ?>>Not sure — please advise</option>
                        </select></label>
                        <p class="field-hint">The Parish Office assigns the actual group after reviewing the pre-application. Grade and school year help organize groups; neither alone determines eligibility or a sacrament date.</p>
                        <div class="inline-fields">
                            <label>Current grade level <span data-grade-required-marker<?= !$hasFormationPath || $isIndividualPath ? ' hidden' : '' ?>>*</span>
                                <select name="school_grade" data-formation-grade<?= $hasFormationPath && !$isIndividualPath ? ' required' : '' ?>>
                                    <option value=""><?= $isIndividualPath ? '-- Select if applicable --' : '-- Select --' ?></option>
                                    <?php foreach (first_communion_grade_options() as $gradeValue => $gradeLabel): ?>
                                        <option value="<?= e($gradeValue) ?>" <?= selectedv('school_grade', $gradeValue) ?>><?= e($gradeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label data-school-year-container<?= $isIndividualPath ? ' hidden' : '' ?>>
                                <span data-school-year-label><?= $isSchoolBasedPath ? 'School year' : 'School year (optional; format: 2026-2027)' ?></span>
                                <span data-school-year-required-marker<?= !$isSchoolBasedPath ? ' hidden' : '' ?>>*</span>
                                <input type="text" name="school_year" data-formation-school-year
                                       pattern="\d{4}-\d{4}" maxlength="9"
                                       placeholder="<?= e($activeSchoolYear) ?>"
                                       value="<?= $isIndividualPath ? '' : oldv('school_year') ?>"
                                       <?= $isSchoolBasedPath ? 'required' : '' ?><?= $isIndividualPath ? ' disabled' : '' ?>>
                            </label>
                        </div>
                        <input type="hidden" name="applicant_person_id" value="<?= oldv('applicant_person_id') ?>">
                        <input type="hidden" name="selected_father_name" value="<?= oldv('selected_father_name') ?>">
                        <input type="hidden" name="selected_mother_maiden_name" value="<?= oldv('selected_mother_maiden_name') ?>">
                    </fieldset>
                <?php elseif ($currentStep === 2): ?>
                    <fieldset><legend>Phase 2 of 5 — Family &amp; Contact</legend>
                        <?= person_name_fields('father', "Father's name", $formData, true, null, 'required: enter the name, or enter N/A if unknown', true) ?>
                        <?= person_name_fields('mother', "Mother's maiden name", $formData, true, null, 'required: enter the name, or enter N/A if unknown', false) ?>
                        <?= person_name_fields('parent_guardian', 'Parent/Guardian', $formData) ?>
                        <div class="inline-fields"><label>Contact Number <span class="field-hint">(optional; email is used for updates)</span><input type="tel" name="contact_number" value="<?= oldv('contact_number') ?>"></label><label>Email * <input type="email" name="email" value="<?= oldv('email') ?>" required></label></div>
                    </fieldset>
                <?php elseif ($currentStep === 3): ?>
                    <fieldset><legend>Phase 3 of 5 — Sacramental Record</legend>
                        <label>Where was the applicant baptized? * <select name="baptized_here" required><option value="">-- Select --</option><option value="this_parish" <?= selectedv('baptized_here', 'this_parish') ?>>This parish</option><option value="elsewhere" <?= selectedv('baptized_here', 'elsewhere') ?>>Another parish/church</option><option value="not_sure" <?= selectedv('baptized_here', 'not_sure') ?>>Not sure — please help us check</option><option value="not_baptized" <?= selectedv('baptized_here', 'not_baptized') ?>>Not yet baptized in the Catholic Church</option></select></label>
                        <p class="field-hint">If you do not know where the applicant was baptized, the parish office can help check.</p>
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
                            <tr><th>Current grade</th><td><?= e(first_communion_grade_options()[(string) ($formData['school_grade'] ?? '')] ?? '') ?></td></tr>
                            <tr><th>School year</th><td><?= oldv('school_year') !== '' ? oldv('school_year') : '—' ?></td></tr>
                            <tr><th>Parents</th><td><?= e(compose_person_name($formData, 'father')) ?> · <?= e(compose_person_name($formData, 'mother')) ?></td></tr>
                            <tr><th>Parent / Guardian</th><td><?= e(compose_person_name($formData, 'parent_guardian')) ?></td></tr>
                            <tr><th>Contact</th><td><?= oldv('contact_number') ?> · <?= oldv('email') ?></td></tr>
                            <tr><th>Baptized at</th><td><?= e((string) ($formData['baptized_here'] ?? '')) ?></td></tr>
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
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
