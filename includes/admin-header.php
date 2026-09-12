<?php
/**
 * =====================================================================
 * SPPO-IS SHARED ADMIN/STAFF PAGE HEADER
 * =====================================================================
 * Purpose: Renders the top navigation bar, sidebar menu, and opening
 *          HTML tags for all admin and priest pages.
 *
 * Expects these optional variables (set before including):
 *   - $pageTitle  : Page title shown in the browser tab
 *   - $basePath   : Base URL path (defaults to base_path())
 *   - $isSecretary: Whether the current user is a secretary
 *   - $extraHead  : Extra HTML to inject into <head> (e.g. scripts)
 * =====================================================================
 */

// Set defaults for optional variables
$basePath = $basePath ?? base_path();
$isSecretary = isset($isSecretary) ? $isSecretary : (current_user()['role'] === 'secretary');
$pageTitle = $pageTitle ?? 'Admin Dashboard';
$extraHead = $extraHead ?? '';

// ---- Current page detection (for the "active" nav states) --------------------
// Normalise the request path to an app-relative path (e.g.
// "/admin/requests/queue.php") so it can be compared against the hrefs below.
$currentUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($basePath !== '' && strncmp($currentUri, $basePath, strlen($basePath)) === 0) {
    $currentUri = substr($currentUri, strlen($basePath));
}
// n.b. when the app sits at the document root ($basePath === '') the path is
// already app-relative and is left untouched.

// Returns ' class="active"' when the current page is one of $navPaths.
$isActive = function (string ...$navPaths) use ($currentUri): string {
    return in_array($currentUri, $navPaths, true) ? ' class="active"' : '';
};

