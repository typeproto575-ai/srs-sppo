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
 *          admin/requests/applications-queue.php. No online ID upload —
 *          identity is verified in person at pickup.
 * Limit:   Successful submissions are capped at 3 per email address and 20
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF: reject submissions without a matching session token.
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    // --- Collect + validate required fields -----------------------------
    $childName       = trim($_POST['child_full_name'] ?? '');
    $childBirthdate  = $_POST['child_birthdate'] ?? '';
    $childBirthplace = trim($_POST['child_birthplace'] ?? '');
    $fatherName      = trim($_POST['father_full_name'] ?? '');
    $fatherBirthplace = trim($_POST['father_birthplace'] ?? '');
    $motherMaidenName = trim($_POST['mother_maiden_name'] ?? '');
    $motherBirthplace = trim($_POST['mother_birthplace'] ?? '');
    $parentsAddress  = trim($_POST['parents_address'] ?? '');
    $parentsUnionType = trim($_POST['parents_union_type'] ?? 'legitimate');
    $parentGuardian  = trim($_POST['parent_guardian_name'] ?? '');
    $contactNumber   = trim($_POST['contact_number'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $preferredDate   = $_POST['preferred_date'] ?? null;

    $godfatherNames      = $_POST['godfather_name'] ?? [];
    if (!is_array($godfatherNames)) { $godfatherNames = []; }
    $godfatherResidences = $_POST['godfather_residence'] ?? [];
    if (!is_array($godfatherResidences)) { $godfatherResidences = []; }
    $godmotherNames      = $_POST['godmother_name'] ?? [];
    if (!is_array($godmotherNames)) { $godmotherNames = []; }
    $godmotherResidences = $_POST['godmother_residence'] ?? [];
    if (!is_array($godmotherResidences)) { $godmotherResidences = []; }

    if ($childName === '')       $errors[] = "Child's full name is required.";
    if ($childBirthdate === '')  $errors[] = "Child's birthdate is required.";
    if (is_future_date($childBirthdate)) $errors[] = "Child's birthdate cannot be in the future.";
    if ($childBirthplace === '') $errors[] = "Child's birthplace is required.";
    if ($parentGuardian === '')  $errors[] = 'Parent/Guardian name is required.';
    if ($contactNumber === '')   $errors[] = 'Contact number is required.';
    if ($contactNumber !== '' && !is_valid_phone_number($contactNumber)) $errors[] = 'Please enter a valid contact number (at least 10 digits).';
    if ($email === '')           $errors[] = 'Email address is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

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
                 VALUES (?, 'online', 'baptismal', 'submitted')"
            );
            $stmt->execute([$trackingNumber]);
            $applicationId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO baptismal_applications
                    (application_id, child_full_name, child_birthdate, child_birthplace,
                     father_full_name, father_birthplace, mother_maiden_name, mother_birthplace,
                     parents_address, parents_union_type, parent_guardian_name,
                     contact_number, email, preferred_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )"
            );
            $stmt->execute([
                $applicationId, $childName, $childBirthdate, $childBirthplace,
                $fatherName, $fatherBirthplace, $motherMaidenName, $motherBirthplace,
                $parentsAddress, $parentsUnionType, $parentGuardian,
                $contactNumber, $email, $preferredDate,
            ]);
            $baptismalApplicationId = (int) $pdo->lastInsertId();

            $godparentStmt = $pdo->prepare(
                "INSERT INTO baptismal_application_godparents (baptismal_application_id, godparent_name, godparent_residence, godparent_type)
                 VALUES (?, ?, ?, ?)"
            );
            foreach ($godfatherNames as $i => $name) { $gtype = 'godfather';
                if (!is_string($name)) continue;
                $name = trim($name);
                if ($name === '') continue;
                $residence = trim(isset($godfatherResidences[$i]) && is_string($godfatherResidences[$i]) ? $godfatherResidences[$i] : '');
                $godparentStmt->execute([$baptismalApplicationId, $name, $residence, $gtype]);
            }
            foreach ($godmotherNames as $i => $name) { $gtype = 'godmother';
                if (!is_string($name)) continue;
                $name = trim($name);
                if ($name === '') continue;
                $residence = trim(isset($godmotherResidences[$i]) && is_string($godmotherResidences[$i]) ? $godmotherResidences[$i] : '');
                $godparentStmt->execute([$baptismalApplicationId, $name, $residence, $gtype]);
            }

            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId,
                "Baptismal application submitted for {$childName}");

            notify($pdo, 'application_new', 'New sacrament application',
                "Baptismal application for {$childName} ({$trackingNumber})",
                '/admin/requests/applications-queue.php');

            // Count this success against the abuse caps (same transaction,
            // so a later rollback never consumes quota).
            record_public_submission($pdo, [$email], 'application_baptismal');

            $pdo->commit();

            if (mail_request_received($email, $trackingNumber, 'Baptismal Application')) {
                log_activity($pdo, null, 'confirmation_email_sent', 'sacrament_applications', $applicationId, "Confirmation email sent to {$email}");
            }

        } catch (Exception $e) {
            $pdo->rollBack();
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
    <div class="alert alert-info online-intake-notice"><strong>Online application for parish review:</strong> Submitting this form does not mean the application is approved. If documents are needed, the parish office will tell you whether to email them or bring them onsite.</div>

    <h1>Baptismal Application</h1>
    <p>Use this form to apply to have an infant or child baptized at this parish. There is no age-eligibility check for Baptism — the parish office will follow up directly with any scheduling requirements.</p>

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
                <legend>Child's Information</legend>
                <label>Child's Full Name * <input type="text" name="child_full_name" value="<?= e($_POST['child_full_name'] ?? '') ?>" required></label>
                <label>Birthdate * <input type="date" name="child_birthdate" value="<?= e($_POST['child_birthdate'] ?? '') ?>" required></label>
                <label>Birthplace * <input type="text" name="child_birthplace" value="<?= e($_POST['child_birthplace'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Father's Full Name <input type="text" name="father_full_name" value="<?= e($_POST['father_full_name'] ?? '') ?>"></label>
                    <label>Father's Birthplace <input type="text" name="father_birthplace" value="<?= e($_POST['father_birthplace'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Mother's Maiden Name <input type="text" name="mother_maiden_name" value="<?= e($_POST['mother_maiden_name'] ?? '') ?>"></label>
                    <label>Mother's Birthplace <input type="text" name="mother_birthplace" value="<?= e($_POST['mother_birthplace'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Parents' Address <input type="text" name="parents_address" value="<?= e($_POST['parents_address'] ?? '') ?>"></label>
                    <label>Union Type
                        <select name="parents_union_type">
                            <option value="legitimate" <?= ($_POST['parents_union_type'] ?? 'legitimate') === 'legitimate' ? 'selected' : '' ?>>Legitimate</option>
                            <option value="civil" <?= ($_POST['parents_union_type'] ?? '') === 'civil' ? 'selected' : '' ?>>Civil</option>
                            <option value="natural" <?= ($_POST['parents_union_type'] ?? '') === 'natural' ? 'selected' : '' ?>>Natural</option>
                        </select>
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Parent/Guardian Submitting This Application</legend>
                <label>Full Name * <input type="text" name="parent_guardian_name" autocomplete="name" value="<?= e($_POST['parent_guardian_name'] ?? '') ?>" required></label>
                <label>Contact Number * <input type="text" name="contact_number" autocomplete="tel" value="<?= e($_POST['contact_number'] ?? '') ?>" required></label>
                <label>Email Address * <input type="email" name="email" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required></label>

            </fieldset>

            <fieldset>
                <legend>Godparents — Godfathers (Ninong)</legend>
                <p class="field-hint">Up to 8 godfathers. Use “Add another godfather” for more rows; blank rows are ignored.</p>
                <?php
                // Render one row per previously submitted value (validation
                // re-render), otherwise a single blank row. New rows are
                // cloned client-side — see main.js, section 9.
                $godfatherRows = $_POST['godfather_name'] ?? [];
                if (!is_array($godfatherRows)) { $godfatherRows = []; }
                $godfatherRows = array_values($godfatherRows);
                $godfatherRes = $_POST['godfather_residence'] ?? [];
                if (!is_array($godfatherRes)) { $godfatherRes = []; }
                $godfatherRes = array_values($godfatherRes);
                if (count($godfatherRows) === 0) { $godfatherRows = ['']; }
                ?>
                <div data-repeat-group="godfather" data-repeat-max="8">
                    <?php foreach ($godfatherRows as $i => $rowName): ?>
                        <div class="godparent-row">
                            <div class="inline-fields">
                                <label>Godfather Name
                                    <input type="text" name="godfather_name[]" value="<?= e(is_string($rowName) ? $rowName : '') ?>">
                                </label>
                                <label>Residence
                                    <input type="text" name="godfather_residence[]" value="<?= e(isset($godfatherRes[$i]) && is_string($godfatherRes[$i]) ? $godfatherRes[$i] : '') ?>">
                                </label>
                            </div>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godfather">Add another godfather</button>
            </fieldset>

            <fieldset>
                <legend>Godparents — Godmothers (Ninang)</legend>
                <p class="field-hint">Up to 8 godmothers. Use “Add another godmother” for more rows; blank rows are ignored.</p>
                <?php
                $godmotherRows = $_POST['godmother_name'] ?? [];
                if (!is_array($godmotherRows)) { $godmotherRows = []; }
                $godmotherRows = array_values($godmotherRows);
                $godmotherRes = $_POST['godmother_residence'] ?? [];
                if (!is_array($godmotherRes)) { $godmotherRes = []; }
                $godmotherRes = array_values($godmotherRes);
                if (count($godmotherRows) === 0) { $godmotherRows = ['']; }
                ?>
                <div data-repeat-group="godmother" data-repeat-max="8">
                    <?php foreach ($godmotherRows as $i => $rowName): ?>
                        <div class="godparent-row">
                            <div class="inline-fields">
                                <label>Godmother Name
                                    <input type="text" name="godmother_name[]" value="<?= e(is_string($rowName) ? $rowName : '') ?>">
                                </label>
                                <label>Residence
                                    <input type="text" name="godmother_residence[]" value="<?= e(isset($godmotherRes[$i]) && is_string($godmotherRes[$i]) ? $godmotherRes[$i] : '') ?>">
                                </label>
                            </div>
                            <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $i === 0 ? ' hidden' : '' ?>>Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="godmother">Add another godmother</button>
            </fieldset>

            <fieldset>
                <legend>Scheduling and Additional Information</legend>
                <label>Preferred Date (if any)
                    <input type="date" name="preferred_date" value="<?= e($_POST['preferred_date'] ?? '') ?>">
                </label>
            </fieldset>

            <button type="submit" class="btn">Review Application</button>
        </form>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
