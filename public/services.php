<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Office Services';
$basePath = $basePath ?? base_path();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="workflow-hero">
    <span class="eyebrow">Parish Office Services</span>
    <h1>What do you need?</h1>
    <p>Choose an application for a future sacrament or request a copy of an existing parish record. Each form shows the relevant preparation guidance before you submit.</p>
</section>

<section class="option-group" id="sacrament-applications" aria-labelledby="application-services-heading">
    <h2 id="application-services-heading">Apply to receive a sacrament</h2>
    <p>Submit an application for parish-office review. The office will confirm requirements, preparation, approval, and scheduling.</p>
    <div class="service-grid">
        <a class="service-card" href="<?= e($basePath) ?>/public/sacrament-applications/baptismal.php">
            <span class="service-number">01</span>
            <h3>Baptism</h3>
            <p>Apply for a child's Baptism at the parish.</p>
            <span class="service-link">Start Baptism application &rarr;</span>
        </a>
        <a class="service-card" href="<?= e($basePath) ?>/public/sacrament-applications/first-communion.php">
            <span class="service-number">02</span>
            <h3>First Holy Communion</h3>
            <p>Submit an application for First Communion preparation.</p>
            <span class="service-link">Start First Communion application &rarr;</span>
        </a>
        <a class="service-card" href="<?= e($basePath) ?>/public/sacrament-applications/confirmation.php">
            <span class="service-number">03</span>
            <h3>Confirmation</h3>
            <p>Submit an application for Confirmation preparation.</p>
            <span class="service-link">Start Confirmation application &rarr;</span>
        </a>
        <a class="service-card" href="<?= e($basePath) ?>/public/sacrament-applications/marriage.php">
            <span class="service-number">04</span>
            <h3>Holy Matrimony</h3>
            <p>Begin the application and review process for a church wedding.</p>
            <span class="service-link">Start Marriage application &rarr;</span>
        </a>
    </div>
</section>

<section class="option-group" id="certificate-requests" aria-labelledby="certificate-services-heading">
    <h2 id="certificate-services-heading">Request a certificate copy</h2>
    <p>Use this when the sacrament has already taken place and you need a certified copy of the parish record.</p>
    <div class="service-grid">
        <a class="service-card service-card-accent" href="<?= e($basePath) ?>/public/certificate-request.php">
            <span class="service-number">05</span>
            <h3>Baptism, First Communion, Confirmation, or Marriage</h3>
            <p>Request a copy of an existing sacramental record. The parish office verifies the record and provides updates.</p>
            <span class="service-link">Request a certificate copy &rarr;</span>
        </a>
    </div>
</section>

<section class="option-group">
    <p>Not sure which documents apply? Read the <a href="<?= e($basePath) ?>/public/requirements.php">optional requirements and preparation guide</a>. Other parish services are handled by contacting or visiting the Parish Office.</p>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
