<?php
/**
 * =====================================================================
 * SRS-SPPO LOGOUT
 * =====================================================================
 * Purpose: End the staff member's session and return them to the
 *          login page. Safe to call even when no one is logged in.
 *
 * Security: logout is state-changing, so it only runs on a POST that
 * carries a valid CSRF token — a cross-site <img> or link cannot log
 * the user out. A direct GET shows a one-click confirm page instead
 * (the "Log Out" control in the admin header is already a POST form).
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php'; // starts session, loads helpers

// Only clear/destroy on a valid POST — same rule as every other write action.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (current_user()) {
        session_unset();    // drop all session variables
        session_destroy();  // invalidate the session on the server
    }
    redirect_to('/admin/login.php');
}

// Not a valid logout POST: bounce anonymous visitors to login, and give a
// logged-in user who hit the URL directly a small confirm page to POST from.
if (!current_user()) {
    redirect_to('/admin/login.php');
}
$basePath = $basePath ?? base_path();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Log Out | SRS-SPPO</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css">
</head>
<body>
<main class="login-page">
    <h1>Log Out</h1>
    <p class="field-hint">Are you sure you want to end your session?</p>
    <form method="post" action="">
        <?= csrf_field() ?>
        <button type="submit" class="btn">Yes, log out</button>
        <a class="btn btn-secondary" href="<?= $basePath ?>/admin/dashboard.php">Cancel</a>
    </form>
</main>
</body>
</html>
