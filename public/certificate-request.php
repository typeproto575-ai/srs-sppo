<?php
/**
 * SRS-SPPO public service selector.
 * Online actions create pre-applications only; the secretary verifies and
 * approves every transaction before processing or physical release.
 */
require_once __DIR__ . '/../includes/functions.php';
$basePath = $basePath ?? base_path();
$pageTitle = 'Parish Office Services';
require_once __DIR__ . '/../includes/header.php';
?>
<section class="workflow-hero">
    <span class="eyebrow">Parish Office Services</span>
    <h1>Request a certificate or apply for a sacrament</h1>
    <p>Use an online form to send your details to the parish office for review, or visit the office for help. A submission is not approval or an official certificate; the secretary verifies requirements and identity before processing.</p>
</section>
<section class="option-group">
    <h2>Request a certificate</h2>
    <p>Use these forms when the sacrament has already taken place and you need a certified copy of the parish record.</p>
    <div class="hub-cards">
        <a class="hub-card" href="<?= $basePath ?>/public/certificate-requests/baptismal.php"><h3>Baptismal Certificate</h3><p>Request a certified copy of an existing baptismal record.</p><strong>Request online &rarr;</strong></a>
        <a class="hub-card" href="<?= $basePath ?>/public/certificate-requests/confirmation.php"><h3>Confirmation Certificate</h3><p>Request a certified copy of an existing confirmation record.</p><strong>Request online &rarr;</strong></a>
        <a class="hub-card" href="<?= $basePath ?>/public/certificate-requests/first-communion.php"><h3>First Communion Certificate</h3><p>Request a certified copy of an existing First Communion record.</p><strong>Request online &rarr;</strong></a>
        <a class="hub-card" href="<?= $basePath ?>/public/certificate-requests/marriage.php"><h3>Marriage Certificate</h3><p>Request a certified copy of an existing marriage record.</p><strong>Request online &rarr;</strong></a>
    </div>
</section>
<section class="option-group">
    <h2>Apply for a sacrament</h2>
    <p>Online applications are available for Baptism, Confirmation, First Communion, and Marriage.</p>
    <div class="hub-cards">
        <a class="hub-card" href="<?= $basePath ?>/public/sacrament-applications/baptismal.php"><h3>Baptism Application</h3><p>Start an application for a child or infant baptism.</p><strong>Apply online &rarr;</strong></a>
        <a class="hub-card" href="<?= $basePath ?>/public/sacrament-applications/marriage.php"><h3>Marriage Application</h3><p>Start the parish review process for a marriage application.</p><strong>Apply online &rarr;</strong></a>
        <a class="hub-card" href="<?= $basePath ?>/public/sacrament-applications/confirmation.php"><h3>Confirmation Application</h3><p>Start an application for Confirmation.</p><strong>Apply online &rarr;</strong></a>
        <a class="hub-card" href="<?= $basePath ?>/public/sacrament-applications/first-communion.php"><h3>First Communion Application</h3><p>Start an application for First Communion.</p><strong>Apply online &rarr;</strong></a>
    </div>
</section>
<section class="option-group">
    <h2>Need to correct an issued certificate?</h2>
    <p>If you already received a certificate and need to report an error or request a correction, use the parish correction request form.</p>
    <div class="hub-cards">
        <a class="hub-card" href="<?= $basePath ?>/public/correction-request.php"><h3>Certificate Correction Request</h3><p>Report an issue in a previously issued certificate.</p><strong>Submit correction request &rarr;</strong></a>
    </div>
</section>
<section class="option-group">
    <h2>Before submitting</h2>
    <p>Online forms do not create an account, accept payment, or release a certificate online. <button type="button" class="help-btn" aria-expanded="false" aria-controls="before-submitting-help" title="Show what to expect if documents are needed">?</button></p>
    <div id="before-submitting-help" class="help-text" hidden><p>Requests are reviewed by the parish office; approval is not guaranteed and identity-document uploads are not collected here. If documents are needed, show them in person at the Parish Office (originals or photocopies, as the office requires). The system never receives or stores documents — please do not email or message them.</p></div>
    <div class="hero-actions"><a class="btn" href="<?= $basePath ?>/public/requirements.php">View Requirements</a><a class="btn btn-secondary" href="<?= $basePath ?>/public/how-it-works.php">How It Works</a><a class="btn btn-secondary" href="<?= $basePath ?>/public/track-request.php">Track My Request</a></div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
