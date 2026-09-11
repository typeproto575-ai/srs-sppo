<?php
/**
 * ============================================================================
 * SRS-SPPO — SCHEDULE AND RECORD SYSTEM OF ST. PATRICK'S PARISH OFFICE
 * ============================================================================
 *
 * FILE: public/certificate-requests/baptismal.php
 *
 * PURPOSE:
 *   Public-facing form where a parishioner requests a copy of a Baptismal
 *   certificate for someone already baptized at this parish.
 *
 * HOW IT WORKS:
 *   1. Visitor fills in requester info + child/baptism details.
 *   2. On POST, every required field is validated (empty checks, email format,
 *      phone format, date-not-in-future).
 *   3. A unique tracking number (e.g. SPPO-2026-000123) is generated.
 *   4. Two rows are inserted inside a DB transaction:
 *        a. certificate_requests          — shared table (who, why, copies).
 *        b. baptismal_certificate_requests — sacrament-specific details.
 *   5. An audit log entry is written (as "null" user since the submitter is
 *      not logged in — the parish staff user who processes it will be logged
 *      when the request is approved/processed).
 *   6. Success page shows the tracking number. A confirmation email is sent
 *      via includes/mail.php (disabled by default in config/mail.php — a safe
 *      no-op until EMAIL_ENABLED is turned on and a relay configured).
 *
 * SECURITY NOTES:
 *   - CSRF token required on POST (csrf_verify() + csrf_field() in the form).
 *   - All output is escaped with e() (htmlspecialchars) to prevent XSS.
 *   - The form uses prepared statements throughout — no raw SQL injection risk.
 *   - No online ID upload — identity is verified in person at pickup.
 *
 * DATABASE TABLES TOUCHED:
 *   - certificate_requests (shared request metadata)
 *   - baptismal_certificate_requests (baptism-specific details)
 *   - audit_logs (activity trail)
 *
 * RELATED FILES:
 *   - public/certificate-request.php       (router/selector page)
 *   - public/track-request.php             (status lookup by tracking number)
 *   - admin/requests/queue.php             (secretary processes the request)
 *   - admin/certificates/generate.php      (generates the actual certificate)
 *   - includes/functions.php               (generate_tracking_number, log_activity, e, etc.)
 *
 * @package     SRS-SPPO
 * @subpackage  Public — Certificate Requests
 * @filesource  public/certificate-requests/baptismal.php
 */

/**
 * Load dependencies.
 *
 * config/database.php    — creates the $pdo PDO connection (MySQL/MariaDB).
 * includes/functions.php — helper functions: e(), generate_tracking_number(),
 *                          log_activity(), base_path(), csrf_verify(), csrf_field(),
 *                          is_valid_phone_number(), is_future_date().
 * includes/auth.php      — starts a session (needed for CSRF token storage).
 *                          This page does NOT require login; it just needs a
 *                          session for the CSRF mechanism to work.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email

/**
 * $basePath — subfolder-safe URL prefix (e.g. '/srs-sppo').
 * Used in the success message link to track-request.php.
 * Defaults to base_path() from functions.php if not already set.
 */
$basePath = $basePath ?? base_path();

/**
 * Page metadata and state variables.
 */
