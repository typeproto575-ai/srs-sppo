<?php
/** Philippine parish First Holy Communion application. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';

$basePath = base_path();
$pageTitle = 'First Holy Communion Application';
$errors = [];
$trackingNumber = null;

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
$currentStep = (int) ($wizard['current_step'] ?? 1);
if ($currentStep < 1 || $currentStep > count($steps)) {
    $currentStep = 1;
    $wizard['current_step'] = 1;
}
$formData = &$wizard['form_data'];
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
    if ($currentStep === 4) {
        foreach (['confession_completed', 'parental_consent', 'privacy_consent'] as $checkbox) {
            $formData[$checkbox] = ($_POST[$checkbox] ?? '') === '1' ? '1' : '0';
        }
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
        } elseif ($currentStep === 2) {
            if ($value('parent_guardian_name') === '') $errors[] = 'Parent/Guardian name is required.';
            if ($value('contact_number') === '' || !is_valid_phone_number($value('contact_number'))) $errors[] = 'A valid contact number is required.';
            if (!filter_var($value('email'), FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        } elseif ($currentStep === 3) {
            if (!in_array($value('baptized_here'), ['this_parish', 'elsewhere', 'not_sure'], true)) $errors[] = 'Please answer where the applicant was baptized.';
        } elseif ($currentStep === 4) {
            if (($formData['parental_consent'] ?? '') !== '1') $errors[] = 'Parent/Guardian consent is required.';
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
        $errors[] = 'Please certify that the application information is true and correct.';
    }
    $name = compose_person_name($formData, 'applicant');
    $birthdate = $_POST['applicant_birthdate'] ?? '';
    $parent = trim($_POST['parent_guardian_name'] ?? '');
    $father = trim($_POST['father_full_name'] ?? '');
    $mother = trim($_POST['mother_maiden_name'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $baptizedHere = $_POST['baptized_here'] ?? '';
    // Office checklist values are controlled by the secretary after physical
    // documents are received; public submissions always start as pending.
    $psaStatus = 'pending';
    $baptismalStatus = 'pending';
    $catecheticalStatus = 'pending';
    $confession = ($_POST['confession_completed'] ?? '') === '1' ? 1 : 0;
    $consent = ($_POST['parental_consent'] ?? '') === '1' ? 1 : 0;

    if (trim((string) ($formData['applicant_first'] ?? '')) === '') $errors[] = "Applicant's first name is required.";
    if (trim((string) ($formData['applicant_last'] ?? '')) === '') $errors[] = "Applicant's last name is required.";
    if (($e = validate_full_name($name)) !== null) $errors[] = $e;
    if ($birthdate === '') $errors[] = "Applicant's birthdate is required.";
    if ($birthdate !== '' && is_future_date($birthdate)) $errors[] = "Applicant's birthdate cannot be in the future.";
    if ($parent === '') $errors[] = 'Parent/Guardian name is required.';
    if (($e = validate_full_name($parent)) !== null) $errors[] = $e;
    if ($contact === '' || !is_valid_phone_number($contact)) $errors[] = 'A valid contact number is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (!in_array($baptizedHere, ['this_parish', 'elsewhere', 'not_sure'], true)) $errors[] = 'Please answer where the applicant was baptized.';
    if (!$consent) $errors[] = 'Parent/Guardian consent is required.';
    if (($_POST['privacy_consent'] ?? '') !== '1') $errors[] = 'You must accept the Privacy Notice before submitting.';

    if (!$errors) {
        $cap = public_submission_allowed($pdo, [$email]);
        if (!$cap['ok']) $errors[] = $cap['reason'];
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $trackingNumber = generate_tracking_number($pdo, 'sacrament_applications');
            // Online pre-application pauses at "awaiting payment": payment is
            // handled outside the system and marked paid by hand later.
            $pdo->prepare("INSERT INTO sacrament_applications (tracking_number, submission_channel, sacrament_type, status) VALUES (?, 'online', 'first_communion', 'awaiting_payment')")->execute([$trackingNumber]);
            $applicationId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO first_communion_applications
                (application_id, applicant_full_name, applicant_birthdate, parent_guardian_name,
                 father_full_name, mother_maiden_name, contact_number, email,
                 baptized_here, baptismal_cert_status,
                 psa_birth_certificate_status, catechetical_formation_status, confession_completed, parental_consent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$applicationId, $name, $birthdate, $parent, $father, $mother, $contact, $email, $baptizedHere === 'this_parish' ? 1 : 0, $baptismalStatus, $psaStatus, $catecheticalStatus, $confession, $consent]);
            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, "First Communion application submitted for {$name}");
            notify($pdo, 'application_new', 'New sacrament application', "First Communion application for {$name} ({$trackingNumber})", '/admin/requests/applications-queue.php');
            record_public_submission($pdo, [$email], 'application_first_communion');
            $pdo->commit();
            if (mail_request_received($email, $trackingNumber, 'First Communion Application')) log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
            unset($_SESSION['first_communion_app']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $trackingNumber = null;
            error_log('First Communion application failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while submitting your application. Please try again.';
        }
    }
}
require_once __DIR__ . '/../../includes/header.php';
function oldv(string $key): string { global $formData; return e(is_scalar($formData[$key] ?? null) ? (string) $formData[$key] : ''); }
function selectedv(string $key, string $value): string { global $formData; return (($formData[$key] ?? '') === $value) ? 'selected' : ''; }
?>
<section class="request-form">
    <div class="alert alert-info online-intake-notice">This form starts the parish review. The parish office will confirm any documents, preparation, and schedule. Submitting it does not confirm a schedule.</div>
    <h1>Application for the Sacrament of First Holy Communion</h1>
    <p>Formularyo para sa Unang Banal na Komunyon</p>
    <?php if ($trackingNumber): ?>
        <?php $successHeading = 'Your application has been submitted'; $successIsApplication = true; require __DIR__ . '/../../includes/submission-success.php'; ?>
    <?php else: ?>
        <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <ol class="request-steps" aria-label="Application progress">
                <?php foreach ($steps as $step => $label): ?>
                    <?php $state = $step === $currentStep ? 'is-current' : ($currentStep > $step ? 'is-done' : ''); ?>
                    <li class="<?= $state ?>"<?= $step === $currentStep ? ' aria-current="step"' : '' ?>><span><?= $step ?></span> <?= e($label) ?></li>
                <?php endforeach; ?>
            </ol>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="<?= $currentStep < count($steps) ? 'next' : 'final_submit' ?>">
                <?php if ($currentStep === 1): ?>
                    <fieldset><legend>Phase 1 of 5 — Child's Information</legend>
                        <div class="inline-fields">
                            <label>First Name * <input type="text" name="applicant_first" autocomplete="given-name" value="<?= oldv('applicant_first') ?>" required></label>
                            <label>Middle Name <span class="field-hint">(if any; leave blank if none)</span> <input type="text" name="applicant_middle" autocomplete="additional-name" value="<?= oldv('applicant_middle') ?>"></label>
                        </div>
                        <div class="inline-fields">
                            <label>Last Name * <input type="text" name="applicant_last" autocomplete="family-name" value="<?= oldv('applicant_last') ?>" required></label>
                            <label>Suffix <span class="field-hint">(optional)</span> <input type="text" name="applicant_suffix" autocomplete="honorific-suffix" value="<?= oldv('applicant_suffix') ?>"></label>
                        </div>
                        <label>Date of Birth * <input type="date" name="applicant_birthdate" max="<?= date('Y-m-d') ?>" value="<?= oldv('applicant_birthdate') ?>" required></label>
                    </fieldset>
                <?php elseif ($currentStep === 2): ?>
                    <fieldset><legend>Phase 2 of 5 — Family &amp; Contact</legend>
                        <label>Father's Full Name <input type="text" name="father_full_name" value="<?= oldv('father_full_name') ?>"></label>
                        <label>Mother's Full Maiden Name <input type="text" name="mother_maiden_name" value="<?= oldv('mother_maiden_name') ?>"></label>
                        <label>Parent/Guardian Full Name * <input type="text" name="parent_guardian_name" value="<?= oldv('parent_guardian_name') ?>" required></label>
                        <div class="inline-fields"><label>Contact Number * <input type="tel" name="contact_number" value="<?= oldv('contact_number') ?>" required></label><label>Email * <input type="email" name="email" value="<?= oldv('email') ?>" required></label></div>
                    </fieldset>
                <?php elseif ($currentStep === 3): ?>
                    <fieldset><legend>Phase 3 of 5 — Sacramental Record</legend>
                        <label>Where was the applicant baptized? * <select name="baptized_here" required><option value="">-- Select --</option><option value="this_parish" <?= selectedv('baptized_here', 'this_parish') ?>>This parish</option><option value="elsewhere" <?= selectedv('baptized_here', 'elsewhere') ?>>Another parish/church</option><option value="not_sure" <?= selectedv('baptized_here', 'not_sure') ?>>Not sure</option></select></label>
                    </fieldset>
                <?php elseif ($currentStep === 4): ?>
                    <fieldset><legend>Phase 4 of 5 — Parent / Guardian Consent</legend>
                        <label class="checkbox-label"><input type="checkbox" name="confession_completed" value="1" <?= ($formData['confession_completed'] ?? '') === '1' ? 'checked' : '' ?>> My child has received First Reconciliation</label>
                        <label class="checkbox-label"><input type="checkbox" name="parental_consent" value="1" <?= ($formData['parental_consent'] ?? '') === '1' ? 'checked' : '' ?> required> I request admission for my child and commit to guiding them in the Catholic faith.</label>
                        <label class="checkbox-label"><input type="checkbox" name="privacy_consent" value="1" <?= ($formData['privacy_consent'] ?? '') === '1' ? 'checked' : '' ?> required> As the child's parent/guardian, I have read and agree to the <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a> covering the processing of my child's personal data.</label>
                    </fieldset>
                <?php else: ?>
                    <fieldset><legend>Phase 5 of 5 — Review Your Application</legend>
                        <table class="review-table">
                            <tr><th>Full name</th><td><?= e(compose_person_name($formData, 'applicant')) ?></td></tr>
                            <tr><th>Date of birth</th><td><?= oldv('applicant_birthdate') ?></td></tr>
                            <tr><th>Parents</th><td><?= oldv('father_full_name') ?> · <?= oldv('mother_maiden_name') ?></td></tr>
                            <tr><th>Parent / Guardian</th><td><?= oldv('parent_guardian_name') ?></td></tr>
                            <tr><th>Contact</th><td><?= oldv('contact_number') ?> · <?= oldv('email') ?></td></tr>
                            <tr><th>Baptized at</th><td><?= e((string) ($formData['baptized_here'] ?? '')) ?></td></tr>
                        </table>
                        <label class="checkbox-label"><input type="checkbox" name="certify_accuracy" value="1" <?= ($formData['certify_accuracy'] ?? '') === '1' ? 'checked' : '' ?> required> I certify that this information is true and correct.</label>
                    </fieldset>
                <?php endif; ?>
                <div class="form-actions">
                    <?php if ($currentStep > 1): ?><button type="submit" name="_action" value="back" onclick="this.form.querySelector('[name=_action]').value='back'" formnovalidate class="btn btn-secondary">Back</button><?php endif; ?>
                    <?php if ($currentStep < count($steps)): ?><button type="submit" name="_action" value="next" class="btn">Next</button><?php else: ?><button type="submit" name="_action" value="final_submit" class="btn">Submit Application</button><?php endif; ?>
                </div>
            </form>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
