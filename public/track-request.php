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
        $trackingNumber = trim($_POST['tracking_number'] ?? '');
        $email          = trim($_POST['email'] ?? '');

        if ($trackingNumber === '' || $email === '') {
            $error = 'Please enter both your tracking number and the email address you used when submitting.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // ---- Rate limiting: block after 8 failed attempts in 15 minutes ----
            // Keyed on tracking+email to prevent enumeration attacks.
            $rateKey = $trackingNumber . '|' . $email;
            if (too_many_attempts($pdo, $rateKey, 8, 15)) {
                $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
                log_activity($pdo, null, 'track_rate_limited', null, null, "Rate limited: {$trackingNumber}");
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
                        record_attempt($pdo, $rateKey);
                        $error = 'We found that tracking number, but the email address does not match our records.';
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
                            record_attempt($pdo, $rateKey);
                            $error = 'We found that tracking number, but the email address does not match our records.';
                        }
                    } else {
                        // Record failed attempt (tracking number not found at all).
                        record_attempt($pdo, $rateKey);
                        $error = 'No request found with that tracking number. Please double-check and try again.';
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
    <p>Enter the tracking number and email address you used when you submitted your request or application.</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><p><?= e($error) ?></p></div>
    <?php endif; ?>

        <?php if ($result): ?>
        <?php
        // --- User-friendly stage timeline ---------------------------------
        // Certificates follow one linear pipeline. Applications have a longer
        // workflow (documents / interview / banns / seminar) which we present
        // as a single "In Process" stage - purely presentational, the
        // underlying status values are unchanged.
        $status = $result['row']['status'];
        if ($result['kind'] === 'certificate') {
            $stageLabels = ['Submitted', 'In Review', 'Approved', 'Ready for Pickup', 'Released'];
            $stageIndex  = array_search($status, ['pending', 'under_review', 'approved', 'ready_for_pickup', 'released'], true);
        } else {
            $stageLabels = ['Submitted', 'In Process', 'Approved', 'Completed'];
            $stageMap = [
                'submitted' => 0,
                'documents_pending' => 1, 'interview_scheduled' => 1, 'banns_in_progress' => 1,
                'seminar_pending' => 1, 'ready_for_approval' => 1,
                'approved' => 2, 'scheduled' => 2,
                'completed' => 3,
            ];
            $stageIndex = $stageMap[$status] ?? 0;
        }
        ?>
        <div class="alert alert-success track-result">
            <p><strong>Tracking Number:</strong> <?= e($result['row']['tracking_number']) ?></p>
            <p><strong>Type:</strong> <?= e(humanize_status($result['kind'])) ?> - <?= e(humanize_status($result['row']['sacrament_type'])) ?></p>
            <p><strong>Date Submitted:</strong> <?= e(date('F j, Y', strtotime($result['row']['date_submitted']))) ?></p>

            <?php if ($status === 'rejected'): ?>
                <div class="alert alert-warning">
                    <p><strong>Status: Rejected.</strong> Please contact the parish office directly for details and next steps.</p>
                </div>
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
                    <?php if ($status === 'ready_for_pickup'): ?>
                        Your request is ready! Please visit the parish office with a valid ID. Payment is made at pickup.
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
            <legend>Look Up Your Request</legend>
            <label>Tracking Number *
                <input type="text" name="tracking_number" placeholder="SPPO-2026-000123" value="<?= e($_POST['tracking_number'] ?? '') ?>" required>
            </label>
            <label>Email Address *
                <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
            </label>
        </fieldset>
        <button type="submit" class="btn">Track Request</button>
    </form>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
