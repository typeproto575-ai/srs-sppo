<?php
/**
 * =====================================================================
 * SRS-SPPO SACRAMENT APPLICATION TABS
 * =====================================================================
 * Purpose: Renders a tab-style navigation bar shown on every sacrament
 *          application page (Baptismal, Confirmation, First Communion,
 *          Marriage). Lets visitors switch between application forms
 *          without going back to the selector page.
 *
 * Usage: require_once __DIR__ . '/application-tabs.php';
 *        Set $currentApp = 'baptismal' | 'confirmation' |
 *                          'first_communion' | 'marriage' beforehand.
 * =====================================================================
 */

// Which tab is currently active (defaults to none highlighted)
$currentApp = $currentApp ?? '';

// All active public application types with their labels and URLs.
// Confirmation and First Communion remain office-encoded only; they are not
// available as new public application routes.
$appTabs = [
    'baptismal' => ['label' => 'Baptismal', 'url' => base_path() . '/public/sacrament-applications/baptismal.php'],
    'marriage'  => ['label' => 'Marriage',  'url' => base_path() . '/public/sacrament-applications/marriage.php'],
];
?>

<!-- Tab navigation between sacrament application forms -->
<nav class="app-tabs" aria-label="Sacrament applications">
    <?php foreach ($appTabs as $key => $tab): ?>
        <a href="<?= e($tab['url']) ?>"
           class="app-tab<?= $currentApp === $key ? ' app-tab-active' : '' ?>"
           <?= $currentApp === $key ? 'aria-current="page"' : '' ?>>
            <?= e($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>