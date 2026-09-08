<?php
/**
 * =====================================================================
 * SPPO-IS MARRIAGE CERTIFICATE REQUEST (public form)
 * =====================================================================
 * Purpose: Request a copy of a Marriage certificate already on record.
 *          Inserts the shared row into certificate_requests (status
 *          'pending') plus details into marriage_certificate_requests.
 * Note:    No online ID upload — identity is verified in person at pickup.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF

// Subfolder-safe URL prefix (kept for links/redirects on this page).
$basePath = $basePath ?? base_path();

$pageTitle = 'Marriage Certificate Request';
$errors    = [];
$trackingNumber = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF: reject submissions without a matching session token.
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    // --- Collect + validate required fields -----------------------------
    $requesterName          = trim($_POST['requester_name'] ?? '');
    $requesterRelationship  = $_POST['requester_relationship'] ?? '';
    $requesterRelOther      = trim($_POST['requester_relationship_other'] ?? '');
    $requesterEmail         = trim($_POST['requester_email'] ?? '');
    $requesterContact       = trim($_POST['requester_contact_number'] ?? '');

    $husbandName    = trim($_POST['party_a_full_name'] ?? '');
    $husbandFather  = trim($_POST['party_a_father_name'] ?? '');
    $husbandMother  = trim($_POST['party_a_mother_name'] ?? '');
    $husbandBaptizedIn = trim($_POST['party_a_baptized_in_church_of'] ?? '');
    $husbandBaptismDate = $_POST['party_a_date_of_baptism'] ?: null;
    $husbandBookNo  = trim($_POST['party_a_book_no'] ?? '');
    $husbandPageNo  = trim($_POST['party_a_page_no'] ?? '');
    $husbandLineNo  = trim($_POST['party_a_line_no'] ?? '');

    $wifeMaidenName = trim($_POST['party_b_full_name'] ?? '');
    $wifeFather     = trim($_POST['party_b_father_name'] ?? '');
    $wifeMother     = trim($_POST['party_b_mother_name'] ?? '');
    $wifeBaptizedIn = trim($_POST['party_b_baptized_in_church_of'] ?? '');
    $wifeBaptismDate = $_POST['party_b_date_of_baptism'] ?: null;
    $wifeBookNo     = trim($_POST['party_b_book_no'] ?? '');
    $wifePageNo     = trim($_POST['party_b_page_no'] ?? '');
    $wifeLineNo     = trim($_POST['party_b_line_no'] ?? '');

    $marriageDate   = $_POST['marriage_date'] ?: null;
    $minister       = trim($_POST['officiating_minister'] ?? '');

    $purpose      = $_POST['purpose'] ?? '';
    $purposeOther = trim($_POST['purpose_other'] ?? '');
    $numCopies    = min(20, max(1, (int) ($_POST['num_copies'] ?? 1)));
    $notes        = trim($_POST['notes'] ?? '');

    if ($requesterName === '')         $errors[] = 'Requester name is required.';
    if ($requesterRelationship === '') $errors[] = 'Relationship is required.';
    if ($requesterEmail === '')        $errors[] = 'Email address is required.';
    if (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($requesterContact === '')      $errors[] = 'Contact number is required.';
    if ($requesterContact !== '' && !is_valid_phone_number($requesterContact)) $errors[] = 'Please enter a valid contact number (at least 10 digits).';
    if ($husbandName === '')           $errors[] = "Party A's full name is required.";
    if ($wifeMaidenName === '')        $errors[] = "Party B's full name is required.";
    if ($purpose === '')               $errors[] = 'Purpose is required.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $trackingNumber = generate_tracking_number($pdo, 'certificate_requests');

            $stmt = $pdo->prepare(
                "INSERT INTO certificate_requests
                    (tracking_number, sacrament_type, requester_name, requester_relationship,
                     requester_relationship_other, requester_email, requester_contact_number,
                     purpose, purpose_other, num_copies, notes, status)
                 VALUES (?, 'marriage', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([
                $trackingNumber, $requesterName, $requesterRelationship, $requesterRelOther,
                $requesterEmail, $requesterContact, $purpose, $purposeOther, $numCopies, $notes,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO marriage_certificate_requests
                    (request_id,
                     party_a_full_name, party_a_father_name, party_a_mother_name,
                     party_a_baptized_in_church_of, party_a_date_of_baptism,
                     party_a_book_no, party_a_page_no, party_a_line_no,
                     party_b_full_name, party_b_father_name, party_b_mother_name,
                     party_b_baptized_in_church_of, party_b_date_of_baptism,
                     party_b_book_no, party_b_page_no, party_b_line_no,
                     marriage_date, officiating_minister)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $requestId,
                $husbandName, $husbandFather, $husbandMother, $husbandBaptizedIn, $husbandBaptismDate,
                $husbandBookNo, $husbandPageNo, $husbandLineNo,
                $wifeMaidenName, $wifeFather, $wifeMother, $wifeBaptizedIn, $wifeBaptismDate,
                $wifeBookNo, $wifePageNo, $wifeLineNo,
                $marriageDate, $minister,
            ]);

            log_activity($pdo, null, 'certificate_request_submitted', 'certificate_requests', $requestId,
                "Marriage certificate request submitted by {$requesterName}");

            $pdo->commit();

            // TODO: send confirmation email to $requesterEmail with $trackingNumber

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while submitting your request. Please try again.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="request-form">
    <h1>Marriage Certificate Request</h1>

    <?php if ($trackingNumber): ?>
        <div class="alert alert-success">
            <p>Your request has been submitted. Your tracking number is:</p>
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

        <form method="post" action="">
            <fieldset>
                <legend>Requester Information</legend>

                <label>Your Full Name *
                    <input type="text" name="requester_name" value="<?= e($_POST['requester_name'] ?? '') ?>" required>
                </label>

                <label>Relationship *
                    <select name="requester_relationship" required>
                        <option value="">-- Select --</option>
                        <option value="self">Self (one of the spouses)</option>
                        <option value="spouse">Spouse</option>
                        <option value="child">Child</option>
                        <option value="parent">Parent</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>If Other, please specify
                    <input type="text" name="requester_relationship_other" value="<?= e($_POST['requester_relationship_other'] ?? '') ?>">
                </label>

                <label>Email Address *
                    <input type="email" name="requester_email" value="<?= e($_POST['requester_email'] ?? '') ?>" required>
                </label>

                <label>Contact Number *
                    <input type="text" name="requester_contact_number" value="<?= e($_POST['requester_contact_number'] ?? '') ?>" required>
                </label>


            </fieldset>

            <fieldset>
                <legend>Party A (e.g., Groom)</legend>

                <label>Full Name *
                    <input type="text" name="party_a_full_name" value="<?= e($_POST['party_a_full_name'] ?? '') ?>" required>
                </label>
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

                <label>Full Name *
                    <input type="text" name="party_b_full_name" value="<?= e($_POST['party_b_full_name'] ?? '') ?>" required>
                </label>
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
                <label>Date of Marriage (if known)
                    <input type="date" name="marriage_date" value="<?= e($_POST['marriage_date'] ?? '') ?>">
                </label>
                <label>Officiating Minister (if known)
                    <input type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? '') ?>">
                </label>
            </fieldset>

            <fieldset>
                <legend>Request Details</legend>

                <label>Purpose *
                    <select name="purpose" required>
                        <option value="">-- Select --</option>
                        <option value="school">School Enrollment</option>
                        <option value="employment">Employment</option>
                        <option value="travel">Travel/Passport</option>
                        <option value="civil_registration">Civil Registration</option>
                        <option value="immigration">Immigration</option>
                        <option value="legal">Legal Matter</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>If Other, please specify
                    <input type="text" name="purpose_other" value="<?= e($_POST['purpose_other'] ?? '') ?>">
                </label>

                <label>Number of Copies
                    <input type="number" name="num_copies" min="1" value="<?= e($_POST['num_copies'] ?? '1') ?>">
                </label>

                <label>Additional Notes
                    <textarea name="notes"><?= e($_POST['notes'] ?? '') ?></textarea>
                </label>
            </fieldset>

            <!-- CSRF token pairs with the csrf_verify() check above -->
            <?= csrf_field() ?>
            <button type="submit" class="btn">Submit Request</button>
        </form>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
