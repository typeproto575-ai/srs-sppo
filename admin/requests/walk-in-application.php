<?php
/**
 * =====================================================================
 * SPPO-IS WALK-IN SACRAMENT APPLICATION ENCODING
 * =====================================================================
 * Purpose: Lets the Secretary encode a sacrament application in person
 *          when a parishioner visits the office instead of using the
 *          public online form.
 *
 * Supports: Baptismal, Confirmation, First Communion, Marriage.
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

    // Validate the sacrament type
    if (!in_array($sacramentType, $validTypes, true)) {
        $errors[] = 'Select a sacrament type.';
    }

    try {
        $pdo->beginTransaction();

        // Generate a unique tracking number for this application
        $trackingNumber = generate_tracking_number($pdo, 'sacrament_applications');

        // Insert into the shared sacrament_applications table
        $stmt = $pdo->prepare("INSERT INTO sacrament_applications (tracking_number, sacrament_type, notes) VALUES (?, ?, ?)");
        $stmt->execute([$trackingNumber, $sacramentType, trim($_POST['notes'] ?? '')]);
        $applicationId = (int) $pdo->lastInsertId();

        // --- Handle type-specific insertion and file uploads -------------
        if ($sacramentType === 'baptismal') {
            // --- Baptismal application ---
            $childName = trim($_POST['child_full_name'] ?? '');
            $childBirthdate = $_POST['child_birthdate'] ?? null;
            $childPlace = trim($_POST['child_birthplace'] ?? '');
            $parentName = trim($_POST['parent_guardian_name'] ?? '');
            $contact = trim($_POST['contact_number'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if ($childName === '' || $childBirthdate === '' || $childPlace === '' || $parentName === '' || $contact === '' || $email === '') {
                throw new Exception('Please fill required baptismal application fields.');
            }
            // No ID upload (feature removed): identity verified in person.

            $stmt = $pdo->prepare(
                "INSERT INTO baptismal_applications (application_id, child_full_name, child_birthdate, child_birthplace, father_full_name, mother_full_name, parent_guardian_name, contact_number, email, preferred_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId, $childName, $childBirthdate, $childPlace,
                trim($_POST['father_full_name'] ?? ''), trim($_POST['mother_full_name'] ?? ''),
                $parentName, $contact, $email, $_POST['preferred_date'] ?: null, trim($_POST['notes'] ?? '')
            ]);
            $baptismalAppExtId = (int) $pdo->lastInsertId();

            // Insert godparents if provided
            if (!empty($_POST['godparents']) && is_array($_POST['godparents'])) {
                $gpStmt = $pdo->prepare("INSERT INTO baptismal_application_godparents (baptismal_application_id, godparent_name, sponsor_residence) VALUES (?, ?, ?)");
                foreach ($_POST['godparents'] as $i => $gpName) {
                    $name = trim($gpName);
                    $res = trim($_POST['godparents_residence'][$i] ?? '');
                    if ($name !== '') {
                        $gpStmt->execute([$baptismalAppExtId, $name, $res]);
                    }
                }
            }

        } elseif ($sacramentType === 'confirmation' || $sacramentType === 'first_communion') {
            // --- Confirmation / First Communion application (shared logic) ---
            $appName = trim($_POST['applicant_full_name'] ?? '');
            $birthdate = $_POST['applicant_birthdate'] ?? null;
            $parentName = trim($_POST['parent_guardian_name'] ?? '');
            $contact = trim($_POST['contact_number'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if ($appName === '' || $birthdate === '' || $parentName === '' || $contact === '' || $email === '') {
                throw new Exception('Please fill required application fields.');
            }
            // No ID upload (feature removed): identity verified in person.

            // Choose the correct table based on sacrament type
            $table = $sacramentType === 'confirmation' ? 'confirmation_applications' : 'first_communion_applications';
            $stmt = $pdo->prepare(
                "INSERT INTO {$table} (application_id, applicant_full_name, applicant_birthdate, parent_guardian_name, contact_number, email, baptized_here, preferred_batch, sponsor_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $applicationId, $appName, $birthdate, $parentName, $contact, $email,
                isset($_POST['baptized_here']) ? 1 : 0, trim($_POST['preferred_batch'] ?? ''), trim($_POST['sponsor_name'] ?? '')
            ]);

        } elseif ($sacramentType === 'marriage') {
            // --- Marriage application ---
            // Minimal required marriage fields for both parties
            $groomName = trim($_POST['groom_full_name'] ?? '');
            $groomBirthdate = $_POST['groom_birthdate'] ?? null;
            $groomBirthplace = trim($_POST['groom_birthplace'] ?? '');
            $groomContact = trim($_POST['groom_contact_number'] ?? '');
            $groomEmail = trim($_POST['groom_email'] ?? '');

            $brideName = trim($_POST['bride_full_name'] ?? '');
            $brideBirthdate = $_POST['bride_birthdate'] ?? null;
            $brideBirthplace = trim($_POST['bride_birthplace'] ?? '');
            $brideContact = trim($_POST['bride_contact_number'] ?? '');
            $brideEmail = trim($_POST['bride_email'] ?? '');

            if ($groomName === '' || $groomBirthdate === '' || $groomBirthplace === '' || $groomContact === '' || $groomEmail === '' || $brideName === '' || $brideBirthdate === '' || $brideBirthplace === '' || $brideContact === '' || $brideEmail === '') {
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
        log_activity($pdo, current_user()['id'], 'walk_in_application_encoded', 'sacrament_applications', $applicationId, "Walk-in {$sacramentType} application encoded.");
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}
?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
        <h1>Encode Walk-in Application</h1>
        <?php if ($trackingNumber): ?>
            <!-- Success message with the generated tracking number -->
            <div class="alert alert-success">
                <p>Application recorded. Tracking number: <strong><?= e($trackingNumber) ?></strong></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <!-- Validation errors -->
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="post" action="" enctype="multipart/form-data">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <fieldset>
                <legend>Sacrament Type</legend>
                <label>Type *
                    <select name="sacrament_type" id="sacrament_type" required onchange="showSection(this.value)">
                        <option value="">-- Select --</option>
                        <option value="baptismal">Baptismal</option>
                        <option value="confirmation">Confirmation</option>
                        <option value="first_communion">First Communion</option>
                        <option value="marriage">Marriage</option>
                    </select>
                </label>
            </fieldset>

            <!-- Sacrament-specific sections (shown/hidden via JS based on selection) -->
            <div id="section-baptismal" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Baptismal Application</legend>
                    <label>Child's Full Name * <input type="text" name="child_full_name"></label>
                    <label>Birthdate * <input type="date" name="child_birthdate"></label>
                    <label>Birthplace * <input type="text" name="child_birthplace"></label>
                    <label>Father's Full Name <input type="text" name="father_full_name"></label>
                    <label>Mother's Full Name <input type="text" name="mother_full_name"></label>
                    <label>Parent/Guardian Name * <input type="text" name="parent_guardian_name"></label>
                    <label>Contact Number * <input type="text" name="contact_number"></label>
                    <label>Email * <input type="email" name="email"></label>
                    <label>Preferred Date <input type="date" name="preferred_date"></label>
                    <!-- Valid ID upload removed: identity verified in person -->
                    <div class="repeat-list">
                        <p>Godparents / Sponsors</p>
                        <div id="godparents-list">
                            <div class="repeat-item">
                                <input type="text" name="godparents[]" placeholder="Godparent full name">
                                <input type="text" name="godparents_residence[]" placeholder="Residence (optional)">
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
                    <label>Applicant's Full Name * <input type="text" name="applicant_full_name"></label>
                    <label>Birthdate * <input type="date" name="applicant_birthdate"></label>
                    <label>Parent/Guardian Name * <input type="text" name="parent_guardian_name"></label>
                    <label>Contact Number * <input type="text" name="contact_number"></label>
                    <label>Email * <input type="email" name="email"></label>
                    <label><input type="checkbox" name="baptized_here" value="1"> Baptized here</label>
                    <label>Preferred Batch <input type="text" name="preferred_batch"></label>
                    <label>Sponsor Name <input type="text" name="sponsor_name"></label>
                    <!-- Valid ID upload removed: identity verified in person -->
                </fieldset>
            </div>

            <div id="section-first_communion" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>First Communion Application</legend>
                    <label>Applicant's Full Name * <input type="text" name="applicant_full_name"></label>
                    <label>Birthdate * <input type="date" name="applicant_birthdate"></label>
                    <label>Parent/Guardian Name * <input type="text" name="parent_guardian_name"></label>
                    <label>Contact Number * <input type="text" name="contact_number"></label>
                    <label>Email * <input type="email" name="email"></label>
                    <label>Preferred Batch <input type="text" name="preferred_batch"></label>
                    <!-- Valid ID upload removed: identity verified in person -->
                </fieldset>
            </div>

            <div id="section-marriage" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Marriage Application</legend>
                    <h4>Groom</h4>
                    <label>Full Name * <input type="text" name="groom_full_name"></label>
                    <label>Birthdate * <input type="date" name="groom_birthdate"></label>
                    <label>Birthplace * <input type="text" name="groom_birthplace"></label>
                    <label>Contact Number * <input type="text" name="groom_contact_number"></label>
                    <label>Email * <input type="email" name="groom_email"></label>
                    <label>Marital Status <select name="groom_marital_status"><option value="single">Single</option><option value="widowed">Widowed</option><option value="annulled">Annulled</option></select></label>
                    <!-- Valid ID upload removed: identity verified in person -->

                    <h4>Bride</h4>
                    <label>Full Name * <input type="text" name="bride_full_name"></label>
                    <label>Birthdate * <input type="date" name="bride_birthdate"></label>
                    <label>Birthplace * <input type="text" name="bride_birthplace"></label>
                    <label>Contact Number * <input type="text" name="bride_contact_number"></label>
                    <label>Email * <input type="email" name="bride_email"></label>
                    <label>Marital Status <select name="bride_marital_status"><option value="single">Single</option><option value="widowed">Widowed</option><option value="annulled">Annulled</option></select></label>
                    <!-- Valid ID upload removed: identity verified in person -->
                    <label>Preferred Wedding Date <input type="date" name="preferred_wedding_date"></label>
                    <div class="repeat-list">
                        <p>Principal Sponsors</p>
                        <div id="sponsors-list">
                            <div class="repeat-item">
                                <input type="text" name="principal_sponsors_name[]" placeholder="Sponsor full name">
                                <input type="text" name="principal_sponsors_residence[]" placeholder="Residence (optional)">
                                <button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>
                            </div>
                        </div>
                        <button type="button" class="btn" onclick="addSponsor()">Add Sponsor</button>
                    </div>
                </fieldset>
            </div>

            <fieldset>
                <legend>Notes</legend>
                <label>Notes <textarea name="notes"></textarea></label>
            </fieldset>

            <button type="submit" class="btn">Encode Application</button>
        </form>

        <script>
        // Show/hide the sacrament-specific section based on the selected type
        function showSection(type) {
            document.querySelectorAll('.sacrament-section').forEach(function (el) { el.style.display = 'none'; });
            var target = document.getElementById('section-' + type);
            if (target) target.style.display = 'block';
        }

        // Add a new godparent input row
        function addGodparent() {
            var list = document.getElementById('godparents-list');
            var div = document.createElement('div');
            div.className = 'repeat-item';
            div.innerHTML = '<input type="text" name="godparents[]" placeholder="Godparent full name">\n                                <input type="text" name="godparents_residence[]" placeholder="Residence (optional)">\n                                <button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>';
            list.appendChild(div);
        }

        // Add a new sponsor input row
        function addSponsor() {
            var list = document.getElementById('sponsors-list');
            var div = document.createElement('div');
            div.className = 'repeat-item';
            div.innerHTML = '<input type="text" name="principal_sponsors_name[]" placeholder="Sponsor full name">\n                                <input type="text" name="principal_sponsors_residence[]" placeholder="Residence (optional)">\n                                <button type="button" class="btn-remove" onclick="removeItem(this)">Remove</button>';
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
                if (!document.querySelector('input[name="child_full_name"]').value) missing.push('Child name');
                if (!document.querySelector('input[name="child_birthdate"]').value) missing.push('Child birthdate');
                if (!document.querySelector('input[name="child_birthplace"]').value) missing.push('Child birthplace');
                if (!document.querySelector('input[name="parent_guardian_name"]').value) missing.push('Parent/guardian name');
            } else if (type === 'confirmation' || type === 'first_communion') {
                if (!document.querySelector('input[name="applicant_full_name"]').value) missing.push('Applicant name');
                if (!document.querySelector('input[name="applicant_birthdate"]').value) missing.push('Applicant birthdate');
            } else if (type === 'marriage') {
                if (!document.querySelector('input[name="groom_full_name"]').value) missing.push('Groom full name');
                if (!document.querySelector('input[name="bride_full_name"]').value) missing.push('Bride full name');
            }
            if (missing.length) {
                e.preventDefault();
                alert('Please complete required fields: ' + missing.join(', '));
            }
        });
        </script>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>