<?php
/**
 * =====================================================================
 * SRS-SPPO CONFIRMATION SACRAMENT APPLICATION (public form)
 * =====================================================================
 * Purpose: Online application to RECEIVE THE SACRAMENT OF CONFIRMATION
 *          at the parish. Inserts a shared row into sacrament_applications
 *          plus the details row in confirmation_applications.
 *
 * SCHEDULING: Confirmation is usually administered in a parish-wide or
 *          catechism-batch, not per applicant. This form only collects an
 *          optional suggested batch/date (preferred_batch) -- it is never
 *          a commitment. Whether the applicant joins an existing batch or
 *          is scheduled individually is entirely the parish office's call,
 *          made after review; nothing here lets the applicant choose that.
 *
 * DOCUMENTS: Confirmation requires a Baptismal Certificate. This form asks
 *          whether the applicant has a copy ready to submit
 *          (baptismal_cert_status) and, if not, links to the baptismal
 *          certificate request flow (public/certificate-requests/baptismal.php)
 *          rather than leaving them with nothing to do. "Baptized at this
 *          parish?" (baptized_here) is recorded as a plain yes/no/not-sure
 *          answer only -- it is NOT looked up or auto-linked to a register
 *          entry (see public/certificate-requests/baptismal.php for that
 *          pattern; it does not apply here, since a certificate still has
 *          to be physically submitted either way per the parish's own
 *          requirements sheet).
 *
 * Flow:    Enters as 'submitted'; the secretary reviews it in
 *          admin/requests/applications-queue.php. No online ID upload —
 *          identity is verified in person.
 * Limit:   Successful submissions are capped at 3 per email address and 20
 *          per client IP over a rolling 24 hours; the accounting row lives
 *          in submission_rate_limits (migrations/018_add_submission_rate_limits.sql).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email

// Subfolder-safe URL prefix for links inside the success/bridge messages.
$basePath = $basePath ?? base_path();

$pageTitle = 'Confirmation Application';
$errors    = [];
$trackingNumber = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF: reject submissions without a matching session token.
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    // --- Collect + validate required fields -----------------------------
    $applicantName      = trim($_POST['applicant_full_name'] ?? '');
    $applicantBirthdate = $_POST['applicant_birthdate'] ?? '';
    $parentGuardian      = trim($_POST['parent_guardian_name'] ?? '');
    $contactNumber       = trim($_POST['contact_number'] ?? '');
    $email               = trim($_POST['email'] ?? '');
    $baptizedHereAnswer  = $_POST['baptized_here'] ?? '';
    // baptismal_cert_status is NOT applicant-set (see public/sacrament-applications/marriage.php
    // for the same rule): it always starts 'pending' and only the secretary flips it to
    // 'submitted' once the physical certificate is actually received and checked.
    $baptismalCertStatus = 'pending';
    $preferredBatch       = trim($_POST['preferred_batch'] ?? '');
    $sponsorName          = trim($_POST['sponsor_name'] ?? '');

    if ($applicantName === '')       $errors[] = "Applicant's full name is required.";
    if ($applicantBirthdate === '')  $errors[] = "Applicant's birthdate is required.";
    if (is_future_date($applicantBirthdate)) $errors[] = "Applicant's birthdate cannot be in the future.";
    if ($parentGuardian === '')  $errors[] = 'Parent/Guardian name is required. (If you are an adult applicant, you may enter your own name.)';
    if ($contactNumber === '')   $errors[] = 'Contact number is required.';
    if ($contactNumber !== '' && !is_valid_phone_number($contactNumber)) $errors[] = 'Please enter a valid contact number (at least 10 digits).';
    if ($email === '')           $errors[] = 'Email address is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (!in_array($baptizedHereAnswer, ['this_parish', 'elsewhere', 'not_sure'], true)) {
        $errors[] = 'Please answer where the applicant was baptized.';
    }

    // Abuse guard: rolling 24h cap per email address and per client IP.
    // Runs only after field validation passes, so re-submitting an invalid
    // form never consumes quota. See includes/functions.php.
    if (empty($errors)) {
        $submissionCap = public_submission_allowed($pdo, [$email]);
        if (!$submissionCap['ok']) {
            $errors[] = $submissionCap['reason'];
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $trackingNumber = generate_tracking_number($pdo, 'sacrament_applications');

            $stmt = $pdo->prepare(
                "INSERT INTO sacrament_applications
                    (tracking_number, submission_channel, sacrament_type, status)
                 VALUES (?, 'online', 'confirmation', 'submitted')"
            );
            $stmt->execute([$trackingNumber]);
            $applicationId = (int) $pdo->lastInsertId();

            // baptized_here is a plain informational flag (1/0); "not_sure"
            // is stored as 0 -- it is never looked up against the register.
            $baptizedHere = $baptizedHereAnswer === 'this_parish' ? 1 : 0;

            $stmt = $pdo->prepare(
                "INSERT INTO confirmation_applications
                    (application_id, applicant_full_name, applicant_birthdate,
                     parent_guardian_name, contact_number, email,
                     baptized_here, baptismal_cert_status, preferred_batch, sponsor_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId, $applicantName, $applicantBirthdate,
                $parentGuardian, $contactNumber, $email,
                $baptizedHere, $baptismalCertStatus, $preferredBatch, $sponsorName,
            ]);

            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId,
                "Confirmation application submitted for {$applicantName}");

            notify($pdo, 'application_new', 'New sacrament application',
                "Confirmation application for {$applicantName} ({$trackingNumber})",
                '/admin/requests/applications-queue.php');

            // Count this success against the abuse caps (same transaction,
            // so a later rollback never consumes quota).
            record_public_submission($pdo, [$email], 'application_confirmation');

            $pdo->commit();

            if (mail_request_received($email, $trackingNumber, 'Confirmation Application')) {
                log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Never show the success panel (with a tracking number) for a
            // submission that was rolled back — nothing was saved.
            $trackingNumber = null;
            $errors[] = 'Something went wrong while submitting your application. Please try again.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="request-form">
    <div class="alert alert-info online-intake-notice"><strong>Online application for parish review:</strong> Submitting this form does not mean the application is approved or scheduled. Confirmation is usually given in a parish batch; the parish office will confirm your date or batch directly.</div>

    <h1>Confirmation Application</h1>
    <p>Use this form to apply to receive the Sacrament of Confirmation at this parish.</p>

    <?php if ($trackingNumber): ?>
            <?php $successHeading = 'Your application has been submitted'; require __DIR__ . '/../../includes/submission-success.php'; ?>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Application form: posts back to this page; CSRF-protected -->
        <form method="post" action="" data-review data-review-title="Check your application before sending" data-review-confirm="Confirm and submit application">
            <?= csrf_field() ?>
            <fieldset>
                <legend>Applicant's Information</legend>
                <label>Full Name *
                    <input type="text" name="applicant_full_name" autocomplete="name" value="<?= e($_POST['applicant_full_name'] ?? '') ?>" required>
                </label>
                <label>Birthdate *
                    <input type="date" name="applicant_birthdate" max="<?= e(date('Y-m-d')) ?>" value="<?= e($_POST['applicant_birthdate'] ?? '') ?>" required>
                </label>
                <label>Parent/Guardian Name * <span class="field-hint">(if an adult applicant, you may enter your own name)</span>
                    <input type="text" name="parent_guardian_name" autocomplete="off" value="<?= e($_POST['parent_guardian_name'] ?? '') ?>" required>
                </label>
                <label>Contact Number *
                    <input type="text" name="contact_number" autocomplete="tel" value="<?= e($_POST['contact_number'] ?? '') ?>" required>
                </label>
                <label>Email Address *
                    <input type="email" name="email" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required>
                </label>
            </fieldset>

            <fieldset>
                <legend>Baptism (required for Confirmation)</legend>
                <label>Where was the applicant baptized? *
                    <select name="baptized_here" required>
                        <option value="">-- Select --</option>
                        <option value="this_parish" <?= ($_POST['baptized_here'] ?? '') === 'this_parish' ? 'selected' : '' ?>>At this parish</option>
                        <option value="elsewhere" <?= ($_POST['baptized_here'] ?? '') === 'elsewhere' ? 'selected' : '' ?>>At another parish or church</option>
                        <option value="not_sure" <?= ($_POST['baptized_here'] ?? '') === 'not_sure' ? 'selected' : '' ?>>Not sure</option>
                    </select>
                </label>
                <p class="field-hint">Bring a copy of the applicant's Baptismal Certificate when the parish office asks for it. Don't have one yet? <a href="<?= e($basePath . '/public/certificate-requests/baptismal.php') ?>" target="_blank" rel="noopener noreferrer">Request a Baptismal Certificate</a>.</p>
            </fieldset>

            <fieldset>
                <legend>Sponsor and Schedule</legend>
                <label>Sponsor's Full Name <span class="field-hint">(optional; ideally your baptismal godparent)</span>
                    <input type="text" name="sponsor_name" autocomplete="off" value="<?= e($_POST['sponsor_name'] ?? '') ?>">
                </label>
                <label>Suggested Batch or Date <span class="field-hint">(optional -- the parish office will confirm the actual date or batch)</span>
                    <input type="text" name="preferred_batch" autocomplete="off" placeholder="e.g. Grade 6, or a specific date" value="<?= e($_POST['preferred_batch'] ?? '') ?>">
                </label>
            </fieldset>

            <button type="submit" class="btn">Review Application</button>
        </form>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
