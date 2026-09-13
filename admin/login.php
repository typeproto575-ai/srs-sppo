<?php
/**
 * =====================================================================
 * SRS-SPPO ADMIN/STAFF LOGIN
 * =====================================================================
 * Purpose: Authenticate a parish staff member (secretary role only — the
 *          retired priest login has been removed; see PROJECT_CONTEXT.md)
 *          and start their session.
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

// Show a message when the session ended via the 30-minute idle timeout.
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $error = 'You were logged out after 30 minutes of inactivity. Please sign in again.';
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

        // ---- Rate limiting: two independent checks ---------------------------
        //   1. Per-username (8 in 15 min): stops brute-force on one account.
        //   2. Per-IP (20 in 15 min): stops attackers rotating through many
        //      usernames from the same machine. Higher cap so a shared office
        //      computer with several staff accounts isn't locked out.
        $userKey = 'user:' . $username;
        $ipKey   = 'ip:' . client_ip();
        if (too_many_attempts($pdo, $userKey, 8, 15) || too_many_attempts($pdo, $ipKey, 20, 15)) {
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
                $_SESSION['last_activity'] = time(); // start the idle window
                log_activity($pdo, $user['id'], 'login'); // audit trail

                redirect_to('/admin/dashboard.php'); // 302 + exit
            }

            // Record failed attempt for rate limiting (before generic error
            // message) — both dimensions so username-rotation is also caught.
            record_attempt($pdo, $userKey);
            record_attempt($pdo, $ipKey);

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
        <label>Username <input type="text" name="username" autocomplete="username" required></label>
        <label>Password</label>
        <div class="password-wrap">
            <input type="password" name="password" autocomplete="current-password" required>
            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                <!-- Eye: password hidden (initial state) -->
                <svg class="eye-on" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <!-- Eye with slash: password visible (starts hidden) -->
                <svg class="eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden>
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                    <path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
            </button>
        </div>
        <button type="submit" class="btn">Log In</button>
    </form>
</main>
<!-- Site-wide JS (loads the password show/peek toggle above) -->
<?php $mainJsVersion = file_exists(__DIR__ . '/../assets/js/main.js') ? filemtime(__DIR__ . '/../assets/js/main.js') : time(); ?>
<script src="<?= $basePath ?>/assets/js/main.js?v=<?= (int) $mainJsVersion ?>"></script>
</body>
</html>
