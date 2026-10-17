<?php
/**
 * =====================================================================
 * SRS-SPPO TRACK MY REQUEST (public status lookup)
 * =====================================================================
 * Purpose: Lets a requester check progress WITHOUT an account. They
 *          enter their tracking number plus the email used at
 *          submission; the page looks the request up in:
 *            1. certificate_requests   (email stored on the row itself)
 *            2. sacrament_applications (email lives in the sacrament-
 *               specific extension table: confirmation, first communion
 *               or marriage)
 *          Status is informational only — pickup still happens in person.
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php'; // starts a session for CSRF

$pageTitle = 'Track My Request';
$error  = null;
$result = null; // ['kind' => 'certificate'|'application', 'row' => [...]]

/**
 * Humanize a status enum value for display, e.g. 'ready_for_pickup' -> 'Ready For Pickup'
 */
function humanize_status(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

// ---- Handle the lookup form submission -------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check: the form must carry the session's token.
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } else {
        // Tracking numbers are generated uppercase; normalize input before rate limiting and lookup.
        $trackingNumber = strtoupper(trim($_POST['tracking_number'] ?? ''));
        $email          = trim($_POST['email'] ?? '');

        if ($trackingNumber === '' || $email === '') {
            $error = 'Please enter both your tracking number and the email address you used when submitting.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // ---- Validate tracking-number format before hitting the DB ----
            // Current tracking numbers use the SPPO format. Older baptismal
            // submissions used BAP- plus eight hexadecimal characters.
            // Reject anything else before it reaches the database lookup.
            $trackingPattern = '/^(?:SPPO(-[A-Z]+)?-\d{4}-\d{6}|BAP-[A-F0-9]{8})$/';
            // ---- Rate limiting: block after 8 failed attempts in 15 minutes ----
            // Keyed on tracking+email (existing key) AND on IP (new, to prevent
            // rotating one against the other to route around the per-key limit).
            // The check runs BEFORE format validation so that hammering garbage-
            // formatted tracking numbers is blocked by the same limit too.
            $rateKey       = $trackingNumber . '|' . $email;
            if (lookup_is_throttled($pdo, 'lookup_track', $rateKey, 8, 20, 15)) {
                $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
            } elseif (!preg_match($trackingPattern, $trackingNumber)) {
                // Malformed format counts as a failed attempt (rate-limit bypass fix).
                lookup_record_attempt($pdo, 'lookup_track', $rateKey);
                $error = 'We couldn\'t find a request matching that tracking number and email combination.';
            } else {
                // 1. Look in certificate_requests first -- email lives directly on the shared table.
                    $stmt = $pdo->prepare(
                        "SELECT tracking_number, sacrament_type, status, date_submitted, requester_email
                         FROM certificate_requests WHERE tracking_number = ?"
                    );
                    $stmt->execute([$trackingNumber]);
                    $row = $stmt->fetch();

                    if ($row) {
                        // Case-insensitive email match protects against trivial snooping.
                        if (strcasecmp(trim($row['requester_email']), $email) === 0) {
                            $result = ['kind' => 'certificate', 'row' => $row];
                        } else {
                            // Record failed attempt (wrong email for this tracking number).
                            lookup_record_attempt($pdo, 'lookup_track', $rateKey);
                            $error = 'We couldn\'t find a request matching that tracking number and email combination.';
                        }
                    } else {
                    // 2. Look in sacrament_applications -- email lives in the sacrament-specific extension table.
                    $stmt = $pdo->prepare(
                        "SELECT id, tracking_number, sacrament_type, status, date_submitted
                         FROM sacrament_applications WHERE tracking_number = ?"
                    );
                    $stmt->execute([$trackingNumber]);
                    $appRow = $stmt->fetch();

                    if ($appRow) {
                        $matchedEmail = false;

                        if ($appRow['sacrament_type'] === 'confirmation') {
                            $s = $pdo->prepare("SELECT email FROM confirmation_applications WHERE application_id = ?");
                            $s->execute([$appRow['id']]);
                            $e2 = $s->fetch();
                            $matchedEmail = $e2 && strcasecmp(trim($e2['email']), $email) === 0;
                        } elseif ($appRow['sacrament_type'] === 'first_communion') {
                            $s = $pdo->prepare("SELECT email FROM first_communion_applications WHERE application_id = ?");
                            $s->execute([$appRow['id']]);
                            $e2 = $s->fetch();
                            $matchedEmail = $e2 && strcasecmp(trim($e2['email']), $email) === 0;
                        } elseif ($appRow['sacrament_type'] === 'marriage') {
                            // Marriage has two possible contact emails (couple).
                            $s = $pdo->prepare("SELECT groom_email, bride_email FROM marriage_applications WHERE application_id = ?");
                            $s->execute([$appRow['id']]);
                            $e2 = $s->fetch();
                            $matchedEmail = $e2 && (
                                strcasecmp(trim($e2['groom_email']), $email) === 0 ||
                                strcasecmp(trim($e2['bride_email']), $email) === 0
                            );
                        } elseif ($appRow['sacrament_type'] === 'baptismal') {
                            $s = $pdo->prepare("SELECT email FROM baptismal_applications WHERE application_id = ?");
                            $s->execute([$appRow['id']]);
                            $e2 = $s->fetch();
                            $matchedEmail = $e2 && strcasecmp(trim($e2['email']), $email) === 0;
                        }

                                                if ($matchedEmail) {
                            $result = ['kind' => 'application', 'row' => $appRow];
                        } else {
                            // Record failed attempt (no matching email in extension table).
                            lookup_record_attempt($pdo, 'lookup_track', $rateKey);
                            $error = 'We couldn\'t find a request matching that tracking number and email combination.';
                        }
                    } else {
                        // Record failed attempt (tracking number not found at all).
                        lookup_record_attempt($pdo, 'lookup_track', $rateKey);
                        $error = 'We couldn\'t find a request matching that tracking number and email combination.';
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="request-form">
    <h1>Track My Request</h1>
    <p>Enter the tracking number provided after submission and the same email address used for your request or application.</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><p><?= e($error) ?></p></div>
    <?php endif; ?>

        <?php if ($result): ?>
        <?php
        $status = $result['row']['status'];
        if ($result['kind'] === 'certificate') {
            $timeline = certificate_timeline_definition($status);
            $stageLabels = array_column($timeline['stages'], 'label');
            $stageIndex = $timeline['index'];
        } else {
            $stageLabels = ['Application Received', 'Secretary Review', 'Requirements / Onsite Verification', 'Approved', 'Scheduled', 'Completed'];
            $stageMap = ['submitted' => 0, 'awaiting_payment' => 1, 'requirements_pending' => 1, 'requirements_incomplete' => 1, 'documents_pending' => 1, 'interview_scheduled' => 2, 'banns_in_progress' => 2, 'seminar_pending' => 2, 'awaiting_onsite_verification' => 2, 'ready_for_approval' => 2, 'approved' => 3, 'scheduled' => 4, 'completed' => 5];
            $stageIndex = $stageMap[$status] ?? 0;
        }
        ?>
        <div class="alert alert-success track-result">
            <p><strong>Tracking Number:</strong> <?= e($result['row']['tracking_number']) ?></p>
            <p><strong>Type:</strong> <?= e(humanize_status($result['kind'])) ?> - <?= e(humanize_status($result['row']['sacrament_type'])) ?></p>
            <p><strong>Date Submitted:</strong> <?= e(date('F j, Y', strtotime($result['row']['date_submitted']))) ?></p>

            <?php if ($status === 'rejected' || $status === 'cancelled'): ?>
                <div class="alert alert-warning">
                    <p><strong>Status: <?= e(ucfirst($status)) ?>.</strong> Please contact the parish office directly for details and next steps.</p>
                </div>
            <?php elseif (in_array($status, ['correction_needed', 'unable_to_locate_record'], true)): ?>
                <div class="alert alert-warning"><p><strong><?= e(humanize_status($status)) ?>.</strong> Please contact the parish office for next steps.</p></div>
            <?php else: ?>
                <ol class="status-timeline">
                    <?php foreach ($stageLabels as $i => $label): ?>
                        <li class="<?= $i < $stageIndex ? 'done' : ($i == $stageIndex ? 'current' : '') ?>">
                            <span class="timeline-dot"></span>
                            <span class="timeline-label"><?= e($label) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="field-hint">
                    <?php if ($status === 'awaiting_payment'): ?>
                        <strong>Awaiting payment.</strong> Your request has been received and matched. Payment is made <strong>outside this website</strong> — the office never takes money online. You may pay at the parish office, contact the parish by email, phone, or the parish Facebook page/Messenger, or ask someone near the parish to pay on your behalf. The parish confirms the fee directly. Processing continues once the office records your payment.
                    <?php elseif ($status === 'ready_for_pickup'): ?>
                        Your certificate is ready. Please visit the parish office with your tracking number and valid identification. Certificates are released in person.
                    <?php elseif ($status === 'released'): ?>
                        This transaction was released at the parish office.
                    <?php else: ?>
                        Current stage: <strong><?= e($stageLabels[$stageIndex] ?? humanize_status($status)) ?></strong>.
                        Check back any time with this tracking number and your email.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<!-- Status lookup form: posts back to this page; CSRF-protected -->
    <form method="post" action="">
        <?= csrf_field() ?>
        <fieldset>
            <legend>Look Up Your Office Transaction</legend>
            <label>Tracking Number *
                <input type="text" name="tracking_number" placeholder="SPPO-2026-000123 or BAP-XXXXXXXX" value="<?= e($_POST['tracking_number'] ?? '') ?>" required>
            </label>
            <label>Email Address *
                <input type="email" name="email" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required>
            </label>
        </fieldset>
        <button type="submit" class="btn">Track Request</button>
    </form>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
