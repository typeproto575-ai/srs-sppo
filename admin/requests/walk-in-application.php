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
            $childName = trim($_POST['child_full_name'] ?? '');
            $childBirthdate = $_POST['child_birthdate'] ?? null;
            $childPlace = trim($_POST['child_birthplace'] ?? '');
            $fatherName = trim($_POST['father_full_name'] ?? '');
            $fatherBirthplace = trim($_POST['father_birthplace'] ?? '');
            $motherMaidenName = trim($_POST['mother_full_name'] ?? ''); // form field is "mother_full_name"; stored in mother_maiden_name column
            $motherBirthplace = trim($_POST['mother_birthplace'] ?? '');
            $parentsAddress = trim($_POST['parents_address'] ?? '');
            $parentsUnionType = trim($_POST['parents_union_type'] ?? 'legitimate');
            $parentName = trim($_POST['parent_guardian_name'] ?? '');
            $contact = null; // Email is the only approved contact channel.
            $email = trim($_POST['email'] ?? '');
            if ($childName === '' || $childBirthdate === '' || $childPlace === '' || $parentName === '' || $email === '') {
                throw new Exception('Please fill required baptismal application fields.');
            }
            // No ID upload (feature removed): identity verified in person.

            $stmt = $pdo->prepare(
                "INSERT INTO baptismal_applications (application_id, child_full_name, child_birthdate, child_birthplace, father_full_name, father_birthplace, mother_maiden_name, mother_birthplace, parents_address, parents_union_type, parent_guardian_name, contact_number, email, preferred_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId, $childName, $childBirthdate, $childPlace,
                $fatherName, $fatherBirthplace, $motherMaidenName, $motherBirthplace,
                $parentsAddress, $parentsUnionType,
                $parentName, $contact, $email, $_POST['preferred_date'] ?: null, trim($_POST['notes'] ?? '')
            ]);
            $baptismalAppExtId = (int) $pdo->lastInsertId();

            // Insert godparents if provided
            if (!empty($_POST['godparents']) && is_array($_POST['godparents'])) {
                $gpStmt = $pdo->prepare("INSERT INTO baptismal_application_godparents (baptismal_application_id, godparent_name, godparent_residence) VALUES (?, ?, ?)");
                foreach ($_POST['godparents'] as $i => $gpName) {
                    $name = trim($gpName);
                    $res = trim($_POST['godparents_residence'][$i] ?? '');
                    if ($name !== '') {
                        $gpStmt->execute([$baptismalAppExtId, $name, $res]);
                    }
                }
            }

        } elseif ($sacramentType === 'confirmation' || $sacramentType === 'first_communion') {
            $isConfirmation = $sacramentType === 'confirmation';
            $extTable = $isConfirmation ? 'confirmation_applications' : 'first_communion_applications';
            $name = trim($_POST['applicant_full_name'] ?? '');
            $birthdate = $_POST['applicant_birthdate'] ?? null;
            $parentGuardian = trim($_POST['parent_guardian_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if ($name === '' || $birthdate === '' || $parentGuardian === '' || $email === '') {
                throw new Exception('Please fill the required application fields.');
            }
            if ($isConfirmation) {
                $stmt = $pdo->prepare(
                    "INSERT INTO confirmation_applications
                        (application_id, applicant_full_name, applicant_birthdate, parent_guardian_name, contact_number, email,
                         baptized_here, baptism_date, baptism_parish_name, baptism_parish_address, preferred_batch, sponsor_name)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $applicationId, $name, $birthdate, $parentGuardian, trim($_POST['contact_number'] ?? ''), $email,
                    ($_POST['baptized_here'] ?? '') === 'this_parish' ? 1 : 0,
                    $_POST['baptism_date'] ?: null, trim($_POST['baptism_parish_name'] ?? ''),
                    trim($_POST['baptism_parish_address'] ?? ''), trim($_POST['preferred_batch'] ?? ''), trim($_POST['sponsor_name'] ?? ''),
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO first_communion_applications
                        (application_id, applicant_full_name, applicant_birthdate, parent_guardian_name, contact_number, email,
                         baptized_here, baptism_date, baptism_parish_name, baptism_parish_address, preferred_batch)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $applicationId, $name, $birthdate, $parentGuardian, trim($_POST['contact_number'] ?? ''), $email,
                    ($_POST['baptized_here'] ?? '') === 'this_parish' ? 1 : 0,
                    $_POST['baptism_date'] ?: null, trim($_POST['baptism_parish_name'] ?? ''),
                    trim($_POST['baptism_parish_address'] ?? ''), trim($_POST['preferred_batch'] ?? ''),
                ]);
            }

        } elseif ($sacramentType === 'marriage') {
            // --- Marriage application ---
            // Minimal required marriage fields for both parties
            $groomName = trim($_POST['groom_full_name'] ?? '');
            $groomBirthdate = $_POST['groom_birthdate'] ?? null;
            $groomBirthplace = trim($_POST['groom_birthplace'] ?? '');
            $groomContact = null; // Email is the only approved contact channel.
            $groomEmail = trim($_POST['groom_email'] ?? '');

            $brideName = trim($_POST['bride_full_name'] ?? '');
            $brideBirthdate = $_POST['bride_birthdate'] ?? null;
            $brideBirthplace = trim($_POST['bride_birthplace'] ?? '');
            $brideContact = null; // Email is the only approved contact channel.
            $brideEmail = trim($_POST['bride_email'] ?? '');

            if ($groomName === '' || $groomBirthdate === '' || $groomBirthplace === '' || $groomEmail === '' || $brideName === '' || $brideBirthdate === '' || $brideBirthplace === '' || $brideEmail === '') {
                throw new Exception('Please fill required marriage application fields for both parties.');
            }

            // No ID upload (feature removed): both parties' identities
            // are verified in person at the office counter.

            $stmt = $pdo->prepare(
                "INSERT INTO marriage_applications (application_id, groom_full_name, groom_birthdate, groom_birthplace, groom_contact_number, groom_email, groom_marital_status,
                 bride_full_name, bride_birthdate, bride_birthplace, bride_contact_number, bride_email, bride_marital_status, preferred_wedding_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId,
                $groomName, $groomBirthdate, $groomBirthplace, $groomContact, $groomEmail, trim($_POST['groom_marital_status'] ?? 'single'),
                $brideName, $brideBirthdate, $brideBirthplace, $brideContact, $brideEmail, trim($_POST['bride_marital_status'] ?? 'single'), $_POST['preferred_wedding_date'] ?: null
            ]);
            $marriageAppExtId = (int) $pdo->lastInsertId();

            // Insert principal sponsors if provided
            if (!empty($_POST['principal_sponsors_name']) && is_array($_POST['principal_sponsors_name'])) {
                $msStmt = $pdo->prepare("INSERT INTO marriage_principal_sponsors (marriage_application_id, sponsor_name, sponsor_residence) VALUES (?, ?, ?)");
                foreach ($_POST['principal_sponsors_name'] as $i => $sName) {
                    $name = trim($sName);
                    $res = trim($_POST['principal_sponsors_residence'][$i] ?? '');
                    if ($name !== '') {
                        $msStmt->execute([$marriageAppExtId, $name, $res]);
                    }
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
        <h1>Encode Walk-in Application</h1>
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

        <form autocomplete="off" method="post" action="">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <fieldset>
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
            <div id="section-baptismal" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Baptismal Application</legend>
                    <label>Child's Full Name * <input autocomplete="off" type="text" name="child_full_name" value="<?= e($_POST['child_full_name'] ?? '') ?>"></label>
                    <label>Birthdate * <input autocomplete="off" type="date" name="child_birthdate" value="<?= e($_POST['child_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace * <input autocomplete="off" type="text" name="child_birthplace" value="<?= e($_POST['child_birthplace'] ?? '') ?>"></label>
                    <label>Father's Full Name <input autocomplete="off" type="text" name="father_full_name" value="<?= e($_POST['father_full_name'] ?? '') ?>"></label>
                    <label>Mother's Full Name <input autocomplete="off" type="text" name="mother_full_name" value="<?= e($_POST['mother_full_name'] ?? '') ?>"></label>
                    <label>Parent/Guardian Name * <input autocomplete="off" type="text" name="parent_guardian_name" value="<?= e($_POST['parent_guardian_name'] ?? '') ?>"></label>
                    <label>Email * <input autocomplete="off" type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
                    <label>Preferred Date <input autocomplete="off" type="date" name="preferred_date" value="<?= e($_POST['preferred_date'] ?? '') ?>"></label>
                    <!-- Valid ID upload removed: identity verified in person -->
                    <div class="repeat-list">
                        <p>Godparents / Sponsors</p>
                        <div id="godparents-list">
                            <div class="repeat-item">
                                <input autocomplete="off" type="text" name="godparents[]" placeholder="Godparent full name">
                                <input autocomplete="off" type="text" name="godparents_residence[]" placeholder="Residence (optional)">
                                <button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>
                            </div>
                        </div>
                        <button type="button" class="btn" onclick="addGodparent()">Add Godparent</button>
                    </div>
                </fieldset>
            </div>

            <div id="section-confirmation" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Confirmation Application</legend>
                    <label>Applicant's Full Name * <input autocomplete="off" type="text" name="applicant_full_name" value="<?= e($_POST['applicant_full_name'] ?? '') ?>"></label>
                    <label>Date of Birth * <input autocomplete="off" type="date" name="applicant_birthdate" value="<?= e($_POST['applicant_birthdate'] ?? '') ?>"></label>
                    <label>Parent/Guardian Name * <input autocomplete="off" type="text" name="parent_guardian_name" value="<?= e($_POST['parent_guardian_name'] ?? '') ?>"></label>
                    <label>Contact Number <input autocomplete="off" type="text" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>"></label>
                    <label>Email * <input autocomplete="off" type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
                    <label>Baptized at this parish?
                        <select name="baptized_here"><option value="">-- Select --</option><option value="this_parish">Yes</option><option value="elsewhere">Another parish/church</option></select>
                    </label>
                    <div class="inline-fields"><label>Baptism Date <input autocomplete="off" type="date" name="baptism_date" value="<?= e($_POST['baptism_date'] ?? '') ?>"></label><label>Baptism Parish <input autocomplete="off" type="text" name="baptism_parish_name" value="<?= e($_POST['baptism_parish_name'] ?? '') ?>"></label></div>
                    <label>Preferred Batch <input autocomplete="off" type="text" name="preferred_batch" value="<?= e($_POST['preferred_batch'] ?? '') ?>"></label>
                    <label>Sponsor <input autocomplete="off" type="text" name="sponsor_name" value="<?= e($_POST['sponsor_name'] ?? '') ?>"></label>
                </fieldset>
            </div>

            <div id="section-first_communion" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>First Communion Application</legend>
                    <label>Applicant's Full Name * <input autocomplete="off" type="text" name="applicant_full_name" value="<?= e($_POST['applicant_full_name'] ?? '') ?>"></label>
                    <label>Date of Birth * <input autocomplete="off" type="date" name="applicant_birthdate" value="<?= e($_POST['applicant_birthdate'] ?? '') ?>"></label>
                    <label>Parent/Guardian Name * <input autocomplete="off" type="text" name="parent_guardian_name" value="<?= e($_POST['parent_guardian_name'] ?? '') ?>"></label>
                    <label>Contact Number <input autocomplete="off" type="text" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>"></label>
                    <label>Email * <input autocomplete="off" type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
                    <label>Baptized at this parish?
                        <select name="baptized_here"><option value="">-- Select --</option><option value="this_parish">Yes</option><option value="elsewhere">Another parish/church</option></select>
                    </label>
                    <div class="inline-fields"><label>Baptism Date <input autocomplete="off" type="date" name="baptism_date" value="<?= e($_POST['baptism_date'] ?? '') ?>"></label><label>Baptism Parish <input autocomplete="off" type="text" name="baptism_parish_name" value="<?= e($_POST['baptism_parish_name'] ?? '') ?>"></label></div>
                    <label>Preferred Batch <input autocomplete="off" type="text" name="preferred_batch" value="<?= e($_POST['preferred_batch'] ?? '') ?>"></label>
                </fieldset>
            </div>

            <div id="section-marriage" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Marriage Application</legend>
                    <h4>Groom</h4>
                    <label>Full Name * <input autocomplete="off" type="text" name="groom_full_name" value="<?= e($_POST['groom_full_name'] ?? '') ?>"></label>
                    <label>Birthdate * <input autocomplete="off" type="date" name="groom_birthdate" value="<?= e($_POST['groom_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace * <input autocomplete="off" type="text" name="groom_birthplace" value="<?= e($_POST['groom_birthplace'] ?? '') ?>"></label>
                    <label>Email * <input autocomplete="off" type="email" name="groom_email" value="<?= e($_POST['groom_email'] ?? '') ?>"></label>
                    <label>Marital Status <select name="groom_marital_status"><option value="single">Single</option><option value="widowed">Widowed</option><option value="annulled">Annulled</option></select></label>
                    <!-- Valid ID upload removed: identity verified in person -->

                    <h4>Bride</h4>
                    <label>Full Name * <input autocomplete="off" type="text" name="bride_full_name" value="<?= e($_POST['bride_full_name'] ?? '') ?>"></label>
                    <label>Birthdate * <input autocomplete="off" type="date" name="bride_birthdate" value="<?= e($_POST['bride_birthdate'] ?? '') ?>"></label>
                    <label>Birthplace * <input autocomplete="off" type="text" name="bride_birthplace" value="<?= e($_POST['bride_birthplace'] ?? '') ?>"></label>
                    <label>Email * <input autocomplete="off" type="email" name="bride_email" value="<?= e($_POST['bride_email'] ?? '') ?>"></label>
                    <label>Marital Status <select name="bride_marital_status"><option value="single">Single</option><option value="widowed">Widowed</option><option value="annulled">Annulled</option></select></label>
                    <!-- Valid ID upload removed: identity verified in person -->
                    <label>Preferred Wedding Date <input autocomplete="off" type="date" name="preferred_wedding_date" value="<?= e($_POST['preferred_wedding_date'] ?? '') ?>"></label>
                    <div class="repeat-list">
                        <p>Principal Sponsors</p>
                        <div id="sponsors-list">
                            <div class="repeat-item">
                                <input autocomplete="off" type="text" name="principal_sponsors_name[]" placeholder="Sponsor full name">
                                <input autocomplete="off" type="text" name="principal_sponsors_residence[]" placeholder="Residence (optional)">
                                <button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>
                            </div>
                        </div>
                        <button type="button" class="btn" onclick="addSponsor()">Add Sponsor</button>
                    </div>
                </fieldset>
            </div>

            <fieldset>
                <legend>Notes</legend>
                <label>Notes <textarea autocomplete="off" name="notes"><?= e($_POST['notes'] ?? '') ?></textarea></label>
            </fieldset>

            <fieldset>
                <legend>Payment (in person)</legend>
                <label class="checkbox-label"><input type="checkbox" name="payment_received" value="1" <?= (($_POST['payment_received'] ?? '') === '1') ? 'checked' : '' ?>> Payment received at the counter &mdash; mark as paid</label>
                <p class="field-hint">Optional. Payment is collected in person at the office; the system records no amount, only that it was received and by whom.</p>
            </fieldset>

            <button type="submit" class="btn">Encode Application</button>
        </form>

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
            div.innerHTML = '<input autocomplete="off" type="text" name="godparents[]" placeholder="Godparent full name">\n                                <input autocomplete="off" type="text" name="godparents_residence[]" placeholder="Residence (optional)">\n                                <button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>';
            list.appendChild(div);
        }

        // Add a new sponsor input row
        function addSponsor() {
            var list = document.getElementById('sponsors-list');
            var div = document.createElement('div');
            div.className = 'repeat-item';
            div.innerHTML = '<input autocomplete="off" type="text" name="principal_sponsors_name[]" placeholder="Sponsor full name">\n                                <input autocomplete="off" type="text" name="principal_sponsors_residence[]" placeholder="Residence (optional)">\n                                <button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>';
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
                if (!document.querySelector('#section-' + type + ' input[name="child_full_name"]').value) missing.push('Child name');
                if (!document.querySelector('#section-' + type + ' input[name="child_birthdate"]').value) missing.push('Child birthdate');
                if (!document.querySelector('#section-' + type + ' input[name="child_birthplace"]').value) missing.push('Child birthplace');
                if (!document.querySelector('#section-' + type + ' input[name="parent_guardian_name"]').value) missing.push('Parent/guardian name');
            } else if (type === 'confirmation' || type === 'first_communion') {
                if (!document.querySelector('#section-' + type + ' input[name="applicant_full_name"]').value) missing.push('Applicant name');
                if (!document.querySelector('#section-' + type + ' input[name="applicant_birthdate"]').value) missing.push('Applicant birthdate');
                if (!document.querySelector('#section-' + type + ' input[name="parent_guardian_name"]').value) missing.push('Parent/guardian name');
            } else if (type === 'marriage') {
                if (!document.querySelector('#section-' + type + ' input[name="groom_full_name"]').value) missing.push('Groom full name');
                if (!document.querySelector('#section-' + type + ' input[name="bride_full_name"]').value) missing.push('Bride full name');
            }
            if (missing.length) {
                e.preventDefault();
                alert('Please complete required fields: ' + missing.join(', '));
            }
        });
        </script>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
