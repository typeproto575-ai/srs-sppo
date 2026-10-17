<?php
/** Philippine parish Holy Matrimony / pre-nuptial canonical application. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';

$basePath = base_path();
$pageTitle = 'Holy Matrimony Application';
$errors = [];
$trackingNumber = null;
$dateConflictWarning = null;

$steps = [
    1 => 'Wedding details',
    2 => 'Groom information',
    3 => 'Bride information',
    4 => 'Canonical status',
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
$wizardAction = $_POST['_action'] ?? '';
$isWizardNavigation = $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($wizardAction, ['next', 'back'], true);

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
        if ($currentStep === 5) {
            $formData['privacy_consent'] = ($_POST['privacy_consent'] ?? '') === '1' ? '1' : '0';
        }

        if ($wizardAction === 'back') {
            $wizard['current_step'] = max(1, $currentStep - 1);
        } else {
            $value = static function (string $key): string {
                return is_scalar($_POST[$key] ?? null) ? trim((string) $_POST[$key]) : '';
            };
            if ($currentStep === 1) {
                if ($value('preferred_wedding_date') === '') $errors[] = 'Target wedding date is required.';
                if (!in_array($value('preferred_wedding_venue'), ['church', 'barangay_chapel'], true)) $errors[] = 'Please select a valid wedding venue.';
            } elseif ($currentStep === 2 || $currentStep === 3) {
                $prefix = $currentStep === 2 ? 'groom' : 'bride';
                $label = ucfirst($prefix);
                if ($value($prefix . '_full_name') === '') $errors[] = "$label's full name is required.";
                $birthdate = $value($prefix . '_birthdate');
                if ($birthdate === '') $errors[] = "$label's date of birth is required.";
                if ($birthdate !== '' && is_future_date($birthdate)) $errors[] = "$label's date of birth cannot be in the future.";
                if ($value($prefix . '_birthplace') === '') $errors[] = "$label's place of birth is required.";
                if ($value($prefix . '_contact_number') === '' || !is_valid_phone_number($value($prefix . '_contact_number'))) $errors[] = "$label's valid contact number is required.";
                if (!filter_var($value($prefix . '_email'), FILTER_VALIDATE_EMAIL)) $errors[] = "$label's email address is required.";
                if (!in_array($value($prefix . '_marital_status'), ['single', 'widowed', 'annulled'], true)) $errors[] = "$label's marital status is required.";
            } elseif ($currentStep === 4) {
                foreach (['groom_baptized' => 'Groom', 'bride_baptized' => 'Bride', 'groom_confirmed' => 'Groom', 'bride_confirmed' => 'Bride'] as $key => $label) {
                    if (!in_array($value($key), ['yes', 'no'], true)) $errors[] = "Please indicate whether the $label is baptized and confirmed Catholic.";
                }
            } elseif ($currentStep === 5 && $value('privacy_consent') !== '1') {
                $errors[] = 'You must accept the Privacy Notice before submitting.';
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
    $_POST = array_merge($formData, $_POST);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isWizardNavigation && ($_POST['_action'] ?? '') === 'final_submit' && $currentStep === count($steps) && csrf_verify()) {
    $g = static function (string $key): string { return trim((string) ($_POST[$key] ?? '')); };
    $gd = $_POST['groom_birthdate'] ?? '';
    $bd = $_POST['bride_birthdate'] ?? '';
    $weddingDate = $_POST['preferred_wedding_date'] ?? '';
    $weddingTime = $g('preferred_wedding_time') ?: null;
    $venue = $g('preferred_wedding_venue') ?: 'church';
    $groomEmail = $g('groom_email'); $brideEmail = $g('bride_email');

    foreach ([['Groom', $g('groom_full_name'), $gd, $g('groom_birthplace'), $groomEmail], ['Bride', $g('bride_full_name'), $bd, $g('bride_birthplace'), $brideEmail]] as [$label, $name, $date, $place, $email]) {
        if ($name === '') $errors[] = "$label's full name is required.";
        if ($date === '') $errors[] = "$label's date of birth is required.";
        if ($date !== '' && is_future_date($date)) $errors[] = "$label's date of birth cannot be in the future.";
        if ($place === '') $errors[] = "$label's place of birth is required.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "$label's email address is required.";
    }
    if ($g('groom_contact_number') === '' || !is_valid_phone_number($g('groom_contact_number'))) $errors[] = "Groom's valid contact number is required.";
    if ($g('bride_contact_number') === '' || !is_valid_phone_number($g('bride_contact_number'))) $errors[] = "Bride's valid contact number is required.";
    if (!in_array($g('groom_marital_status'), ['single', 'widowed', 'annulled'], true)) $errors[] = "Groom's marital status is required.";
    if (!in_array($g('bride_marital_status'), ['single', 'widowed', 'annulled'], true)) $errors[] = "Bride's marital status is required.";
    if ($weddingDate === '') $errors[] = 'Target wedding date is required.';
    if (!in_array($venue, ['church', 'barangay_chapel'], true)) $errors[] = 'Please select a valid wedding venue.';
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
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $trackingNumber = generate_tracking_number($pdo, 'sacrament_applications');
            // Online pre-application pauses at "awaiting payment": payment is
            // handled outside the system and marked paid by hand later.
            $pdo->prepare("INSERT INTO sacrament_applications (tracking_number, submission_channel, sacrament_type, status) VALUES (?, 'online', 'marriage', 'awaiting_payment')")->execute([$trackingNumber]);
            $applicationId = (int) $pdo->lastInsertId();
            $data = [
                'application_id' => $applicationId,
                'groom_full_name' => $g('groom_full_name'), 'groom_birthdate' => $gd, 'groom_birthplace' => $g('groom_birthplace'),
                'groom_current_address' => $g('groom_current_address'), 'groom_address_length' => $g('groom_address_length'), 'groom_contact_number' => $g('groom_contact_number'), 'groom_email' => $groomEmail,
                'groom_religion' => $g('groom_religion'), 'groom_occupation' => $g('groom_occupation'), 'groom_father_name' => $g('groom_father_name'), 'groom_mother_maiden_name' => $g('groom_mother_maiden_name'),
                'groom_marital_status' => $g('groom_marital_status'), 'groom_baptized_here' => $g('groom_baptized') === 'yes' ? 1 : 0, 'groom_confirmed' => $g('groom_confirmed') === 'yes' ? 1 : 0, 'groom_previous_marriage' => $g('groom_previous_marriage') ?: 'no',
                'groom_baptismal_cert_status' => 'pending', 'groom_confirmation_cert_status' => 'pending',
                'bride_full_name' => $g('bride_full_name'), 'bride_birthdate' => $bd, 'bride_birthplace' => $g('bride_birthplace'),
                'bride_current_address' => $g('bride_current_address'), 'bride_address_length' => $g('bride_address_length'), 'bride_contact_number' => $g('bride_contact_number'), 'bride_email' => $brideEmail,
                'bride_religion' => $g('bride_religion'), 'bride_occupation' => $g('bride_occupation'), 'bride_father_name' => $g('bride_father_name'), 'bride_mother_maiden_name' => $g('bride_mother_maiden_name'),
                'bride_marital_status' => $g('bride_marital_status'), 'bride_baptized_here' => $g('bride_baptized') === 'yes' ? 1 : 0, 'bride_confirmed' => $g('bride_confirmed') === 'yes' ? 1 : 0, 'bride_previous_marriage' => $g('bride_previous_marriage') ?: 'no',
                'bride_baptismal_cert_status' => 'pending', 'bride_confirmation_cert_status' => 'pending',
                'preferred_wedding_date' => $weddingDate, 'preferred_wedding_time' => $weddingTime, 'preferred_wedding_venue' => $venue, 'assigned_priest_name' => $g('assigned_priest_name'),
                'civilly_married_to_each_other' => $g('civilly_married') === 'yes' ? 1 : 0, 'civil_marriage_date' => $g('civil_marriage_date') ?: null, 'civil_marriage_place' => $g('civil_marriage_place'), 'marriage_license_ref' => $g('marriage_license_ref'),
                // Documentation and canonical clearances are office-controlled;
                // public intake records them as pending until verified onsite.
                'groom_cenomar_status' => 'pending', 'bride_cenomar_status' => 'pending', 'marriage_license_status' => 'pending', 'psa_marriage_contract_status' => 'not_applicable',
                'groom_baptismal_annotated' => 0, 'bride_baptismal_annotated' => 0, 'groom_confirmation_annotated' => 0, 'bride_confirmation_annotated' => 0,
                'pre_cana_seminar_completed' => 0, 'pre_cana_completion_date' => null, 'canonical_interview_completed' => 0, 'canonical_interview_date' => null,
                'groom_banns_status' => 'pending', 'bride_banns_status' => 'pending',
            ];
            $cols = array_keys($data); $ph = array_map(static fn($k) => ':' . $k, $cols);
            $stmt = $pdo->prepare('INSERT INTO marriage_applications (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')');
            foreach ($data as $key => $value) $stmt->bindValue(':' . $key, $value);
            $stmt->execute();
            $marriageApplicationId = (int) $pdo->lastInsertId();
            $sponsorNames = $_POST['sponsor_name'] ?? [];
            if (!is_array($sponsorNames)) { $sponsorNames = []; }
            $sponsorResidences = $_POST['sponsor_residence'] ?? [];
            if (!is_array($sponsorResidences)) { $sponsorResidences = []; }
            $sponsorStmt = $pdo->prepare('INSERT INTO marriage_principal_sponsors (marriage_application_id, sponsor_name, sponsor_residence) VALUES (?, ?, ?)');
            foreach ($sponsorNames as $i => $name) { if (!is_string($name)) continue; $name = trim($name); if ($name !== '') $sponsorStmt->execute([$marriageApplicationId, $name, trim(isset($sponsorResidences[$i]) && is_string($sponsorResidences[$i]) ? $sponsorResidences[$i] : '')]); }
            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, 'Holy Matrimony application submitted for ' . $data['groom_full_name'] . ' & ' . $data['bride_full_name']);
            notify($pdo, 'application_new', 'New sacrament application', "Holy Matrimony application ({$trackingNumber})", '/admin/requests/applications-queue.php');
            record_public_submission($pdo, [$groomEmail, $brideEmail], 'application_marriage');
            $pdo->commit();
            unset($_SESSION['marriage_app']);
            if (mail_request_received($groomEmail, $trackingNumber, 'Holy Matrimony Application')) log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, 'Confirmation email sent to groom.');
            if ($brideEmail !== $groomEmail && mail_request_received($brideEmail, $trackingNumber, 'Holy Matrimony Application')) log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, 'Confirmation email sent to bride.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $trackingNumber = null; error_log('Holy Matrimony application failed: ' . $e->getMessage()); $errors[] = 'Something went wrong while submitting your application. Please try again.';
        }
    }
}
require_once __DIR__ . '/../../includes/header.php';
function mv(string $key): string { global $formData; $v = $formData[$key] ?? ''; return e(is_string($v) ? $v : ''); }
function msel(string $key, string $value): string { global $formData; return ($formData[$key] ?? '') === $value ? 'selected' : ''; }
?>
<section class="request-form">
<div class="alert alert-info online-intake-notice"><strong>Pre-nuptial canonical application:</strong> The parish office will verify documents, interview both parties, publish banns, and approve the wedding separately.</div>
<h1>Application for the Sacrament of Holy Matrimony</h1><p>Pre-Nuptial Canonical Application Form</p>
<?php if ($trackingNumber): ?><?php $successHeading = 'Your application has been submitted'; require __DIR__ . '/../../includes/submission-success.php'; ?><?php else: ?>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($dateConflictWarning): ?><div class="alert alert-warning"><?= e($dateConflictWarning) ?></div><?php endif; ?>
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
<fieldset><legend>Phase 1 of 5 — Wedding Details</legend><div class="inline-fields"><label>Target Wedding Date * <input type="date" name="preferred_wedding_date" value="<?= mv('preferred_wedding_date') ?>" required></label><label>Time <input type="time" name="preferred_wedding_time" value="<?= mv('preferred_wedding_time') ?>"></label></div><div class="inline-fields"><label>Venue <select name="preferred_wedding_venue"><option value="church" <?= msel('preferred_wedding_venue','church') ?>>Parish Church</option><option value="barangay_chapel" <?= msel('preferred_wedding_venue','barangay_chapel') ?>>Barangay Chapel</option></select></label><label>Officiating Priest <input type="text" name="assigned_priest_name" value="<?= mv('assigned_priest_name') ?>"></label></div></fieldset>
<?php elseif ($currentStep === 2): ?>
<fieldset><legend>Phase 2 of 5 — Groom's Information</legend><label>Full Name * <input name="groom_full_name" value="<?= mv('groom_full_name') ?>" required></label><div class="inline-fields"><label>Date of Birth * <input type="date" name="groom_birthdate" value="<?= mv('groom_birthdate') ?>" required></label><label>Place of Birth * <input name="groom_birthplace" value="<?= mv('groom_birthplace') ?>" required></label></div><label>Current Address <input name="groom_current_address" value="<?= mv('groom_current_address') ?>"></label><div class="inline-fields"><label>Length of Stay <input name="groom_address_length" value="<?= mv('groom_address_length') ?>"></label><label>Telephone / Mobile * <input name="groom_contact_number" value="<?= mv('groom_contact_number') ?>" required></label></div><div class="inline-fields"><label>Religion / Sect <input name="groom_religion" value="<?= mv('groom_religion') ?>"></label><label>Occupation / Employment <input name="groom_occupation" value="<?= mv('groom_occupation') ?>"></label></div><label>Email * <input type="email" name="groom_email" value="<?= mv('groom_email') ?>" required></label><div class="inline-fields"><label>Father's Full Name <input name="groom_father_name" value="<?= mv('groom_father_name') ?>"></label><label>Mother's Full Maiden Name <input name="groom_mother_maiden_name" value="<?= mv('groom_mother_maiden_name') ?>"></label></div><div class="inline-fields"><label>Marital Status * <select name="groom_marital_status" required><option value="">-- Select --</option><option value="single" <?= msel('groom_marital_status','single') ?>>Single</option><option value="widowed" <?= msel('groom_marital_status','widowed') ?>>Widowed</option><option value="annulled" <?= msel('groom_marital_status','annulled') ?>>Annulled</option></select></label><label>Previous Marriage <select name="groom_previous_marriage"><option value="no" <?= msel('groom_previous_marriage','no') ?>>No</option><option value="church" <?= msel('groom_previous_marriage','church') ?>>Yes — Church</option><option value="civil" <?= msel('groom_previous_marriage','civil') ?>>Yes — Civil</option></select></label></div></fieldset>
<?php elseif ($currentStep === 3): ?>
<fieldset><legend>Phase 3 of 5 — Bride's Information</legend><label>Full Name * <input name="bride_full_name" value="<?= mv('bride_full_name') ?>" required></label><div class="inline-fields"><label>Date of Birth * <input type="date" name="bride_birthdate" value="<?= mv('bride_birthdate') ?>" required></label><label>Place of Birth * <input name="bride_birthplace" value="<?= mv('bride_birthplace') ?>" required></label></div><label>Current Address <input name="bride_current_address" value="<?= mv('bride_current_address') ?>"></label><div class="inline-fields"><label>Length of Stay <input name="bride_address_length" value="<?= mv('bride_address_length') ?>"></label><label>Telephone / Mobile * <input name="bride_contact_number" value="<?= mv('bride_contact_number') ?>" required></label></div><div class="inline-fields"><label>Religion / Sect <input name="bride_religion" value="<?= mv('bride_religion') ?>"></label><label>Occupation / Employment <input name="bride_occupation" value="<?= mv('bride_occupation') ?>"></label></div><label>Email * <input type="email" name="bride_email" value="<?= mv('bride_email') ?>" required></label><div class="inline-fields"><label>Father's Full Name <input name="bride_father_name" value="<?= mv('bride_father_name') ?>"></label><label>Mother's Full Maiden Name <input name="bride_mother_maiden_name" value="<?= mv('bride_mother_maiden_name') ?>"></label></div><div class="inline-fields"><label>Marital Status * <select name="bride_marital_status" required><option value="">-- Select --</option><option value="single" <?= msel('bride_marital_status','single') ?>>Single</option><option value="widowed" <?= msel('bride_marital_status','widowed') ?>>Widowed</option><option value="annulled" <?= msel('bride_marital_status','annulled') ?>>Annulled</option></select></label><label>Previous Marriage <select name="bride_previous_marriage"><option value="no" <?= msel('bride_previous_marriage','no') ?>>No</option><option value="church" <?= msel('bride_previous_marriage','church') ?>>Yes — Church</option><option value="civil" <?= msel('bride_previous_marriage','civil') ?>>Yes — Civil</option></select></label></div></fieldset>
<?php elseif ($currentStep === 4): ?>
<fieldset><legend>Phase 4 of 5 — Canonical &amp; Legal Status</legend><div class="inline-fields"><label>Groom baptized Catholic? <select name="groom_baptized"><option value="">-- Select --</option><option value="yes" <?= msel('groom_baptized','yes') ?>>Yes</option><option value="no" <?= msel('groom_baptized','no') ?>>No</option></select></label><label>Bride baptized Catholic? <select name="bride_baptized"><option value="">-- Select --</option><option value="yes" <?= msel('bride_baptized','yes') ?>>Yes</option><option value="no" <?= msel('bride_baptized','no') ?>>No</option></select></label></div><div class="inline-fields"><label>Groom confirmed Catholic? <select name="groom_confirmed"><option value="">-- Select --</option><option value="yes" <?= msel('groom_confirmed','yes') ?>>Yes</option><option value="no" <?= msel('groom_confirmed','no') ?>>No</option></select></label><label>Bride confirmed Catholic? <select name="bride_confirmed"><option value="">-- Select --</option><option value="yes" <?= msel('bride_confirmed','yes') ?>>Yes</option><option value="no" <?= msel('bride_confirmed','no') ?>>No</option></select></label></div><label>Are you civilly married to each other? <select name="civilly_married"><option value="no" <?= msel('civilly_married','no') ?>>No</option><option value="yes" <?= msel('civilly_married','yes') ?>>Yes</option></select></label><div class="inline-fields"><label>Date <input type="date" name="civil_marriage_date" value="<?= mv('civil_marriage_date') ?>"></label><label>Place <input name="civil_marriage_place" value="<?= mv('civil_marriage_place') ?>"></label></div></fieldset>
<?php endif; ?>

<?php
// Render one row per previously submitted non-blank sponsor (validation
// re-render), otherwise a single blank row — max(1, non-blank count),
// clamped to 4. New rows are cloned client-side — see main.js, section 9.
$rawSponsorNames = $formData['sponsor_name'] ?? [];
if (!is_array($rawSponsorNames)) { $rawSponsorNames = []; }
$rawSponsorNames = array_values($rawSponsorNames);
$rawSponsorRes = $formData['sponsor_residence'] ?? [];
if (!is_array($rawSponsorRes)) { $rawSponsorRes = []; }
$rawSponsorRes = array_values($rawSponsorRes);
$sponsorRows = [];
$sponsorRes = [];
foreach ($rawSponsorNames as $i => $rawName) {
    if (!is_string($rawName) || trim($rawName) === '') { continue; }
    $rawR = $rawSponsorRes[$i] ?? '';
    $sponsorRows[] = $rawName;
    $sponsorRes[] = is_string($rawR) ? $rawR : '';
    if (count($sponsorRows) >= 4) { break; }
}
if (count($sponsorRows) === 0) { $sponsorRows = ['']; $sponsorRes = ['']; }
?>
<?php if ($currentStep === 4): ?>
<fieldset><legend>Principal Sponsors</legend><div data-repeat-group="sponsor" data-repeat-max="4"><?php foreach ($sponsorRows as $i => $rowName): ?><div class="godparent-row"><div class="inline-fields"><label>Sponsor Name <input name="sponsor_name[]" value="<?= e(is_string($rowName) ? $rowName : '') ?>"></label><label>Residence <input name="sponsor_residence[]" value="<?= e(isset($sponsorRes[$i]) && is_string($sponsorRes[$i]) ? $sponsorRes[$i] : '') ?>"></label></div><button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button></div><?php endforeach; ?></div><button type="button" class="btn btn-secondary repeat-add" data-repeat-add="sponsor">Add another sponsor</button></fieldset>
<?php elseif ($currentStep === 5): ?>
<fieldset><legend>Phase 5 of 5 — Review Your Application</legend>
<table class="review-table">
<tr><th>Wedding date</th><td><?= mv('preferred_wedding_date') ?> <?= mv('preferred_wedding_time') ?></td></tr>
<tr><th>Venue</th><td><?= mv('preferred_wedding_venue') ?></td></tr>
<tr><th>Groom</th><td><?= mv('groom_full_name') ?> · <?= mv('groom_birthdate') ?> · <?= mv('groom_email') ?> · <?= mv('groom_contact_number') ?></td></tr>
<tr><th>Bride</th><td><?= mv('bride_full_name') ?> · <?= mv('bride_birthdate') ?> · <?= mv('bride_email') ?> · <?= mv('bride_contact_number') ?></td></tr>
<tr><th>Groom's marital status</th><td><?= mv('groom_marital_status') ?></td></tr>
<tr><th>Bride's marital status</th><td><?= mv('bride_marital_status') ?></td></tr>
<tr><th>Baptism / confirmation</th><td>Groom: <?= mv('groom_baptized') ?> / <?= mv('groom_confirmed') ?>; Bride: <?= mv('bride_baptized') ?> / <?= mv('bride_confirmed') ?></td></tr>
<tr><th>Civil marriage</th><td><?= mv('civilly_married') ?> <?= mv('civil_marriage_date') ?> <?= mv('civil_marriage_place') ?></td></tr>
<tr><th>Principal sponsors</th><td><?= e(implode(', ', array_filter($sponsorRows, 'is_string'))) ?></td></tr>
</table>
</fieldset>
<label class="checkbox-label"><input type="checkbox" name="privacy_consent" value="1" <?= ($formData['privacy_consent'] ?? '') === '1' ? 'checked' : '' ?> required> I have read and agree to the <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a>.</label>
<?php endif; ?>
<div class="form-actions">
<?php if ($currentStep > 1): ?><button type="submit" name="_action" value="back" onclick="this.form.querySelector('[name=_action]').value='back'" formnovalidate class="btn btn-secondary">Back</button><?php endif; ?>
<?php if ($currentStep < count($steps)): ?><button type="submit" name="_action" value="next" class="btn">Next</button><?php else: ?><button type="submit" name="_action" value="final_submit" class="btn">Submit Application</button><?php endif; ?>
</div>
</form>
<?php endif; ?></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
