<?php
/**
 * =====================================================================
 * SRS-SPPO BAPTISMAL SACRAMENT APPLICATION (public form)
 * =====================================================================
 * Purpose: Online application to HAVE A CHILD BAPTIZED at the parish.
 *          Inserts an unverified intake row into the v2
 *          sacrament_applications table and ensures a master person profile
 *          with a reusable public reference.
 * Flow:    Enters as 'submitted'; the secretary reviews it in
 *          admin/requests/applications-queue.php. No online ID upload â€”
 *          identity is verified in person at pickup.
 * Limit:   Successful submissions are capped at 5 per email address and 20
 *          per client IP over a rolling 24 hours; the accounting row lives
 *          using the shared public-submission guard.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email
require_once __DIR__ . '/../../includes/sacrament-data.php';

// Subfolder-safe URL prefix for links inside the success message.
$basePath = $basePath ?? base_path();

$pageTitle = 'Baptismal Pre-Application';
$errors    = [];
$trackingNumber = null;
$successBaptismVenue = '';

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
            'child_residence' => (string) ($person['address'] ?? ''),
        ]);
        $app['current_step'] = STEP_APP_DETAILS;
        session_write_close();
        header('Location: baptismal.php');
        exit;
    }
}

// Stepper labels shared with certificate-request flows (visual parity).
$stepLabels = [
    STEP_APP_DETAILS     => 'Child information',
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

function baptismal_application_optional_residence(mixed $value, string $label, int $maxLength, array &$errors): ?string
{
    try {
        return sd_application_optional_residence($value, $label, $maxLength);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
        return is_string($value) ? trim($value) : null;
    }
}

function baptismal_application_residence_rows(
    mixed $values,
    string $label,
    int $maxLength,
    array &$errors
): array {
    if (!is_array($values)) {
        $errors[] = $label . ' values must be submitted as text.';
        return [];
    }
    if (count($values) > 8) {
        $role = preg_replace('/\s+residence$/i', '', $label) ?: $label;
        $errors[] = 'No more than 8 ' . strtolower($role) . ' residences can be entered.';
    }

    foreach ($values as $index => $value) {
        $values[$index] = baptismal_application_optional_residence($value, $label, $maxLength, $errors);
    }

    return $values;
}

function compose_godparent_names(array $data, string $prefix): array {
    return array_values(array_filter(
        array_map(static fn($name) => is_scalar($name) ? trim((string) $name) : '', fa($prefix . '_full_name', $data)),
        static fn($name) => $name !== ''
    ));
}

function validate_godparent_names(array $data, string $prefix, string $label): array {
    $errors = [];
    $names = fa($prefix . '_full_name', $data);
    if (count($names) > 8) {
        $errors[] = 'No more than 8 ' . ($prefix === 'godfather' ? 'godfathers' : 'godmothers') . ' can be entered.';
    }
    foreach ($names as $name) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['_action'] ?? null) ? trim($_POST['_action']) : '';
    if ($action === 'next' && $currentStep === STEP_FAMILY_REQS) {
        $_POST['guardian_authority_confirmed'] = ($_POST['guardian_authority_confirmed'] ?? '') === '1' ? '1' : '';
    }

    // CSRF verification for all POSTs
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } elseif ($action === 'next' || $action === 'back') {
        if ($action === 'next') {
            $errors = [];

            if ($currentStep === STEP_APP_DETAILS) {
                $childName       = compose_person_name($_POST, 'child');
                $childBirthdate  = $_POST['child_birthdate'] ?? '';
                $childBirthplace = fd('child_birthplace', $_POST);
                $_POST['child_residence'] = baptismal_application_optional_residence(
                    $_POST['child_residence'] ?? null,
                    "Child's current residence",
                    255,
                    $errors
                ) ?? '';
                $baptismVenueInput = $_POST['baptism_venue'] ?? '';
                if (!is_string($baptismVenueInput)) {
                    $errors[] = 'Preferred baptism venue must be text.';
                    $baptismVenueInput = '';
                }
                $baptismVenue = trim($baptismVenueInput);
                $_POST['child_birthplace'] = $childBirthplace;
                $_POST['baptism_venue'] = $baptismVenue;

                if (fd('child_first', $_POST) === '') $errors[] = "Child's first name is required.";
                if (fd('child_last', $_POST) === '') $errors[] = "Child's last name is required.";
                if (($e = validate_full_name($childName)) !== null) $errors[] = $e;
                if ($childBirthdate === '')  $errors[] = "Child's birthdate is required.";
                if (is_future_date($childBirthdate)) $errors[] = "Child's birthdate cannot be in the future.";
                if (is_unknown_place_of_birth($childBirthplace)) {
                    $errors[] = "Please enter the child's city or municipality and province of birth.";
                }
                if (mb_strlen($childBirthplace) > 150) $errors[] = "Child's birthplace must be 150 characters or fewer.";
                if (mb_strlen($baptismVenue) > 150) $errors[] = 'Preferred baptism venue must be 150 characters or fewer.';
            }

            if ($currentStep === STEP_FAMILY_REQS) {
                $_POST['parents_residence'] = baptismal_application_optional_residence(
                    $_POST['parents_residence'] ?? null,
                    "Parents' current residence",
                    255,
                    $errors
                ) ?? '';
                $_POST['godfather_residence'] = baptismal_application_residence_rows(
                    $_POST['godfather_residence'] ?? [],
                    'Godfather residence',
                    150,
                    $errors
                );
                $_POST['godmother_residence'] = baptismal_application_residence_rows(
                    $_POST['godmother_residence'] ?? [],
                    'Godmother residence',
                    150,
                    $errors
                );
                $parentGuardian = compose_person_name($_POST, 'parent_guardian');
                if ($parentGuardian !== '' && ($e = validate_full_name($parentGuardian)) !== null) $errors[] = $e;
                $guardianConfirmationError = sd_baptism_guardian_attestation_error(
                    $parentGuardian,
                    ($_POST['guardian_authority_confirmed'] ?? '') === '1'
                );
                if ($guardianConfirmationError !== null) $errors[] = $guardianConfirmationError;
                foreach (['father' => "Father's name"] as $prefix => $label) {
                    if (($e = validate_parent_name_or_na($_POST, $prefix, $label)) !== null) $errors[] = $e;
                }
                if (($e = validate_required_parent_name($_POST, 'mother', "Mother's maiden name")) !== null) $errors[] = $e;
                $errors = array_merge(
                    $errors,
                    validate_godparent_names($_POST, 'godfather', 'Godfather'),
                    validate_godparent_names($_POST, 'godmother', 'Godmother')
                );
                $presence = sd_baptism_godparent_presence([
                    'godfather' => compose_godparent_names($_POST, 'godfather'),
                    'godmother' => compose_godparent_names($_POST, 'godmother'),
                ], false);
                $errors = array_merge($errors, $presence['errors']);
            }

            if ($currentStep === STEP_CONTACT_CONSENT) {
                $contactNumber = fd('contact_number', $_POST);
                $email         = fd('email', $_POST);
                $privacyConsent = $_POST['privacy_consent'] ?? '';

                if ($contactNumber === '') {
                    $errors[] = 'Contact number is required.';
                } elseif (!is_valid_phone_number($contactNumber)) {
                    $errors[] = 'Please enter a valid contact number (at least 10 digits).';
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
            $childBirthplace = fd('child_birthplace', $formData);
            $baptismVenueInput = $formData['baptism_venue'] ?? '';
            if (!is_string($baptismVenueInput)) {
                $errors[] = 'Preferred baptism venue must be text.';
                $baptismVenueInput = '';
                $formData['baptism_venue'] = '';
            }
            $baptismVenue = trim($baptismVenueInput);
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
            $childResidence = baptismal_application_optional_residence(
                $formData['child_residence'] ?? null,
                "Child's current residence",
                255,
                $errors
            );
            $parentsResidence = baptismal_application_optional_residence(
                $formData['parents_residence'] ?? null,
                "Parents' current residence",
                255,
                $errors
            );
            $formData['godfather_residence'] = baptismal_application_residence_rows(
                $formData['godfather_residence'] ?? [],
                'Godfather residence',
                150,
                $errors
            );
            $formData['godmother_residence'] = baptismal_application_residence_rows(
                $formData['godmother_residence'] ?? [],
                'Godmother residence',
                150,
                $errors
            );
            $godparents = sd_application_godparents($formData);
            $childPersonId = null;

            if (fd('child_first', $formData) === '') $errors[] = "Child's first name is required.";
            if (fd('child_last', $formData) === '') $errors[] = "Child's last name is required.";
            if (($e = validate_full_name($childName)) !== null) $errors[] = $e;
            if ($childBirthdate === '')  $errors[] = "Child's birthdate is required.";
            if (is_future_date($childBirthdate)) $errors[] = "Child's birthdate cannot be in the future.";
            if (is_unknown_place_of_birth($childBirthplace)) {
                $errors[] = "Please enter the child's city or municipality and province of birth.";
            }
            if (mb_strlen($childBirthplace) > 150) $errors[] = "Child's birthplace must be 150 characters or fewer.";
            if (mb_strlen($baptismVenue) > 150) $errors[] = 'Preferred baptism venue must be 150 characters or fewer.';
            if ($contactNumber === '') {
                $errors[] = 'Contact number is required.';
            } elseif (!is_valid_phone_number($contactNumber)) {
                $errors[] = 'Please enter a valid contact number (at least 10 digits).';
            }
            if ($email === '')           $errors[] = 'Email address is required.';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            if ($parentGuardian !== '' && ($e = validate_full_name($parentGuardian)) !== null) $errors[] = $e;
            foreach (['father' => "Father's name"] as $prefix => $label) {
                if (($e = validate_parent_name_or_na($formData, $prefix, $label)) !== null) $errors[] = $e;
            }
            if (($e = validate_required_parent_name($formData, 'mother', "Mother's maiden name")) !== null) $errors[] = $e;
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
            $guardianConfirmationError = sd_baptism_guardian_attestation_error(
                $parentGuardian,
                ($formData['guardian_authority_confirmed'] ?? '') === '1'
            );
            if ($guardianConfirmationError !== null) $errors[] = $guardianConfirmationError;
            $presence = sd_baptism_godparent_presence([
                'godfather' => $godfatherNames,
                'godmother' => $godmotherNames,
            ], false);
            $errors = array_merge($errors, $presence['errors']);

            if (empty($errors)) {
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
                        $details = [
                            'child_name' => $childName,
                            'child_birth_date' => $childBirthdate,
                            'child_birthplace' => $childBirthplace,
                            'child_residence' => $childResidence,
                            'father_name' => $fatherName,
                            'mother_maiden_name' => $motherMaidenName,
                            'parents_residence' => $parentsResidence,
                            'godparents' => $godparents,
                        ];
                        if ($parentGuardian !== '') {
                            $details['parent_guardian_name'] = $parentGuardian;
                            $details['guardian_authority_confirmed'] = true;
                        }
                        if ($baptismVenue !== '') {
                            $details['baptism_venue'] = $baptismVenue;
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

                        log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, "Baptismal pre-application submitted for {$childName}");
                        if ($channel === 'online') {
                            notify($pdo, 'application_new', 'New sacrament pre-application', "Baptismal pre-application for {$childName} ({$requestNo})", '/admin/requests/applications-queue.php');
                            record_public_submission($pdo, [$email], 'application_baptismal');
                        }
                        $pdo->commit();
                        $successBaptismVenue = $baptismVenue;
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

$reviewGodparents = static function (array $godparents, string $role): string {
    $participants = array_values(array_filter(
        $godparents,
        static fn(array $participant): bool => ($participant['role'] ?? '') === $role
    ));
    if ($participants === []) {
        return '—';
    }

    $items = [];
    foreach ($participants as $participant) {
        $item = '<li>' . e((string) ($participant['full_name'] ?? ''));
        $residence = is_scalar($participant['residence'] ?? null)
            ? trim((string) $participant['residence'])
            : '';
        if ($residence !== '') {
            $item .= '<br><span class="field-hint">Current residence: ' . e($residence) . '</span>';
        }
        $items[] = $item . '</li>';
    }

    return '<ul class="public-review-godparents">' . implode('', $items) . '</ul>';
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
        $successRequiredDocuments = sacrament_application_requirements('baptism')['before'];
        require __DIR__ . '/../../includes/submission-success.php';
        ?>
        <?php if ($successBaptismVenue !== ''): ?>
            <p><strong>Preferred place / venue of baptism:</strong> <?= e($successBaptismVenue) ?></p>
            <p class="field-hint">This is a preference only; the Parish Office will confirm the venue.</p>
        <?php endif; ?>
        <a href="baptismal.php?reset=1" class="btn">Start a New Pre-Application</a>
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
            <legend>Phase 1 of 4 — Child Information</legend>
            <p class="field-hint">Your answers are kept in this browser only until you close it. Nothing is sent to the parish until you press Submit.</p>
            <div class="public-person-lookup" data-public-person-lookup
                 data-lookup-context="preapplication"
                 data-endpoint="<?= e($basePath . '/public/certificate-request.php') ?>"
                 data-name-inputs="child_first,child_middle,child_last,child_suffix"
                 data-birth-field="child_birthdate" data-person-id-field="child_person_id"
                 data-father-value-field="selected_father_name" data-mother-value-field="selected_mother_maiden_name">
                <h3 class="lookup-title">Already registered with the parish?</h3>
                <p class="field-hint">Enter the child’s person reference to fill in recorded details and view linked sacrament records. Keep the reference private. Without a reference, fill in the form manually.</p>
                <div data-person-results aria-live="polite"></div>
            </div>
            <h3 class="form-subheading">Child’s details</h3>
            <div class="inline-fields">
                <label>Child's First Name *
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
            </label>
            <label>Child's Current Residence <span class="field-hint">(optional)</span>
                <input type="text" name="child_residence" maxlength="255" autocomplete="street-address"
                       value="<?= e(fd('child_residence', $formData)) ?>">
            </label>
            <input type="hidden" name="child_person_id" value="<?= e(fd('child_person_id', $formData)) ?>">
            <input type="hidden" name="selected_father_name" value="<?= e(fd('selected_father_name', $formData)) ?>">
            <input type="hidden" name="selected_mother_maiden_name" value="<?= e(fd('selected_mother_maiden_name', $formData)) ?>">
            <label>Place of Birth *
                <input type="text" name="child_birthplace" maxlength="150"
                       placeholder="City / Municipality, Province"
                       pattern="(?!(?:\s*)(?:[nN]/[aA]|[nN][aA]|[nN]\.[aA]\.|[nN][oO][nN][eE]|[uU][nN][kK][nN][oO][wW][nN]|-)\s*$).+"
                       title="Enter the city or municipality and province. Placeholder values are not accepted."
                       value="<?= e(fd('child_birthplace', $formData)) ?>" required>
            </label>
            <label>Preferred place / venue of baptism <span class="field-hint">(optional)</span>
                <input type="text" name="baptism_venue" maxlength="150" value="<?= e(fd('baptism_venue', $formData)) ?>">
                <span class="field-hint">This is a preference only; the Parish Office will confirm the venue.</span>
            </label>
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
            <?= person_name_fields('father', "Father's name", $formData, true, null, 'required: enter the name, or enter N/A if unknown', true) ?>
            <?= person_name_fields('mother', "Mother's maiden name", $formData, true, null, 'required: enter the first and last name; N/A is not accepted', false, true) ?>
            <label>Parents' Current Residence <span class="field-hint">(optional; shared by both parents)</span>
                <input type="text" name="parents_residence" maxlength="255" autocomplete="street-address"
                       value="<?= e(fd('parents_residence', $formData)) ?>">
            </label>
            <section class="guardian-section">
                <p class="field-hint">The Guardian field is optional. Adults applying for themselves may leave it blank.</p>
                <?= person_name_fields('parent_guardian', 'Guardian', $formData, false) ?>
                <div class="alert alert-info" data-guardian-attestation<?= compose_person_name($formData, 'parent_guardian') === '' ? ' hidden' : '' ?>>
                    <label class="checkbox-label">
                        <input type="checkbox" name="guardian_authority_confirmed" value="1" <?= (fd('guardian_authority_confirmed', $formData) === '1') ? 'checked' : '' ?>>
                        If I am a guardian, I confirm that I am the child's legal guardian or have permission from the child's parents to submit this baptism pre-application.
                    </label>
                    <p class="field-hint">This confirmation is required when a guardian is listed. A guardian does not have to be related to the child; if you are not the child's legal guardian, you must have permission from the child's parents.</p>
                </div>
            </section>
            <?php
            $godfatherRows = max(1, count(fa('godfather_full_name', $formData)));
            $godmotherRows = max(1, count(fa('godmother_full_name', $formData)));
            ?>
            <fieldset>
                <legend>Godfathers (Ninong) <span class="required-marker" aria-hidden="true">*</span></legend>
                <p class="field-hint">At least one godfather is required; up to 8 may be entered.</p>
                <div data-repeat-group="godfather" data-repeat-max="8">
                    <?php for ($i = 0; $i < $godfatherRows; $i++): ?>
                        <div class="godparent-row">
                            <label>Godfather's Full Name
                                <input type="text" name="godfather_full_name[]" value="<?= e(fa('godfather_full_name', $formData)[$i] ?? '') ?>" autocomplete="name">
                            </label>
                            <label>Current Residence <span class="field-hint">(optional)</span>
                                <input type="text" name="godfather_residence[]" maxlength="150" autocomplete="street-address"
                                       value="<?= e(fa('godfather_residence', $formData)[$i] ?? '') ?>">
                            </label>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godfather">Add another godfather</button>
            </fieldset>
            <fieldset>
                <legend>Godmothers (Ninang) <span class="required-marker" aria-hidden="true">*</span></legend>
                <p class="field-hint">At least one godmother is required; up to 8 may be entered.</p>
                <div data-repeat-group="godmother" data-repeat-max="8">
                    <?php for ($i = 0; $i < $godmotherRows; $i++): ?>
                        <div class="godparent-row">
                            <label>Godmother's Full Name
                                <input type="text" name="godmother_full_name[]" value="<?= e(fa('godmother_full_name', $formData)[$i] ?? '') ?>" autocomplete="name">
                            </label>
                            <label>Current Residence <span class="field-hint">(optional)</span>
                                <input type="text" name="godmother_residence[]" maxlength="150" autocomplete="street-address"
                                       value="<?= e(fa('godmother_residence', $formData)[$i] ?? '') ?>">
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
    <script>
        (function () {
            var section = document.querySelector('.guardian-section');
            if (!section) return;
            var attestation = section.querySelector('[data-guardian-attestation]');
            var fields = section.querySelectorAll('input[name^="parent_guardian_"]');
            var checkbox = section.querySelector('input[name="guardian_authority_confirmed"]');
            var updateAttestationVisibility = function () {
                var guardianNamed = Array.prototype.some.call(fields, function (field) {
                    return field.value.trim() !== '';
                });
                attestation.hidden = !guardianNamed;
                if (!guardianNamed && checkbox) checkbox.checked = false;
            };
            fields.forEach(function (field) {
                field.addEventListener('input', updateAttestationVisibility);
                field.addEventListener('change', updateAttestationVisibility);
            });
            updateAttestationVisibility();
        })();
    </script>
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
                <input type="tel" name="contact_number" value="<?= e(fd('contact_number', $formData)) ?>" required>
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
            <table class="review-table">
                <tr><th>Child's Full Name</th><td><?= e(compose_person_name($formData, 'child')) ?></td></tr>
                <tr><th>Date of Birth</th><td><?= e($formData['child_birthdate'] ?? '') ?></td></tr>
                <tr><th>Place of Birth</th><td><?= e(fd('child_birthplace', $formData)) ?></td></tr>
                <tr><th>Child's Current Residence</th><td><?= e(fd('child_residence', $formData) ?: '—') ?></td></tr>
                <tr>
                    <th>Preferred place / venue of baptism</th>
                    <td><?= e(fd('baptism_venue', $formData)) ?><br><span class="field-hint">Preference only; the Parish Office will confirm the venue.</span></td>
                </tr>
                <tr><th>Father's Name</th><td><?= e(compose_person_name($formData, 'father')) ?></td></tr>
                <tr><th>Mother's Maiden Name</th><td><?= e(compose_person_name($formData, 'mother')) ?></td></tr>
                <tr><th>Parents' Current Residence</th><td><?= e(fd('parents_residence', $formData) ?: '—') ?></td></tr>
                <tr><th>Guardian</th><td><?= e(compose_person_name($formData, 'parent_guardian') ?: '—') ?></td></tr>
                <?php if (compose_person_name($formData, 'parent_guardian') !== ''): ?>
                    <tr><th>Guardian authority</th><td><?= e(($formData['guardian_authority_confirmed'] ?? '') === '1' ? 'Confirmed' : 'Not confirmed') ?></td></tr>
                <?php endif; ?>
                <tr><th>Godfathers</th><td><?= $reviewGodparents(sd_application_godparents($formData), 'godfather') ?></td></tr>
                <tr><th>Godmothers</th><td><?= $reviewGodparents(sd_application_godparents($formData), 'godmother') ?></td></tr>
                <tr><th>Contact Number</th><td><?= e(fd('contact_number', $formData)) ?></td></tr>
                <tr><th>Email</th><td><?= e(fd('email', $formData)) ?></td></tr>
                <tr><th>Preferred Date</th><td><?= e((string) ($formData['preferred_date'] ?? '')) ?></td></tr>
            </table>
            <?php $applicationType = 'baptism'; $requirementsNoticePart = 'after'; require __DIR__ . '/../../includes/public-requirements-notice.php'; unset($requirementsNoticePart); ?>
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
