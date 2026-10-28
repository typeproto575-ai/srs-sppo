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
$pageDescriptions = [
    'Home' => "St. Patrick's Parish Office in Dingalan, Aurora: prepare requirements, submit a request for parish review, and track its status.",
    'Requirements' => 'Review parish office requirements before requesting a sacramental certificate or applying for a sacrament.',
    'Parish Office Services' => 'Request a Baptismal, Confirmation, First Communion, or Marriage certificate from St. Patrick Parish Office.',
    'Sacrament Applications' => 'Submit information for Baptismal, Confirmation, First Communion, or Marriage preparation for parish review.',
    'Track a Request or Application' => 'Check the processing status of a St. Patrick Parish Office request or sacrament application using its tracking number and email address.',
    'How It Works' => 'Learn how online intake, secretary review, in-person requirements, and certificate pickup work at St. Patrick Parish Office.',
    'Privacy Notice' => 'Read how St. Patrick Parish Office handles information submitted through this parish records system.',
    'Terms and Conditions' => 'Review the terms for using the St. Patrick Parish Office online request system.',
];
$pageDescription = $pageDescription ?? ($pageDescriptions[$pageTitle] ?? $pageDescriptions['Home']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Character encoding + responsive viewport for mobile visitors -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | SRS-SPPO</title>
    <meta name="description" content="<?= e($pageDescription) ?>">
    <meta name="theme-color" content="#2F5E3F">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Circular parish logo as the browser favicon (transparent corners) -->
    <link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/logo-circle.png">
    <?php
        $publicStyleVersion = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
    ?>
    <!-- Main stylesheet for the public site -->
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css?v=<?= (int) $publicStyleVersion ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?> | St. Patrick's Parish Office">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?= $basePath ?>/assets/images/logo-circle.png">
    <meta property="og:image:alt" content="St. Patrick Parish logo">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<!-- Top navigation bar -->
<header class="site-header">
    <!-- Brand doubles as the home link; shows the parish logo image + name -->
    <a href="<?= $basePath ?>/public/index.php" class="site-brand">
        <img src="<?= $basePath ?>/assets/images/logo.jpg" alt="St. Patrick Parish logo" class="brand-logo-public">
        St. Patrick's Parish Office
    </a>
    <!-- Primary public navigation (small inline SVG icons, no external library) -->
    <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16"/></svg>Menu</button>
    <nav id="site-nav">
        <a href="<?= $basePath ?>/public/index.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Home</a>
        <a href="<?= $basePath ?>/public/how-it-works.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>How It Works</a>
        <a href="<?= $basePath ?>/public/requirements.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Requirements</a>
        <a href="<?= $basePath ?>/public/certificate-request.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>Office Services</a>
        
        <a href="<?= $basePath ?>/public/correction-request.php"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>Request Correction</a>
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
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || t.getAttribute('aria-expanded') !== 'true') return;
        var nav = document.getElementById('site-nav');
        if (nav) nav.classList.remove('open');
        t.setAttribute('aria-expanded', 'false');
        t.focus();
    });
})();
</script>
<main class="site-main<?= !empty($pageClass) ? ' ' . e($pageClass) : '' ?>" id="main-content">
