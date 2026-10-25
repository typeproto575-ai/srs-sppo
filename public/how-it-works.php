<?php
/**
 * =====================================================================
 * SRS-SPPO "HOW IT WORKS" (public explainer page)
 * =====================================================================
 * Purpose: Walks visitors through the parish's request workflow in
 *          six ledger-style steps, then offers two path cards that
 *          route them to the right entry point. Static content — no
 *          database queries.
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Subfolder-safe URL prefix for the navigation links below.
$basePath = $basePath ?? base_path();

$pageTitle = 'How It Works';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Intro hero: frames the request workflow -->
<section class="workflow-hero">
<span class="eyebrow">How Online Intake Works</span>
    <h1>Convenient submission, careful parish review.</h1>
    <p>Send the details online first. The parish office will review them and tell you what to do next.</p>
</section>

<!-- Step-by-step ledger (one row per step) -->
<div class="ledger">

    <!-- Step 1: choose request type -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>Choose what you need</h3>
            <p>Choose a certificate if the sacrament already happened. Choose an application if you are preparing for Baptism, Confirmation, First Communion, or Marriage.</p>
        </div>
    </div>

    <!-- Step 2: provide known details -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>Send your request</h3>
            <p>Enter the details requested by the form. You do not need an account. Do not upload or send personal documents with the form.</p>
        </div>
    </div>

    <!-- Step 3: staff verification -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>Secretary review</h3>
            <p>The parish office checks the details against its records and confirms any requirements. Sending a form does not mean it is approved.</p>
        </div>
    </div>

    <!-- Step 4: payment (before processing; made outside the system) -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>If payment is needed</h3>
            <p>Payment is not taken on this website. If payment is needed, the parish office will tell you the amount and how to pay. Processing continues after the office records payment.</p>
        </div>
    </div>

    <!-- Step 5: notification -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>Keep your tracking number</h3>
            <p>After submitting, keep your tracking number and the email address you entered. Use both to check the request status.</p>
        </div>
    </div>

    <!-- Step 6: pickup (identity verified here — no online uploads) -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>Finish at the parish office</h3>
            <p>If documents are needed, the parish office will tell you what to bring. Certificates are collected at the Parish Office; they are not issued online.</p>
        </div>
    </div>

</div>

<!-- Two route cards: pick the flow that matches the visitor's need -->
<div class="path-cards">
    <div class="path-card">
        <h3>Certificate Request</h3>
        <p>Need proof of a sacrament that already took place? Request a certified copy.</p>
        <a href="<?= $basePath ?>/public/certificate-request.php" class="btn btn-secondary">Request a Certificate</a>
    </div>
    <div class="path-card">
        <h3>Sacrament Application</h3>
        <p>Preparing to receive Baptism, Confirmation, First Communion, or Marriage? Send your details before your office visit.</p>
        <a href="<?= $basePath ?>/public/sacrament-application.php" class="btn btn-secondary">Start an Application</a>
    </div>
    <div class="path-card">
        <h3>Correction Request</h3>
        <p>Already received a certificate with a wrong detail? Report it here.</p>
        <a href="<?= $basePath ?>/public/correction-request.php" class="btn btn-secondary">Request a Correction</a>
    </div>
</div>

<!-- Final calls-to-action -->
<div class="workflow-cta">
    <p>Already sent a certificate request or sacrament application? Check its status with the tracking number and the same email address used when submitting. You may use one email address for multiple submissions; track each one with its own number.</p>
    <a href="<?= $basePath ?>/public/track-request.php" class="btn btn-secondary">Track a Request</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
