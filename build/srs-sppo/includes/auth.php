<?php
/**
 * Session guard. Include at the top of any /admin page.
 * Usage:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_role('secretary');            // all authenticated staff access
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

    // ---- Idle timeout (30 minutes of inactivity ends the session) -----------
    // A logged-in-but-unattended workstation is a real risk in a parish
    // office, so any 30-minute gap between requests forces a fresh login.
    // Uses its own timestamp (last_activity), separate from last_active_check
    // below, which only throttles the periodic DB re-verification.
    $idleLimit  = 30 * 60; // seconds
    $now        = time();
    $lastActive = $_SESSION['last_activity'] ?? null;

    if ($lastActive !== null && ($now - $lastActive) > $idleLimit) {
        // Expire the session exactly like the deactivation path below.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', $now - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        redirect_to('/admin/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = $now;

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
            $stmt = $pdo->prepare('SELECT is_active, auth_version FROM users WHERE id = ?');
            $stmt->execute([current_user()['id']]);
            $row = $stmt->fetch();

            if (!$row || (int) $row['is_active'] !== 1 || (int) ($row['auth_version'] ?? 1) !== (int) (current_user()['auth_version'] ?? 1)) {
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
    $role = current_user()['role'];
    $allowed = in_array($role, $roles, true);
    if (!$allowed) {
        http_response_code(403);
        die('You do not have access to this page.');
    }
}
