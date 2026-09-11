<?php
/**
 * =====================================================================
 * SRS-SPPO SHARED PUBLIC PAGE HEADER
 * =====================================================================
 * Purpose: Opens the HTML document and renders the public navigation
 *          bar for every visitor-facing page.
 *
 * Expects (all optional, set before including this file):
 *   - $pageTitle : Browser-tab title; falls back to the parish name.
 *   - $basePath  : URL prefix; falls back to base_path() so links work
 *                  when the app runs in a subfolder (e.g. /srs-sppo).
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
    <title><?= e($pageTitle) ?> | SRS-SPPO</title>
    <!-- Circular parish logo as the browser favicon (transparent corners) -->
    <link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/logo-circle.png">
    <?php
        $publicStyleVersion = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
    ?>
    <!-- Main stylesheet for the public site -->
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css?v=<?= (int) $publicStyleVersion ?>">
    <meta name="description" content="St. Patrick's Parish Office, Dingalan, Aurora - request sacramental certificates, apply for Baptism or Marriage, and track your request online.">
    <meta property="og:title" content="St. Patrick's Parish Office">
    <meta property="og:description" content="Sacramental certificates and applications for the Parish of St. Patrick, Dingalan, Aurora.">
    <meta property="og:type" content="website">
</head>
<body>
<!-- Top navigation bar -->
<header class="site-header">
    <!-- Brand doubles as the home link; shows the parish logo image + name -->
    <a href="<?= $basePath ?>/public/index.php" class="site-brand">
        <img src="<?= $basePath ?>/assets/images/logo.jpg" alt="St. Patrick Parish logo" class="brand-logo-public">
        St. Patrick's Parish Office
    </a>
    <!-- Primary public navigation (small inline SVG icons, no external library) -->
    <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav">☰ Menu</button>
    <nav id="site-nav">
        <a href="<?= $basePath ?>/public/index.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Home</a>
        <a href="<?= $basePath ?>/public/how-it-works.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>How It Works</a>
        <a href="<?= $basePath ?>/public/requirements.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Requirements</a>
        <a href="<?= $basePath ?>/public/certificate-request.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>Request a Certificate</a>
        <a href="<?= $basePath ?>/public/track-request.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Track My Request</a>
    </nav>
</header>
<script>
(function () {
    var t = document.getElementById('nav-toggle');
    if (!t) return;
    t.addEventListener('click', function () {
        var nav = document.getElementById('site-nav');
        if (!nav) return;
        var open = nav.classList.toggle('open');
        t.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();
</script>
<main class="site-main">
