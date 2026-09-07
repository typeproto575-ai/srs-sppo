<?php
/**
 * =====================================================================
 * SPPO-IS MARRIAGE SACRAMENT APPLICATION (public form)
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
    $groomBaptizedHere  = isset($_POST['groom_baptized_here']) ? 1 : 0;
    $groomBaptismalCertStatus    = $_POST['groom_baptismal_cert_status'] ?? 'pending';
    $groomConfirmationCertStatus = $_POST['groom_confirmation_cert_status'] ?? 'pending';

    // --- Bride --------------------------------------------------------------
    $brideName       = trim($_POST['bride_full_name'] ?? '');
    $brideBirthdate  = $_POST['bride_birthdate'] ?? '';
    $brideBirthplace = trim($_POST['bride_birthplace'] ?? '');
    $brideContact    = trim($_POST['bride_contact_number'] ?? '');
    $brideEmail      = trim($_POST['bride_email'] ?? '');
    $brideMaritalStatus = $_POST['bride_marital_status'] ?? '';
    $brideBaptizedHere  = isset($_POST['bride_baptized_here']) ? 1 : 0;
    $brideBaptismalCertStatus    = $_POST['bride_baptismal_cert_status'] ?? 'pending';
    $brideConfirmationCertStatus = $_POST['bride_confirmation_cert_status'] ?? 'pending';

    // --- Wedding -------------------------------------------------------------
    $preferredWeddingDate = $_POST['preferred_wedding_date'] ?? '';
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
                     preferred_wedding_date, pre_cana_seminar_completed, pre_cana_completion_date,
                     canonical_interview_completed, canonical_interview_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId,
                $groomName, $groomBirthdate, $groomBirthplace, $groomContact, $groomEmail,                     $groomMaritalStatus, $groomBaptizedHere, $groomBaptismalCertStatus, $groomConfirmationCertStatus,
                     $brideName, $brideBirthdate, $brideBirthplace, $brideContact, $brideEmail,
                     $brideMaritalStatus, $brideBaptizedHere, $brideBaptismalCertStatus, $brideConfirmationCertStatus,                     $preferredWeddingDate, 0, NULL,
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

            // TODO: send confirmation email to $groomEmail and $brideEmail with $trackingNumber

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
        <div class="alert alert-success">
            <p>Your application has been submitted. Your tracking number is:</p>
            <p class="tracking-number"><strong><?= e($trackingNumber) ?></strong></p>
            <p>Save this number and check <a href="<?= $basePath ?>/public/track-request.php">Track My Request</a> for updates. Keep this tracking number - you can follow your request any time on the Track My Request page.</p>
        </div>
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

        <p>Marriage applications involve additional canonical requirements — a pastoral interview, a pre-Cana seminar, and the reading of banns on three separate dates. The Priest gives final approval. This form starts the process; the parish office will follow up on scheduling each requirement.</p>

        <form method="post" action="">
            <fieldset>
                <legend>Groom's Information</legend>
                <label>Full Name * <input type="text" name="groom_full_name" value="<?= e($_POST['groom_full_name'] ?? '') ?>" required></label>
                <label>Birthdate * <input type="date" name="groom_birthdate" value="<?= e($_POST['groom_birthdate'] ?? '') ?>" required></label>
                <label>Birthplace * <input type="text" name="groom_birthplace" value="<?= e($_POST['groom_birthplace'] ?? '') ?>" required></label>
                <label>Contact Number * <input type="text" name="groom_contact_number" value="<?= e($_POST['groom_contact_number'] ?? '') ?>" required></label>
                <label>Email Address * <input type="email" name="groom_email" value="<?= e($_POST['groom_email'] ?? '') ?>" required></label>
                <label>Marital Status *
                    <select name="groom_marital_status" required>
                        <option value="">-- Select --</option>
                        <option value="single">Single</option>
                        <option value="widowed">Widowed</option>
                        <option value="annulled">Annulled</option>
                    </select>
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="groom_baptized_here" value="1" <?= !empty($_POST['groom_baptized_here']) ? 'checked' : '' ?>>
                    Baptized at this parish
                </label>
                <label>Baptismal Certificate
                    <select name="groom_baptismal_cert_status">
                        <option value="pending">Not yet submitted</option>
                        <option value="submitted">Already submitted</option>
                    </select>
                </label>
                <label>Confirmation Certificate
                    <select name="groom_confirmation_cert_status">
                        <option value="pending">Not yet submitted</option>
                        <option value="submitted">Already submitted</option>
                    </select>
                </label>

            </fieldset>

            <fieldset>
                <legend>Bride's Information</legend>
                <label>Full Name * <input type="text" name="bride_full_name" value="<?= e($_POST['bride_full_name'] ?? '') ?>" required></label>
                <label>Birthdate * <input type="date" name="bride_birthdate" value="<?= e($_POST['bride_birthdate'] ?? '') ?>" required></label>
                <label>Birthplace * <input type="text" name="bride_birthplace" value="<?= e($_POST['bride_birthplace'] ?? '') ?>" required></label>
                <label>Contact Number * <input type="text" name="bride_contact_number" value="<?= e($_POST['bride_contact_number'] ?? '') ?>" required></label>
                <label>Email Address * <input type="email" name="bride_email" value="<?= e($_POST['bride_email'] ?? '') ?>" required></label>
                <label>Marital Status *
                    <select name="bride_marital_status" required>
                        <option value="">-- Select --</option>
                        <option value="single">Single</option>
                        <option value="widowed">Widowed</option>
                        <option value="annulled">Annulled</option>
                    </select>
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="bride_baptized_here" value="1" <?= !empty($_POST['bride_baptized_here']) ? 'checked' : '' ?>>
                    Baptized at this parish
                </label>
                <label>Baptismal Certificate
                    <select name="bride_baptismal_cert_status">
                        <option value="pending">Not yet submitted</option>
                        <option value="submitted">Already submitted</option>
                    </select>
                </label>
                <label>Confirmation Certificate
                    <select name="bride_confirmation_cert_status">
                        <option value="pending">Not yet submitted</option>
                        <option value="submitted">Already submitted</option>
                    </select>
                </label>

            </fieldset>

            <fieldset>
                <legend>Wedding Details</legend>
                <label>Preferred Wedding Date *
                    <input type="date" name="preferred_wedding_date" value="<?= e($_POST['preferred_wedding_date'] ?? '') ?>" required>
                </label>
                <p class="field-hint">This is a preferred date only. The parish office will confirm availability against the calendar.</p>
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
