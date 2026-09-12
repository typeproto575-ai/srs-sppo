<?php
/**
 * =====================================================================
 * SRS-SPPO WALK-IN CERTIFICATE REQUEST ENCODING (admin)
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
        $extCols  = ['confirmand_full_name','father_full_name','mother_maiden_name','date_of_confirmation','date_of_baptism','place_of_baptism','officiating_minister','sponsor_name','book_no','page_no','line_no'];
        $confirmandName = trim($_POST['confirmand_full_name'] ?? '');
        if ($confirmandName === '') $errors[] = "Confirmand's full name is required.";
        $extVals = [$confirmandName, trim($_POST['father_full_name'] ?? ''), trim($_POST['mother_maiden_name'] ?? ''),
                    $_POST['date_of_confirmation'] ?: null, $_POST['date_of_baptism'] ?: null, trim($_POST['place_of_baptism'] ?? ''),
                    trim($_POST['officiating_minister'] ?? ''), trim($_POST['sponsor_name'] ?? ''),
                    trim($_POST['book_no'] ?? ''), trim($_POST['page_no'] ?? ''), trim($_POST['line_no'] ?? '')];
    } elseif ($sacramentType === 'first_communion') {
        $extTable = 'first_communion_certificate_requests';
        $extCols  = ['communicant_full_name','father_full_name','mother_maiden_name','date_of_first_communion','officiating_minister','book_no','page_no','line_no'];
        $communicantName = trim($_POST['communicant_full_name'] ?? '');
        if ($communicantName === '') $errors[] = "Communicant's full name is required.";
        $extVals = [$communicantName, trim($_POST['father_full_name'] ?? ''), trim($_POST['mother_maiden_name'] ?? ''),
                    $_POST['date_of_first_communion'] ?: null, trim($_POST['officiating_minister'] ?? ''),
                    trim($_POST['book_no'] ?? ''), trim($_POST['page_no'] ?? ''), trim($_POST['line_no'] ?? '')];
    } elseif ($sacramentType === 'marriage') {
        $extTable = 'marriage_certificate_requests';
        $extCols  = ['party_a_full_name','party_a_father_name','party_a_mother_name','party_a_baptized_in_church_of','party_a_date_of_baptism','party_a_book_no','party_a_page_no','party_a_line_no','party_b_full_name','party_b_father_name','party_b_mother_name','party_b_baptized_in_church_of','party_b_date_of_baptism','party_b_book_no','party_b_page_no','party_b_line_no','marriage_date','officiating_minister'];
        $husbandName = trim($_POST['party_a_full_name'] ?? '');
        $wifeName    = trim($_POST['party_b_full_name'] ?? '');
        if ($husbandName === '') $errors[] = "Party A's full name is required.";
        if ($wifeName === '')    $errors[] = "Party B's full name is required.";
        $extVals = [$husbandName, trim($_POST['party_a_father_name'] ?? ''), trim($_POST['party_a_mother_name'] ?? ''),
                    trim($_POST['party_a_baptized_in_church_of'] ?? ''), $_POST['party_a_date_of_baptism'] ?: null,
                    trim($_POST['party_a_book_no'] ?? ''), trim($_POST['party_a_page_no'] ?? ''), trim($_POST['party_a_line_no'] ?? ''),
                    $wifeName, trim($_POST['party_b_father_name'] ?? ''), trim($_POST['party_b_mother_name'] ?? ''),
                    trim($_POST['party_b_baptized_in_church_of'] ?? ''), $_POST['party_b_date_of_baptism'] ?: null,
                    trim($_POST['party_b_book_no'] ?? ''), trim($_POST['party_b_page_no'] ?? ''), trim($_POST['party_b_line_no'] ?? ''),
                    $_POST['marriage_date'] ?: null, trim($_POST['officiating_minister'] ?? '')];
    }

    if (empty($errors)) {
        try {
            $trackingNumber = retry_on_duplicate($pdo, function () use ($pdo, $sacramentType, $requesterName, $requesterRelationship, $requesterRelOther, $requesterEmail, $requesterContact, $purpose, $purposeOther, $notes, $extCols, $extVals): string {
            $trackingNumber = generate_tracking_number($pdo, 'certificate_requests');

            $stmt = $pdo->prepare(
                "INSERT INTO certificate_requests
                    (tracking_number, sacrament_type, requester_name, requester_relationship,
                     requester_relationship_other, requester_email, requester_contact_number,
                     purpose, purpose_other, notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([
                $trackingNumber, $sacramentType, $requesterName, $requesterRelationship, $requesterRelOther,
                $requesterEmail, $requesterContact, $purpose, $purposeOther, $notes,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $placeholders = implode(', ', array_fill(0, count($extCols) + 1, '?'));
            $colsSql = implode(', ', array_merge(['request_id'], $extCols));
            $stmt = $pdo->prepare("INSERT INTO {$extTable} ({$colsSql}) VALUES ({$placeholders})");
            $stmt->execute(array_merge([$requestId], $extVals));

            log_activity($pdo, current_user()['id'], 'walk_in_request_encoded', 'certificate_requests', $requestId,
                "Walk-in {$sacramentType} certificate request encoded for {$requesterName}");

            notify($pdo, 'request_new', 'New walk-in certificate request',
                "Walk-in {$sacramentType} certificate request for {$requesterName} ({$trackingNumber})",
                '/admin/requests/queue.php?status=pending');

            return $trackingNumber;
        });
        } catch (Exception $e) {
            error_log('SRS-SPPO walk-in certificate request insert failed: ' . $e->getMessage());
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
                <label>Requester's Full Name * <input type="text" name="requester_name" value="<?= e($_POST['requester_name'] ?? '') ?>" required></label>
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
                <label>If Other <input type="text" name="requester_relationship_other" value="<?= e($_POST['requester_relationship_other'] ?? '') ?>"></label>
                <label>Email Address * <input type="email" name="requester_email" value="<?= e($_POST['requester_email'] ?? '') ?>" required></label>
                <label>Contact Number * <input type="text" name="requester_contact_number" value="<?= e($_POST['requester_contact_number'] ?? '') ?>" required></label>
            </fieldset>

            <div id="section-baptismal" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Baptismal Record Details</legend>
                    <label>Child's Full Name * <input type="text" name="child_full_name" value="<?= e($_POST['child_full_name'] ?? '') ?>"></label>
                    <label>Father's Full Name * <input type="text" name="father_full_name" value="<?= e($_POST['father_full_name'] ?? '') ?>"></label>
                    <label>Mother's Full Name * <input type="text" name="mother_full_name" value="<?= e($_POST['mother_full_name'] ?? '') ?>"></label>
                    <label>Birthplace <input type="text" name="birthplace" value="<?= e($_POST['birthplace'] ?? '') ?>"></label>
                    <label>Birthdate <input type="date" name="birthdate" value="<?= e($_POST['birthdate'] ?? '') ?>"></label>
                    <label>Date of Baptism <input type="date" name="date_of_baptism" value="<?= e($_POST['date_of_baptism'] ?? '') ?>"></label>
                    <label>Officiating Minister <input type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? '') ?>"></label>
                    <label>Sponsors <textarea name="sponsors_text"><?= e($_POST['sponsors_text'] ?? '') ?></textarea></label>
                    <div class="inline-fields">
                        <label>Book No. <input type="text" name="book_no" value="<?= e($_POST['book_no'] ?? '') ?>"></label>
                        <label>Page <input type="text" name="page_no" value="<?= e($_POST['page_no'] ?? '') ?>"></label>
                        <label>Line <input type="text" name="line_no" value="<?= e($_POST['line_no'] ?? '') ?>"></label>
                    </div>
                </fieldset>
            </div>

            <div id="section-confirmation" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Confirmation Record Details</legend>
                    <label>Confirmand's Full Name * <input type="text" name="confirmand_full_name" value="<?= e($_POST['confirmand_full_name'] ?? '') ?>"></label>
                    <label>Father's Full Name <input type="text" name="father_full_name" value="<?= e($_POST['father_full_name'] ?? '') ?>"></label>
                    <label>Mother's Maiden Name <input type="text" name="mother_maiden_name" value="<?= e($_POST['mother_maiden_name'] ?? '') ?>"></label>
                    <label>Date of Confirmation <input type="date" name="date_of_confirmation" value="<?= e($_POST['date_of_confirmation'] ?? '') ?>"></label>
                    <div class="inline-fields">
                        <label>Date of Baptism <input type="date" name="date_of_baptism" value="<?= e($_POST['date_of_baptism'] ?? '') ?>"></label>
                        <label>Place of Baptism <input type="text" name="place_of_baptism" value="<?= e($_POST['place_of_baptism'] ?? '') ?>"></label>
                    </div>
                    <label>Officiating Minister <input type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? '') ?>"></label>
                    <label>Sponsor <input type="text" name="sponsor_name" value="<?= e($_POST['sponsor_name'] ?? '') ?>"></label>
                    <div class="inline-fields">
                        <label>Book No. <input type="text" name="book_no" value="<?= e($_POST['book_no'] ?? '') ?>"></label>
                        <label>Page <input type="text" name="page_no" value="<?= e($_POST['page_no'] ?? '') ?>"></label>
                        <label>Line <input type="text" name="line_no" value="<?= e($_POST['line_no'] ?? '') ?>"></label>
                    </div>
                </fieldset>
            </div>

            <div id="section-first_communion" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>First Communion Record Details</legend>
                    <label>Communicant's Full Name * <input type="text" name="communicant_full_name" value="<?= e($_POST['communicant_full_name'] ?? '') ?>"></label>
                    <label>Father's Full Name <input type="text" name="father_full_name" value="<?= e($_POST['father_full_name'] ?? '') ?>"></label>
                    <label>Mother's Maiden Name <input type="text" name="mother_maiden_name" value="<?= e($_POST['mother_maiden_name'] ?? '') ?>"></label>
                    <label>Date of First Communion <input type="date" name="date_of_first_communion" value="<?= e($_POST['date_of_first_communion'] ?? '') ?>"></label>
                    <label>Officiating Minister <input type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? '') ?>"></label>
                    <div class="inline-fields">
                        <label>Book No. <input type="text" name="book_no" value="<?= e($_POST['book_no'] ?? '') ?>"></label>
                        <label>Page <input type="text" name="page_no" value="<?= e($_POST['page_no'] ?? '') ?>"></label>
                        <label>Line <input type="text" name="line_no" value="<?= e($_POST['line_no'] ?? '') ?>"></label>
                    </div>
                </fieldset>
            </div>

            <div id="section-marriage" class="sacrament-section" style="display:none;">
                <fieldset>
                    <legend>Party A (e.g., Groom)</legend>
                    <label>Full Name * <input type="text" name="party_a_full_name" value="<?= e($_POST['party_a_full_name'] ?? '') ?>"></label>
                    <div class="inline-fields">
                        <label>Father's Name <input type="text" name="party_a_father_name" value="<?= e($_POST['party_a_father_name'] ?? '') ?>"></label>
                        <label>Mother's Name <input type="text" name="party_a_mother_name" value="<?= e($_POST['party_a_mother_name'] ?? '') ?>"></label>
                    </div>
                    <div class="inline-fields">
                        <label>Baptized in Church of <input type="text" name="party_a_baptized_in_church_of" value="<?= e($_POST['party_a_baptized_in_church_of'] ?? '') ?>"></label>
                        <label>Date of Baptism <input type="date" name="party_a_date_of_baptism" value="<?= e($_POST['party_a_date_of_baptism'] ?? '') ?>"></label>
                    </div>
                    <div class="inline-fields">
                        <label>Book No. <input type="text" name="party_a_book_no" value="<?= e($_POST['party_a_book_no'] ?? '') ?>"></label>
                        <label>Page <input type="text" name="party_a_page_no" value="<?= e($_POST['party_a_page_no'] ?? '') ?>"></label>
                        <label>Line <input type="text" name="party_a_line_no" value="<?= e($_POST['party_a_line_no'] ?? '') ?>"></label>
                    </div>
                </fieldset>
                <fieldset>
                    <legend>Party B (e.g., Bride)</legend>
                    <label>Full Name * <input type="text" name="party_b_full_name" value="<?= e($_POST['party_b_full_name'] ?? '') ?>"></label>
                    <div class="inline-fields">
                        <label>Father's Name <input type="text" name="party_b_father_name" value="<?= e($_POST['party_b_father_name'] ?? '') ?>"></label>
                        <label>Mother's Name <input type="text" name="party_b_mother_name" value="<?= e($_POST['party_b_mother_name'] ?? '') ?>"></label>
                    </div>
                    <div class="inline-fields">
                        <label>Baptized in Church of <input type="text" name="party_b_baptized_in_church_of" value="<?= e($_POST['party_b_baptized_in_church_of'] ?? '') ?>"></label>
                        <label>Date of Baptism <input type="date" name="party_b_date_of_baptism" value="<?= e($_POST['party_b_date_of_baptism'] ?? '') ?>"></label>
                    </div>
                    <div class="inline-fields">
                        <label>Book No. <input type="text" name="party_b_book_no" value="<?= e($_POST['party_b_book_no'] ?? '') ?>"></label>
                        <label>Page <input type="text" name="party_b_page_no" value="<?= e($_POST['party_b_page_no'] ?? '') ?>"></label>
                        <label>Line <input type="text" name="party_b_line_no" value="<?= e($_POST['party_b_line_no'] ?? '') ?>"></label>
                    </div>
                </fieldset>
                <fieldset>
                    <legend>Contracted Marriage Detail</legend>
                    <label>Date of Marriage <input type="date" name="marriage_date" value="<?= e($_POST['marriage_date'] ?? '') ?>"></label>
                    <label>Officiating Minister <input type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? '') ?>"></label>
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
                <label>If Other, please specify <input type="text" name="purpose_other" value="<?= e($_POST['purpose_other'] ?? '') ?>"></label>
                <label>Additional Notes / Observanda
                    <textarea name="notes"><?= e($_POST['notes'] ?? '') ?></textarea>
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