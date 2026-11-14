<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$basePath = base_path();
$pageTitle = 'View My Requests';
$errors = [];
$items = [];
$reference = '';
$email = '';
$searched = false;
$verificationSent = false;
$verificationError = '';
$verifiedPersonReference = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attempts = $_SESSION['person_request_lookup_attempts'] ?? [];
    $now = time();
    $attempts = is_array($attempts)
        ? array_values(array_filter($attempts, static fn($time) => is_int($time) && $time > $now - 900))
        : [];
    if (count($attempts) >= 10) {
        $errors[] = 'Too many attempts. Please wait 15 minutes before trying again.';
    } elseif (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $attempts[] = $now;
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $reference = strtoupper(trim(is_string($_POST['person_reference'] ?? null) ? $_POST['person_reference'] : ''));
        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $pending = $_SESSION['pending_person_request_lookup'] ?? null;

        if ($action === 'verify_code' && is_array($pending)) {
            $code = trim(is_string($_POST['verification_code'] ?? null) ? $_POST['verification_code'] : '');
            $reference = (string) ($pending['reference'] ?? '');
            $email = (string) ($pending['email'] ?? '');
            if ((int) ($pending['expires_at'] ?? 0) < $now
                || !preg_match('/^\d{6}$/', $code)
                || !hash_equals((string) ($pending['code_hash'] ?? ''), hash('sha256', $code))) {
                $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
                $_SESSION['pending_person_request_lookup'] = $pending;
                $verificationError = (int) $pending['attempts'] >= 5
                    ? 'The access code expired or the attempt limit was reached. Start again to request a new code.'
                    : 'The access code is invalid or expired.';
                if ((int) $pending['attempts'] >= 5) {
                    unset($_SESSION['pending_person_request_lookup']);
                }
            } else {
                $_SESSION['verified_person_request_lookup'] = [
                    'reference' => $reference,
                    'email' => $email,
                    'person_id' => (int) ($pending['person_id'] ?? 0),
                    'expires_at' => $now + 900,
                ];
                unset($_SESSION['pending_person_request_lookup']);
                $searched = true;
            }
        } elseif ($action === 'send_code'
            && (preg_match('/^SPPO-P-[A-Z0-9]{16,32}$/', $reference)
                || preg_match('/^(?:SA|CR)-\d{4}-\d{4,}$/', $reference))
            && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            unset($_SESSION['pending_person_request_lookup'], $_SESSION['verified_person_request_lookup']);
            $personStmt = preg_match('/^SPPO-P-/', $reference)
                ? $pdo->prepare('SELECT id FROM persons WHERE public_reference = ? LIMIT 1')
                : $pdo->prepare(
                    "SELECT CASE
                                WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(details, '$.bride.email'))) = LOWER(?)
                                     AND spouse_person_id IS NOT NULL
                                THEN spouse_person_id ELSE person_id
                            END AS id
                     FROM sacrament_applications
                     WHERE request_no = ?
                       AND (LOWER(applicant_email) = LOWER(?)
                            OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(details, '$.bride.email'))) = LOWER(?))
                     UNION
                     SELECT person_id AS id FROM certificate_requests
                     WHERE request_no = ? AND LOWER(requester_email) = LOWER(?)
                     LIMIT 1"
                );
            if (preg_match('/^SPPO-P-/', $reference)) {
                $personStmt->execute([$reference]);
            } else {
                $personStmt->execute([$email, $reference, $email, $email, $reference, $email]);
            }
            $personId = $personStmt->fetchColumn();
            $hasMatchingRequest = false;
            if ($personId !== false) {
                $appCheck = $pdo->prepare(
                    "SELECT 1 FROM sacrament_applications
                     WHERE (person_id = ? OR spouse_person_id = ?)
                       AND (LOWER(applicant_email) = LOWER(?)
                            OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(details, '$.bride.email'))) = LOWER(?))
                     LIMIT 1"
                );
                $appCheck->execute([(int) $personId, (int) $personId, $email, $email]);
                $certCheck = $pdo->prepare(
                    'SELECT 1 FROM certificate_requests
                     WHERE person_id = ? AND LOWER(requester_email) = LOWER(?) LIMIT 1'
                );
                $certCheck->execute([(int) $personId, $email]);
                $hasMatchingRequest = (bool) $appCheck->fetchColumn() || (bool) $certCheck->fetchColumn();
            }
            if ($hasMatchingRequest) {
                $code = (string) random_int(100000, 999999);
                $sent = send_mail(
                    $email,
                    'Your parish request access code',
                    "Your one-time access code is {$code}.\r\n\r\nIt expires in 10 minutes. Do not share this code with anyone.\r\n\r\nSt. Patrick's Parish Office — Dingalan, Aurora",
                    $pdo,
                    'public_request_access'
                );
                if ($sent) {
                    $_SESSION['pending_person_request_lookup'] = [
                        'reference' => $reference,
                        'email' => $email,
                        'person_id' => (int) $personId,
                        'code_hash' => hash('sha256', $code),
                        'expires_at' => $now + 600,
                        'attempts' => 0,
                    ];
                    $verificationSent = true;
                } else {
                    $verificationError = 'We could not send an access code. Please contact the Parish Office.';
                }
            } else {
                $verificationSent = true;
                $_SESSION['pending_person_request_lookup'] = [
                    'reference' => $reference,
                    'email' => $email,
                    'person_id' => 0,
                    'code_hash' => hash('sha256', bin2hex(random_bytes(16))),
                    'expires_at' => $now + 600,
                    'attempts' => 0,
                ];
            }
        } else {
            $errors[] = 'Enter a valid person reference and the email address used for a linked request.';
        }
        $_SESSION['person_request_lookup_attempts'] = $attempts;
    }

    $verified = $_SESSION['verified_person_request_lookup'] ?? null;
    if (is_array($verified) && (int) ($verified['expires_at'] ?? 0) >= $now) {
        $reference = (string) ($verified['reference'] ?? '');
        $email = (string) ($verified['email'] ?? '');
        $personId = (int) ($verified['person_id'] ?? 0);
        $searched = true;
        if ($personId > 0) {
            $referenceStmt = $pdo->prepare('SELECT public_reference FROM persons WHERE id = ? LIMIT 1');
            $referenceStmt->execute([$personId]);
            $verifiedPersonReference = (string) ($referenceStmt->fetchColumn() ?: '');
            $applications = $pdo->prepare(
                "SELECT 'application' AS item_type, sacrament_type, status, created_at, preferred_date, requirements
                 FROM sacrament_applications
                 WHERE person_id = ? OR spouse_person_id = ?
                 ORDER BY created_at DESC, id DESC"
            );
            $applications->execute([(int) $personId, (int) $personId]);
            $items = $applications->fetchAll(PDO::FETCH_ASSOC);

            $certificates = $pdo->prepare(
                "SELECT 'certificate' AS item_type, sacrament_type, status, created_at, NULL AS preferred_date, NULL AS requirements
                 FROM certificate_requests
                 WHERE person_id = ?
                 ORDER BY created_at DESC, id DESC"
            );
            $certificates->execute([(int) $personId]);
            $items = array_merge($items, $certificates->fetchAll(PDO::FETCH_ASSOC));
            usort($items, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
        }
    }
}

