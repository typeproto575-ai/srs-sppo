<?php
/**
 * =====================================================================
 * SRS-SPPO ADMIN/STAFF LOGIN
 * =====================================================================
 * Purpose: Authenticate a parish staff member (secretary or priest —
 *          priest role is retired; only secretaries remain active) and
 *          start their session.
 *
 * Security features:
 *   - CSRF token check on the POST (csrf_verify from functions.php)
 *   - Passwords verified with password_verify() against the stored hash
 *   - session_regenerate_id() after login to prevent session fixation
 *   - Inactive accounts (is_active = 0) cannot log in
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php'; // starts the session

// Subfolder-safe URL prefix for the stylesheet link below.
$basePath = $basePath ?? base_path();

$error = null; // shown inside the form when authentication fails

// Show a message if the session was ended due to account deactivation.
if (isset($_GET['deactivated']) && $_GET['deactivated'] === '1') {
    $error = 'Your account has been deactivated. Please contact the parish office.';
}

// ---- Handle the login form submission --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reject cross-site forgeries: the form must carry our session's token.
    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } else {
        // Collect credentials (never echoed back unescaped — only $error is).
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // ---- Rate limiting: block after 8 failed attempts in 15 minutes ----
        // Keyed on username to prevent brute-force on a specific account.
        if (too_many_attempts($pdo, $username, 8, 15)) {
            $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
            log_activity($pdo, null, 'login_rate_limited', null, null, "Rate limited: {$username}");
        } else {
            // Look up an ACTIVE account by username. Prepared statement
            // prevents SQL injection from the username field.
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            // Verify password against the bcrypt/argon2 hash stored in DB.
            if ($user && password_verify($password, $user['password_hash'])) {
                // New session ID on privilege change (stops fixation attacks).
                session_regenerate_id(true);

                // Store only the fields the app needs — never the password hash.
                $_SESSION['user'] = [
                    'id'        => $user['id'],
                    'username'  => $user['username'],
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                ];
                log_activity($pdo, $user['id'], 'login'); // audit trail

                redirect_to('/admin/dashboard.php'); // 302 + exit
            }

            // Record failed attempt for rate limiting (before generic error message).
            record_attempt($pdo, $username);

            // Generic message: don't reveal whether the username exists.
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Login | SRS-SPPO</title>
    <!-- Circular parish logo as the browser favicon (transparent corners) -->
    <link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/logo-circle.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css">
    <!-- Login layout styles (.login-page) live in admin.css -->
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css">
</head>
<body>
<main class="login-page">
    <!-- Parish logo above the login card -->
    <img src="<?= $basePath ?>/assets/images/logo.jpg" alt="St. Patrick Parish logo" class="login-logo">
    <h1>Staff Login</h1>
    <!-- Authentication error, HTML-escaped -->
    <?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>
    <!-- Posts back to this same page; token guards against CSRF -->
    <form method="post" action="">
        <?= csrf_field() ?>
        <label>Username <input type="text" name="username" required></label>
        <label>Password <input type="password" name="password" required></label>
        <button type="submit" class="btn">Log In</button>
    </form>
</main>
</body>
</html>
