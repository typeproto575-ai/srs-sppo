<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email

// Subfolder-safe URL prefix for internal links.
$basePath = $basePath ?? base_path();

$pageTitle = 'Confirmation Application';
$errors    = [];
$trackingNumber = null;

// --- Wizard state (mirrors baptismal.php phased flow) ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (($_GET['reset'] ?? '') === '1') {
    unset($_SESSION['confirmation_app']);
    header('Location: confirmation.php');
    exit;
}
if (($_GET['submitted'] ?? '') === '1' && is_string($_SESSION['confirmation_success'] ?? null)) {
    $trackingNumber = $_SESSION['confirmation_success'];
    unset($_SESSION['confirmation_success'], $_SESSION['confirmation_app']);
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
if (!function_exists('cfd')) {
    function cfd(string $k, array $d): string
    {
        $value = $d[$k] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
$confLabels = [
    CONF_STEP_DETAILS     => 'Applicant information',
    CONF_STEP_BAPTISM     => 'Baptism details',
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
                'applicant_birthdate', 'applicant_person_id',
                'father_first', 'father_middle', 'father_last', 'father_suffix',
                'mother_first', 'mother_middle', 'mother_last', 'mother_suffix',
                'parent_guardian_first', 'parent_guardian_middle', 'parent_guardian_last', 'parent_guardian_suffix',
            ],
            CONF_STEP_BAPTISM => ['baptized_here'],
            CONF_STEP_CONTACT => ['contact_number', 'email'],
        ];
        foreach ($stepFields[$currentStep] ?? [] as $field) {
            $formData[$field] = cfd($field, $_POST);
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
                if ($formData['applicant_birthdate'] !== '' && is_future_date($formData['applicant_birthdate'])) $errors[] = "Applicant's birthdate cannot be in the future.";
                $parentGuardian = compose_person_name($formData, 'parent_guardian');
                if ($parentGuardian === '') $errors[] = 'Parent/Guardian name is required.';
                if (($e = validate_full_name($parentGuardian)) !== null) $errors[] = $e;
            } elseif ($currentStep === CONF_STEP_BAPTISM) {
                if (!in_array($formData['baptized_here'], ['this_parish', 'elsewhere', 'not_sure'], true)) $errors[] = 'Please answer where the applicant was baptized.';
            } elseif ($currentStep === CONF_STEP_CONTACT) {
                if ($formData['contact_number'] === '' || !is_valid_phone_number($formData['contact_number'])) {
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
        if ($applicantBirthdate !== '' && is_future_date($applicantBirthdate)) $errors[] = "Applicant's birthdate cannot be in the future.";
        if ($parentGuardian === '') $errors[] = 'Parent/Guardian name is required.';
        if (($e = validate_full_name($parentGuardian)) !== null) $errors[] = $e;
        if ($contactNumber === '' || !is_valid_phone_number($contactNumber)) {
            $errors[] = 'Please enter a valid Philippine mobile number, such as 09171234567 or +639171234567.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if (!in_array($baptizedHere, ['this_parish', 'elsewhere', 'not_sure'], true)) $errors[] = 'Please answer where the applicant was baptized.';
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
                $trackingNumber = retry_on_duplicate($pdo, function () use (
                    $pdo,
                    $applicantName,
                    $applicantBirthdate,
                    $parentGuardian,
                    $contactNumber,
                    $email,
                    $baptizedHere,
                    $personId,
                    $channel,
                     &$applicationId
                     
                ): string {
                    $requestNo = generate_request_no($pdo, 'sacrament_applications');
                    $details = [
                        'applicant_full_name' => $applicantName,
                        'birth_date' => $applicantBirthdate,
                        'father_name' => compose_person_name($formData, 'father'),
                        'mother_maiden_name' => compose_person_name($formData, 'mother'),
                        'parent_guardian_name' => $parentGuardian,
                        'baptized_here' => $baptizedHere,
                    ];
                    $requirements = [
                        'origin_baptismal_certificate' => [
                            'status' => $baptizedHere === 'this_parish' ? 'not_applicable' : 'not_received',
                            'note' => '',
                        ],
                        'confirmation_sponsor_details' => ['status' => 'not_received', 'note' => ''],
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

                log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, "Confirmation application submitted for {$applicantName}");
                notify($pdo, 'application_new', 'New sacrament application', "Confirmation application for {$applicantName} ({$trackingNumber})", '/admin/requests/applications-queue.php');
                if ($channel === 'online') {
                    record_public_submission($pdo, [$email], 'application_confirmation');
                }

                if ($isSecretary) {
                    redirect_to('/admin/requests/applications-queue.php?added=' . rawurlencode($trackingNumber));
                    exit;
                }

                try {
                    if ($channel === 'online' && mail_request_received($email, $trackingNumber, 'Confirmation Application', $pdo, 'sacrament_applications', $applicationId)) {
                        log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
                    }
                } catch (Throwable $e) {
                    error_log('Confirmation application confirmation email failed: ' . $e->getMessage());
                }

                $_SESSION['confirmation_success'] = $trackingNumber;
                unset($_SESSION['confirmation_app']);
                session_write_close();
                header('Location: confirmation.php?submitted=1');
                exit;
            } catch (Throwable $e) {
                error_log('Confirmation application failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong while submitting your application. Please try again.';
            }
        }
    }
}
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<section class="request-form">
<?php if ($trackingNumber !== null): ?>
    <div class="success-panel">
        <h1>Confirmation Application Submitted</h1>
        <?php
        $successHeading = 'Keep this reference number';
        $successIsApplication = true;
        $successRequiredDocuments = ['Baptismal certificate from the origin parish if baptized elsewhere', 'Confirmation sponsor details'];
        require __DIR__ . '/../../includes/submission-success.php';
        ?>
        <a class="btn" href="confirmation.php?reset=1">Start a New Application</a>
    </div>
<?php else: ?>
    <h1><?= e($pageTitle) ?></h1>
    <ol class="request-steps" aria-label="Progress">
        <?php foreach ($confOrder as $i => $key): ?>
            <?php $state = ($key === $currentStep) ? 'is-current' : (($currentStep > $key) ? 'is-done' : ''); ?>
            <li class="<?= $state ?>"<?= ($key === $currentStep) ? ' aria-current="step"' : '' ?>><span><?= $i + 1 ?></span> <?= e($confLabels[$key]) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_wizard_action" value="<?= $currentStep === CONF_STEP_VERIFY ? 'final_submit' : 'next' ?>">
        <?php if ($currentStep === CONF_STEP_DETAILS): ?>
        <fieldset>
            <legend>Phase 1 of 4 — Applicant Information</legend>
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
            <label>Birthdate *
                <input type="date" name="applicant_birthdate" max="<?= e(date('Y-m-d')) ?>" value="<?= $wv('applicant_birthdate') ?>" required>
            </label>
            <input type="hidden" name="applicant_person_id" value="<?= $wv('applicant_person_id') ?>">
            <div class="public-person-lookup" data-public-person-lookup
                 data-endpoint="<?= e($basePath . '/public/certificate-request.php') ?>"
                 data-name-inputs="applicant_first,applicant_middle,applicant_last,applicant_suffix"
                 data-birth-field="applicant_birthdate" data-person-id-field="applicant_person_id"
                 data-father-inputs="father_first,father_middle,father_last,father_suffix"
                 data-mother-inputs="mother_first,mother_middle,mother_last,mother_suffix">
                <p class="field-hint">Search the parish person index by full name and date of birth. A match will fill the applicant’s details; you can also continue without a match.</p>
                <div data-person-results aria-live="polite"></div>
            </div>
            <?= person_name_fields('father', "Father's name", $formData, false) ?>
            <?= person_name_fields('mother', "Mother's maiden name", $formData, false) ?>
            <label>Parent/Guardian Name * <span class="field-hint">(if an adult applicant, you may enter your own name)</span>
                <?= person_name_fields('parent_guardian', 'Parent/Guardian', $formData) ?>
            </label>
        </fieldset>
        <?php elseif ($currentStep === CONF_STEP_BAPTISM): ?>
            <fieldset>
                <legend>Phase 2 of 4 — Baptism Details</legend>
                <label>Where was the applicant baptized? *
                    <select name="baptized_here" required>
                        <option value="">-- Select --</option>
                        <option value="this_parish" <?= ($formData['baptized_here'] ?? '') === 'this_parish' ? 'selected' : '' ?>>At this parish</option>
                        <option value="elsewhere" <?= ($formData['baptized_here'] ?? '') === 'elsewhere' ? 'selected' : '' ?>>At another parish or church</option>
                        <option value="not_sure" <?= ($formData['baptized_here'] ?? '') === 'not_sure' ? 'selected' : '' ?>>I’m not sure — I need help checking</option>
                    </select>
                </label>
                <p class="field-hint">It’s okay if you don’t know which parish baptized the applicant. The parish office can help verify the record. Bring a copy of the applicant's Baptismal Certificate when asked. Don't have one yet? <a href="<?= e($basePath . '/public/certificate-request.php?type=baptism') ?>" target="_blank" rel="noopener noreferrer">Request a Baptismal Certificate</a>.</p>
            </fieldset>
        <?php elseif ($currentStep === CONF_STEP_CONTACT): ?>
            <fieldset>
                <legend>Phase 3 of 4 — Contact &amp; Consent</legend>
                <label>Contact Number * <span class="field-hint">(e.g. 09171234567 or +639171234567)</span>
                    <input type="tel" name="contact_number" autocomplete="tel" value="<?= $wv('contact_number') ?>" required>
                </label>
                <label>Email Address *
                    <input type="email" name="email" autocomplete="email" value="<?= $wv('email') ?>" required>
                </label>
                <label>Privacy Consent * <span class="field-hint">(agree to the <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a> and parish use of these details for sacrament preparation)</span>
                    <input type="checkbox" name="privacy_consent" value="1" <?= (($formData['privacy_consent'] ?? '') === '1') ? 'checked' : '' ?> required>
                </label>
            </fieldset>
        <?php elseif ($currentStep === CONF_STEP_VERIFY): ?>
            <fieldset>
                <legend>Phase 4 of 4 — Review &amp; Submit</legend>
                <table class="review-table">
                    <tr><th>Applicant</th><td><?= e(compose_person_name($formData, 'applicant')) ?></td></tr>
                    <tr><th>Birthdate</th><td><?= $wv('applicant_birthdate') ?></td></tr>
                    <tr><th>Parent / Guardian</th><td><?= e(compose_person_name($formData, 'parent_guardian')) ?></td></tr>
                    <tr><th>Baptized at</th><td><?= ($formData['baptized_here'] ?? '') === 'this_parish' ? 'At this parish' : (($formData['baptized_here'] ?? '') === 'elsewhere' ? 'At another parish or church' : 'Not sure — please help us check') ?></td></tr>
                    <tr><th>Contact number</th><td><?= $wv('contact_number') ?></td></tr>
                    <tr><th>Email</th><td><?= $wv('email') ?></td></tr>
                </table>
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
                <button type="submit" name="wizard_action" value="next" class="btn btn-primary"><?= $currentStep === CONF_STEP_CONTACT ? 'Review Application' : 'Next' ?></button>
            <?php else: ?>
                <button type="submit" name="wizard_action" value="final_submit" onclick="this.form.elements['_wizard_action'].value='final_submit'" class="btn btn-primary">Submit Application</button>
            <?php endif; ?>
        </div>
    </form>
    <hr>
    <p class="form-footer">Fields marked with * are required. Submitting creates a confirmation application record for parish office review.</p>
<?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
