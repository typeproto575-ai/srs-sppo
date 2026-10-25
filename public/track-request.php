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

$pageTitle = 'Track a Request or Application';
$error = null;
$results = [];

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
            // Accept the random format for new submissions and sequential
            // formats already issued, including legacy baptismal numbers.
            // Reject anything else before it reaches the database lookup.
            $trackingPattern = '/^(?:SPPO(?:-APP)?-\d{4}-(?:[A-HJ-NP-Z2-9]{16}|\d{6})|BAP-[A-F0-9]{8})$/';
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
                $error = 'We couldn\'t find a request or application matching that tracking number and email combination.';
            } else {
                $certificateStmt = $pdo->prepare(
                    "SELECT tracking_number, sacrament_type, status, date_submitted, requester_email
                     FROM certificate_requests WHERE tracking_number = ?"
                );
                $certificateStmt->execute([$trackingNumber]);
                $certificateRow = $certificateStmt->fetch();
                if ($certificateRow && strcasecmp(trim((string) $certificateRow['requester_email']), $email) === 0) {
                    $results[] = ['kind' => 'certificate', 'row' => $certificateRow];
                }

                $applicationStmt = $pdo->prepare(
                    "SELECT id, tracking_number, sacrament_type, status, date_submitted
                     FROM sacrament_applications WHERE tracking_number = ?"
                );
                $applicationStmt->execute([$trackingNumber]);
                $applicationRow = $applicationStmt->fetch();
                if ($applicationRow) {
                    $emailQuery = match ($applicationRow['sacrament_type']) {
                        'baptismal' => 'SELECT email FROM baptismal_applications WHERE application_id = ?',
                        'confirmation' => 'SELECT email FROM confirmation_applications WHERE application_id = ?',
                        'first_communion' => 'SELECT email FROM first_communion_applications WHERE application_id = ?',
                        'marriage' => 'SELECT groom_email, bride_email FROM marriage_applications WHERE application_id = ?',
                        default => null,
                    };
                    if ($emailQuery !== null) {
                        $emailStmt = $pdo->prepare($emailQuery);
                        $emailStmt->execute([(int) $applicationRow['id']]);
                        $applicationEmails = $emailStmt->fetch() ?: [];
                        $matchedEmail = false;
                        foreach ($applicationEmails as $applicationEmail) {
                            if (strcasecmp(trim((string) $applicationEmail), $email) === 0) {
                                $matchedEmail = true;
                                break;
                            }
                        }
                        if ($matchedEmail) {
                            $results[] = ['kind' => 'application', 'row' => $applicationRow];
                        }
                    }
                }

                if ($results === []) {
                    lookup_record_attempt($pdo, 'lookup_track', $rateKey);
                    $error = 'We couldn\'t find a request or application matching that tracking number and email combination.';
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="request-form">
    <h1>Track a Request or Application</h1>
    <p>Use the tracking number and the same email address you provided for that certificate request or sacrament application. One email address can be used for more than one submission; each tracking number shows its own status.</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><p><?= e($error) ?></p></div>
    <?php endif; ?>

        <?php foreach ($results as $result): ?>
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
            <h2><?= $result['kind'] === 'application' ? 'Sacrament Application' : 'Certificate Request' ?></h2>
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
                        <strong>Awaiting payment.</strong> Your <?= $result['kind'] === 'application' ? 'application' : 'request' ?> has been received. Payment is made <strong>outside this website</strong> — the office never takes money online. You may pay at the parish office, contact the parish by email, phone, or the parish Facebook page/Messenger, or ask someone near the parish to pay on your behalf. The parish confirms the fee directly. Processing continues once the office records your payment.
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
        <?php endforeach; ?>

<!-- Status lookup form: posts back to this page; CSRF-protected -->
    <form method="post" action="">
        <?= csrf_field() ?>
        <fieldset>
            <legend>Look Up Your Office Transaction</legend>
            <label>Tracking Number *
                <input type="text" name="tracking_number" placeholder="Enter the tracking number from your confirmation" value="<?= e($_POST['tracking_number'] ?? '') ?>" required>
            </label>
            <label>Email Address *
                <input type="email" name="email" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required>
            </label>
        </fieldset>
        <button type="submit" class="btn">Track Request or Application</button>
    </form>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
