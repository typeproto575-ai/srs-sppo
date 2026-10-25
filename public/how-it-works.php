<?php
/**
 * =====================================================================
 * SRS-SPPO "HOW IT WORKS" (public explainer page)
 * =====================================================================
 * Purpose: Walks visitors through the parish's request workflow in
 *          five ledger-style steps, then offers two path cards that
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
    <p>Online forms make it easier to send complete details before visiting the office. They do not replace secretary verification, the physical register, or in-person certificate release.</p>
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
<h3>Submit a pre-application</h3>
            <p>Enter the names, dates, relationship, purpose, and other details requested by the form. You do not need an account, and you should not upload an identity document.</p>
        </div>
    </div>

    <!-- Step 3: staff verification -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>Secretary review</h3>
            <p>The secretary checks the submission against the parish register and reviews requirements. An online submission is not an official parish record and is not automatically approved.</p>
        </div>
    </div>

    <!-- Step 4: payment (before processing; made outside the system) -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>Payment (outside this website)</h3>
            <p>Once your details match a parish record, your request or application is marked <strong>awaiting payment</strong>. Payment is never taken on this website. You may pay at the parish office, contact the parish by email, phone, or the parish Facebook page/Messenger, or ask someone near the parish to pay on your behalf. The parish confirms the fee directly. The office records your payment by hand, and processing continues.</p>
        </div>
    </div>

    <!-- Step 5: notification -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>Keep your tracking number</h3>
            <p>After a successful submission, keep the tracking number and recorded email address. Use both to view a limited public status timeline.</p>
        </div>
    </div>

    <!-- Step 6: pickup (identity verified here — no online uploads) -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
<h3>Verify and complete onsite</h3>
            <p>If documents are needed, bring them to the Parish Office in person when asked. Do not send documents by email or Messenger; you may still contact the office with questions. Identity is verified in person, and certificates are printed and released only onsite. There is no online payment.</p>
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
