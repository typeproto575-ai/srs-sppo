<?php
/**
 * =====================================================================
 * SRS-SPPO CONFIRMATION CERTIFICATE REQUEST (public form)
 * =====================================================================
 * Purpose: Request a copy of a Confirmation certificate already on
 *          record. Inserts the shared row into certificate_requests
 *          (status 'pending') plus details into
 *          confirmation_certificate_requests.
 * Note:    No online ID upload — identity is verified in person at pickup.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email

// Subfolder-safe URL prefix (kept for links/redirects on this page).
$basePath = $basePath ?? base_path();

$pageTitle = 'Confirmation Certificate Request';
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

    $confirmandName      = trim($_POST['confirmand_full_name'] ?? '');
    $fatherName          = trim($_POST['father_full_name'] ?? '');
    $motherMaidenName    = trim($_POST['mother_maiden_name'] ?? '');
    $confirmationDate    = $_POST['date_of_confirmation'] ?: null;
    $baptismDate       = $_POST['date_of_baptism'] ?: null;
    $baptismPlace      = trim($_POST['place_of_baptism'] ?? '');
    $minister          = trim($_POST['officiating_minister'] ?? '');
    $sponsorName       = trim($_POST['sponsor_name'] ?? '');
    $bookNo            = trim($_POST['book_no'] ?? '');
    $pageNo            = trim($_POST['page_no'] ?? '');
    $lineNo            = trim($_POST['line_no'] ?? '');

    $purpose      = $_POST['purpose'] ?? '';
    $purposeOther = trim($_POST['purpose_other'] ?? '');
    $numCopies    = min(20, max(1, (int) ($_POST['num_copies'] ?? 1)));
    $notes        = trim($_POST['notes'] ?? '');

    if ($requesterName === '')         $errors[] = 'Requester name is required.';
    if ($requesterRelationship === '') $errors[] = 'Relationship to confirmand is required.';
    if ($requesterEmail === '')        $errors[] = 'Email address is required.';
    if (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($requesterContact === '')      $errors[] = 'Contact number is required.';
    if ($requesterContact !== '' && !is_valid_phone_number($requesterContact)) $errors[] = 'Please enter a valid contact number (at least 10 digits).';
    if ($confirmandName === '')        $errors[] = "Confirmand's full name is required.";
    if ($fatherName === '')            $errors[] = "Father's full name is required.";
        if ($motherMaidenName === '')      $errors[] = "Mother's maiden name is required.";
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
                 VALUES (?, 'confirmation', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([
                $trackingNumber, $requesterName, $requesterRelationship, $requesterRelOther,
                $requesterEmail, $requesterContact, $purpose, $purposeOther, $numCopies, $notes,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO confirmation_certificate_requests
                    (request_id, confirmand_full_name, father_full_name, mother_maiden_name,
                     date_of_confirmation, date_of_baptism, place_of_baptism,
                     officiating_minister, sponsor_name, book_no, page_no, line_no)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $requestId, $confirmandName, $fatherName, $motherMaidenName, $confirmationDate,
                $baptismDate, $baptismPlace,
                $minister, $sponsorName, $bookNo, $pageNo, $lineNo,
            ]);

            log_activity($pdo, null, 'certificate_request_submitted', 'certificate_requests', $requestId,
                "Confirmation certificate request submitted by {$requesterName}");

            notify($pdo, 'request_new', 'New certificate request',
                "Confirmation certificate request from {$requesterName} ({$trackingNumber})",
                '/admin/requests/queue.php?status=pending');

            $pdo->commit();

            if (mail_request_received($requesterEmail, $trackingNumber, 'Confirmation Certificate Request')) {
                log_activity($pdo, null, 'confirmation_email_sent', 'certificate_requests', $requestId, "Confirmation email sent to {$requesterEmail}");
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while submitting your request. Please try again.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="request-form">
    <h1>Confirmation Certificate Request</h1>

    <?php if ($trackingNumber): ?>
            <?php $successHeading = 'Your request has been submitted'; require __DIR__ . '/../../includes/submission-success.php'; ?>
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

                <label>Relationship to Confirmand *
                    <select name="requester_relationship" required>
                        <option value="">-- Select --</option>
                        <option value="self">Self</option>
                        <option value="parent">Parent</option>
                        <option value="guardian">Guardian</option>
                        <option value="sibling">Sibling</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>If Other, please specify
                    <input type="text" name="requester_relationship_other" value="<?= e($_POST['requester_relationship_other'] ?? '') ?>">
                </label>

                <label>Email Address *
                    <input type="email" name="requester_email" autocomplete="email" value="<?= e($_POST['requester_email'] ?? '') ?>" required>
                </label>

                <label>Contact Number *
                    <input type="text" name="requester_contact_number" autocomplete="tel" value="<?= e($_POST['requester_contact_number'] ?? '') ?>" required>
                </label>


            </fieldset>

            <fieldset>
                <legend>Confirmation Record Details</legend>

                <label>Confirmand's Full Name *
                    <input type="text" name="confirmand_full_name" value="<?= e($_POST['confirmand_full_name'] ?? '') ?>" required>
                </label>
                <label>Father's Full Name *
                    <input type="text" name="father_full_name" value="<?= e($_POST['father_full_name'] ?? '') ?>" required>
                </label>
                <label>Mother's Maiden Name *
                    <input type="text" name="mother_maiden_name" value="<?= e($_POST['mother_maiden_name'] ?? '') ?>" required>
                </label>
                <label>Date of Confirmation (if known)
                    <input type="date" name="date_of_confirmation" value="<?= e($_POST['date_of_confirmation'] ?? '') ?>">
                </label>
                <div class="inline-fields">
                    <label>Date of Baptism (if known) <input type="date" name="date_of_baptism" value="<?= e($_POST['date_of_baptism'] ?? '') ?>"></label>
                    <label>Place of Baptism <input type="text" name="place_of_baptism" value="<?= e($_POST['place_of_baptism'] ?? '') ?>"></label>
                </div>
                <label>Officiating Minister (if known)
                    <input type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? '') ?>">
                </label>
                <label>Sponsor (if known)
                    <input type="text" name="sponsor_name" value="<?= e($_POST['sponsor_name'] ?? '') ?>">
                </label>

                <div class="inline-fields">
                    <label>Book No. <input type="text" name="book_no" value="<?= e($_POST['book_no'] ?? '') ?>"></label>
                    <label>Page <input type="text" name="page_no" value="<?= e($_POST['page_no'] ?? '') ?>"></label>
                    <label>Line <input type="text" name="line_no" value="<?= e($_POST['line_no'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Request Details</legend>

                <label>Purpose *
                    <select name="purpose" required>
                        <option value="">-- Select --</option>
                        <option value="marriage">Marriage</option>
                        <option value="school">School Enrollment</option>
                        <option value="employment">Employment</option>
                        <option value="travel">Travel/Passport</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>If Other, please specify
                    <input type="text" name="purpose_other" value="<?= e($_POST['purpose_other'] ?? '') ?>">
                </label>

                <label>Number of Copies
                    <input type="number" name="num_copies" min="1" value="<?= e($_POST['num_copies'] ?? '1') ?>">
                </label>

                <label>Additional Notes / Observanda
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