// Returns ' open' when the current page lives under one of $prefixes, so the
// sidebar group holding the active page stays expanded after navigation.
$groupIsOpen = function (string ...$prefixes) use ($currentUri): string {
    foreach ($prefixes as $prefix) {
        if (strncmp($currentUri, $prefix, strlen($prefix)) === 0) return ' open';
    }
    return '';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Responsive viewport: without this, phones render the admin shell scaled-down -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | SPPO-IS</title>
    <!-- Parish logo as the browser favicon (tab icon) -->
    <link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/logo-circle.png">
    <?php
        $styleVersion = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
        $adminStyleVersion = file_exists(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : time();
    ?>
    <!-- Main stylesheets for the admin/priest interface -->
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css?v=<?= (int) $styleVersion ?>">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css?v=<?= (int) $adminStyleVersion ?>">
    <?= $extraHead ?> <!-- Any page-specific head content (e.g. FullCalendar) -->
</head>
<body>
<!-- Top master header bar -->
<header class="site-master-header">
    <!-- Brand link: keep the dashboard page connected while matching the earlier parish-workspace layout -->
    <a href="<?= $basePath ?>/admin/dashboard.php" class="header-brand-wrap" aria-label="Go to dashboard">
        <img src="<?= $basePath ?>/assets/images/logo.jpg" alt="St. Patrick Parish logo" class="brand-logo" aria-hidden="true">
        <span class="header-home-brand">Schedule and Record System</span>
    </a>
    <div class="master-profile">
        <!-- Quick utility links in the top bar -->
        <nav class="master-utilities" aria-label="Admin utilities">
            <a href="<?= $basePath ?>/admin/reports/index.php"<?= $isActive('/admin/reports/index.php', '/admin/reports/monthly.php', '/admin/reports/yearly.php') ?>>Reports</a>
            <?php if ($isSecretary): ?>
                <!-- Secretary-only tools -->
                <a href="<?= $basePath ?>/admin/system/backup.php"<?= $isActive('/admin/system/backup.php') ?>>Backup</a>
                <a href="<?= $basePath ?>/admin/system/accounts.php"<?= $isActive('/admin/system/accounts.php') ?>>Accounts</a>
            <?php endif; ?>
            <a href="<?= $basePath ?>/admin/system/audit-log.php"<?= $isActive('/admin/system/audit-log.php') ?>>Audit Log</a>
            <a class="btn btn-header-logout" href="<?= $basePath ?>/admin/logout.php">Log Out</a>
        </nav>
    </div>
</header>
<div class="admin-layout">
    <!-- Left sidebar navigation -->
    <nav class="admin-sidebar" aria-label="Admin navigation">
        <div class="sidebar-scroll">
        <!-- Requests section -->
        <details class="sidebar-group" data-sidebar-group="requests" name="admin-sidebar"<?= $groupIsOpen('/admin/requests/') ?>>
            <summary class="sidebar-group-title">Requests</summary>
            <ul class="sidebar-menu">
                <li><a href="<?= $basePath ?>/admin/requests/queue.php"<?= $isActive('/admin/requests/queue.php') ?>>Certificate Requests</a></li>
                <li><a href="<?= $basePath ?>/admin/requests/applications-queue.php"<?= $isActive('/admin/requests/applications-queue.php') ?>>Sacrament Applications</a></li>
                <?php if ($isSecretary): ?>
                    <!-- Secretary-only walk-in encoding tools -->
                    <li><a href="<?= $basePath ?>/admin/requests/walk-in-encode.php"<?= $isActive('/admin/requests/walk-in-encode.php') ?>>Walk-in Request</a></li>
                    <li><a href="<?= $basePath ?>/admin/requests/walk-in-application.php"<?= $isActive('/admin/requests/walk-in-application.php') ?>>Walk-in Application</a></li>
                <?php endif; ?>
            </ul>
        </details>

        <!-- Records section -->
        <details class="sidebar-group" data-sidebar-group="records" name="admin-sidebar"<?= $groupIsOpen('/admin/records/', '/admin/system/person-mapper.php') ?>>
            <summary class="sidebar-group-title">Records</summary>
            <ul class="sidebar-menu sidebar-submenu">
                <li><a href="<?= $basePath ?>/admin/records/baptismal.php"<?= $isActive('/admin/records/baptismal.php') ?>>Baptismal</a></li>
                <li><a href="<?= $basePath ?>/admin/records/confirmation.php"<?= $isActive('/admin/records/confirmation.php') ?>>Confirmation</a></li>
                <li><a href="<?= $basePath ?>/admin/records/first-communion.php"<?= $isActive('/admin/records/first-communion.php') ?>>First Communion</a></li>
                <li><a href="<?= $basePath ?>/admin/records/marriage.php"<?= $isActive('/admin/records/marriage.php') ?>>Marriage</a></li>
                <li><a href="<?= $basePath ?>/admin/records/death-burial.php"<?= $isActive('/admin/records/death-burial.php') ?>>Death / Burial</a></li>
                <li><a href="<?= $basePath ?>/admin/system/person-mapper.php"<?= $isActive('/admin/system/person-mapper.php') ?>>Person Mapper</a></li>
            </ul>
        </details>

        <!-- Certificate generation section -->
        <details class="sidebar-group" data-sidebar-group="certificates" name="admin-sidebar"<?= $groupIsOpen('/admin/certificates/') ?>>
            <summary class="sidebar-group-title">Certificates</summary>
            <ul class="sidebar-menu sidebar-submenu">
                <li><a href="<?= $basePath ?>/admin/certificates/generate.php"<?= $isActive('/admin/certificates/generate.php') ?>>Generate / Preview</a></li>
                <li><a href="<?= $basePath ?>/admin/certificates/history.php"<?= $isActive('/admin/certificates/history.php') ?>>Print / Pickup History</a></li>
            </ul>
        </details>

        <!-- Schedule section -->
        <details class="sidebar-group" data-sidebar-group="schedule" name="admin-sidebar"<?= $groupIsOpen('/admin/schedule/') ?>>
            <summary class="sidebar-group-title">Schedule</summary>
            <ul class="sidebar-menu sidebar-submenu">
                <li><a href="<?= $basePath ?>/admin/schedule/calendar.php"<?= $isActive('/admin/schedule/calendar.php') ?>>Calendar</a></li>
                <li><a href="<?= $basePath ?>/admin/schedule/booking-conflicts.php"<?= $isActive('/admin/schedule/booking-conflicts.php') ?>>Booking Conflicts</a></li>
                <li><a href="<?= $basePath ?>/admin/schedule/announcements.php"<?= $isActive('/admin/schedule/announcements.php') ?>>Announcements</a></li>
            </ul>
        </details>

        <!-- Website section -->
        <details class="sidebar-group" data-sidebar-group="website" name="admin-sidebar"<?= $groupIsOpen('/admin/system/site-settings.php') ?>>
            <summary class="sidebar-group-title">Public Website</summary>
            <ul class="sidebar-menu sidebar-submenu">
                <li><a href="<?= $basePath ?>/admin/system/site-settings.php"<?= $isActive('/admin/system/site-settings.php') ?>>Public Page Content</a></li>
            </ul>
        </details>
        </div>
    </nav>

    <script>
    // Sidebar accordion: only one group open at a time.
    // The server pre-opens the group holding the current page ($groupIsOpen),
    // so after navigation the active section is already expanded.
    document.querySelectorAll('.sidebar-group').forEach(function (group) {
        group.addEventListener('toggle', function () {
            if (this.open) {
                document.querySelectorAll('.sidebar-group').forEach(function (other) {
                    if (other !== group) other.open = false;
                });
            }
        });
    });
    </script>

    <main class="admin-content">