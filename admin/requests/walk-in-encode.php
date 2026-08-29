<?php
/**
 * =====================================================================
 * SPPO-IS WALK-IN CERTIFICATE REQUEST ENCODING (admin)
 * =====================================================================
 * Purpose: Lets the secretary encode a certificate request for a
 *          parishioner who walks into the office (phone request,
 *          no internet, etc.) - same data and flow as the public form,
 *          entered by staff on their behalf.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Encode Walk-in Request';
$errors    = [];
$trackingNumber = null;

// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form) before any validation or DB writes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid - please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    require_role('secretary');

    $sacramentType = $_POST['sacrament_type'] ?? '';
    $validTypes = ['baptismal', 'confirmation', 'first_communion', 'marriage'];

    $requesterName          = trim($_POST['requester_name'] ?? '');
    $requesterRelationship  = $_POST['requester_relationship'] ?? '';
    $requesterRelOther      = trim($_POST['requester_relationship_other'] ?? '');
    $requesterEmail         = trim($_POST['requester_email'] ?? '');
    $requesterContact       = trim($_POST['requester_contact_number'] ?? '');
    $purpose      = $_POST['purpose'] ?? '';
    $purposeOther = trim($_POST['purpose_other'] ?? '');
    $numCopies    = max(1, (int) ($_POST['num_copies'] ?? 1));
    $notes        = trim($_POST['notes'] ?? '');

    if (!in_array($sacramentType, $validTypes, true)) $errors[] = 'Select a sacrament type.';
    if ($requesterName === '')         $errors[] = 'Requester name is required.';
    if ($requesterRelationship === '') $errors[] = 'Relationship is required.';
    if ($requesterEmail === '')        $errors[] = 'Email address is required.';
    if ($requesterContact === '')      $errors[] = 'Contact number is required.';
    if ($purpose === '')               $errors[] = 'Purpose is required.';

    // Sacrament-specific fields: each type stores its details in a
    // companion table keyed by request_id (same as the public forms).
    $extTable = null;
    $extCols  = [];
    $extVals  = [];

    if ($sacramentType === 'baptismal') {
        $extTable = 'baptismal_certificate_requests';
        $extCols  = ['child_full_name','father_full_name','mother_full_name','birthplace','birthdate','date_of_baptism','officiating_minister','sponsors_text','book_no','page_no','line_no'];
        $childName = trim($_POST['child_full_name'] ?? '');
        $fatherName = trim($_POST['father_full_name'] ?? '');
        $motherName = trim($_POST['mother_full_name'] ?? '');
        if ($childName === '') $errors[] = "Child's full name is required.";
        if ($fatherName === '') $errors[] = "Father's full name is required.";
        if ($motherName === '') $errors[] = "Mother's full name is required.";
        $extVals = [$childName, $fatherName, $motherName, trim($_POST['birthplace'] ?? ''), $_POST['birthdate'] ?? '',
                    $_POST['date_of_baptism'] ?: null, trim($_POST['officiating_minister'] ?? ''), trim($_POST['sponsors_text'] ?? ''),
                    trim($_POST['book_no'] ?? ''), trim($_POST['page_no'] ?? ''), trim($_POST['line_no'] ?? '')];
    } elseif ($sacramentType === 'confirmation') {
        $extTable = 'confirmation_certificate_requests';
        $extCols  = ['confirmand_full_name','father_full_name','mother_full_name','date_of_confirmation','officiating_minister','sponsor_name','book_no','page_no','line_no'];
        $confirmandName = trim($_POST['confirmand_full_name'] ?? '');
        if ($confirmandName === '') $errors[] = "Confirmand's full name is required.";
        $extVals = [$confirmandName, trim($_POST['father_full_name'] ?? ''), trim($_POST['mother_full_name'] ?? ''),
                    $_POST['date_of_confirmation'] ?: null, trim($_POST['officiating_minister'] ?? ''), trim($_POST['sponsor_name'] ?? ''),
                    trim($_POST['book_no'] ?? ''), trim($_POST['page_no'] ?? ''), trim($_POST['line_no'] ?? '')];
    } elseif ($sacramentType === 'first_communion') {
        $extTable = 'first_communion_certificate_requests';
        $extCols  = ['communicant_full_name','father_full_name','mother_full_name','date_of_first_communion','officiating_minister','book_no','page_no','line_no'];
        $communicantName = trim($_POST['communicant_full_name'] ?? '');
        if ($communicantName === '') $errors[] = "Communicant's full name is required.";
        $extVals = [$communicantName, trim($_POST['father_full_name'] ?? ''), trim($_POST['mother_full_name'] ?? ''),
                    $_POST['date_of_first_communion'] ?: null, trim($_POST['officiating_minister'] ?? ''),
                    trim($_POST['book_no'] ?? ''), trim($_POST['page_no'] ?? ''), trim($_POST['line_no'] ?? '')];
    } elseif ($sacramentType === 'marriage') {
        $extTable = 'marriage_certificate_requests';
        $extCols  = ['husband_full_name','wife_maiden_name','date_of_marriage','officiating_minister','book_no','page_no','line_no'];
        $husbandName = trim($_POST['husband_full_name'] ?? '');
        $wifeName    = trim($_POST['wife_maiden_name'] ?? '');
        if ($husbandName === '') $errors[] = "Husband's full name is required.";
        if ($wifeName === '')    $errors[] = "Wife's maiden name is required.";
        $extVals = [$husbandName, $wifeName, $_POST['date_of_marriage'] ?: null, trim($_POST['officiating_minister'] ?? ''),
                    trim($_POST['book_no'] ?? ''), trim($_POST['page_no'] ?? ''), trim($_POST['line_no'] ?? '')];
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $trackingNumber = generate_tracking_number($pdo, 'certificate_requests');

            $stmt = $pdo->prepare(
                "INSERT INTO certificate_requests
                    (tracking_number, sacrament_type, requester_name, requester_relationship,
                     requester_relationship_other, requester_email, requester_contact_number,
                     purpose, purpose_other, num_copies, notes, valid_id_path, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'pending')"
            );
            $stmt->execute([
                $trackingNumber, $sacramentType, $requesterName, $requesterRelationship, $requesterRelOther,
                $requesterEmail, $requesterContact, $purpose, $purposeOther, $numCopies, $notes,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $placeholders = implode(', ', array_fill(0, count($extCols) + 1, '?'));
            $colsSql = implode(', ', array_merge(['request_id'], $extCols));
            $stmt = $pdo->prepare("INSERT INTO {$extTable} ({$colsSql}) VALUES ({$placeholders})");
            $stmt->execute(array_merge([$requestId], $extVals));

            log_activity($pdo, current_user()['id'], 'walk_in_request_encoded', 'certificate_requests', $requestId,
                "Walk-in {$sacramentType} certificate request encoded for {$requesterName}");

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving this request. Please try again.';
        }
    }
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Encode Walk-in Request</h1>
        <p class="field-hint">Use this when a parishioner requests a certificate in person instead of through the public form.</p>

        <?php if ($trackingNumber): ?>
            <div class="alert alert-success">
                <p>Request encoded. Tracking number:</p>
                <p class="tracking-number"><strong><?= e($trackingNumber) ?></strong></p>
                <p>Give this number to the requester, or write it on their receipt.</p>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="post" action="">
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

            <fieldset>
                <legend>Requester Information</legend>
                <label>Requester's Full Name * <input type="text" name="requester_name" required></label>
                <label>Relationship *
                    <select name="requester_relationship" required>
                        <option value="">-- Select --</option>
                        <option value="self">Self</option>
                        <option value="parent">Parent</option>
                        <option value="guardian">Guardian</option>
                        <option value="spouse">Spouse</option>
                        <option value="sibling">Sibling</option>
                        <option value="child">Child</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>If Other <input type="text" name="requester_relationship_other"></label>
                <label>Email Address * <input type="email" name="requester_email" required></label>
                <label>Contact Number * <input type="text" name="requester_contact_number" required></label>
            </fieldset>

            <div id="section-baptismal" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Baptismal Record Details</legend>
                    <label>Child's Full Name * <input type="text" name="child_full_name"></label>
                    <label>Father's Full Name * <input type="text" name="father_full_name"></label>
                    <label>Mother's Full Name * <input type="text" name="mother_full_name"></label>
                    <label>Birthplace <input type="text" name="birthplace"></label>
                    <label>Birthdate <input type="date" name="birthdate"></label>
                    <label>Date of Baptism <input type="date" name="date_of_baptism"></label>
                    <label>Officiating Minister <input type="text" name="officiating_minister"></label>
                    <label>Sponsors <textarea name="sponsors_text"></textarea></label>
                    <div class="inline-fields">
                        <label>Book No. <input type="text" name="book_no"></label>
                        <label>Page <input type="text" name="page_no"></label>
                        <label>Line <input type="text" name="line_no"></label>
                    </div>
                </fieldset>
            </div>

            <div id="section-confirmation" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Confirmation Record Details</legend>
                    <label>Confirmand's Full Name * <input type="text" name="confirmand_full_name"></label>
                    <label>Father's Full Name <input type="text" name="father_full_name"></label>
                    <label>Mother's Full Name <input type="text" name="mother_full_name"></label>
                    <label>Date of Confirmation <input type="date" name="date_of_confirmation"></label>
                    <label>Officiating Minister <input type="text" name="officiating_minister"></label>
                    <label>Sponsor <input type="text" name="sponsor_name"></label>
                    <div class="inline-fields">
                        <label>Book No. <input type="text" name="book_no"></label>
                        <label>Page <input type="text" name="page_no"></label>
                        <label>Line <input type="text" name="line_no"></label>
                    </div>
                </fieldset>
            </div>

            <div id="section-first_communion" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>First Communion Record Details</legend>
                    <label>Communicant's Full Name * <input type="text" name="communicant_full_name"></label>
                    <label>Father's Full Name <input type="text" name="father_full_name"></label>
                    <label>Mother's Full Name <input type="text" name="mother_full_name"></label>
                    <label>Date of First Communion <input type="date" name="date_of_first_communion"></label>
                    <label>Officiating Minister <input type="text" name="officiating_minister"></label>
                    <div class="inline-fields">
                        <label>Book No. <input type="text" name="book_no"></label>
                        <label>Page <input type="text" name="page_no"></label>
                        <label>Line <input type="text" name="line_no"></label>
                    </div>
                </fieldset>
            </div>

            <div id="section-marriage" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Marriage Record Details</legend>
                    <label>Husband's Full Name * <input type="text" name="husband_full_name"></label>
                    <label>Wife's Maiden Name * <input type="text" name="wife_maiden_name"></label>
                    <label>Date of Marriage <input type="date" name="date_of_marriage"></label>
                    <label>Officiating Minister <input type="text" name="officiating_minister"></label>
                    <div class="inline-fields">
                        <label>Book No. <input type="text" name="book_no"></label>
                        <label>Page <input type="text" name="page_no"></label>
                        <label>Line <input type="text" name="line_no"></label>
                    </div>
                </fieldset>
            </div>

            <fieldset>
                <legend>Request Details</legend>
                <label>Purpose *
                    <select name="purpose" required>
                        <option value="">-- Select --</option>
                        <option value="marriage">Marriage</option>
                        <option value="school">School Enrollment</option>
                        <option value="employment">Employment</option>
                        <option value="travel">Travel/Passport</option>
                        <option value="civil_registration">Civil Registration</option>
                        <option value="immigration">Immigration</option>
                        <option value="legal">Legal Matter</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>If Other, please specify <input type="text" name="purpose_other"></label>
                <label>Number of Copies
                    <input type="number" name="num_copies" min="1" value="1">
                </label>
                <label>Additional Notes / Observanda
                    <textarea name="notes"></textarea>
                </label>
            </fieldset>

            <button type="submit" class="btn">Encode Request</button>
        </form>
    <script>
        // Show only the selected sacrament's detail section (all hidden by default).
        function showSection(type) {
            document.querySelectorAll('.sacrament-section').forEach(function (section) {
                section.style.display = (section.id === 'section-' + type) ? '' : 'none';
            });
        }
    </script>
<?php require_once __DIR__ . '/../../includes/admin-footer.php';