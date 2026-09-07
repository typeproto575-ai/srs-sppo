<?php
/**
 * =====================================================================
 * SPPO-IS SHARED PUBLIC PAGE HEADER
 * =====================================================================
 * Purpose: Opens the HTML document and renders the public navigation
 *          bar for every visitor-facing page.
 *
 * Expects (all optional, set before including this file):
 *   - $pageTitle : Browser-tab title; falls back to the parish name.
 *   - $basePath  : URL prefix; falls back to base_path() so links work
 *                  when the app runs in a subfolder (e.g. /sppo-is).
 *
 * Requires includes/functions.php to already be loaded (for e() and
 * base_path()) — every public page does this at the top.
 * =====================================================================
 */

// Default page title when a page doesn't provide its own.
$pageTitle = $pageTitle ?? "St. Patrick's Parish Office";
// Subfolder-safe URL prefix used for every asset and internal link.
$basePath  = $basePath ?? base_path();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Character encoding + responsive viewport for mobile visitors -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | SPPO-IS</title>
    <?php
        $publicStyleVersion = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
    ?>
    <!-- Main stylesheet for the public site -->
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css?v=<?= (int) $publicStyleVersion ?>">
</head>
<body>
<!-- Top navigation bar -->
<header class="site-header">
    <!-- Brand doubles as the home link -->
    <a href="<?= $basePath ?>/public/index.php" class="site-brand">St. Patrick's Parish Office</a>
    <!-- Primary public navigation -->
    <nav>
        <a href="<?= $basePath ?>/public/index.php">Home</a>
        <a href="<?= $basePath ?>/public/how-it-works.php">How It Works</a>
        <a href="<?= $basePath ?>/public/requirements.php">Requirements</a>
        <a href="<?= $basePath ?>/public/certificate-request.php">Request a Certificate</a>
        <a href="<?= $basePath ?>/public/track-request.php">Track My Request</a>
    </nav>
</header>
<main class="site-main">
