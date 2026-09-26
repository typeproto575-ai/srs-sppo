<?php
/**
 * One-time staff password-reset endpoint.
 * Validates the hashed token, changes the password, and invalidates sessions.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$basePath = $basePath ?? base_path();
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$tokenHash = $token !== '' ? hash('sha256', $token) : '';
$error = null;
$success = false;
$resetRow = null;

if ($tokenHash !== '') {
    $stmt = $pdo->prepare('SELECT id, user_id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < 5 LIMIT 1');
    $stmt->execute([$tokenHash]);
    $resetRow = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$resetRow) {
        $error = 'This reset link is invalid or expired. Request a new link.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($password) < 12) {
            $error = 'Choose a password with at least 12 characters.';
        } elseif ($password !== $confirm) {
            $error = 'The passwords do not match.';
        }
        if (!$error) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ? AND is_active = 1');
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), (int) $resetRow['user_id']]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Account is unavailable.');
                }
                $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')->execute([(int) $resetRow['id']]);
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND id != ?')->execute([(int) $resetRow['user_id'], (int) $resetRow['id']]);
                log_activity($pdo, (int) $resetRow['user_id'], 'password_reset_completed', 'users', (int) $resetRow['user_id'], 'Password reset completed; active sessions invalidated.');
                $pdo->commit();
                $success = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'The password could not be reset. Please request a new link.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password | SRS-SPPO</title><link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/logo-circle.png"><link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css"><link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css"></head>
<body class="login-shell"><main class="login-page"><img src="<?= $basePath ?>/assets/images/logo.jpg" alt="St. Patrick Parish logo" class="login-logo"><h1>Choose a new password</h1>
<?php if ($success): ?><p class="alert alert-success">Your password has been reset. Existing staff sessions have been invalidated. You may now sign in.</p><p><a class="btn" href="<?= $basePath ?>/admin/login.php">Return to staff login</a></p>
<?php elseif ($error): ?><p class="alert alert-error"><?= e($error) ?></p><p><a href="<?= $basePath ?>/admin/forgot-password.php">Request a new reset link</a></p>
<?php else: ?><p class="login-help">Use a password of at least 12 characters. This link can be used once and expires after 30 minutes.</p><form method="post" action=""><input type="hidden" name="token" value="<?= e($token) ?>"><?= csrf_field() ?><label>New password <input type="password" name="password" autocomplete="new-password" minlength="12" required></label><label>Confirm new password <input type="password" name="password_confirm" autocomplete="new-password" minlength="12" required></label><button type="submit" class="btn">Reset password</button></form><?php endif; ?>
</main></body></html>
