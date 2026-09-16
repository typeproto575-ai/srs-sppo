<?php
$f = 'c:/xampp/htdocs/srs-sppo/public/track-request.php';
$c = file_get_contents($f);
if ($c === false) { die("Read failed\n"); }

$count = 0;

// 1. Add tracking-number format validation + IP-based rate limiting + generic error message.
$old = "        } elseif (!filter_var(\$email, FILTER_VALIDATE_EMAIL)) {
            \$error = 'Please enter a valid email address.';
        } else {
            // ---- Rate limiting: block after 8 failed attempts in 15 minutes ----
            // Keyed on tracking+email to prevent enumeration attacks.
            \$rateKey = \$trackingNumber . '|' . \$email;
            if (too_many_attempts(\$pdo, \$rateKey, 8, 15)) {
                \$error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
                log_activity(\$pdo, null, 'track_rate_limited', null, null, \"Rate limited: {\$trackingNumber}\");
            } else {
                // 1. Look in certificate_requests first -- email lives directly on the shared table.
                \$stmt = \$pdo->prepare(
                    \"SELECT tracking_number, sacrament_type, status, date_submitted, requester_email
                     FROM certificate_requests WHERE tracking_number = ?\"
                );
                \$stmt->execute([\$trackingNumber]);
                \$row = \$stmt->fetch();

                if (\$row) {
                    // Case-insensitive email match protects against trivial snooping.
                    if (strcasecmp(trim(\$row['requester_email']), \$email) === 0) {
                        \$result = ['kind' => 'certificate', 'row' => \$row];
                    } else {
                        // Record failed attempt (wrong email for this tracking number).
                        record_attempt(\$pdo, \$rateKey);
                        \$error = 'We found that tracking number, but the email address does not match our records.';
                    }
                } else {";

$new = "        } elseif (!filter_var(\$email, FILTER_VALIDATE_EMAIL)) {
            \$error = 'Please enter a valid email address.';
        } elseif (!preg_match('/^SPPO-\\d{4}-\\d{6}\$/', \$trackingNumber)) {
            // Malformed tracking number — reject before hitting the DB.
            \$error = 'Please enter a valid tracking number (e.g. SPPO-2026-000123).';
        } else {
            // ---- Rate limiting: block after 8 failed attempts in 15 minutes ----
            // Keyed on tracking+email to prevent enumeration attacks.
            \$rateKey = \$trackingNumber . '|' . \$email;
            // IP-level throttle prevents rotating tracking numbers against a
            // fixed email (or vice versa) to route around the per-key limit.
            \$remoteIp = \$_SERVER['REMOTE_ADDR'] ?? 'unknown';
            \$ipKey = 'ip:' . \$remoteIp;
            if (too_many_attempts(\$pdo, \$rateKey, 8, 15) || too_many_attempts(\$pdo, \$ipKey, 20, 15)) {
                \$error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
                log_activity(\$pdo, null, 'track_rate_limited', null, null, \"Rate limited: {\$trackingNumber} from {\$remoteIp}\");
            } else {
                // 1. Look in certificate_requests first -- email lives directly on the shared table.
                \$stmt = \$pdo->prepare(
                    \"SELECT tracking_number, sacrament_type, status, date_submitted, requester_email
                     FROM certificate_requests WHERE tracking_number = ?\"
                );
                \$stmt->execute([\$trackingNumber]);
                \$row = \$stmt->fetch();

                // Identical generic message for both \"not found\" and \"wrong email\"
                // so an attacker cannot confirm a tracking number is valid.
                \$genericError = \"We couldn't find a request matching that tracking number and email combination.\";

                if (\$row) {
                    // Case-insensitive email match protects against trivial snooping.
                    if (strcasecmp(trim(\$row['requester_email']), \$email) === 0) {
                        \$result = ['kind' => 'certificate', 'row' => \$row];
                    } else {
                        // Record failed attempt (wrong email for this tracking number).
                        record_attempt(\$pdo, \$rateKey);
                        record_attempt(\$pdo, \$ipKey);
                        \$error = \$genericError;
                    }
                } else {";

if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c, $count);
    echo "Replaced rate-limit + error block: $count\n";
} else {
    echo "WARNING: block not found\n";
}

// 2. Replace the sacrament_applications not-found error with the generic one.
$old2 = "                    \$error = 'No request found with that tracking number.';";
$new2 = "                    record_attempt(\$pdo, \$ipKey);
                    \$error = \$genericError;";
if (strpos($c, $old2) !== false) {
    $c = str_replace($old2, $new2, $c, $count);
    echo "Replaced not-found error: $count\n";
} else {
    echo "WARNING: not-found error not found\n";
}

$tmp = $f . '.tmpt';
file_put_contents($tmp, $c);
rename($tmp, $f);
echo "Done\n";