$statusLabels = [
    'new' => 'Submitted — Pending Review',
    'reviewing' => 'Under Review',
    'requirements_pending' => 'Requirements Pending',
    'approved' => 'Approved',
    'scheduled' => 'Scheduled',
    'completed' => 'Completed',
    'rejected' => 'Closed (not eligible)',
    'matched' => 'Matched to Parish Record',
    'for_signature' => 'Pending Priest Signature',
    'ready' => 'Ready for Pickup',
    'released' => 'Released',
];
$sacramentLabels = [
    'baptism' => 'Baptism',
    'first_communion' => 'First Communion',
    'confirmation' => 'Confirmation',
    'marriage' => 'Marriage',
];

require_once __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h1>View Requests Linked to Your Person Reference</h1>
    <p>Use your person reference across linked requests. Older submissions can also be found with their original request number. We will email a one-time access code to the address used on a linked request. This page does not approve requests or accept documents.</p>
    <?php if (!$searched && !isset($_SESSION['pending_person_request_lookup'])): ?>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_code">
            <label for="person_reference">Person reference or older request number</label>
            <input id="person_reference" name="person_reference" autocomplete="off" required maxlength="48" value="<?= e($reference) ?>" placeholder="SPPO-P-...">
            <label for="email">Email address used on the request</label>
            <input id="email" name="email" type="email" autocomplete="email" required maxlength="150" value="<?= e($email) ?>">
            <button type="submit" class="btn">Email me an access code</button>
        </form>
    <?php elseif (!$searched): ?>
        <?php if ($verificationSent): ?><p class="alert alert-info" role="status">If the reference and email match a request, an access code has been emailed. Check your inbox and spam folder.</p><?php endif; ?>
        <?php if ($verificationError !== ''): ?><p class="alert alert-error" role="alert"><?= e($verificationError) ?></p><?php endif; ?>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify_code">
            <label for="verification_code">Six-digit email access code</label>
            <input id="verification_code" name="verification_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            <button type="submit" class="btn">Verify and view requests</button>
        </form>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_code">
            <input type="hidden" name="person_reference" value="<?= e((string) ($_SESSION['pending_person_request_lookup']['reference'] ?? '')) ?>">
            <input type="hidden" name="email" value="<?= e((string) ($_SESSION['pending_person_request_lookup']['email'] ?? '')) ?>">
            <button type="submit" class="btn btn-secondary">Send a new code</button>
        </form>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php elseif ($searched && $items === []): ?>
        <p class="alert alert-info" role="status">No requests matched those details. Check the reference and email, or contact the Parish Office.</p>
    <?php elseif ($items !== []): ?>
        <h2>Your requests</h2>
        <?php if ($verifiedPersonReference !== ''): ?>
            <p><strong>Your person reference:</strong> <code><?= e($verifiedPersonReference) ?></code> Keep this reference for future requests.</p>
        <?php endif; ?>
        <p>If a request needs additional documents, contact the Parish Office using this same person reference. Do not submit a duplicate application.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Request</th><th>Status</th><th>Submitted</th><th>Preferred date</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e(($item['item_type'] === 'application' ? 'Pre-application: ' : 'Certificate: ') . ($sacramentLabels[$item['sacrament_type']] ?? ucfirst((string) $item['sacrament_type']))) ?></td>
                        <td><?= e($statusLabels[$item['status']] ?? ucfirst(str_replace('_', ' ', (string) $item['status']))) ?></td>
                        <td><?= e((string) $item['created_at']) ?></td>
                        <td><?= e((string) ($item['preferred_date'] ?? '—') ?: '—') ?></td>
                    </tr>
                    <?php
                    $requirements = json_decode((string) ($item['requirements'] ?? ''), true);
                    $missingRequirements = [];
                    if ($item['item_type'] === 'application' && is_array($requirements)) {
                        foreach ($requirements as $key => $requirement) {
                            if (is_array($requirement) && ($requirement['status'] ?? '') === 'not_received') {
                                $requirementLabel = ucwords(str_replace('_', ' ', (string) $key));
                                $requirementNote = trim((string) ($requirement['note'] ?? ''));
                                $missingRequirements[] = $requirementNote !== '' ? $requirementLabel . ' (' . $requirementNote . ')' : $requirementLabel;                            }
                        }
                    }
                    ?>
                    <?php if ($missingRequirements !== []): ?>
                        <tr><td colspan="4"><strong>Still needed:</strong> <?= e(implode(', ', $missingRequirements)) ?>. Contact the Parish Office using this same person reference; do not submit a duplicate.</td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
