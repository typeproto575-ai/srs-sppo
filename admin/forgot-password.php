<?php
/**
 * Public-facing staff password-reset request form.
 * Uses generic responses so the page does not reveal whether an email exists.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';

$basePath = $basePath ?? base_path();
$message = 'If an active staff account matches that email, a password-reset link has been sent. Check the official parish inbox. If no email arrives, contact the parish administrator.';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } elseif (too_many_attempts($pdo, 'password-reset-ip:' . client_ip(), 5, 15)) {
        log_activity($pdo, null, 'password_reset_rate_limited', null, null, 'Password reset request rate limited.');
    } elseif (too_many_attempts($pdo, 'password-reset-email:' . hash('sha256', strtolower(trim($_POST['email'] ?? ''))), 5, 15)) {
        log_activity($pdo, null, 'password_reset_rate_limited', null, null, 'Password reset request rate limited.');
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        record_attempt($pdo, 'password-reset-ip:' . client_ip());
        record_attempt($pdo, 'password-reset-email:' . hash('sha256', $email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = ? AND is_active = 1 AND role = 'secretary' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $pdo->prepare('UPDATE users SET reset_token_hash = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?')
                    ->execute([$tokenHash, (int) $user['id']]);
                $resetUrl = rtrim(MAIL_SITE_URL, '/') . $basePath . '/admin/reset-password.php?token=' . rawurlencode($rawToken);
                // DEV ONLY - remove before commit
                if (MAIL_ENABLED === false && in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:80', '127.0.0.1:80'], true)) {
                    error_log('DEV RESET LINK: ' . $resetUrl);
                }
                mail_password_reset($user['email'], $resetUrl, $pdo, (int) $user['id']);
                log_activity($pdo, (int) $user['id'], 'password_reset_requested', 'users', (int) $user['id'], 'Password reset link requested.');
            } else {
                log_activity($pdo, null, 'password_reset_requested_unknown', null, null, 'Password reset requested for an unrecognized email.');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | SRS-SPPO</title>
<link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/logo-circle.png">
<link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css"><link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css">
</head>
<body class="login-shell"><main class="login-page">
<img src="<?= $basePath ?>/assets/images/logo.jpg" alt="St. Patrick Parish logo" class="login-logo">
<h1>Reset staff password</h1>
<p class="login-help">Enter your official parish staff email. For security, the same message is shown whether or not an account matches.</p>
<?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error): ?><p class="alert alert-success"><?= e($message) ?></p><?php endif; ?>
<form method="post" action="">
<?= csrf_field() ?>
<label>Staff email <input type="email" name="email" autocomplete="email" required></label>
<button type="submit" class="btn">Send reset link</button>
</form>
<p class="login-recovery-link"><a href="<?= $basePath ?>/admin/login.php">Return to staff login</a></p>
</main>
<?php $mainJsVersion = file_exists(__DIR__ . '/../assets/js/main.js') ? filemtime(__DIR__ . '/../assets/js/main.js') : time(); ?><script src="<?= $basePath ?>/assets/js/main.js?v=<?= (int) $mainJsVersion ?>"></script>
</body></html>