$pageTitle       = 'Baptismal Certificate Request';
$errors          = [];   // validation error messages (filled on POST)
$trackingNumber  = null; // set on successful submission, shown on success page

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF: reject submissions without a matching session token.
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    // --- Collect + validate required fields -----------------------------
    $requesterName        = trim($_POST['requester_name'] ?? '');
    $requesterRelationship = $_POST['requester_relationship'] ?? '';
    $requesterRelOther     = trim($_POST['requester_relationship_other'] ?? '');
    $requesterEmail        = trim($_POST['requester_email'] ?? '');
    $requesterContact      = trim($_POST['requester_contact_number'] ?? '');

    $childName    = trim($_POST['child_full_name'] ?? '');
    $fatherName   = trim($_POST['father_full_name'] ?? '');
    $motherName   = trim($_POST['mother_full_name'] ?? '');
    $birthplace   = trim($_POST['birthplace'] ?? '');
    $birthdate    = $_POST['birthdate'] ?? '';
    $baptismDate  = $_POST['date_of_baptism'] ?: null;
    $minister     = trim($_POST['officiating_minister'] ?? '');
    $sponsors     = trim($_POST['sponsors_text'] ?? '');
    $bookNo       = trim($_POST['book_no'] ?? '');
    $pageNo       = trim($_POST['page_no'] ?? '');
    $lineNo       = trim($_POST['line_no'] ?? '');

    $purpose      = $_POST['purpose'] ?? '';
    $purposeOther = trim($_POST['purpose_other'] ?? '');
    $numCopies    = min(20, max(1, (int) ($_POST['num_copies'] ?? 1)));
    $notes        = trim($_POST['notes'] ?? '');

    if ($requesterName === '')        $errors[] = 'Requester name is required.';
    if ($requesterRelationship === '') $errors[] = 'Relationship to child is required.';
    if ($requesterEmail === '')       $errors[] = 'Email address is required.';
    if (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($requesterContact === '')     $errors[] = 'Contact number is required.';
    if ($requesterContact !== '' && !is_valid_phone_number($requesterContact)) $errors[] = 'Please enter a valid contact number (at least 10 digits).';
    if ($childName === '')            $errors[] = "Child's full name is required.";
    if ($fatherName === '')           $errors[] = "Father's full name is required.";
    if ($motherName === '')           $errors[] = "Mother's full name is required.";
    if ($birthplace === '')           $errors[] = 'Birthplace is required.';
    if ($birthdate === '')            $errors[] = 'Birthdate is required.';
    if (is_future_date($birthdate))   $errors[] = 'Birthdate cannot be in the future.';
    if ($purpose === '')              $errors[] = 'Purpose is required.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $trackingNumber = generate_tracking_number($pdo, 'certificate_requests');

            $stmt = $pdo->prepare(
                "INSERT INTO certificate_requests
                    (tracking_number, sacrament_type, requester_name, requester_relationship,
                     requester_relationship_other, requester_email, requester_contact_number,
                     purpose, purpose_other, num_copies, notes, status)
                 VALUES (?, 'baptismal', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([
                $trackingNumber, $requesterName, $requesterRelationship, $requesterRelOther,
                $requesterEmail, $requesterContact, $purpose, $purposeOther, $numCopies, $notes,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO baptismal_certificate_requests
                    (request_id, child_full_name, father_full_name, mother_full_name,
                     birthplace, birthdate, date_of_baptism, officiating_minister,
                     sponsors_text, book_no, page_no, line_no)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $requestId, $childName, $fatherName, $motherName, $birthplace, $birthdate,
                $baptismDate, $minister, $sponsors, $bookNo, $pageNo, $lineNo,
            ]);

            log_activity($pdo, null, 'certificate_request_submitted', 'certificate_requests', $requestId,
                "Baptismal certificate request submitted by {$requesterName}");

            $pdo->commit();

            if (mail_request_received($requesterEmail, $trackingNumber, 'Baptismal Certificate Request')) {
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
    <h1>Baptismal Certificate Request</h1>

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

                <label>Relationship to Child *
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
                <legend>Baptismal Record Details</legend>

                <label>Child's Full Name (as baptized) *
                    <input type="text" name="child_full_name" value="<?= e($_POST['child_full_name'] ?? '') ?>" required>
                </label>
                <label>Father's Full Name *
                    <input type="text" name="father_full_name" value="<?= e($_POST['father_full_name'] ?? '') ?>" required>
                </label>
                <label>Mother's Full Name *
                    <input type="text" name="mother_full_name" value="<?= e($_POST['mother_full_name'] ?? '') ?>" required>
                </label>
                <label>Birthplace *
                    <input type="text" name="birthplace" value="<?= e($_POST['birthplace'] ?? '') ?>" required>
                </label>
                <label>Birthdate *
                    <input type="date" name="birthdate" value="<?= e($_POST['birthdate'] ?? '') ?>" required>
                </label>
                <label>Date of Baptism (if known)
                    <input type="date" name="date_of_baptism" value="<?= e($_POST['date_of_baptism'] ?? '') ?>">
                </label>
                <label>Officiating Minister (if known)
                    <input type="text" name="officiating_minister" value="<?= e($_POST['officiating_minister'] ?? '') ?>">
                </label>
                <label>Sponsors/Godparents (if known)
                    <textarea name="sponsors_text"><?= e($_POST['sponsors_text'] ?? '') ?></textarea>
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
