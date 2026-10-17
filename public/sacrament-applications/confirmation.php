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
            CONF_STEP_DETAILS => ['applicant_full_name', 'applicant_birthdate', 'parent_guardian_name'],
            CONF_STEP_BAPTISM => ['baptized_here', 'baptismal_certificate_status'],
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
                if ($formData['applicant_full_name'] === '') $errors[] = "Applicant's full name is required.";
                if ($formData['applicant_birthdate'] === '') $errors[] = "Applicant's birthdate is required.";
                if ($formData['applicant_birthdate'] !== '' && is_future_date($formData['applicant_birthdate'])) $errors[] = "Applicant's birthdate cannot be in the future.";
                if ($formData['parent_guardian_name'] === '') $errors[] = 'Parent/Guardian name is required.';
            } elseif ($currentStep === CONF_STEP_BAPTISM) {
                if (!in_array($formData['baptized_here'], ['this_parish', 'elsewhere', 'not_sure'], true)) $errors[] = 'Please answer where the applicant was baptized.';
                if (!in_array($formData['baptismal_certificate_status'], ['ready', 'not_ready'], true)) $errors[] = 'Please indicate whether the applicant has a baptismal certificate ready.';
            } elseif ($currentStep === CONF_STEP_CONTACT) {
                if ($formData['contact_number'] === '') $errors[] = 'Contact number is required.';
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

        $applicantName = cfd('applicant_full_name', $formData);
        $applicantBirthdate = cfd('applicant_birthdate', $formData);
        $parentGuardian = cfd('parent_guardian_name', $formData);
        $contactNumber = cfd('contact_number', $formData);
        $email = cfd('email', $formData);
        $baptizedHere = $formData['baptized_here'] ?? '';
        $baptismalCert = $formData['baptismal_certificate_status'] ?? '';
        $preferredBatch = $formData['preferred_batch'] ?? null;
        $sponsorName = cfd('sponsor_name', $formData);

        if ($formData['certify_accuracy'] !== '1') $errors[] = 'You must certify that all information is true and correct before submitting.';
        if ($applicantName === '') $errors[] = "Applicant's full name is required.";
        if ($applicantBirthdate === '') $errors[] = "Applicant's birthdate is required.";
        if ($applicantBirthdate !== '' && is_future_date($applicantBirthdate)) $errors[] = "Applicant's birthdate cannot be in the future.";
        if ($parentGuardian === '') $errors[] = 'Parent/Guardian name is required.';
        if ($contactNumber === '') $errors[] = 'Contact number is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if (!in_array($baptizedHere, ['this_parish', 'elsewhere', 'not_sure'], true)) $errors[] = 'Please answer where the applicant was baptized.';
        if (!in_array($baptismalCert, ['ready', 'not_ready'], true)) $errors[] = 'Please indicate whether the applicant has a baptismal certificate ready.';
        if (($formData['privacy_consent'] ?? '') !== '1') $errors[] = 'Please agree to the Privacy Notice before submitting.';

        if (!$errors) {
            try {
                $pdo = get_db();
                $submissionCap = public_submission_allowed($pdo, [$email]);
                if (!$submissionCap['ok']) {
                    $errors[] = 'You have reached the submission limit for this email address. Please try again later.';
                }
                if (!$errors) {
                    $trackingNumber = 'CFM-' . bin2hex(random_bytes(4));
                    $stmt = $pdo->prepare("INSERT INTO sacrament_applications
                        (tracking_number, submission_channel, sacrament_type, status, created_at)
                        VALUES (?, 'online', 'confirmation', 'awaiting_payment', NOW())");
                    $stmt->execute([$trackingNumber]);
                    $applicationId = (int) $pdo->lastInsertId();

                    $stmt2 = $pdo->prepare("INSERT INTO confirmation_applications
                        (application_id, applicant_full_name, applicant_birthdate,
                         parent_guardian_name, contact_number, email, baptized_here,
                         baptismal_certificate_status, preferred_batch, sponsor_name)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt2->execute([
                        $applicationId,
                        $applicantName,
                        $applicantBirthdate,
                        $parentGuardian,
                        $contactNumber,
                        $email,
                        ($baptizedHere === 'this_parish') ? 1 : 0,
                        $baptismalCert,
                        $preferredBatch,
                        $sponsorName,
                    ]);
                }
            } catch (PDOException $e) {
                $errors[] = 'We could not save your application right now. Please try again later.';
            }
        }

        if (!$errors) {
            $_SESSION['confirmation_success'] = $trackingNumber;
            unset($_SESSION['confirmation_app']);
            session_write_close();
            header('Location: confirmation.php?submitted=1');
            exit;
        }
    }
}
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<section class="request-form">
<?php if ($trackingNumber !== null): ?>
    <div class="success-panel">
        <h1>Confirmation Application Submitted</h1>
        <p>Your application has been received for parish review.</p>
        <p>Tracking Number: <strong><?= e($trackingNumber) ?></strong></p>
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
            <label>Full Name *
                <input type="text" name="applicant_full_name" autocomplete="name" value="<?= $wv('applicant_full_name') ?>" required>
            </label>
            <label>Birthdate *
                <input type="date" name="applicant_birthdate" max="<?= e(date('Y-m-d')) ?>" value="<?= $wv('applicant_birthdate') ?>" required>
            </label>
            <label>Parent/Guardian Name * <span class="field-hint">(if an adult applicant, you may enter your own name)</span>
                <input type="text" name="parent_guardian_name" autocomplete="off" value="<?= $wv('parent_guardian_name') ?>" required>
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
                        <option value="not_sure" <?= ($formData['baptized_here'] ?? '') === 'not_sure' ? 'selected' : '' ?>>Not sure</option>
                    </select>
                </label>
                <p class="field-hint">Bring a copy of the applicant's Baptismal Certificate when the parish office asks for it. Don't have one yet? <a href="<?= e($basePath . '/public/certificate-requests/baptismal.php') ?>" target="_blank" rel="noopener noreferrer">Request a Baptismal Certificate</a>.</p>
            </fieldset>
            <fieldset>
                <legend>Baptismal Certificate</legend>
                <label>Do you have a copy of the applicant's Baptismal Certificate ready to submit?
                    <select name="baptismal_certificate_status" required>
                        <option value="">-- Select --</option>
                        <option value="ready" <?= ($formData['baptismal_certificate_status'] ?? '') === 'ready' ? 'selected' : '' ?>>Yes, ready to submit</option>
                        <option value="not_ready" <?= ($formData['baptismal_certificate_status'] ?? '') === 'not_ready' ? 'selected' : '' ?>>Not ready yet — request a certificate</option>
                    </select>
                </label>
            </fieldset>
        <?php elseif ($currentStep === CONF_STEP_CONTACT): ?>
            <fieldset>
                <legend>Phase 3 of 4 — Contact &amp; Consent</legend>
                <label>Contact Number * <span class="field-hint">(prefix with country code)</span>
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
                    <tr><th>Applicant</th><td><?= $wv('applicant_full_name') ?></td></tr>
                    <tr><th>Birthdate</th><td><?= $wv('applicant_birthdate') ?></td></tr>
                    <tr><th>Parent / Guardian</th><td><?= $wv('parent_guardian_name') ?></td></tr>
                    <tr><th>Baptized at</th><td><?= ($formData['baptized_here'] ?? '') === 'this_parish' ? 'At this parish' : (($formData['baptized_here'] ?? '') === 'elsewhere' ? 'At another parish or church' : 'Not sure') ?></td></tr>
                    <tr><th>Baptismal certificate</th><td><?= ($formData['baptismal_certificate_status'] ?? '') === 'ready' ? 'Ready to submit' : 'Not ready yet' ?></td></tr>
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
