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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) $errors[] = 'Your session expired. Please submit the form again.';
    $name = trim($_POST['applicant_full_name'] ?? '');
    $birthdate = $_POST['applicant_birthdate'] ?? '';
    $gender = trim($_POST['applicant_gender'] ?? '');
    $birthplace = trim($_POST['applicant_birthplace'] ?? '');
    $address = trim($_POST['current_address'] ?? '');
    $school = trim($_POST['school_name'] ?? '');
    $grade = trim($_POST['grade_level_section'] ?? '');
    $adviser = trim($_POST['adviser_name'] ?? '');
    $parent = trim($_POST['parent_guardian_name'] ?? '');
    $father = trim($_POST['father_full_name'] ?? '');
    $mother = trim($_POST['mother_maiden_name'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $parentsMarried = trim($_POST['parents_catholic_married'] ?? '');
    $baptizedHere = $_POST['baptized_here'] ?? '';
    $baptismDate = $_POST['baptism_date'] ?? null;
    $baptismParish = trim($_POST['baptism_parish_name'] ?? '');
    $baptismAddress = trim($_POST['baptism_parish_address'] ?? '');
    // Office checklist values are controlled by the secretary after physical
    // documents are received; public submissions always start as pending.
    $psaStatus = 'pending';
    $baptismalStatus = 'pending';
    $catecheticalStatus = 'pending';
    $confession = ($_POST['confession_completed'] ?? '') === '1' ? 1 : 0;
    $consent = ($_POST['parental_consent'] ?? '') === '1' ? 1 : 0;
    $preferredBatch = trim($_POST['preferred_batch'] ?? '');

    if ($name === '') $errors[] = "Applicant's full name is required.";
    if ($birthdate === '') $errors[] = "Applicant's birthdate is required.";
    if ($birthdate !== '' && is_future_date($birthdate)) $errors[] = "Applicant's birthdate cannot be in the future.";
    if ($parent === '') $errors[] = 'Parent/Guardian name is required.';
    if ($contact === '' || !is_valid_phone_number($contact)) $errors[] = 'A valid contact number is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (!in_array($gender, ['male', 'female'], true)) $errors[] = 'Please select the applicant gender.';
    if (!in_array($parentsMarried, ['yes', 'no', 'civil_only'], true)) $errors[] = 'Please answer the parents Catholic-marriage question.';
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
                (application_id, applicant_full_name, applicant_birthdate, applicant_gender, applicant_birthplace,
                 current_address, school_name, grade_level_section, adviser_name, parent_guardian_name,
                 father_full_name, mother_maiden_name, contact_number, email, parents_catholic_married,
                 baptized_here, baptism_date, baptism_parish_name, baptism_parish_address, baptismal_cert_status,
                 psa_birth_certificate_status, catechetical_formation_status, confession_completed, parental_consent, preferred_batch)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$applicationId, $name, $birthdate, $gender, $birthplace, $address, $school, $grade, $adviser, $parent, $father, $mother, $contact, $email, $parentsMarried, $baptizedHere === 'this_parish' ? 1 : 0, $baptismDate ?: null, $baptismParish, $baptismAddress, $baptismalStatus, $psaStatus, $catecheticalStatus, $confession, $consent, $preferredBatch]);
            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId, "First Communion application submitted for {$name}");
            notify($pdo, 'application_new', 'New sacrament application', "First Communion application for {$name} ({$trackingNumber})", '/admin/requests/applications-queue.php');
            record_public_submission($pdo, [$email], 'application_first_communion');
            $pdo->commit();
            if (mail_request_received($email, $trackingNumber, 'First Communion Application')) log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $trackingNumber = null;
            error_log('First Communion application failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while submitting your application. Please try again.';
        }
    }
}
require_once __DIR__ . '/../../includes/header.php';
function oldv(string $key): string { return e($_POST[$key] ?? ''); }
?>
<section class="request-form">
    <div class="alert alert-info online-intake-notice"><strong>Philippine parish application:</strong> This starts the parish review process. It does not guarantee admission or a schedule; the office will verify documents and confirm the batch.</div>
    <h1>Application for the Sacrament of First Holy Communion</h1>
    <p>Formularyo para sa Unang Banal na Komunyon</p>
    <?php if ($trackingNumber): ?>
        <?php $successHeading = 'Your application has been submitted'; require __DIR__ . '/../../includes/submission-success.php'; ?>
    <?php else: ?>
        <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" data-review data-review-title="Check your application before sending" data-review-confirm="Confirm and submit application">
            <?= csrf_field() ?>
            <fieldset><legend>I. Candidate's Profile</legend>
                <label>Full Name * <input type="text" name="applicant_full_name" value="<?= oldv('applicant_full_name') ?>" required></label>
                <div class="inline-fields"><label>Date of Birth * <input type="date" name="applicant_birthdate" max="<?= date('Y-m-d') ?>" value="<?= oldv('applicant_birthdate') ?>" required></label><label>Gender * <select name="applicant_gender" required><option value="">-- Select --</option><option value="male" <?= ($_POST['applicant_gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option><option value="female" <?= ($_POST['applicant_gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option></select></label></div>
                <label>Place of Birth <input type="text" name="applicant_birthplace" value="<?= oldv('applicant_birthplace') ?>"></label>
                <label>Current Address <input type="text" name="current_address" value="<?= oldv('current_address') ?>"></label>
                <label>School Currently Attending <input type="text" name="school_name" value="<?= oldv('school_name') ?>"></label>
                <div class="inline-fields"><label>Grade Level &amp; Section <input type="text" name="grade_level_section" value="<?= oldv('grade_level_section') ?>"></label><label>Adviser <input type="text" name="adviser_name" value="<?= oldv('adviser_name') ?>"></label></div>
            </fieldset>
            <fieldset><legend>II. Family Background</legend>
                <label>Father's Full Name <input type="text" name="father_full_name" value="<?= oldv('father_full_name') ?>"></label>
                <label>Mother's Full Maiden Name <input type="text" name="mother_maiden_name" value="<?= oldv('mother_maiden_name') ?>"></label>
                <label>Parent/Guardian Full Name * <input type="text" name="parent_guardian_name" value="<?= oldv('parent_guardian_name') ?>" required></label>
                <div class="inline-fields"><label>Contact Number * <input type="text" name="contact_number" value="<?= oldv('contact_number') ?>" required></label><label>Email * <input type="email" name="email" value="<?= oldv('email') ?>" required></label></div>
                <label>Are Parents Married in the Catholic Church? * <select name="parents_catholic_married" required><option value="">-- Select --</option><option value="yes">Yes</option><option value="no">No</option><option value="civil_only">Civilly Only</option></select></label>
            </fieldset>
            <fieldset><legend>III. Sacramental Record</legend>
                <label>Where was the applicant baptized? * <select name="baptized_here" required><option value="">-- Select --</option><option value="this_parish">This parish</option><option value="elsewhere">Another parish/church</option><option value="not_sure">Not sure</option></select></label>
                <div class="inline-fields"><label>Date of Holy Baptism <input type="date" name="baptism_date" value="<?= oldv('baptism_date') ?>"></label><label>Parish Church of Baptism <input type="text" name="baptism_parish_name" value="<?= oldv('baptism_parish_name') ?>"></label></div>
                <label>Address of Baptismal Church <input type="text" name="baptism_parish_address" value="<?= oldv('baptism_parish_address') ?>"></label>
            </fieldset>
            <fieldset><legend>IV. Parent / Guardian Confirmation</legend>
                <label class="checkbox-label"><input type="checkbox" name="confession_completed" value="1"> Record of First Sacrament of Reconciliation completed</label>
                <label class="checkbox-label"><input type="checkbox" name="parental_consent" value="1" required> I request admission for my child and commit to guiding them in the Catholic faith.</label>
                <label>Suggested Batch or Date <input type="text" name="preferred_batch" value="<?= oldv('preferred_batch') ?>"></label>
            </fieldset>
            <label class="checkbox-label"><input type="checkbox" name="privacy_consent" value="1" <?= ($_POST['privacy_consent'] ?? '') === '1' ? 'checked' : '' ?> required> As the child's parent/guardian, I have read and agree to the <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a> covering the processing of my child's personal data.</label>
            <button type="submit" class="btn">Review and Submit Application</button>
        </form>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
