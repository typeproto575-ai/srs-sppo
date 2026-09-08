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
 *          identity is verified in person at pickup.
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
    $fatherBirthplace = trim($_POST['father_birthplace'] ?? '');
    $motherMaidenName = trim($_POST['mother_maiden_name'] ?? '');
    $motherBirthplace = trim($_POST['mother_birthplace'] ?? '');
    $parentsAddress  = trim($_POST['parents_address'] ?? '');
    $parentsUnionType = trim($_POST['parents_union_type'] ?? 'legitimate');
    $parentGuardian  = trim($_POST['parent_guardian_name'] ?? '');
    $contactNumber   = trim($_POST['contact_number'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $preferredDate   = $_POST['preferred_date'] ?: null;
    $baptismDate     = $_POST['baptism_date'] ?: null;
    $delegatedMinister = trim($_POST['delegated_minister'] ?? '');
    $bookNo          = trim($_POST['book_no'] ?? '');
    $pageNo          = trim($_POST['page_no'] ?? '');
    $lineNo          = trim($_POST['line_no'] ?? '');
    $certificateDone = isset($_POST['certificate_done']) ? 1 : 0;
    $notes           = trim($_POST['notes'] ?? '');

    $godfatherNames      = $_POST['godfather_name'] ?? [];
    $godfatherResidences = $_POST['godfather_residence'] ?? [];
    $godmotherNames      = $_POST['godmother_name'] ?? [];
    $godmotherResidences = $_POST['godmother_residence'] ?? [];

    if ($childName === '')       $errors[] = "Child's full name is required.";
    if ($childBirthdate === '')  $errors[] = "Child's birthdate is required.";
    if (is_future_date($childBirthdate)) $errors[] = "Child's birthdate cannot be in the future.";
    if ($childBirthplace === '') $errors[] = "Child's birthplace is required.";
    if ($parentGuardian === '')  $errors[] = 'Parent/Guardian name is required.';
    if ($contactNumber === '')   $errors[] = 'Contact number is required.';
    if ($contactNumber !== '' && !is_valid_phone_number($contactNumber)) $errors[] = 'Please enter a valid contact number (at least 10 digits).';
    if ($email === '')           $errors[] = 'Email address is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

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
                     father_full_name, father_birthplace, mother_maiden_name, mother_birthplace,
                     parents_address, parents_union_type, parent_guardian_name,
                     contact_number, email, preferred_date,
                     delegated_minister, book_no, page_no, line_no, certificate_done, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId, $childName, $childBirthdate, $childBirthplace,
                $fatherName, $fatherBirthplace, $motherMaidenName, $motherBirthplace,
                $parentsAddress, $parentsUnionType, $parentGuardian,
                $contactNumber, $email, $preferredDate,
                $delegatedMinister, $bookNo, $pageNo, $lineNo, $certificateDone, $notes,
            ]);
            $baptismalApplicationId = (int) $pdo->lastInsertId();

            $godparentStmt = $pdo->prepare(
                "INSERT INTO baptismal_application_godparents (baptismal_application_id, godparent_name, godparent_residence)
                 VALUES (?, ?, ?)"
            );
            foreach ($godfatherNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $residence = trim($godfatherResidences[$i] ?? '');
                $godparentStmt->execute([$baptismalApplicationId, $name, $residence]);
            }
            foreach ($godmotherNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $residence = trim($godmotherResidences[$i] ?? '');
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
                <label>Full Name * <input type="text" name="parent_guardian_name" value="<?= e($_POST['parent_guardian_name'] ?? '') ?>" required></label>
                <label>Contact Number * <input type="text" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>" required></label>
                <label>Email Address * <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required></label>

            </fieldset>

            <fieldset>
                <legend>Godparents — Godfathers (Ninong)</legend>
                <p class="field-hint">Up to 8 godfathers. Leave blank any you haven't confirmed.</p>
                <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="inline-fields">
                        <label>Godfather Name
                            <input type="text" name="godfather_name[]" value="<?= e($_POST['godfather_name'][$i] ?? '') ?>">
                        </label>
                        <label>Residence
                            <input type="text" name="godfather_residence[]" value="<?= e($_POST['godfather_residence'][$i] ?? '') ?>">
                        </label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset>
                <legend>Godparents — Godmothers (Ninang)</legend>
                <p class="field-hint">Up to 8 godmothers. Leave blank any you haven't confirmed.</p>
                <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="inline-fields">
                        <label>Godmother Name
                            <input type="text" name="godmother_name[]" value="<?= e($_POST['godmother_name'][$i] ?? '') ?>">
                        </label>
                        <label>Residence
                            <input type="text" name="godmother_residence[]" value="<?= e($_POST['godmother_residence'][$i] ?? '') ?>">
                        </label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset>
                <legend>Scheduling</legend>
                <label>Preferred Date (if any)
                    <input type="date" name="preferred_date" value="<?= e($_POST['preferred_date'] ?? '') ?>">
                </label>
                <label>Date of Baptism (if known)
                    <input type="date" name="baptism_date" value="<?= e($_POST['baptism_date'] ?? '') ?>">
                </label>
                <label>Delegated Minister (if any)
                    <input type="text" name="delegated_minister" value="<?= e($_POST['delegated_minister'] ?? '') ?>">
                </label>
                <div class="inline-fields">
                    <label>Book No. <input type="text" name="book_no" value="<?= e($_POST['book_no'] ?? '') ?>"></label>
                    <label>Page <input type="text" name="page_no" value="<?= e($_POST['page_no'] ?? '') ?>"></label>
                    <label>Line <input type="text" name="line_no" value="<?= e($_POST['line_no'] ?? '') ?>"></label>
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="certificate_done" value="1" <?= isset($_POST['certificate_done']) ? 'checked' : '' ?>>
                    Certificate already done (mark if a certificate was previously issued)
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
