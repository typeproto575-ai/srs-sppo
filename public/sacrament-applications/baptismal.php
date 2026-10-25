<?php
/**
 * =====================================================================
 * SRS-SPPO BAPTISMAL SACRAMENT APPLICATION (public form)
 * =====================================================================
 * Purpose: Online application to HAVE A CHILD BAPTIZED at the parish.
 *          Inserts a shared row into sacrament_applications plus the
 *          details row in baptismal_applications and one row per
 *          godparent in baptismal_application_godparents.
 * Flow:    Enters as 'submitted'; the secretary reviews it in
 *          admin/requests/applications-queue.php. No online ID upload â€”
 *          identity is verified in person at pickup.
 * Limit:   Successful submissions are capped at 5 per email address and 20
 *          per client IP over a rolling 24 hours; the accounting row lives
 *          in submission_rate_limits (migrations/018_add_submission_rate_limits.sql).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email

// Subfolder-safe URL prefix for links inside the success message.
$basePath = $basePath ?? base_path();

$pageTitle = 'Baptismal Application';
$errors    = [];
$trackingNumber = null;

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

// =====================================================================
// POST HANDLING â€” Navigation and Final Submit
// =====================================================================
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

                if (fd('child_first', $_POST) === '') $errors[] = "Child's first name is required.";
                if (fd('child_last', $_POST) === '') $errors[] = "Child's last name is required.";
                if (($e = validate_full_name($childName)) !== null) $errors[] = $e;
                if ($childBirthdate === '')  $errors[] = "Child's birthdate is required.";
                if (is_future_date($childBirthdate)) $errors[] = "Child's birthdate cannot be in the future.";
                if ($childBirthplace === '') $errors[] = "Child's birthplace is required.";
            }

            if ($currentStep === STEP_FAMILY_REQS) {
                $parentGuardian = fd('parent_guardian_name', $_POST);
                if ($parentGuardian === '') $errors[] = 'Parent/Guardian name is required.';
                if (($e = validate_full_name($parentGuardian)) !== null) $errors[] = $e;
            }

            if ($currentStep === STEP_CONTACT_CONSENT) {
                $contactNumber = fd('contact_number', $_POST);
                $email         = fd('email', $_POST);
                $privacyConsent = $_POST['privacy_consent'] ?? '';

                if ($contactNumber === '' || !is_valid_phone_number($contactNumber)) {
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
            $fatherName      = fd('father_full_name',      $formData);
            $motherMaidenName = fd('mother_maiden_name',   $formData);
            $parentGuardian  = fd('parent_guardian_name',  $formData);
            $contactNumber   = fd('contact_number',        $formData);
            $email           = fd('email',                 $formData);
            $preferredDate   = $formData['preferred_date'] ?? null;
            $privacyConsent  = $formData['privacy_consent'] ?? '';

            $godfatherNames      = fa('godfather_name',      $formData);
            $godmotherNames      = fa('godmother_name',      $formData);

            if (fd('child_first', $formData) === '') $errors[] = "Child's first name is required.";
            if (fd('child_last', $formData) === '') $errors[] = "Child's last name is required.";
            if (($e = validate_full_name($childName)) !== null) $errors[] = $e;
            if ($childBirthdate === '')  $errors[] = "Child's birthdate is required.";
            if (is_future_date($childBirthdate)) $errors[] = "Child's birthdate cannot be in the future.";
            if ($childBirthplace === '') $errors[] = "Child's birthplace is required.";
            if ($parentGuardian === '')  $errors[] = 'Parent/Guardian name is required.';
            if ($contactNumber === '')   $errors[] = 'Contact number is required.';
            if ($contactNumber !== '' && !is_valid_phone_number($contactNumber)) {
                $errors[] = 'Please enter a valid contact number (at least 10 digits).';
            }
            if ($email === '')           $errors[] = 'Email address is required.';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }

            if (empty($errors)) {
                try {
                    $submissionCap = public_submission_allowed($pdo, [$email]);
                    if (!$submissionCap['ok']) {
                        $errors[] = 'You have reached the submission limit for this email address. Please try again later.';
                    }

                    if (empty($errors)) {
                        $pdo->beginTransaction();
                        $trackingNumber = generate_tracking_number($pdo, 'sacrament_applications');
                        $stmt = $pdo->prepare("INSERT INTO sacrament_applications
                            (tracking_number, submission_channel, sacrament_type, status)
                            VALUES (?, 'online', 'baptismal', 'awaiting_payment')");
                        $stmt->execute([$trackingNumber]);
                        $applicationId = (int) $pdo->lastInsertId();

                        $stmt = $pdo->prepare("INSERT INTO baptismal_applications
                            (application_id, child_full_name, child_birthdate, child_birthplace,
                             father_full_name, mother_maiden_name, parents_union_type, parent_guardian_name,
                             contact_number, email, preferred_date)
                            VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)");
                        $stmt->execute([
                            $applicationId, $childName, $childBirthdate, $childBirthplace,
                            $fatherName, $motherMaidenName, $parentGuardian,
                            $contactNumber, $email, $preferredDate ?: null,
                        ]);
                        $baptismalApplicationId = (int) $pdo->lastInsertId();

                        $godparentStmt = $pdo->prepare("INSERT INTO baptismal_application_godparents
                            (baptismal_application_id, godparent_name, godparent_type)
                            VALUES (?, ?, ?)");
                        foreach ([
                            ['names' => $godfatherNames, 'type' => 'godfather'],
                            ['names' => $godmotherNames, 'type' => 'godmother'],
                        ] as $group) {
                            foreach ($group['names'] as $i => $name) {
                                if (!is_scalar($name) || trim((string) $name) === '') continue;
                                $godparentStmt->execute([
                                    $baptismalApplicationId,
                                    trim((string) $name),
                                    $group['type'],
                                ]);
                            }
                        }

                        log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, "Baptismal application submitted for {$childName}");
                        notify($pdo, 'application_new', 'New sacrament application', "Baptismal application for {$childName} ({$trackingNumber})", '/admin/requests/applications-queue.php');
                        record_public_submission($pdo, [$email], 'application_baptismal');
                        $pdo->commit();

                        try {
                            if (mail_request_received($email, $trackingNumber, 'Baptismal Application', $pdo, 'sacrament_applications', $applicationId)) {
                                log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
                            }
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
                    $errors[] = 'Something went wrong while submitting your application. Please try again.';
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
        <h1>Application Submitted</h1>
        <?php $successHeading = 'Keep this tracking number'; $successIsApplication = true; require __DIR__ . '/../../includes/submission-success.php'; ?>
        <a href="baptismal.php?reset=1" class="btn">Start New Application</a>
    </div>
</section>
<?php elseif ($currentStep === STEP_APP_DETAILS): ?>
    <h1><?= e($pageTitle) ?></h1>
    <ol class="request-steps" aria-label="Progress">
        <?php foreach ($stepOrder as $i => $key): ?>
            <?php $state = $key === $currentStep ? 'is-current' : ($currentStep > $key ? 'is-done' : ''); ?>
            <li class="<?= $state ?>"<?= $key === $currentStep ? ' aria-current="step"' : '' ?>><span><?= $i + 1 ?></span> <?= e($stepLabels[$key]) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php if (!empty($errors)): ?>
        <div class="error-panel">
            <ul><?= implode('', array_map(fn($e) => "<li>{$e}</li>", $errors)) ?></ul>
        </div>
    <?php endif; ?>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="next">
        <fieldset>
            <legend>Phase 1 of 4 — Child Information</legend>
            <div class="inline-fields">
                <label>Child's First Name *
                    <input type="text" name="child_first" autocomplete="given-name" value="<?= e(fd('child_first', $formData)) ?>" required>
                </label>
                <label>Middle Name <span class="field-hint">(if any; leave blank if none)</span>
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
            <label>Date of Birth
                <input type="date" name="child_birthdate" value="<?= e($formData['child_birthdate'] ?? '') ?>" required>
            </label>
            <label>Place of Birth
                <input type="text" name="child_birthplace" value="<?= e(fd('child_birthplace', $formData)) ?>" required>
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
        <div class="error-panel">
            <ul><?= implode('', array_map(fn($e) => "<li>{$e}</li>", $errors)) ?></ul>
        </div>
    <?php endif; ?>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="next">
        <fieldset>
            <legend>Phase 2 of 4 — Family Information</legend>
            <label>Father's Full Name
                <input type="text" name="father_full_name" value="<?= e(fd('father_full_name', $formData)) ?>">
            </label>
            <label>Mother's Maiden Name
                <input type="text" name="mother_maiden_name" value="<?= e(fd('mother_maiden_name', $formData)) ?>">
            </label>
            <label>Parent/Guardian Name
                <input type="text" name="parent_guardian_name" value="<?= e(fd('parent_guardian_name', $formData)) ?>" required>
            </label>
            <?php
            $godfatherRows = fa('godfather_name', $formData);
            $godmotherRows = fa('godmother_name', $formData);
            if (!$godfatherRows) $godfatherRows = [''];
            if (!$godmotherRows) $godmotherRows = [''];
            ?>
            <fieldset>
                <legend>Godfathers (Ninong)</legend>
                <p class="field-hint">Up to 8 godfathers. Blank names are ignored.</p>
                <div data-repeat-group="godfather" data-repeat-max="8">
                    <?php foreach ($godfatherRows as $i => $name): ?>
                        <div class="godparent-row">
                            <div class="inline-fields">
                                <label>Godfather Name
                                    <input type="text" name="godfather_name[]" value="<?= e(is_scalar($name) ? (string) $name : '') ?>">
                                </label>
                            </div>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godfather">Add another godfather</button>
            </fieldset>
            <fieldset>
                <legend>Godmothers (Ninang)</legend>
                <p class="field-hint">Up to 8 godmothers. Blank names are ignored.</p>
                <div data-repeat-group="godmother" data-repeat-max="8">
                    <?php foreach ($godmotherRows as $i => $name): ?>
                        <div class="godparent-row">
                            <div class="inline-fields">
                                <label>Godmother Name
                                    <input type="text" name="godmother_name[]" value="<?= e(is_scalar($name) ? (string) $name : '') ?>">
                                </label>
                            </div>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endforeach; ?>
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
        <div class="error-panel">
            <ul><?= implode('', array_map(fn($e) => "<li>{$e}</li>", $errors)) ?></ul>
        </div>
    <?php endif; ?>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="next">
        <fieldset>
            <legend>Phase 3 of 4 — Contact & Consent</legend>
            <label>Contact Number
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
            <button type="submit" class="btn">Review Application</button>
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
        <div class="error-panel">
            <ul><?= implode('', array_map(fn($e) => "<li>{$e}</li>", $errors)) ?></ul>
        </div>
    <?php endif; ?>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="final_submit">
        <fieldset>
            <legend>Review Your Information</legend>
            <table class="review-table">
                <tr><th>Child's Full Name</th><td><?= e(compose_person_name($formData, 'child')) ?></td></tr>
                <tr><th>Date of Birth</th><td><?= e($formData['child_birthdate'] ?? '') ?></td></tr>
                <tr><th>Place of Birth</th><td><?= e(fd('child_birthplace', $formData)) ?></td></tr>
                <tr><th>Father's Name</th><td><?= e(fd('father_full_name', $formData)) ?></td></tr>
                <tr><th>Mother's Maiden Name</th><td><?= e(fd('mother_maiden_name', $formData)) ?></td></tr>
                <tr><th>Parent/Guardian</th><td><?= e(fd('parent_guardian_name', $formData)) ?></td></tr>
                <tr><th>Godfathers</th><td><?= $reviewNames(fa('godfather_name', $formData)) ?></td></tr>
                <tr><th>Godmothers</th><td><?= $reviewNames(fa('godmother_name', $formData)) ?></td></tr>
                <tr><th>Contact Number</th><td><?= e(fd('contact_number', $formData)) ?></td></tr>
                <tr><th>Email</th><td><?= e(fd('email', $formData)) ?></td></tr>
                <tr><th>Preferred Date</th><td><?= e((string) ($formData['preferred_date'] ?? '')) ?></td></tr>
            </table>
        </fieldset>
        <label class="checkbox-label" style="display:block; margin-top: 1rem;">
            <input type="checkbox" name="certify_accuracy" value="1" required>
            I certify that all information provided above is true and correct to the best of my knowledge.
        </label>
        <div class="form-actions">
            <button type="submit" name="_action" value="back" onclick="this.form.querySelector('[name=_action]').value='back'" formnovalidate class="btn btn-secondary">Back to Edit</button>
            <button type="submit" class="btn">Submit Application</button>
        </div>
    </form>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
