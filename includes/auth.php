<?php
/**
 * Session guard. Include at the top of any /admin or /priest page.
 * Usage:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_role('secretary');            // secretary-only page
 *   require_role(['secretary', 'priest']); // either role
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
