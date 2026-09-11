<?php
/**
 * Session guard. Include at the top of any /admin page.
 * Usage:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_role('secretary');            // secretary-only page
 *   require_role('secretary'); // secretary is the only staff role
 */

// Make sure helper functions (secure_session_start, redirect_to, e, ...)
// are available even if the including page loaded auth.php before functions.php.
require_once __DIR__ . '/functions.php';

// Start the session with hardened cookie parameters (HttpOnly, SameSite=Lax,
// Secure on HTTPS — see functions.php). No-op if a session is already active.
secure_session_start();

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        // Subfolder-safe redirect (base_path prefix applied automatically).
        redirect_to('/admin/login.php');
    }

    // ---- Session validity check (throttled) --------------------------------
    // Re-verify the account is still active in the DB, but only once per
    // $checkInterval seconds to avoid a query on every single page load.
    // If the account was deactivated (e.g. by the secretary in another
    // session), destroy this session immediately and bounce to login.
    $checkInterval = 60; // seconds between DB re-checks
    $now = time();
    $lastCheck = $_SESSION['last_active_check'] ?? 0;

    if ($now - $lastCheck >= $checkInterval) {
        $_SESSION['last_active_check'] = $now;

        global $pdo;
        if (isset($pdo) && is_object($pdo)) {
            $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
            $stmt->execute([current_user()['id']]);
            $row = $stmt->fetch();

            if (!$row || (int) $row['is_active'] !== 1) {
                // Account was deactivated — destroy the session.
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', $now - 42000,
                        $params['path'], $params['domain'],
                        $params['secure'], $params['httponly']
                    );
                }
                session_destroy();

                // Redirect to login with a message.
                redirect_to('/admin/login.php?deactivated=1');
            }
        }
    }
}

function require_role($roles): void
{
    require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403);
        die('You do not have access to this page.');
    }
}
