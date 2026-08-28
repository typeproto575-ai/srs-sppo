<?php
/**
 * =====================================================================
 * SPPO-IS LOGOUT
 * =====================================================================
 * Purpose: End the staff member's session and return them to the
 *          login page. Safe to call even when no one is logged in.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php'; // starts session, loads helpers

// Only clear/destroy when a session user actually exists.
if (current_user()) {
    session_unset();    // drop all session variables
    session_destroy();  // invalidate the session on the server
}

// Redirect to the login page (subfolder-safe via redirect_to).
redirect_to('/admin/login.php');
