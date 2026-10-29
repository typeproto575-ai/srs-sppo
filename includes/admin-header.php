<?php
/**
 * =====================================================================
 * SPPO-IS SHARED ADMIN/STAFF PAGE HEADER
 * =====================================================================
 * Purpose: Renders the top navigation bar, sidebar menu, and opening
 *          HTML tags for all admin (secretary) pages.
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
$isSecretary = isset($isSecretary) ? $isSecretary : (current_user()['role'] ?? '') === 'secretary';
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
        <!-- Notification bell: global admin notifications (see admin/api/notifications.php) -->
        <div class="notif-wrap" data-notif-wrap data-notif-api="<?= $basePath ?>/admin/api/notifications.php" data-csrf="<?= e(csrf_token()) ?>">
            <button type="button" class="notif-bell" data-notif-bell aria-label="Notifications" aria-expanded="false">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
            </button>
            <div class="notif-panel" data-notif-panel hidden>
                <div class="notif-panel-head">
                    <span class="notif-panel-title">Notifications</span>
                    <a href="<?= $basePath ?>/admin/notifications.php" class="notif-view-all">View All</a>
                    <button type="button" class="notif-mark-all" data-notif-mark-all>Mark all read</button>
                </div>
                <ul class="notif-list" data-notif-list></ul>
                <p class="notif-empty" data-notif-empty hidden>No new notifications.</p>
            </div>
        </div>
        <!-- Quick utility links in the top bar -->
        <nav class="master-utilities" aria-label="Admin utilities">
            <a href="<?= $basePath ?>/admin/reports/index.php"<?= $isActive('/admin/reports/index.php', '/admin/reports/monthly.php', '/admin/reports/yearly.php') ?>>Reports</a>
            <form autocomplete="off" method="post" action="<?= $basePath ?>/admin/logout.php" class="logout-form">
                <?= csrf_field() ?>
                <button type="submit" class="logout-link-btn">Log Out</button>
            </form>
        </nav>
    </div>
</header>
<div class="admin-layout">
    <!-- Left sidebar navigation -->
    <nav class="admin-sidebar" aria-label="Admin navigation">
        <div class="sidebar-scroll">
        <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-expanded="true" aria-controls="admin-sidebar-links">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/><path d="m15 8 4 4-4 4"/></svg>
            <span data-sidebar-toggle-label>Hide menu</span>
        </button>
        <div id="admin-sidebar-links">
        <!-- Requests section -->
        <details class="sidebar-group" data-sidebar-group="requests" name="admin-sidebar"<?= $groupIsOpen('/admin/requests/') ?>>
            <summary class="sidebar-group-title" title="Requests"><span class="sidebar-group-glyph" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v12h-4l-2 3h-4l-2-3H4z"/><path d="M4 12h5l2 3h2l2-3h5"/></svg></span><span class="sidebar-group-label">Requests</span></summary>
            <ul class="sidebar-menu">
                <li><a href="<?= $basePath ?>/admin/requests/queue.php"<?= $isActive('/admin/requests/queue.php') ?> title="Certificate Requests" data-compact="CR"><span class="sidebar-link-glyph" aria-hidden="true">CR</span><span class="sidebar-link-label">Certificate Requests</span></a></li>
                <li><a href="<?= $basePath ?>/admin/requests/applications-queue.php"<?= $isActive('/admin/requests/applications-queue.php') ?> title="Sacrament Applications" data-compact="SA"><span class="sidebar-link-glyph" aria-hidden="true">SA</span><span class="sidebar-link-label">Sacrament Applications</span></a></li>
            </ul>
        </details>

        <!-- Schedule section -->
        <details class="sidebar-group" data-sidebar-group="schedule" name="admin-sidebar"<?= $groupIsOpen('/admin/schedule/') ?>>
            <summary class="sidebar-group-title" title="Schedule"><span class="sidebar-group-glyph" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/></svg></span><span class="sidebar-group-label">Schedule</span></summary>
            <ul class="sidebar-menu sidebar-submenu">
                <li><a href="<?= $basePath ?>/admin/schedule/calendar.php"<?= $isActive('/admin/schedule/calendar.php') ?> title="Calendar" data-compact="CA"><span class="sidebar-link-glyph" aria-hidden="true">CA</span><span class="sidebar-link-label">Calendar</span></a></li>
                <li><a href="<?= $basePath ?>/admin/schedule/announcements.php"<?= $isActive('/admin/schedule/announcements.php') ?> title="Announcements" data-compact="AN"><span class="sidebar-link-glyph" aria-hidden="true">AN</span><span class="sidebar-link-label">Announcements</span></a></li>
            </ul>
        </details>

        <?php if ($isSecretary): ?>
        <details class="sidebar-group" data-sidebar-group="system" name="admin-sidebar"<?= $groupIsOpen('/admin/system/') ?>>
            <summary class="sidebar-group-title" title="System"><span class="sidebar-group-glyph" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.6.9l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.6-.9l-1.7.7-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.9l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.6-.9l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.6.9l1.7-.7 1.4 2.4-1.4 1.1a7 7 0 0 1-.1 1.8z"/></svg></span><span class="sidebar-group-label">System</span></summary>
            <ul class="sidebar-menu sidebar-submenu">
                <li><a href="<?= $basePath ?>/admin/system/accounts.php"<?= $isActive('/admin/system/accounts.php') ?> title="Accounts" data-compact="AC"><span class="sidebar-link-glyph" aria-hidden="true">AC</span><span class="sidebar-link-label">Accounts</span></a></li>
                <li><a href="<?= $basePath ?>/admin/system/audit-log.php"<?= $isActive('/admin/system/audit-log.php') ?> title="Audit Log" data-compact="AL"><span class="sidebar-link-glyph" aria-hidden="true">AL</span><span class="sidebar-link-label">Audit Log</span></a></li>
            </ul>
        </details>
        <?php endif; ?>
        </div>
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
