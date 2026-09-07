<?php
/**
 * =====================================================================
 * SPPO-IS FIRST COMMUNION SACRAMENT APPLICATION (public form)
 * =====================================================================
 * Purpose: Apply for a child to RECEIVE First Holy Communion. Inserts
 *          a shared row into sacrament_applications plus details into
 *          first_communion_applications. Age is checked against the
 *          configurable eligibility range — flagged only, never blocked.
 * Flow:    Enters as 'submitted'; reviewed in the admin applications
 *          queue (no online ID upload).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF

// Subfolder-safe URL prefix for the success-message link.
$basePath = $basePath ?? base_path();

$pageTitle = 'First Communion Application';
$errors    = [];
$trackingNumber = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF: reject submissions without a matching session token.
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    // --- Collect + validate required fields -----------------------------
    $applicantName       = trim($_POST['applicant_full_name'] ?? '');
    $applicantBirthdate  = $_POST['applicant_birthdate'] ?? '';
    $parentGuardian      = trim($_POST['parent_guardian_name'] ?? '');
    $contactNumber       = trim($_POST['contact_number'] ?? '');
    $email               = trim($_POST['email'] ?? '');
    $baptizedHere        = isset($_POST['baptized_here']) ? 1 : 0;
    $baptismalCertStatus = $_POST['baptismal_cert_status'] ?? 'pending';
    $preferredBatch      = trim($_POST['preferred_batch'] ?? '');
    $notes               = trim($_POST['notes'] ?? '');

    if ($applicantName === '')      $errors[] = "Applicant's full name is required.";
    if ($applicantBirthdate === '') $errors[] = "Applicant's birthdate is required.";
    if ($parentGuardian === '')     $errors[] = 'Parent/Guardian name is required.';
    if ($contactNumber === '')      $errors[] = 'Contact number is required.';
    if ($email === '')              $errors[] = 'Email address is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

    // Eligibility check: flag only, never block submission.
    $ageEligibilityFlag = 0;
    if ($applicantBirthdate !== '') {
        $age = calculate_age($applicantBirthdate);
        if (is_outside_eligibility($pdo, 'first_communion', $age)) {
            $ageEligibilityFlag = 1;
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $trackingNumber = generate_tracking_number($pdo, 'sacrament_applications');

            $stmt = $pdo->prepare(
                "INSERT INTO sacrament_applications
                    (tracking_number, sacrament_type, status, notes)
                 VALUES (?, 'first_communion', 'submitted', ?)"
            );
            $stmt->execute([$trackingNumber, $notes]);
            $applicationId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO first_communion_applications
                    (application_id, applicant_full_name, applicant_birthdate, parent_guardian_name,
                     contact_number, email, baptized_here, baptismal_cert_status,
                     preferred_batch, age_eligibility_flag)
                 VALUES                     (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId, $applicantName, $applicantBirthdate, $parentGuardian,
                $contactNumber, $email, $baptizedHere, $baptismalCertStatus,
                $preferredBatch, $ageEligibilityFlag,
            ]);

            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId,
                "First Communion application submitted for {$applicantName}" . ($ageEligibilityFlag ? ' (flagged: outside configured age range)' : ''));

            $pdo->commit();

            // TODO: send confirmation email to $email with $trackingNumber

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while submitting your application. Please try again.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="request-form">
    <h1>First Communion Application</h1>

    <?php if ($trackingNumber): ?>
        <div class="alert alert-success">
            <p>Your application has been submitted. Your tracking number is:</p>
            <p class="tracking-number"><strong><?= e($trackingNumber) ?></strong></p>
            <p>Save this number and check <a href="<?= $basePath ?>/public/track-request.php">Track My Request</a> for updates. Keep this tracking number - you can follow your request any time on the Track My Request page.</p>
        </div>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <fieldset>
                <legend>Applicant Information</legend>

                <label>Applicant's Full Name *
                    <input type="text" name="applicant_full_name" value="<?= e($_POST['applicant_full_name'] ?? '') ?>" required>
                </label>
                <label>Applicant's Birthdate *
                    <input type="date" name="applicant_birthdate" value="<?= e($_POST['applicant_birthdate'] ?? '') ?>" required>
                </label>
                <label>Parent/Guardian Name *
                    <input type="text" name="parent_guardian_name" value="<?= e($_POST['parent_guardian_name'] ?? '') ?>" required>
                </label>
                <label>Contact Number *
                    <input type="text" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>" required>
                </label>
                <label>Email Address *
                    <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
                </label>

            </fieldset>

            <fieldset>
                <legend>Baptismal Requirement</legend>

                <label class="checkbox-label">
                    <input type="checkbox" name="baptized_here" value="1" <?= !empty($_POST['baptized_here']) ? 'checked' : '' ?>>
                    Applicant was baptized at this parish
                </label>

                <label>Baptismal Certificate Status
                    <select name="baptismal_cert_status">
                        <option value="pending" <?= (($_POST['baptismal_cert_status'] ?? '') === 'pending') ? 'selected' : '' ?>>Not yet submitted</option>
                        <option value="submitted" <?= (($_POST['baptismal_cert_status'] ?? '') === 'submitted') ? 'selected' : '' ?>>Already submitted</option>
                    </select>
                </label>
                <p class="field-hint">If baptized elsewhere, please bring a copy of the Baptismal Certificate to the parish office. This will not block your application — the secretary will follow up if anything is missing.</p>
            </fieldset>

            <fieldset>
                <legend>First Communion Details</legend>

                <label>Preferred Batch/Schedule (if known)
                    <input type="text" name="preferred_batch" value="<?= e($_POST['preferred_batch'] ?? '') ?>">
                </label>
                <label>Additional Notes
                    <textarea name="notes"><?= e($_POST['notes'] ?? '') ?></textarea>
                </label>
            </fieldset>

            <!-- CSRF token pairs with the csrf_verify() check above -->
            <?= csrf_field() ?>
            <button type="submit" class="btn">Submit Application</button>
        </form>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
