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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= e($pageTitle) ?> | SPPO-IS</title>
    <!-- Main stylesheets for the admin/priest interface -->
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css">
    <?= $extraHead ?> <!-- Any page-specific head content (e.g. FullCalendar) -->
</head>
<body>
<!-- Top master header bar -->
<header class="site-master-header">
    <!-- Brand link: goes to the Secretary dashboard (sole system administrator) -->
    <a href="<?= $basePath ?>/admin/dashboard.php" class="header-home-brand">SPPO-IS | PARISH WORKSPACE</a>
    <div class="master-profile">
        <!-- Quick utility links in the top bar -->
        <nav class="master-utilities" aria-label="Admin utilities">
            <a href="<?= $basePath ?>/admin/reports/monthly.php">Monthly</a>
            <a href="<?= $basePath ?>/admin/reports/yearly.php">Yearly</a>
            <?php if ($isSecretary): ?>
                <!-- Secretary-only tools -->
                <a href="<?= $basePath ?>/admin/system/backup.php">Backup</a>
                <a href="<?= $basePath ?>/admin/system/accounts.php">Accounts</a>
            <?php endif; ?>
            <a href="<?= $basePath ?>/admin/system/audit-log.php">Audit Log</a>
            <a class="btn btn-header-logout" href="<?= $basePath ?>/admin/logout.php">Log Out</a>
        </nav>
    </div>
</header>
<div class="admin-layout">
    <!-- Left sidebar navigation -->
    <nav class="admin-sidebar" aria-label="Admin navigation">
        <!-- Requests section -->
        <div class="sidebar-group">
            <p class="sidebar-group-title">Requests</p>
            <ul class="sidebar-menu">
                <li><a href="<?= $basePath ?>/admin/requests/queue.php">Certificate Requests</a></li>
                <li><a href="<?= $basePath ?>/admin/requests/applications-queue.php">Sacrament Applications</a></li>
                <?php if ($isSecretary): ?>
                    <!-- Secretary-only walk-in encoding tools -->
                    <li><a href="<?= $basePath ?>/admin/requests/walk-in-encode.php">Walk-in Request</a></li>
                    <li><a href="<?= $basePath ?>/admin/requests/walk-in-application.php">Walk-in Application</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Records section -->
        <div class="sidebar-group">
            <p class="sidebar-group-title">Records</p>
            <ul class="sidebar-menu sidebar-submenu">
                <li><a href="<?= $basePath ?>/admin/records/baptismal.php">Baptismal</a></li>
                <li><a href="<?= $basePath ?>/admin/records/confirmation.php">Confirmation</a></li>
                <li><a href="<?= $basePath ?>/admin/records/first-communion.php">First Communion</a></li>
                <li><a href="<?= $basePath ?>/admin/records/marriage.php">Marriage</a></li>
            </ul>
        </div>

        <!-- Certificate generation section -->
        <div class="sidebar-group">
            <p class="sidebar-group-title">Certificates</p>
            <ul class="sidebar-menu sidebar-submenu">
                <li><a href="<?= $basePath ?>/admin/certificates/generate.php">Generate / Preview</a></li>
                <li><a href="<?= $basePath ?>/admin/certificates/history.php">Print / Pickup History</a></li>
            </ul>
        </div>

        <!-- Schedule section -->
        <div class="sidebar-group">
            <p class="sidebar-group-title">Schedule</p>
            <ul class="sidebar-menu sidebar-submenu">
                <li><a href="<?= $basePath ?>/admin/schedule/calendar.php">Calendar</a></li>
                <li><a href="<?= $basePath ?>/admin/schedule/booking-conflicts.php">Booking Conflicts</a></li>
                <li><a href="<?= $basePath ?>/admin/schedule/announcements.php">Announcements</a></li>
            </ul>
        </div>
    </nav>

    <main class="admin-content">