<?php
/**
 * =====================================================================
 * SRS-SPPO WALK-IN SACRAMENT APPLICATION ENCODING
 * =====================================================================
 * Purpose: Lets the Secretary encode a sacrament application in person
 *          when a parishioner visits the office instead of using the
 *          public online form.
 *
  * Supports: Baptismal, Confirmation, First Communion, and Marriage.
  * (Each type writes the shared sacrament_applications row plus its
  *  extension table; walk-ins are recorded with submission_channel 'onsite'.)
 * Note: No ID uploads are collected — per the updated design doc,
 *       identity is verified in person; ID-related columns stay NULL.
 *
 * Access: Secretary only (require_role('secretary')).
 * =====================================================================
 */

// Load database connection, shared helpers, and session guard
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Page title shown in the browser tab
$pageTitle = 'Encode Walk-in Application';
$errors = [];
$trackingNumber = null;

// --- Handle form submission ----------------------------------------------
// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form) before any validation or DB writes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    require_role('secretary');

    $sacramentType = $_POST['sacrament_type'] ?? '';
    $validTypes = ['baptismal', 'confirmation', 'first_communion', 'marriage'];

    // Walk-in counter flow: apply -> pay -> process. If the fee was already
    // paid at the counter, record that fact now (paid_at + paid_by). No amount
    // is stored; the system never takes money. Defaults to not-paid.
    $markPaid = ($_POST['payment_received'] ?? '') === '1';

    // Validate the sacrament type
    if (!in_array($sacramentType, $validTypes, true)) {
        $errors[] = 'Select a sacrament type.';
    }

    // Retry the whole transaction when a concurrent submission collides with
    // our generated tracking number (retry_on_duplicate regenerates + retries).
    try {
        $trackingNumber = retry_on_duplicate($pdo, function () use ($pdo, $sacramentType, $markPaid): string {
        // Generate a unique tracking number for this application
        $trackingNumber = generate_tracking_number($pdo, 'sacrament_applications');

        // Record the counter payment fact if marked (no amount stored).
        $paidAt = $markPaid ? date('Y-m-d H:i:s') : null;
        $paidBy = $markPaid ? current_user()['id'] : null;

        // Insert into the shared sacrament_applications table
        $stmt = $pdo->prepare("INSERT INTO sacrament_applications (tracking_number, submission_channel, sacrament_type, notes, paid_at, paid_by) VALUES (?, 'onsite', ?, ?, ?, ?)");
        $stmt->execute([$trackingNumber, $sacramentType, trim($_POST['notes'] ?? ''), $paidAt, $paidBy]);
        $applicationId = (int) $pdo->lastInsertId();

        // --- Handle type-specific insertion and file uploads -------------
        if ($sacramentType === 'baptismal') {
            // --- Baptismal application ---
            $childName = compose_person_name($_POST, 'child');
            $childBirthdate = $_POST['child_birthdate'] ?? null;
            $childPlace = trim($_POST['child_birthplace'] ?? '');
            $fatherName = compose_person_name($_POST, 'father');
            $motherMaidenName = compose_person_name($_POST, 'mother');
            $parentName = compose_person_name($_POST, 'parent_guardian');
            $contact = null; // Email is the only approved contact channel.
            $email = trim($_POST['email'] ?? '');
            if (trim((string) ($_POST['child_first'] ?? '')) === '' || trim((string) ($_POST['child_last'] ?? '')) === '' || $childBirthdate === '' || $childPlace === '' || $parentName === '' || $email === '') {
                throw new Exception('Please fill required baptismal application fields.');
            }
            if (($nameError = validate_full_name($childName)) !== null) {
                throw new Exception($nameError);
            }
            foreach ([
                validate_person_name_fields($_POST, 'father', "Father's name", false),
                validate_person_name_fields($_POST, 'mother', "Mother's name", false),
                validate_person_name_fields($_POST, 'parent_guardian', 'Parent/Guardian'),
            ] as $nameError) {
                if ($nameError !== null) throw new Exception($nameError);
            }
            $godparentErrors = validate_person_name_rows($_POST, 'godparent', 'Godparent');
            if ($godparentErrors !== []) throw new Exception(implode(' ', $godparentErrors));
            // No ID upload (feature removed): identity verified in person.

            $stmt = $pdo->prepare(
                "INSERT INTO baptismal_applications (application_id, child_full_name, child_birthdate, child_birthplace, father_full_name, mother_maiden_name, parents_union_type, parent_guardian_name, contact_number, email, preferred_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId, $childName, $childBirthdate, $childPlace,
                $fatherName, $motherMaidenName,
                $parentName, $contact, $email, $_POST['preferred_date'] ?: null, trim($_POST['notes'] ?? '')
            ]);
            $baptismalAppExtId = (int) $pdo->lastInsertId();

            // Insert godparents if provided
            if (!empty($_POST['godparent_first']) && is_array($_POST['godparent_first'])) {
                $gpStmt = $pdo->prepare("INSERT INTO baptismal_application_godparents (baptismal_application_id, godparent_name) VALUES (?, ?)");
                foreach (compose_person_name_rows($_POST, 'godparent') as $name) {
                    $gpStmt->execute([$baptismalAppExtId, $name]);
                }
            }

        } elseif ($sacramentType === 'confirmation' || $sacramentType === 'first_communion') {
            $isConfirmation = $sacramentType === 'confirmation';
            $extTable = $isConfirmation ? 'confirmation_applications' : 'first_communion_applications';
            $name = compose_person_name($_POST, 'applicant');
            $birthdate = $_POST['applicant_birthdate'] ?? null;
            $parentGuardian = compose_person_name($_POST, 'parent_guardian');
            $email = trim($_POST['email'] ?? '');
            if (trim((string) ($_POST['applicant_first'] ?? '')) === '' || trim((string) ($_POST['applicant_last'] ?? '')) === '' || $birthdate === '' || $parentGuardian === '' || $email === '') {
                throw new Exception('Please fill the required application fields.');
            }
            if (($nameError = validate_full_name($name)) !== null) {
                throw new Exception($nameError);
            }
            $parentNameError = validate_person_name_fields($_POST, 'parent_guardian', 'Parent/Guardian');
            if ($parentNameError !== null) throw new Exception($parentNameError);
            if ($isConfirmation) {
                $sponsorNameError = validate_person_name_fields($_POST, 'sponsor', 'Sponsor', false);
                if ($sponsorNameError !== null) throw new Exception($sponsorNameError);
            }
            if ($isConfirmation) {
                $stmt = $pdo->prepare(
                    "INSERT INTO confirmation_applications
                        (application_id, applicant_full_name, applicant_birthdate, parent_guardian_name, contact_number, email,
                         baptized_here, sponsor_name)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $applicationId, $name, $birthdate, $parentGuardian, trim($_POST['contact_number'] ?? ''), $email,
                    ($_POST['baptized_here'] ?? '') === 'this_parish' ? 1 : 0,
                    compose_person_name($_POST, 'sponsor'),
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO first_communion_applications
                        (application_id, applicant_full_name, applicant_birthdate, parent_guardian_name, contact_number, email,
                         baptized_here)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $applicationId, $name, $birthdate, $parentGuardian, trim($_POST['contact_number'] ?? ''), $email,
                    ($_POST['baptized_here'] ?? '') === 'this_parish' ? 1 : 0,
                ]);
            }

        } elseif ($sacramentType === 'marriage') {
            // --- Marriage application ---
            // Minimal required marriage fields for both parties
            $groomName = compose_person_name($_POST, 'groom');
            $groomBirthdate = $_POST['groom_birthdate'] ?? null;
            $groomBirthplace = trim($_POST['groom_birthplace'] ?? '');
            $groomContact = null; // Email is the only approved contact channel.
            $groomEmail = trim($_POST['groom_email'] ?? '');

            $brideName = compose_person_name($_POST, 'bride');
            $brideBirthdate = $_POST['bride_birthdate'] ?? null;
            $brideBirthplace = trim($_POST['bride_birthplace'] ?? '');
            $brideContact = null; // Email is the only approved contact channel.
            $brideEmail = trim($_POST['bride_email'] ?? '');
            $preferredWeddingDate = trim($_POST['preferred_wedding_date'] ?? '');
            $groomMaritalStatus = trim($_POST['groom_marital_status'] ?? '');
            $brideMaritalStatus = trim($_POST['bride_marital_status'] ?? '');

            if (trim((string) ($_POST['groom_first'] ?? '')) === '' || trim((string) ($_POST['groom_last'] ?? '')) === '' || trim((string) ($_POST['bride_first'] ?? '')) === '' || trim((string) ($_POST['bride_last'] ?? '')) === '' || $groomBirthdate === '' || $groomBirthplace === '' || $groomEmail === '' || $brideBirthdate === '' || $brideBirthplace === '' || $brideEmail === '' || $preferredWeddingDate === '' || !in_array($groomMaritalStatus, ['single', 'married', 'widowed', 'annulled'], true) || !in_array($brideMaritalStatus, ['single', 'married', 'widowed', 'annulled'], true)) {
                throw new Exception('Please fill required marriage application fields for both parties.');
            }
            foreach ([$groomName, $brideName] as $name) {
                if (($nameError = validate_full_name($name)) !== null) {
                    throw new Exception($nameError);
                }
            }
            foreach (['groom' => 'Groom', 'bride' => 'Bride'] as $prefix => $label) {
                $nameError = validate_person_name_fields($_POST, $prefix, $label);
                if ($nameError !== null) throw new Exception($nameError);
            }
            $sponsorErrors = validate_person_name_rows($_POST, 'principal_sponsor', 'Sponsor');
            if ($sponsorErrors !== []) throw new Exception(implode(' ', $sponsorErrors));

            // No ID upload (feature removed): both parties' identities
            // are verified in person at the office counter.

            $stmt = $pdo->prepare(
                "INSERT INTO marriage_applications (application_id, groom_full_name, groom_birthdate, groom_birthplace, groom_contact_number, groom_email, groom_marital_status,
                 bride_full_name, bride_birthdate, bride_birthplace, bride_contact_number, bride_email, bride_marital_status, preferred_wedding_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId,
                $groomName, $groomBirthdate, $groomBirthplace, $groomContact, $groomEmail, $groomMaritalStatus,
                $brideName, $brideBirthdate, $brideBirthplace, $brideContact, $brideEmail, $brideMaritalStatus, $preferredWeddingDate
            ]);
            $marriageAppExtId = (int) $pdo->lastInsertId();

            // Insert principal sponsors if provided
            if (!empty($_POST['principal_sponsor_first']) && is_array($_POST['principal_sponsor_first'])) {
                $msStmt = $pdo->prepare("INSERT INTO marriage_principal_sponsors (marriage_application_id, sponsor_name) VALUES (?, ?)");
                foreach (array_slice(compose_person_name_rows($_POST, 'principal_sponsor'), 0, 4) as $name) {
                    $msStmt->execute([$marriageAppExtId, $name]);
                }
            }
        }

        // Log the walk-in application encoding to the audit trail
        log_activity($pdo, current_user()['id'], 'walk_in_application_encoded', 'sacrament_applications', $applicationId, "Walk-in {$sacramentType} application encoded." . ($markPaid ? ' (payment recorded)' : ''));
        notify($pdo, 'application_new', 'New walk-in application',
            "Walk-in {$sacramentType} application ({$trackingNumber})",
            '/admin/requests/applications-queue.php');
        return $trackingNumber;
        });
    } catch (Exception $e) {
        // Validation exceptions (plain Exception) stay visible, but raw PDO
        // exception text (SQL state, table/column names) must never reach the
        // UI — log it for the sysadmin and show a generic message instead.
        if ($e instanceof PDOException) {
            error_log('SRS-SPPO walk-in application insert failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving this application. Please try again.';
        } else {
            $errors[] = $e->getMessage();
        }
    }
}
?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
        <div class="walk-in-page walk-in-application-page">
        <header class="admin-queue-heading">
            <div>
                <p class="admin-queue-kicker">In-person service</p>
                <h1>Encode a sacrament application</h1>
                <p>Record an application for a parishioner visiting the office. Choose a sacrament to show the matching details.</p>
            </div>
        </header>
        <?php if ($trackingNumber): ?>
            <!-- Success message with the generated tracking number -->
            <div class="alert alert-success">
                <p>Application recorded. Tracking number: <strong><?= e($trackingNumber) ?></strong></p>
                <p class="field-hint">Payment: <?= (($_POST['payment_received'] ?? '') === '1') ? 'received at the counter and recorded' : 'not yet recorded' ?>. The system records no amount &mdash; only that payment was received and by whom.</p>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <!-- Validation errors -->
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form autocomplete="off" method="post" action="" class="walk-in-form walk-in-wizard" data-walk-in-kind="application">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <input type="hidden" name="walk_in_phase" value="<?= e((string) ($_POST['walk_in_phase'] ?? '1')) ?>" data-walk-in-current-step>
            <fieldset data-walk-in-phase="type">
                <legend>Sacrament Type</legend>
                <label>Type *
                    <select name="sacrament_type" id="sacrament_type" required onchange="showSection(this.value)">
                        <option value="">-- Select --</option>
                        <option value="baptismal" <?= (($_POST['sacrament_type'] ?? '') === 'baptismal') ? 'selected' : '' ?>>Baptismal</option>
                        <option value="confirmation" <?= (($_POST['sacrament_type'] ?? '') === 'confirmation') ? 'selected' : '' ?>>Confirmation</option>
                        <option value="first_communion" <?= (($_POST['sacrament_type'] ?? '') === 'first_communion') ? 'selected' : '' ?>>First Communion</option>
                        <option value="marriage" <?= (($_POST['sacrament_type'] ?? '') === 'marriage') ? 'selected' : '' ?>>Marriage</option>
                    </select>
                </label>
            </fieldset>

            <!-- Sacrament-specific sections (shown/hidden via JS based on selection) -->
            <div id="section-baptismal" class="sacrament-section" data-walk-in-phase="details" style="display:none;">
                <fieldset>
                    <legend>Baptismal Application</legend>
                    <div class="inline-fields">
                        <label>Child's First Name * <input autocomplete="given-name" type="text" name="child_first" value="<?= e($_POST['child_first'] ?? '') ?>" required></label>
                        <label>Middle Name <span class="field-hint">(optional)</span> <input autocomplete="additional-name" type="text" name="child_middle" value="<?= e($_POST['child_middle'] ?? '') ?>"></label>
                    </div>
                    <div class="inline-fields">
                        <label>Last Name * <input autocomplete="family-name" type="text" name="child_last" value="<?= e($_POST['child_last'] ?? '') ?>" required></label>
                        <label>Suffix <span class="field-hint">(optional)</span> <input autocomplete="honorific-suffix" type="text" name="child_suffix" value="<?= e($_POST['child_suffix'] ?? '') ?>"></label>
                    </div>
                    <label>Birthdate * <input autocomplete="off" type="date" name="child_birthdate" value="<?= e($_POST['child_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace * <input autocomplete="off" type="text" name="child_birthplace" value="<?= e($_POST['child_birthplace'] ?? '') ?>"></label>
                    <?= person_name_fields('father', "Father's name", $_POST, false) ?>
                    <?= person_name_fields('mother', "Mother's name", $_POST, false) ?>
                    <?= person_name_fields('parent_guardian', 'Parent/Guardian', $_POST) ?>
                    <label>Email * <input autocomplete="off" type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
                    <label>Preferred Date <input autocomplete="off" type="date" name="preferred_date" value="<?= e($_POST['preferred_date'] ?? '') ?>"></label>
                    <!-- Valid ID upload removed: identity verified in person -->
                    <div class="repeat-list">
                        <p>Godparents / Sponsors</p>
                        <p class="field-hint">For each person, enter first and last name. Middle name and suffix are optional.</p>
                        <div id="godparents-list">
                            <?php for ($i = 0; $i < max(1, person_name_row_count($_POST, 'godparent')); $i++): ?>
                                <div class="repeat-item">
                                    <?= person_name_fields('godparent', 'Godparent', $_POST, false, $i) ?>
                                    <button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <button type="button" class="btn" onclick="addGodparent()">Add Godparent</button>
                    </div>
                </fieldset>
            </div>

            <div id="section-confirmation" class="sacrament-section" data-walk-in-phase="details" style="display:none;">
                <fieldset>
                    <legend>Confirmation Application</legend>
                    <div class="inline-fields">
                        <label>First Name * <input autocomplete="given-name" type="text" name="applicant_first" value="<?= e($_POST['applicant_first'] ?? '') ?>" required></label>
                        <label>Middle Name <span class="field-hint">(optional)</span> <input autocomplete="additional-name" type="text" name="applicant_middle" value="<?= e($_POST['applicant_middle'] ?? '') ?>"></label>
                    </div>
                    <div class="inline-fields">
                        <label>Last Name * <input autocomplete="family-name" type="text" name="applicant_last" value="<?= e($_POST['applicant_last'] ?? '') ?>" required></label>
                        <label>Suffix <span class="field-hint">(optional)</span> <input autocomplete="honorific-suffix" type="text" name="applicant_suffix" value="<?= e($_POST['applicant_suffix'] ?? '') ?>"></label>
                    </div>
                    <label>Date of Birth * <input autocomplete="off" type="date" name="applicant_birthdate" value="<?= e($_POST['applicant_birthdate'] ?? '') ?>"></label>
                    <?= person_name_fields('parent_guardian', 'Parent/Guardian', $_POST) ?>
                    <label>Contact Number <input autocomplete="off" type="text" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>"></label>
                    <label>Email * <input autocomplete="off" type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
                    <label>Baptized at this parish?
                        <select name="baptized_here"><option value="">-- Select --</option><option value="this_parish">Yes</option><option value="elsewhere">Another parish/church</option></select>
                    </label>
                    <?= person_name_fields('sponsor', 'Sponsor', $_POST, false) ?>
                </fieldset>
            </div>

            <div id="section-first_communion" class="sacrament-section" data-walk-in-phase="details" style="display:none;">
                <fieldset>
                    <legend>First Communion Application</legend>
                    <div class="inline-fields">
                        <label>First Name * <input autocomplete="given-name" type="text" name="applicant_first" value="<?= e($_POST['applicant_first'] ?? '') ?>" required></label>
                        <label>Middle Name <span class="field-hint">(optional)</span> <input autocomplete="additional-name" type="text" name="applicant_middle" value="<?= e($_POST['applicant_middle'] ?? '') ?>"></label>
                    </div>
                    <div class="inline-fields">
                        <label>Last Name * <input autocomplete="family-name" type="text" name="applicant_last" value="<?= e($_POST['applicant_last'] ?? '') ?>" required></label>
                        <label>Suffix <span class="field-hint">(optional)</span> <input autocomplete="honorific-suffix" type="text" name="applicant_suffix" value="<?= e($_POST['applicant_suffix'] ?? '') ?>"></label>
                    </div>
                    <label>Date of Birth * <input autocomplete="off" type="date" name="applicant_birthdate" value="<?= e($_POST['applicant_birthdate'] ?? '') ?>"></label>
                    <?= person_name_fields('parent_guardian', 'Parent/Guardian', $_POST) ?>
                    <label>Contact Number <input autocomplete="off" type="text" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>"></label>
                    <label>Email * <input autocomplete="off" type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
                    <label>Baptized at this parish?
                        <select name="baptized_here"><option value="">-- Select --</option><option value="this_parish">Yes</option><option value="elsewhere">Another parish/church</option></select>
                    </label>
                </fieldset>
            </div>

            <div id="section-marriage" class="sacrament-section" data-walk-in-phase="details" style="display:none;">
                <fieldset>
                    <legend>Marriage Application</legend>
                    <h4>Groom</h4>
                    <div class="inline-fields">
                        <label>Groom's First Name * <input autocomplete="given-name" type="text" name="groom_first" value="<?= e($_POST['groom_first'] ?? '') ?>" required></label>
                        <label>Middle Name <span class="field-hint">(optional)</span> <input autocomplete="additional-name" type="text" name="groom_middle" value="<?= e($_POST['groom_middle'] ?? '') ?>"></label>
                    </div>
                    <div class="inline-fields">
                        <label>Last Name * <input autocomplete="family-name" type="text" name="groom_last" value="<?= e($_POST['groom_last'] ?? '') ?>" required></label>
                        <label>Suffix <span class="field-hint">(optional)</span> <input autocomplete="honorific-suffix" type="text" name="groom_suffix" value="<?= e($_POST['groom_suffix'] ?? '') ?>"></label>
                    </div>
                    <label>Birthdate * <input autocomplete="off" type="date" name="groom_birthdate" value="<?= e($_POST['groom_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace * <input autocomplete="off" type="text" name="groom_birthplace" value="<?= e($_POST['groom_birthplace'] ?? '') ?>"></label>
                    <label>Email * <input autocomplete="off" type="email" name="groom_email" value="<?= e($_POST['groom_email'] ?? '') ?>"></label>
                    <label>Marital Status * <select name="groom_marital_status" required><option value="">-- Select --</option><option value="single" <?= ($_POST['groom_marital_status'] ?? '') === 'single' ? 'selected' : '' ?>>Single</option><option value="married" <?= ($_POST['groom_marital_status'] ?? '') === 'married' ? 'selected' : '' ?>>Married</option><option value="widowed" <?= ($_POST['groom_marital_status'] ?? '') === 'widowed' ? 'selected' : '' ?>>Widowed</option><option value="annulled" <?= ($_POST['groom_marital_status'] ?? '') === 'annulled' ? 'selected' : '' ?>>Annulled</option></select></label>
                    <!-- Valid ID upload removed: identity verified in person -->

                    <h4>Bride</h4>
                    <div class="inline-fields">
                        <label>Bride's First Name * <input autocomplete="given-name" type="text" name="bride_first" value="<?= e($_POST['bride_first'] ?? '') ?>" required></label>
                        <label>Middle Name <span class="field-hint">(optional)</span> <input autocomplete="additional-name" type="text" name="bride_middle" value="<?= e($_POST['bride_middle'] ?? '') ?>"></label>
                    </div>
                    <div class="inline-fields">
                        <label>Last Name * <input autocomplete="family-name" type="text" name="bride_last" value="<?= e($_POST['bride_last'] ?? '') ?>" required></label>
                        <label>Suffix <span class="field-hint">(optional)</span> <input autocomplete="honorific-suffix" type="text" name="bride_suffix" value="<?= e($_POST['bride_suffix'] ?? '') ?>"></label>
                    </div>
                    <label>Birthdate * <input autocomplete="off" type="date" name="bride_birthdate" value="<?= e($_POST['bride_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace * <input autocomplete="off" type="text" name="bride_birthplace" value="<?= e($_POST['bride_birthplace'] ?? '') ?>"></label>
                    <label>Email * <input autocomplete="off" type="email" name="bride_email" value="<?= e($_POST['bride_email'] ?? '') ?>"></label>
                    <label>Marital Status * <select name="bride_marital_status" required><option value="">-- Select --</option><option value="single" <?= ($_POST['bride_marital_status'] ?? '') === 'single' ? 'selected' : '' ?>>Single</option><option value="married" <?= ($_POST['bride_marital_status'] ?? '') === 'married' ? 'selected' : '' ?>>Married</option><option value="widowed" <?= ($_POST['bride_marital_status'] ?? '') === 'widowed' ? 'selected' : '' ?>>Widowed</option><option value="annulled" <?= ($_POST['bride_marital_status'] ?? '') === 'annulled' ? 'selected' : '' ?>>Annulled</option></select></label>
                    <!-- Valid ID upload removed: identity verified in person -->
                    <label>Preferred Wedding Date * <input autocomplete="off" type="date" name="preferred_wedding_date" value="<?= e($_POST['preferred_wedding_date'] ?? '') ?>" required></label>
                    <div class="repeat-list">
                        <p>Principal Sponsors</p>
                        <div id="sponsors-list">
                            <?php for ($i = 0; $i < max(1, person_name_row_count($_POST, 'principal_sponsor')); $i++): ?>
                                <div class="repeat-item">
                                    <?= person_name_fields('principal_sponsor', 'Sponsor', $_POST, false, $i) ?>
                                    <button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <button type="button" class="btn" onclick="addSponsor()">Add Sponsor</button>
                    </div>
                </fieldset>
            </div>

            <div data-walk-in-phase="wrap-up">
            <fieldset>
                <legend>Notes</legend>
                <label>Notes <textarea autocomplete="off" name="notes"><?= e($_POST['notes'] ?? '') ?></textarea></label>
            </fieldset>

            <fieldset>
                <legend>Payment (in person)</legend>
                <label class="checkbox-label"><input type="checkbox" name="payment_received" value="1" <?= (($_POST['payment_received'] ?? '') === '1') ? 'checked' : '' ?>> Payment received at the counter &mdash; mark as paid</label>
                <p class="field-hint">Optional. Payment is collected in person at the office; the system records no amount, only that it was received and by whom.</p>
            </fieldset>
            </div>

            <button type="submit" class="btn" data-walk-in-submit>Encode Application</button>
        </form>
        </div>

        <script>
        // Show/hide the sacrament-specific section based on the selected type
        function showSection(type) {
            document.querySelectorAll('.sacrament-section').forEach(function (el) {
                var active = el.id === 'section-' + type;
                el.style.display = active ? 'block' : 'none';
                el.querySelectorAll('input, select, textarea').forEach(function (field) {
                    field.disabled = !active;
                });
            });
        }
        showSection(document.getElementById('sacrament_type').value);

        // Add a new godparent input row
        function addGodparent() {
            var list = document.getElementById('godparents-list');
            var div = document.createElement('div');
            div.className = 'repeat-item';
            div.innerHTML = '<div class="structured-name-fields" role="group" aria-label="Godparent"><p><strong>Godparent</strong> <span class="field-hint">(optional)</span></p><div class="inline-fields"><label>First Name<input autocomplete="given-name" type="text" name="godparent_first[]"></label><label>Middle Name <span class="field-hint">(optional)</span><input autocomplete="additional-name" type="text" name="godparent_middle[]"></label></div><div class="inline-fields"><label>Last Name<input autocomplete="family-name" type="text" name="godparent_last[]"></label><label>Suffix <span class="field-hint">(optional)</span><input autocomplete="honorific-suffix" type="text" name="godparent_suffix[]"></label></div></div><button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>';
            list.appendChild(div);
        }

        // Add a new sponsor input row
        function addSponsor() {
            var list = document.getElementById('sponsors-list');
            var div = document.createElement('div');
            div.className = 'repeat-item';
            div.innerHTML = '<div class="structured-name-fields" role="group" aria-label="Sponsor"><p><strong>Sponsor</strong> <span class="field-hint">(optional)</span></p><div class="inline-fields"><label>First Name<input autocomplete="given-name" type="text" name="principal_sponsor_first[]"></label><label>Middle Name <span class="field-hint">(optional)</span><input autocomplete="additional-name" type="text" name="principal_sponsor_middle[]"></label></div><div class="inline-fields"><label>Last Name<input autocomplete="family-name" type="text" name="principal_sponsor_last[]"></label><label>Suffix <span class="field-hint">(optional)</span><input autocomplete="honorific-suffix" type="text" name="principal_sponsor_suffix[]"></label></div></div><button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>';
            list.appendChild(div);
        }

        // Remove a repeatable input row
        function removeItem(btn) {
            var parent = btn.closest('.repeat-item');
            if (parent) parent.remove();
        }

        // Basic client-side validation before submit
        document.querySelector('form').addEventListener('submit', function (e) {
            var type = document.getElementById('sacrament_type').value;
            var missing = [];
            if (type === 'baptismal') {
                if (!document.querySelector('#section-' + type + ' input[name="child_first"]').value || !document.querySelector('#section-' + type + ' input[name="child_last"]').value) missing.push('Child first and last names');
                if (!document.querySelector('#section-' + type + ' input[name="child_birthdate"]').value) missing.push('Child birthdate');
                if (!document.querySelector('#section-' + type + ' input[name="child_birthplace"]').value) missing.push('Child birthplace');
                if (!document.querySelector('#section-' + type + ' input[name="parent_guardian_first"]').value || !document.querySelector('#section-' + type + ' input[name="parent_guardian_last"]').value) missing.push('Parent/guardian first and last names');
            } else if (type === 'confirmation' || type === 'first_communion') {
                if (!document.querySelector('#section-' + type + ' input[name="applicant_first"]').value || !document.querySelector('#section-' + type + ' input[name="applicant_last"]').value) missing.push('Applicant first and last names');
                if (!document.querySelector('#section-' + type + ' input[name="applicant_birthdate"]').value) missing.push('Applicant birthdate');
                if (!document.querySelector('#section-' + type + ' input[name="parent_guardian_first"]').value || !document.querySelector('#section-' + type + ' input[name="parent_guardian_last"]').value) missing.push('Parent/guardian first and last names');
            } else if (type === 'marriage') {
                if (!document.querySelector('#section-' + type + ' input[name="groom_first"]').value || !document.querySelector('#section-' + type + ' input[name="groom_last"]').value) missing.push('Groom first and last names');
                if (!document.querySelector('#section-' + type + ' input[name="bride_first"]').value || !document.querySelector('#section-' + type + ' input[name="bride_last"]').value) missing.push('Bride first and last names');
            }
            if (missing.length) {
                e.preventDefault();
                alert('Please complete required fields: ' + missing.join(', '));
            }
        });
        </script>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
