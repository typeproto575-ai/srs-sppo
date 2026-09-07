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

    $husbandName    = trim($_POST['husband_full_name'] ?? '');
    $wifeMaidenName = trim($_POST['wife_maiden_name'] ?? '');
    $marriageDate   = $_POST['date_of_marriage'] ?: null;
    $minister       = trim($_POST['officiating_minister'] ?? '');
    $bookNo         = trim($_POST['book_no'] ?? '');
    $pageNo         = trim($_POST['page_no'] ?? '');
    $lineNo         = trim($_POST['line_no'] ?? '');

    $purpose      = $_POST['purpose'] ?? '';
    $purposeOther = trim($_POST['purpose_other'] ?? '');
    $numCopies    = max(1, (int) ($_POST['num_copies'] ?? 1));
    $notes        = trim($_POST['notes'] ?? '');

    if ($requesterName === '')         $errors[] = 'Requester name is required.';
    if ($requesterRelationship === '') $errors[] = 'Relationship is required.';
    if ($requesterEmail === '')        $errors[] = 'Email address is required.';
    if (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($requesterContact === '')      $errors[] = 'Contact number is required.';
    if ($husbandName === '')           $errors[] = "Husband's full name is required.";
    if ($wifeMaidenName === '')        $errors[] = "Wife's maiden name is required.";
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
                    (request_id, husband_full_name, wife_maiden_name, date_of_marriage,
                     officiating_minister, book_no, page_no, line_no)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $requestId, $husbandName, $wifeMaidenName, $marriageDate,
                $minister, $bookNo, $pageNo, $lineNo,
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
                <legend>Marriage Record Details</legend>

                <label>Husband's Full Name *
                    <input type="text" name="husband_full_name" value="<?= e($_POST['husband_full_name'] ?? '') ?>" required>
                </label>
                <label>Wife's Maiden Name *
                    <input type="text" name="wife_maiden_name" value="<?= e($_POST['wife_maiden_name'] ?? '') ?>" required>
                </label>
                <label>Date of Marriage (if known)
                    <input type="date" name="date_of_marriage" value="<?= e($_POST['date_of_marriage'] ?? '') ?>">
                </label>
                <label>Officiating Minister (if known)
                    <input type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? '') ?>">
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
