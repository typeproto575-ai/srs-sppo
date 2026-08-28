<?php
/**
 * =====================================================================
 * SPPO-IS TRACK MY REQUEST (public status lookup)
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
                    // Each sacrament stores its contact email(s) in its own table.
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
                    }

                    if ($matchedEmail) {
                        $result = ['kind' => 'application', 'row' => $appRow];
                    } else {
                        $error = 'We found that tracking number, but the email address does not match our records.';
                    }
                } else {
                    $error = 'No request found with that tracking number. Please double-check and try again.';
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
        <div class="alert alert-success">
            <p><strong>Tracking Number:</strong> <?= e($result['row']['tracking_number']) ?></p>
            <p><strong>Type:</strong> <?= e(humanize_status($result['kind'])) ?> — <?= e(humanize_status($result['row']['sacrament_type'])) ?></p>
            <p><strong>Status:</strong> <?= e(humanize_status($result['row']['status'])) ?></p>
            <p><strong>Date Submitted:</strong> <?= e(date('F j, Y', strtotime($result['row']['date_submitted']))) ?></p>
            <p>If your status is "Ready for Pickup," please visit the parish office with a valid ID. Payment is made at pickup.</p>
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
