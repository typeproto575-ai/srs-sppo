<?php
/**
 * =====================================================================
 * SRS-SPPO BAPTISMAL SACRAMENT APPLICATION (public form)
 * =====================================================================
 * Purpose: Online pre-application for an unbaptized person seeking Baptism.
 *          Inserts an unverified intake row into the v2
 *          sacrament_applications table and ensures a master person profile
 *          with a reusable public reference.
 * Flow:    Enters as 'submitted'; the secretary reviews it in
 *          admin/requests/applications-queue.php. No online ID upload â€”
 *          identity is verified in person at pickup.
 * Limit:   Successful submissions are capped at 10 per email address and 40
 *          per client IP over a rolling 24 hours; the accounting row lives
 *          using the shared public-submission guard.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email

// Subfolder-safe URL prefix for links inside the success message.
$basePath = $basePath ?? base_path();

$pageTitle = 'Baptismal Pre-Application';
$errors    = [];
$trackingNumber = null;
$isLateBaptism = false;

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (($_GET['reset'] ?? '') === '1') {
    unset($_SESSION['baptismal_app']);
    header('Location: baptismal.php');
    exit;
}

const STEP_COUNT = 4;
const STEP_APP_DETAILS        = 1;
const STEP_FAMILY_REQS        = 2;
const STEP_CONTACT_CONSENT    = 3;
const STEP_FINAL_VERIFY       = 4;

// Initialize session data for the application flow
if (!isset($_SESSION['baptismal_app'])) {
    $_SESSION['baptismal_app'] = [
        'current_step' => STEP_APP_DETAILS,
        'form_data'    => [],
    ];
}

$app = &$_SESSION['baptismal_app'];
$currentStep = (int) $app['current_step'];
$formData    = &$app['form_data'];
unset($formData['guardian_relationship']);

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
        $formData = array_merge(public_application_profile_fields($person, 'child'), [
            'contact_number' => (string) ($person['contact_number'] ?? ''),
            'email' => (string) ($person['email'] ?? ''),
        ]);
        $app['current_step'] = STEP_APP_DETAILS;
        session_write_close();
        header('Location: baptismal.php');
        exit;
    }
}

// Stepper labels shared with certificate-request flows (visual parity).
$stepLabels = [
    STEP_APP_DETAILS     => 'Applicant information',
    STEP_FAMILY_REQS     => 'Family & godparents',
    STEP_CONTACT_CONSENT => 'Contact & consent',
    STEP_FINAL_VERIFY    => 'Review & submit',
];
$stepOrder = [STEP_APP_DETAILS, STEP_FAMILY_REQS, STEP_CONTACT_CONSENT, STEP_FINAL_VERIFY];

// Helper: get a string value from form data
function fd(string $key, array $data): string {
    return trim((string) ($data[$key] ?? ''));
}

// Helper: get an array value from form data
function fa(string $key, array $data): array {
    $val = $data[$key] ?? [];
    return is_array($val) ? $val : [];
}

function compose_godparent_names(array $data, string $prefix): array {
    return array_values(array_filter(
        array_map(static fn($name) => is_scalar($name) ? format_full_name((string) $name) : '', fa($prefix . '_full_name', $data)),
        static fn($name) => $name !== ''
    ));
}

function validate_godparent_names(array $data, string $prefix, string $label): array {
    $errors = [];
    foreach (fa($prefix . '_full_name', $data) as $name) {
        if (!is_scalar($name) || trim((string) $name) === '') continue;
        if (($error = validate_full_name(trim((string) $name))) !== null) {
            $errors[] = $label . ': ' . $error;
        }
    }
    return $errors;
}

// =====================================================================
// POST HANDLING â€” Navigation and Final Submit
// =====================================================================
function validate_baptism_parent_guardian(array $data): array
{
    $name = compose_person_name($data, 'parent_guardian');
    $authorizationConfirmed = baptism_guardian_authorization_confirmed($data);
    $errors = [];

    if ($name !== '' && ($error = validate_full_name($name)) !== null) {
        $errors[] = $error;
    }
    if ($name !== '' && !$authorizationConfirmed) {
        $errors[] = "If you list a guardian, confirm they are the child's legal guardian or have permission from the child's parent to submit this baptism pre-application.";
    }

    return array_values(array_unique($errors));
}

function baptism_guardian_authorization_confirmed(array $data): bool
{
    $authorization = $data['guardian_authorization_confirmed'] ?? '';
    return compose_person_name($data, 'parent_guardian') !== ''
        && is_string($authorization)
        && $authorization === '1';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['_action'] ?? null) ? trim($_POST['_action']) : '';

    // CSRF verification for all POSTs
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } elseif ($action === 'next' || $action === 'back') {
        if ($action === 'next') {
            $errors = [];

            if ($currentStep === STEP_APP_DETAILS) {
                $childName       = compose_person_name($_POST, 'child');
                $childBirthdate  = $_POST['child_birthdate'] ?? '';
                $childBirthplace = fd('child_birthplace',      $_POST);
                $childReportedSex = $_POST['child_sex_as_recorded'] ?? '';

                if (fd('child_first', $_POST) === '') $errors[] = "First name is required.";
                if (fd('child_last', $_POST) === '') $errors[] = "Last name is required.";
                if (($e = validate_full_name($childName)) !== null) $errors[] = $e;
                $applicantAge = baptism_application_age($childBirthdate);
                if ($childBirthdate === '') {
                    $errors[] = "Birthdate is required.";
                } elseif ($applicantAge === null) {
                    $errors[] = "Enter a valid date of birth.";
                }
                if ($childBirthdate !== '' && is_future_date($childBirthdate)) $errors[] = "Birthdate cannot be in the future.";
                if ($childBirthplace === '') $errors[] = "Birthplace is required.";
                if (!is_string($childReportedSex) || !in_array($childReportedSex, ['male', 'female'], true)) {
                    $errors[] = 'Select Male or Female as shown on the PSA/LCR birth certificate.';
                }
            }

            if ($currentStep === STEP_FAMILY_REQS) {
                $errors = array_merge($errors, validate_baptism_parent_guardian($_POST));
                foreach (['father' => "Father's name", 'mother' => "Mother's maiden name"] as $prefix => $label) {
                    if (($e = validate_parent_name_or_na($_POST, $prefix, $label, true)) !== null) $errors[] = $e;
                }
                $errors = array_merge(
                    $errors,
                    validate_godparent_names($_POST, 'godfather', 'Godfather'),
                    validate_godparent_names($_POST, 'godmother', 'Godmother')
                );
                if (compose_godparent_names($_POST, 'godfather') === []) {
                    $errors[] = 'At least one godfather is required.';
                }
                if (compose_godparent_names($_POST, 'godmother') === []) {
                    $errors[] = 'At least one godmother is required.';
                }
            }

            if ($currentStep === STEP_CONTACT_CONSENT) {
                $contactNumber = fd('contact_number', $_POST);
                $email         = fd('email', $_POST);
                $privacyConsent = $_POST['privacy_consent'] ?? '';

                if ($contactNumber === '') {
                    $errors[] = 'Please enter a contact number.';
                } elseif (!is_valid_phone_number($contactNumber)) {
                    $errors[] = 'Please enter a valid contact number (e.g. 09171234567 or +639171234567).';
                }
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Please enter a valid email address.';
                }
                if ($privacyConsent !== '1') $errors[] = 'You must agree to the Privacy Notice to continue.';
            }
        }

        if (empty($errors)) {
            if ($action === 'next') {
                $stepData = [];
                foreach ($_POST as $k => $v) {
                    if ($k === '_action' || $k === 'csrf_token') continue;
                    $stepData[$k] = $v;
                }
                if ($currentStep === STEP_FAMILY_REQS) {
                    $stepData['guardian_authorization_confirmed'] = baptism_guardian_authorization_confirmed($stepData) ? '1' : '';
                }
                $formData = array_merge($formData, $stepData);
                public_prefill_person_parents($formData);
                $app['current_step'] = min($currentStep + 1, STEP_COUNT);
            } else {
                $app['current_step'] = max($currentStep - 1, STEP_APP_DETAILS);
            }
            session_write_close();
            header('Location: ' . htmlspecialchars($_SERVER['REQUEST_URI']));
            exit;
        } elseif ($action === 'next') {
            $stepData = [];
            foreach ($_POST as $key => $value) {
                if ($key === '_action' || $key === 'csrf_token') continue;
                $stepData[$key] = $value;
            }
            if ($currentStep === STEP_FAMILY_REQS) {
                $stepData['guardian_authorization_confirmed'] = baptism_guardian_authorization_confirmed($stepData) ? '1' : '';
            }
            $formData = array_merge($formData, $stepData);
            public_prefill_person_parents($formData);
        }
    }
    // ------------------------------------------------------------------
    // FINAL SUBMIT from Phase 4 (verification step)
    // ------------------------------------------------------------------
    elseif ($action === 'final_submit' && $currentStep === STEP_FINAL_VERIFY) {
        $errors = [];

        $certify = $_POST['certify_accuracy'] ?? '';
        if ($certify !== '1') {
            $errors[] = 'You must certify that all information is true and correct before submitting.';
        }

        if (empty($errors)) {
            $childName       = compose_person_name($formData, 'child');
            $childBirthdate  = $formData['child_birthdate'] ?? '';
            $childBirthplace = fd('child_birthplace',      $formData);
            $childReportedSex = $formData['child_sex_as_recorded'] ?? '';
            $fatherName      = compose_person_name($formData, 'father');
            $motherMaidenName = compose_person_name($formData, 'mother');
            $parentGuardian  = compose_person_name($formData, 'parent_guardian');
            $contactNumber   = fd('contact_number',        $formData);
            $email           = fd('email',                 $formData);
            $preferredDate   = $formData['preferred_date'] ?? null;
            $privacyConsent  = $formData['privacy_consent'] ?? '';
            $isSecretary     = (current_user()['role'] ?? '') === 'secretary';
            $channel         = $isSecretary ? 'onsite' : 'online';

            $godfatherNames      = compose_godparent_names($formData, 'godfather');
            $godmotherNames      = compose_godparent_names($formData, 'godmother');
            $childPersonId = null;

            if (fd('child_first', $formData) === '') $errors[] = "First name is required.";
            if (fd('child_last', $formData) === '') $errors[] = "Last name is required.";
            if (($e = validate_full_name($childName)) !== null) $errors[] = $e;
            $applicantAge = baptism_application_age($childBirthdate);
            if ($childBirthdate === '') {
                $errors[] = "Birthdate is required.";
            } elseif ($applicantAge === null) {
                $errors[] = "Enter a valid date of birth.";
            }
            if ($childBirthdate !== '' && is_future_date($childBirthdate)) $errors[] = "Birthdate cannot be in the future.";
            if ($childBirthplace === '') $errors[] = "Birthplace is required.";
            if (!is_string($childReportedSex) || !in_array($childReportedSex, ['male', 'female'], true)) {
                $errors[] = 'Select Male or Female as shown on the PSA/LCR birth certificate.';
            }
            $errors = array_merge($errors, validate_baptism_parent_guardian($formData));
            if ($contactNumber === '') {
                $errors[] = 'Please enter a contact number.';
            } elseif (!is_valid_phone_number($contactNumber)) {
                $errors[] = 'Please enter a valid contact number (e.g. 09171234567 or +639171234567).';
            }
            if ($email === '')           $errors[] = 'Email address is required.';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            foreach (['father' => "Father's name", 'mother' => "Mother's maiden name"] as $prefix => $label) {
                if (($e = validate_parent_name_or_na($formData, $prefix, $label, true)) !== null) $errors[] = $e;
            }
            if (fd('child_person_id', $formData) !== '') {
                try {
                    $childPersonId = public_selected_person_id(
                        $pdo,
                        $formData['child_person_id'],
                        fd('child_first', $formData),
                        fd('child_middle', $formData),
                        fd('child_last', $formData),
                        fd('child_suffix', $formData),
                        $childBirthdate
                    );
                } catch (InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $errors = array_merge(
                $errors,
                validate_godparent_names($formData, 'godfather', 'Godfather'),
                validate_godparent_names($formData, 'godmother', 'Godmother')
            );
            if ($godfatherNames === []) $errors[] = 'At least one godfather is required.';
            if ($godmotherNames === []) $errors[] = 'At least one godmother is required.';

            if (empty($errors)) {
                $isLateBaptism = baptism_application_is_late($childBirthdate) === true;
                try {
                    $submissionCap = public_submission_allowed($pdo, [$email]);
                    if (!$submissionCap['ok']) {
                        $errors[] = 'You have reached the submission limit for this email address. Please try again later.';
                    }

                    if (empty($errors)) {
                        $pdo->beginTransaction();
                        $requestNo = generate_request_no($pdo, 'sacrament_applications');
                        $personProfile = public_person_reference($pdo, $childPersonId, [
                            'first_name' => fd('child_first', $formData),
                            'middle_name' => fd('child_middle', $formData),
                            'last_name' => fd('child_last', $formData),
                            'suffix' => fd('child_suffix', $formData),
                            'birth_date' => $childBirthdate,
                            'birth_place' => $childBirthplace,
                            'father_name' => $fatherName,
                            'mother_maiden_name' => $motherMaidenName,
                        ]);
                        $childPersonId = $personProfile['person_id'];
                        public_assert_no_open_sacrament_application(
                            $pdo,
                            'baptism',
                            [$childPersonId],
                            $personProfile['reference']
                        );
                        $godparents = [];
                        foreach (['godfather' => $godfatherNames, 'godmother' => $godmotherNames] as $role => $names) {
                            foreach ($names as $name) {
                                if (is_scalar($name) && trim((string) $name) !== '') {
                                    $godparents[] = ['role' => $role, 'full_name' => trim((string) $name)];
                                }
                            }
                        }
                        $details = [
                            'child_name' => $childName,
                            'child_birth_date' => $childBirthdate,
                            'child_birthplace' => $childBirthplace,
                            'father_name' => $fatherName,
                            'mother_maiden_name' => $motherMaidenName,
                            'parent_guardian_name' => $parentGuardian,
                            'godparents' => $godparents,
                        ];
                        if ($childReportedSex !== '') {
                            $details['child_sex_as_recorded'] = $childReportedSex;
                        }
                        if ($isLateBaptism) {
                            $details['late_baptism'] = true;
                            $details['age_at_submission'] = $applicantAge;
                        }
                        if ($parentGuardian !== '') {
                            $details['guardian_authorization_confirmed'] = true;
                        }
                        $requirements = [
                            'psa_birth_certificate' => ['status' => 'not_received', 'note' => ''],
                            'godparent_eligibility' => ['status' => 'not_received', 'note' => ''],
                            'guardianship_papers' => [
                                'status' => $fatherName === '' && $motherMaidenName === '' ? 'not_received' : 'not_applicable',
                                'note' => '',
                            ],
                        ];
                        $stmt = $pdo->prepare(
                            "INSERT INTO sacrament_applications
                             (request_no, channel, sacrament_type, person_id, applicant_name, applicant_email,
                              applicant_contact, preferred_date, status, details, requirements, privacy_consent_at)
                             VALUES (?, ?, 'baptism', ?, ?, ?, ?, ?, 'new', ?, ?, NOW())"
                        );
                        $stmt->execute([
                            $requestNo,
                            $channel,
                            $childPersonId,
                            $childName,
                            $email,
                            $contactNumber,
                            $preferredDate ?: null,
                            json_encode($details, JSON_UNESCAPED_UNICODE),
                            json_encode($requirements, JSON_UNESCAPED_UNICODE),
                        ]);
                        $applicationId = (int) $pdo->lastInsertId();

                        $applicationTypeLabel = $isLateBaptism ? 'Late Baptism' : 'Baptismal';
                        log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, "{$applicationTypeLabel} pre-application submitted for {$childName}");
                        if ($channel === 'online') {
                            $notificationTitle = $isLateBaptism ? 'New Late Baptism pre-application' : 'New sacrament pre-application';
                            notify($pdo, 'application_new', $notificationTitle, "{$applicationTypeLabel} pre-application for {$childName} ({$requestNo})", '/admin/requests/applications-queue.php');
                            record_public_submission($pdo, [$email], 'application_baptismal');
                        }
                        $pdo->commit();
                        $trackingNumber = $personProfile['reference'];

                        if ($isSecretary) {
                            redirect_to('/admin/requests/applications-queue.php?added=' . rawurlencode($requestNo));
                            exit;
                        }

                        try {
                            if ($channel === 'online' && mail_request_received($email, $trackingNumber, 'Baptismal Application', $pdo, 'sacrament_applications', $applicationId, $requestNo)) {
                                log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
                            }
                        } catch (InvalidArgumentException $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $trackingNumber = null;
                            $errors[] = $e->getMessage();
                        } catch (Throwable $e) {
                            error_log('Baptismal application confirmation email failed: ' . $e->getMessage());
                        }
                        unset($_SESSION['baptismal_app']);
                        $currentStep = STEP_COUNT + 1;
                    }
                } catch (InvalidArgumentException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $trackingNumber = null;
                    $errors[] = $e->getMessage();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $trackingNumber = null;
                    error_log('Baptismal application failed: ' . $e->getMessage());
                    $errors[] = 'Something went wrong while submitting your pre-application. Please try again.';
                }
            }
        }
    }
}

// Redirect on GET if step out of range
if ($currentStep < STEP_APP_DETAILS || $currentStep > STEP_COUNT + 1) {
    header('Location: baptismal.php');
    exit;
}

$reviewNames = static function (array $names): string {
    return e(implode(', ', array_filter(array_map(
        static fn($name) => is_scalar($name) ? trim((string) $name) : '',
        $names
    ))));
};
require_once __DIR__ . '/../../includes/header.php';
?><section class="application-form">
<?php
if ($currentStep === STEP_COUNT + 1): ?>
    <div class="success-panel">
        <h1>Pre-Application Submitted</h1>
        <?php
        $successHeading = 'Keep this reference number';
        $successIsApplication = true;
        $successRequestNumber = $requestNo;
        $successAfterSteps = sacrament_application_requirements('baptism')['after'];
        require __DIR__ . '/../../includes/submission-success.php';
        ?>
        <?php if ($isLateBaptism): ?>
            <p class="field-hint"><strong>Late Baptism (age 7+):</strong> The Parish Office will contact you about preparation and any additional requirements.</p>
        <?php endif; ?>
        <div class="submission-success-actions">
            <a href="baptismal.php?reset=1" class="btn">Start a New Pre-Application</a>
        </div>
    </div>
</section>
<?php elseif ($currentStep === STEP_APP_DETAILS): ?>
    <h1><?= e($pageTitle) ?></h1>
    <?php $applicationType = 'baptism'; require __DIR__ . '/../../includes/public-requirements-notice.php'; ?>
    <ol class="request-steps" aria-label="Progress">
        <?php foreach ($stepOrder as $i => $key): ?>
            <?php $state = $key === $currentStep ? 'is-current' : ($currentStep > $key ? 'is-done' : ''); ?>
            <li class="<?= $state ?>"<?= $key === $currentStep ? ' aria-current="step"' : '' ?>><span><?= $i + 1 ?></span> <?= e($stepLabels[$key]) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <h2 class="form-stage-heading">Pre-application form</h2>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="next">
        <fieldset>
            <legend>Phase 1 of 4 — Applicant Information</legend>
            <p class="field-hint">Your answers are kept in this browser only until you close it. Nothing is sent to the parish until you press Submit.</p>
            <p class="field-hint"><strong>Late Baptism:</strong> Applicants aged <?= BAPTISM_LATE_PATHWAY_MIN_AGE ?> or older are automatically marked as Late Baptism for staff review. The Parish Office will advise about preparation and any additional requirements.</p>
            <div class="public-person-lookup" data-public-person-lookup
                 data-lookup-context="preapplication"
                 data-endpoint="<?= e($basePath . '/public/certificate-request.php') ?>"
                 data-name-inputs="child_first,child_middle,child_last,child_suffix"
                 data-birth-field="child_birthdate" data-birth-place-field="child_birthplace" data-person-id-field="child_person_id"
                 data-father-value-field="selected_father_name" data-mother-value-field="selected_mother_maiden_name">
                <h3 class="lookup-title">Already registered with the parish?</h3>
                <p class="field-hint">Enter the applicant’s person reference to fill in recorded details. Keep the reference private. Without a reference, fill in the form manually.</p>
                <div data-person-results aria-live="polite"></div>
            </div>
            <h3 class="form-subheading">Applicant’s details</h3>
            <div class="inline-fields">
                <label>First Name *
                    <input type="text" name="child_first" autocomplete="given-name" value="<?= e(fd('child_first', $formData)) ?>" required>
                </label>
                <label>Middle Name <span class="field-hint">(optional)</span>
                    <input type="text" name="child_middle" autocomplete="additional-name" value="<?= e(fd('child_middle', $formData)) ?>">
                </label>
            </div>
            <div class="inline-fields">
                <label>Last Name *
                    <input type="text" name="child_last" autocomplete="family-name" value="<?= e(fd('child_last', $formData)) ?>" required>
                </label>
                <label>Suffix <span class="field-hint">(optional)</span>
                    <input type="text" name="child_suffix" autocomplete="honorific-suffix" value="<?= e(fd('child_suffix', $formData)) ?>">
                </label>
            </div>
            <label>Date of birth *
                <input type="date" name="child_birthdate" value="<?= e($formData['child_birthdate'] ?? '') ?>" required>
                <span class="field-hint">A reference fills this from parish records when available. The date determines whether this is marked as Late Baptism.</span>
            </label>
            <?php $reportedSexValue = is_string($formData['child_sex_as_recorded'] ?? null) ? $formData['child_sex_as_recorded'] : ''; ?>
            <label>Sex as recorded on PSA/LCR birth certificate *
                <select name="child_sex_as_recorded" required>
                    <option value="">-- Select --</option>
                    <option value="female"<?= $reportedSexValue === 'female' ? ' selected' : '' ?>>Female</option>
                    <option value="male"<?= $reportedSexValue === 'male' ? ' selected' : '' ?>>Male</option>
                </select>
                <span class="field-hint">Copy this from the PSA/LCR birth certificate. The Parish Office will verify it against the document; it will not update the parish profile automatically.</span>
            </label>
            <input type="hidden" name="child_person_id" value="<?= e(fd('child_person_id', $formData)) ?>">
            <input type="hidden" name="selected_father_name" value="<?= e(fd('selected_father_name', $formData)) ?>">
            <input type="hidden" name="selected_mother_maiden_name" value="<?= e(fd('selected_mother_maiden_name', $formData)) ?>">
            <label>Place of Birth *
                <input type="text" name="child_birthplace" value="<?= e(fd('child_birthplace', $formData)) ?>" required>
            </label>
            <label class="checkbox-label na-toggle"><input type="checkbox" data-na-target="child_birthplace"> Not available / unknown (N/A)</label>
        </fieldset>
        <div class="form-actions">
            <button type="submit" class="btn">Next</button>
        </div>
    </form>
</section>
<?php elseif ($currentStep === STEP_FAMILY_REQS): ?>
    <h1><?= e($pageTitle) ?></h1>
    <ol class="request-steps" aria-label="Progress">
        <?php foreach ($stepOrder as $i => $key): ?>
            <?php $state = $key === $currentStep ? 'is-current' : ($currentStep > $key ? 'is-done' : ''); ?>
            <li class="<?= $state ?>"<?= $key === $currentStep ? ' aria-current="step"' : '' ?>><span><?= $i + 1 ?></span> <?= e($stepLabels[$key]) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <h2 class="form-stage-heading">Pre-application form</h2>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="next">
        <fieldset>
            <legend>Phase 2 of 4 — Family Information</legend>
            <?= person_name_fields('father', "Father's name", $formData, true, null, 'required: enter the name or tick Not applicable', true, true) ?>
            <?= person_name_fields('mother', "Mother's maiden name", $formData, true, null, 'required: enter the first and maiden last name, or tick Not applicable', false, true) ?>
            <?= person_name_fields(
                'parent_guardian',
                'Legal Guardian / Authorized Representative',
                $formData,
                false,
                null,
                'optional; leave blank if no guardian should be listed'
            ) ?>
            <p class="field-hint">The Legal Guardian / Authorized Representative field is optional. Adults applying for themselves may leave it blank.</p>
            <label class="checkbox-label">
                <input type="checkbox" name="guardian_authorization_confirmed" value="1" <?= fd('guardian_authorization_confirmed', $formData) === '1' ? 'checked' : '' ?>>
                If I list a guardian, I confirm they are the child's legal guardian or have permission from the child's parent to submit this baptism pre-application.
            </label>
            <p class="field-hint">A guardian does not have to be related to the child. If you are not the child's legal guardian, you must have permission from the child's parent.</p>
            <?php
            $godfatherRows = max(1, count(fa('godfather_full_name', $formData)));
            $godmotherRows = max(1, count(fa('godmother_full_name', $formData)));
            ?>
            <fieldset>
                <legend>Godfathers (Ninong)</legend>
                <p class="field-hint">At least one godfather is required. Add up to 7 more if needed.</p>
                <div data-repeat-group="godfather" data-repeat-max="8">
                    <?php for ($i = 0; $i < $godfatherRows; $i++): ?>
                        <div class="godparent-row">
                            <label>Godfather's Full Name
                                <input type="text" name="godfather_full_name[<?= $i ?>]" value="<?= e(fa('godfather_full_name', $formData)[$i] ?? '') ?>" autocomplete="name"<?= $i === 0 ? ' required' : '' ?>>
                            </label>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godfather">Add another godfather</button>
            </fieldset>
            <fieldset>
                <legend>Godmothers (Ninang)</legend>
                <p class="field-hint">At least one godmother is required. Add up to 7 more if needed.</p>
                <div data-repeat-group="godmother" data-repeat-max="8">
                    <?php for ($i = 0; $i < $godmotherRows; $i++): ?>
                        <div class="godparent-row">
                            <label>Godmother's Full Name
                                <input type="text" name="godmother_full_name[<?= $i ?>]" value="<?= e(fa('godmother_full_name', $formData)[$i] ?? '') ?>" autocomplete="name"<?= $i === 0 ? ' required' : '' ?>>
                            </label>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godmother">Add another godmother</button>
            </fieldset>
        </fieldset>
        <div class="form-actions">
            <button type="submit" name="_action" value="back" onclick="this.form.querySelector('[name=_action]').value='back'" formnovalidate class="btn btn-secondary">Back</button>
            <button type="submit" class="btn">Next</button>
        </div>
    </form>
</section>
<?php elseif ($currentStep === STEP_CONTACT_CONSENT): ?>
    <h1><?= e($pageTitle) ?></h1>
    <ol class="request-steps" aria-label="Progress">
        <?php foreach ($stepOrder as $i => $key): ?>
            <?php $state = $key === $currentStep ? 'is-current' : ($currentStep > $key ? 'is-done' : ''); ?>
            <li class="<?= $state ?>"<?= $key === $currentStep ? ' aria-current="step"' : '' ?>><span><?= $i + 1 ?></span> <?= e($stepLabels[$key]) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <h2 class="form-stage-heading">Pre-application form</h2>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="next">
        <fieldset>
            <legend>Phase 3 of 4 — Contact & Consent</legend>
            <label>Contact Number *
                <input type="tel" name="contact_number" autocomplete="tel" value="<?= e(fd('contact_number', $formData)) ?>" required>
            </label>
            <label>Email Address
                <input type="email" name="email" value="<?= e(fd('email', $formData)) ?>" required>
            </label>
            <label>Preferred Date (optional)
                <input type="date" name="preferred_date" value="<?= e($formData['preferred_date'] ?? '') ?>">
            </label>
            <label class="checkbox-label">
                <input type="checkbox" name="privacy_consent" value="1" <?= (fd('privacy_consent', $formData) === '1') ? 'checked' : '' ?> required>
                I agree to the Privacy Notice
            </label>
        </fieldset>
        <div class="form-actions">
            <button type="submit" name="_action" value="back" onclick="this.form.querySelector('[name=_action]').value='back'" formnovalidate class="btn btn-secondary">Back</button>
            <button type="submit" class="btn">Review Pre-Application</button>
        </div>
    </form>
</section>
<?php elseif ($currentStep === STEP_FINAL_VERIFY): ?>
    <h1>Final Review</h1>
    <ol class="request-steps" aria-label="Progress">
        <?php foreach ($stepOrder as $i => $key): ?>
            <?php $state = $key === $currentStep ? 'is-current' : ($currentStep > $key ? 'is-done' : ''); ?>
            <li class="<?= $state ?>"<?= $key === $currentStep ? ' aria-current="step"' : '' ?>><span><?= $i + 1 ?></span> <?= e($stepLabels[$key]) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <h2 class="form-stage-heading">Pre-application form</h2>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="final_submit">
        <fieldset>
            <legend>Review Your Information</legend>
            <?php $reviewIsLateBaptism = baptism_application_is_late(fd('child_birthdate', $formData)) === true; ?>
            <?php $reviewReportedSex = $formData['child_sex_as_recorded'] ?? ''; ?>
            <table class="review-table">
                <tr><th>Applicant's Full Name</th><td><?= e(compose_person_name($formData, 'child')) ?></td></tr>
                <tr><th>Date of Birth</th><td><?= e($formData['child_birthdate'] ?? '') ?></td></tr>
                <tr><th>Sex as recorded on PSA/LCR birth certificate</th><td><?= $reviewReportedSex === 'female' ? 'Female' : ($reviewReportedSex === 'male' ? 'Male' : 'Not provided') ?></td></tr>
                <tr><th>Baptism Pathway</th><td><?= $reviewIsLateBaptism ? 'Late Baptism (age 7+)' : 'Regular Baptism (under 7)' ?></td></tr>
                <tr><th>Place of Birth</th><td><?= e(fd('child_birthplace', $formData)) ?></td></tr>
                <tr><th>Father's Name</th><td><?= e(compose_person_name($formData, 'father')) ?></td></tr>
                <tr><th>Mother's Maiden Name</th><td><?= e(compose_person_name($formData, 'mother')) ?></td></tr>
                <tr><th>Legal Guardian / Authorized Representative</th><td><?= e(compose_person_name($formData, 'parent_guardian') ?: 'Not provided') ?></td></tr>
                <?php if (compose_person_name($formData, 'parent_guardian') !== ''): ?>
                    <tr><th>Guardian Authority Confirmed</th><td>Yes</td></tr>
                <?php endif; ?>
                <tr><th>Godfathers</th><td><?= $reviewNames(compose_godparent_names($formData, 'godfather')) ?></td></tr>
                <tr><th>Godmothers</th><td><?= $reviewNames(compose_godparent_names($formData, 'godmother')) ?></td></tr>
                <tr><th>Contact Number</th><td><?= e(fd('contact_number', $formData)) ?></td></tr>
                <tr><th>Email</th><td><?= e(fd('email', $formData)) ?></td></tr>
                <tr><th>Preferred Date</th><td><?= e((string) ($formData['preferred_date'] ?? '')) ?></td></tr>
            </table>
        </fieldset>
        <label class="checkbox-label" style="margin-top: 1rem;">
            <input type="checkbox" name="certify_accuracy" value="1" required>
            I certify that all information provided above is true and correct to the best of my knowledge.
        </label>
        <div class="form-actions">
            <button type="submit" name="_action" value="back" onclick="this.form.querySelector('[name=_action]').value='back'" formnovalidate class="btn btn-secondary">Back to Edit</button>
            <button type="submit" class="btn">Submit Pre-Application</button>
        </div>
    </form>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
