<?php
/**
 * Public sacrament application selector.
 */
require_once __DIR__ . '/../includes/functions.php';
$basePath = $basePath ?? base_path();
$pageTitle = 'Sacrament Applications';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="workflow-hero">
    <span class="eyebrow">Sacrament Applications</span>
    <h1>Apply to receive a sacrament</h1>
    <p>Use these online forms to send your information to the parish office for review. Submitting a form does not mean the application is approved; the secretary will contact you about requirements, preparation, and scheduling.</p>
</section>
<section class="option-group">
    <h2>Choose an application</h2>
    <div class="hub-cards">
        <a class="hub-card" href="<?= $basePath ?>/public/sacrament-applications/baptismal.php">
            <h3>Baptism Application</h3>
            <p>Apply to have an infant or child baptized at Saint Patrick's Parish.</p>
            <strong>Start Baptism application &rarr;</strong>
        </a>
        <a class="hub-card" href="<?= $basePath ?>/public/sacrament-applications/marriage.php">
            <h3>Marriage Application</h3>
            <p>Begin the parish review process for a church wedding.</p>
            <strong>Start Marriage application &rarr;</strong>
        </a>
        <a class="hub-card" href="<?= $basePath ?>/public/sacrament-applications/confirmation.php">
            <h3>Confirmation Application</h3>
            <p>Submit information for Confirmation preparation and scheduling.</p>
            <strong>Start Confirmation application &rarr;</strong>
        </a>
        <a class="hub-card" href="<?= $basePath ?>/public/sacrament-applications/first-communion.php">
            <h3>First Communion Application</h3>
            <p>Submit information for First Communion preparation and scheduling.</p>
            <strong>Start First Communion application &rarr;</strong>
        </a>
    </div>
</section>
<section class="option-group">
    <h2>Before you begin</h2>
    <p>Prepare the information listed on the requirements page. The parish office may ask for additional documents or an in-person visit. Keep the tracking number and email address you use after submitting.</p>
    <div class="hero-actions">
        <a class="btn" href="<?= $basePath ?>/public/requirements.php">View Requirements</a>
        <a class="btn btn-secondary" href="<?= $basePath ?>/public/how-it-works.php">How It Works</a>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
