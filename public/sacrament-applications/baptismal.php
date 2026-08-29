<?php
/**
 * =====================================================================
 * SPPO-IS BAPTISMAL SACRAMENT APPLICATION (public form)
 * =====================================================================
 * Purpose: Online application to HAVE A CHILD BAPTIZED at the parish.
 *          Inserts a shared row into sacrament_applications plus the
 *          details row in baptismal_applications and one row per
 *          godparent in baptismal_application_godparents.
 * Flow:    Enters as 'submitted'; the secretary reviews it in
 *          admin/requests/applications-queue.php. No online ID upload —
 *          identity is verified in person (parent_valid_id_path = NULL).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF

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
    $motherName      = trim($_POST['mother_full_name'] ?? '');
    $parentGuardian  = trim($_POST['parent_guardian_name'] ?? '');
    $contactNumber   = trim($_POST['contact_number'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $preferredDate   = $_POST['preferred_date'] ?: null;
    $notes           = trim($_POST['notes'] ?? '');

    $godparentNames      = $_POST['godparent_name'] ?? [];
    $godparentResidences = $_POST['godparent_residence'] ?? [];

    if ($childName === '')       $errors[] = "Child's full name is required.";
    if ($childBirthdate === '')  $errors[] = "Child's birthdate is required.";
    if ($childBirthplace === '') $errors[] = "Child's birthplace is required.";
    if ($parentGuardian === '')  $errors[] = 'Parent/Guardian name is required.';
    if ($contactNumber === '')   $errors[] = 'Contact number is required.';
    if ($email === '')           $errors[] = 'Email address is required.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $trackingNumber = generate_tracking_number($pdo, 'sacrament_applications');

            $stmt = $pdo->prepare(
                "INSERT INTO sacrament_applications
                    (tracking_number, sacrament_type, status, notes)
                 VALUES (?, 'baptismal', 'submitted', ?)"
            );
            $stmt->execute([$trackingNumber, $notes]);
            $applicationId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO baptismal_applications
                    (application_id, child_full_name, child_birthdate, child_birthplace,
                     father_full_name, mother_full_name, parent_guardian_name,
                     contact_number, email, preferred_date, notes, parent_valid_id_path)
                 VALUES                     (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)"
            );
            $stmt->execute([
                $applicationId, $childName, $childBirthdate, $childBirthplace,
                $fatherName, $motherName, $parentGuardian,
                $contactNumber, $email, $preferredDate, $notes,
            ]);
            $baptismalApplicationId = (int) $pdo->lastInsertId();

            $godparentStmt = $pdo->prepare(
                "INSERT INTO baptismal_application_godparents (baptismal_application_id, godparent_name, godparent_residence)
                 VALUES (?, ?, ?)"
            );
            foreach ($godparentNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $residence = trim($godparentResidences[$i] ?? '');
                $godparentStmt->execute([$baptismalApplicationId, $name, $residence]);
            }

            log_activity($pdo, null, 'sacrament_application_submitted', 'sacrament_applications', $applicationId,
                "Baptismal application submitted for {$childName}");

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
    <h1>Baptismal Application</h1>
    <p>Use this form to apply to have an infant or child baptized at this parish. There is no age-eligibility check for Baptism — the parish office will follow up directly with any scheduling requirements.</p>

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

        <!-- Application form: posts back to this page; CSRF-protected -->
        <form method="post" action="">
            <?= csrf_field() ?>
            <fieldset>
                <legend>Child's Information</legend>
                <label>Child's Full Name * <input type="text" name="child_full_name" value="<?= e($_POST['child_full_name'] ?? '') ?>" required></label>
                <label>Birthdate * <input type="date" name="child_birthdate" value="<?= e($_POST['child_birthdate'] ?? '') ?>" required></label>
                <label>Birthplace * <input type="text" name="child_birthplace" value="<?= e($_POST['child_birthplace'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Father's Full Name <input type="text" name="father_full_name" value="<?= e($_POST['father_full_name'] ?? '') ?>"></label>
                    <label>Mother's Full Name <input type="text" name="mother_full_name" value="<?= e($_POST['mother_full_name'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Parent/Guardian Submitting This Application</legend>
                <label>Full Name * <input type="text" name="parent_guardian_name" value="<?= e($_POST['parent_guardian_name'] ?? '') ?>" required></label>
                <label>Contact Number * <input type="text" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>" required></label>
                <label>Email Address * <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required></label>

            </fieldset>

            <fieldset>
                <legend>Godparents (Ninong/Ninang)</legend>
                <p class="field-hint">List as many as you have confirmed so far. You can leave rows blank and add more later at the parish office.</p>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="inline-fields">
                        <label>Godparent Name
                            <input type="text" name="godparent_name[]" value="<?= e($_POST['godparent_name'][$i] ?? '') ?>">
                        </label>
                        <label>Residence
                            <input type="text" name="godparent_residence[]" value="<?= e($_POST['godparent_residence'][$i] ?? '') ?>">
                        </label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset>
                <legend>Scheduling</legend>
                <label>Preferred Date (if any)
                    <input type="date" name="preferred_date" value="<?= e($_POST['preferred_date'] ?? '') ?>">
                </label>
                <label>Additional Notes
                    <textarea name="notes"><?= e($_POST['notes'] ?? '') ?></textarea>
                </label>
            </fieldset>

            <button type="submit" class="btn">Submit Application</button>
        </form>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
