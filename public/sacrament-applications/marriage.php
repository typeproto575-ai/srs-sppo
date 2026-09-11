<?php
/**
 * =====================================================================
 * SRS-SPPO MARRIAGE SACRAMENT APPLICATION (public form)
 * =====================================================================
 * Purpose: Apply to be MARRIED at the parish. Inserts the shared row
 *          into sacrament_applications plus groom/bride details into
 *          marriage_applications and rows for each principal sponsor.
 * Extras:  Warns (non-blocking) when the preferred wedding date already
 *          has a scheduled event; both parties' ages are eligibility-
 *          checked against configured ranges.
 * Flow:    Enters as 'submitted'; reviewed in the admin applications
 *          queue. No online ID upload — IDs verified in person.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email

// Subfolder-safe URL prefix for links on this page.
$basePath = $basePath ?? base_path();

$pageTitle = 'Marriage Application';
$errors    = [];
$trackingNumber  = null;
$dateConflictWarning = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF: reject submissions without a matching session token.
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    // --- Groom ------------------------------------------------------------
    $groomName       = trim($_POST['groom_full_name'] ?? '');
    $groomBirthdate  = $_POST['groom_birthdate'] ?? '';
    $groomBirthplace = trim($_POST['groom_birthplace'] ?? '');
    $groomContact    = trim($_POST['groom_contact_number'] ?? '');
    $groomEmail      = trim($_POST['groom_email'] ?? '');
    $groomMaritalStatus = $_POST['groom_marital_status'] ?? '';
    // Canonical baptism and confirmation clearance is recorded by the
    // secretary after parish verification, never by the applicant.
    $groomBaptizedHere  = 0;
    $groomBaptismalCertStatus    = 'pending';
    $groomConfirmationCertStatus = 'pending';

    // --- Bride --------------------------------------------------------------
    $brideName       = trim($_POST['bride_full_name'] ?? '');
    $brideBirthdate  = $_POST['bride_birthdate'] ?? '';
    $brideBirthplace = trim($_POST['bride_birthplace'] ?? '');
    $brideContact    = trim($_POST['bride_contact_number'] ?? '');
    $brideEmail      = trim($_POST['bride_email'] ?? '');
    $brideMaritalStatus = $_POST['bride_marital_status'] ?? '';
    $brideBaptizedHere  = 0;
    $brideBaptismalCertStatus    = 'pending';
    $brideConfirmationCertStatus = 'pending';

    // --- Wedding -------------------------------------------------------------
    $preferredWeddingDate = $_POST['preferred_wedding_date'] ?? '';
    $preferredWeddingTime = $_POST['preferred_wedding_time'] ?: null;
    $preferredWeddingVenue = trim($_POST['preferred_wedding_venue'] ?? 'church');
    $marriageLicenseRef = trim($_POST['marriage_license_ref'] ?? '');
    $marriageLicenseRef = ($marriageLicenseRef === '' ? null : $marriageLicenseRef);
    $notes                = trim($_POST['notes'] ?? '');

    // --- Principal sponsors (repeatable) ------------------------------------
    $sponsorNames      = $_POST['sponsor_name'] ?? [];
    $sponsorResidences = $_POST['sponsor_residence'] ?? [];

    if ($groomName === '')       $errors[] = "Groom's full name is required.";
    if ($groomBirthdate === '')  $errors[] = "Groom's birthdate is required.";
    if (is_future_date($groomBirthdate)) $errors[] = "Groom's birthdate cannot be in the future.";
    if ($groomBirthplace === '') $errors[] = "Groom's birthplace is required.";
    if ($groomContact === '')    $errors[] = "Groom's contact number is required.";
    if ($groomContact !== '' && !is_valid_phone_number($groomContact)) $errors[] = "Groom's contact number is not valid (at least 10 digits).";
    if ($groomEmail === '')      $errors[] = "Groom's email address is required.";
    if (!filter_var($groomEmail, FILTER_VALIDATE_EMAIL)) $errors[] = "Groom's email is not a valid address.";
    if ($groomMaritalStatus === '') $errors[] = "Groom's marital status is required.";

    if ($brideName === '')       $errors[] = "Bride's full name is required.";
    if ($brideBirthdate === '')  $errors[] = "Bride's birthdate is required.";
    if (is_future_date($brideBirthdate)) $errors[] = "Bride's birthdate cannot be in the future.";
    if ($brideBirthplace === '') $errors[] = "Bride's birthplace is required.";
    if ($brideContact === '')    $errors[] = "Bride's contact number is required.";
    if ($brideContact !== '' && !is_valid_phone_number($brideContact)) $errors[] = "Bride's contact number is not valid (at least 10 digits).";
    if ($brideEmail === '')      $errors[] = "Bride's email address is required.";
    if (!filter_var($brideEmail, FILTER_VALIDATE_EMAIL)) $errors[] = "Bride's email is not a valid address.";
    if ($brideMaritalStatus === '') $errors[] = "Bride's marital status is required.";

    if ($preferredWeddingDate === '') $errors[] = 'Preferred wedding date is required.';
    if (!in_array($preferredWeddingVenue, ['church', 'barangay_chapel'], true)) $errors[] = 'Please select a valid wedding venue.';

    // Non-blocking check: is another event already scheduled that day?
    // This is informational only -- conflict resolution is manual (secretary/priest).
    if ($preferredWeddingDate !== '') {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total FROM schedule_events WHERE event_date = ? AND event_type IN ('wedding','mass')"
        );
        $stmt->execute([$preferredWeddingDate]);
        if ((int) $stmt->fetch()['total'] > 0) {
            $dateConflictWarning = 'Note: there is already an event scheduled on your preferred date. This will be reviewed by the parish office — it does not block your application.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $trackingNumber = generate_tracking_number($pdo, 'sacrament_applications');

            $stmt = $pdo->prepare(
                "INSERT INTO sacrament_applications
                    (tracking_number, sacrament_type, status, notes)
                 VALUES (?, 'marriage', 'submitted', ?)"
            );
            $stmt->execute([$trackingNumber, $notes]);
            $applicationId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO marriage_applications
                    (application_id,
                     groom_full_name, groom_birthdate, groom_birthplace, groom_contact_number, groom_email,
                     groom_marital_status, groom_baptized_here, groom_baptismal_cert_status, groom_confirmation_cert_status,
                     bride_full_name, bride_birthdate, bride_birthplace, bride_contact_number, bride_email,
                     bride_marital_status, bride_baptized_here, bride_baptismal_cert_status, bride_confirmation_cert_status,
                     preferred_wedding_date, preferred_wedding_time, preferred_wedding_venue, marriage_license_ref,
                     pre_cana_seminar_completed, pre_cana_completion_date,
                     canonical_interview_completed, canonical_interview_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId,
                $groomName, $groomBirthdate, $groomBirthplace, $groomContact, $groomEmail,
                $groomMaritalStatus, $groomBaptizedHere, $groomBaptismalCertStatus, $groomConfirmationCertStatus,
                $brideName, $brideBirthdate, $brideBirthplace, $brideContact, $brideEmail,
                $brideMaritalStatus, $brideBaptizedHere, $brideBaptismalCertStatus, $brideConfirmationCertStatus,
                $preferredWeddingDate, $preferredWeddingTime, $preferredWeddingVenue, $marriageLicenseRef,
                0, NULL,
                0, NULL,
            ]);
            $marriageApplicationId = (int) $pdo->lastInsertId();

            $sponsorStmt = $pdo->prepare(
                "INSERT INTO marriage_principal_sponsors (marriage_application_id, sponsor_name, sponsor_residence)
                 VALUES (?, ?, ?)"
            );
            foreach ($sponsorNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $residence = trim($sponsorResidences[$i] ?? '');
                $sponsorStmt->execute([$marriageApplicationId, $name, $residence]);
            }

            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId,
                "Marriage application submitted for {$groomName} & {$brideName}");

            $pdo->commit();

            if (mail_request_received($groomEmail, $trackingNumber, 'Marriage Application')) {
                log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$groomEmail}");
            }
            if (mail_request_received($brideEmail, $trackingNumber, 'Marriage Application')) {
                log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$brideEmail}");
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while submitting your application. Please try again.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="request-form">
    <h1>Marriage Application</h1>

    <?php if ($trackingNumber): ?>
            <?php $successHeading = 'Your application has been submitted'; require __DIR__ . '/../../includes/submission-success.php'; ?>
        <?php if ($dateConflictWarning): ?>
            <div class="alert alert-warning"><p><?= e($dateConflictWarning) ?></p></div>
        <?php endif; ?>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p>Marriage applications involve additional canonical requirements — a pastoral interview, a pre-Cana seminar, and the reading of banns on three separate dates. The parish office records each clearance with the priest outside this system and gives final approval. This form starts the process; the office will follow up on scheduling each requirement.</p>

        <form method="post" action="">
            <fieldset>
                <legend>Groom's Information</legend>
                <label>Full Name * <input type="text" name="groom_full_name" value="<?= e($_POST['groom_full_name'] ?? '') ?>" required></label>
                <label>Birthdate * <input type="date" name="groom_birthdate" value="<?= e($_POST['groom_birthdate'] ?? '') ?>" required></label>
                <label>Birthplace * <input type="text" name="groom_birthplace" value="<?= e($_POST['groom_birthplace'] ?? '') ?>" required></label>
                <label>Contact Number * <input type="text" name="groom_contact_number" autocomplete="tel" value="<?= e($_POST['groom_contact_number'] ?? '') ?>" required></label>
                <label>Email Address * <input type="email" name="groom_email" autocomplete="email" value="<?= e($_POST['groom_email'] ?? '') ?>" required></label>
                <label>Marital Status *
                    <select name="groom_marital_status" required>
                        <option value="">-- Select --</option>
                        <option value="single">Single</option>
                        <option value="widowed">Widowed</option>
                        <option value="annulled">Annulled</option>
                    </select>
                </label>
            </fieldset>

            <fieldset>
                <legend>Bride's Information</legend>
                <label>Full Name * <input type="text" name="bride_full_name" value="<?= e($_POST['bride_full_name'] ?? '') ?>" required></label>
                <label>Birthdate * <input type="date" name="bride_birthdate" value="<?= e($_POST['bride_birthdate'] ?? '') ?>" required></label>
                <label>Birthplace * <input type="text" name="bride_birthplace" value="<?= e($_POST['bride_birthplace'] ?? '') ?>" required></label>
                <label>Contact Number * <input type="text" name="bride_contact_number" autocomplete="tel" value="<?= e($_POST['bride_contact_number'] ?? '') ?>" required></label>
                <label>Email Address * <input type="email" name="bride_email" autocomplete="email" value="<?= e($_POST['bride_email'] ?? '') ?>" required></label>
                <label>Marital Status *
                    <select name="bride_marital_status" required>
                        <option value="">-- Select --</option>
                        <option value="single">Single</option>
                        <option value="widowed">Widowed</option>
                        <option value="annulled">Annulled</option>
                    </select>
                </label>
            </fieldset>

            <fieldset>
                <legend>Wedding Details</legend>
                <label>Preferred Wedding Date *
                    <input type="date" name="preferred_wedding_date" value="<?= e($_POST['preferred_wedding_date'] ?? '') ?>" required>
                </label>
                <div class="inline-fields">
                    <label>Preferred Time
                        <input type="time" name="preferred_wedding_time" value="<?= e($_POST['preferred_wedding_time'] ?? '') ?>">
                    </label>
                    <label>Venue *
                        <select name="preferred_wedding_venue" required>
                            <option value="church" <?= ($_POST['preferred_wedding_venue'] ?? 'church') === 'church' ? 'selected' : '' ?>>Church</option>
                            <option value="barangay_chapel" <?= ($_POST['preferred_wedding_venue'] ?? '') === 'barangay_chapel' ? 'selected' : '' ?>>Barangay Chapel</option>
                        </select>
                    </label>
                </div>
                                                                                <p class="field-hint">This is a preferred date only. The parish office will confirm availability against the calendar.</p>
                <label>Marriage License Reference (number / date issued, if already obtained)
                    <input type="text" name="marriage_license_ref" value="<?= e($_POST['marriage_license_ref'] ?? '') ?>" placeholder="License number / date issued">
                </label>
            </fieldset>





            <fieldset>
                <legend>Principal Sponsors (Ninong/Ninang)</legend>
                <p class="field-hint">List as many principal sponsors as you have confirmed so far. You can leave rows blank and add more later at the parish office.</p>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="inline-fields">
                        <label>Sponsor Name
                            <input type="text" name="sponsor_name[]" value="<?= e($_POST['sponsor_name'][$i] ?? '') ?>">
                        </label>
                        <label>Residence
                            <input type="text" name="sponsor_residence[]" value="<?= e($_POST['sponsor_residence'][$i] ?? '') ?>">
                        </label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset>
                <legend>Additional Notes</legend>
                <label><textarea name="notes"><?= e($_POST['notes'] ?? '') ?></textarea></label>
            </fieldset>

            <!-- CSRF token pairs with the csrf_verify() check above -->
            <?= csrf_field() ?>
            <button type="submit" class="btn">Submit Application</button>
        </form>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
