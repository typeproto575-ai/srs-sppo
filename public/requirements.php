<?php
declare(strict_types=1);

/**
 * =====================================================================
 * SRS-SPPO SACRAMENT REQUIREMENTS PAGE
 * =====================================================================
 * Purpose: Public-facing page listing the documentary requirements
 *          parishioners must prepare for each sacrament or certificate
 *          request (Baptism, Confirmation, First Communion, Marriage).
 *          It helps visitors know what to bring before coming to the
 *          parish office.
 *
 * This page is accessible without login.
 * =====================================================================
 */

// Load shared helper functions
require_once __DIR__ . '/../includes/functions.php';

// Page title shown in the browser tab
$pageTitle = 'Sacrament Requirements';

// Render the shared public header (navigation + opening HTML)
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Hero section: page intro -->
<section class="workflow-hero">
    <span class="eyebrow">Before You Visit</span>
    <h1>Sacrament & Certificate Requirements</h1>
    <p>Prepare these documents before coming to the parish office. Having everything ready makes your visit faster — our secretary will verify each item against the register.</p>
</section>

<!-- Requirements ledger: one ruled row per sacrament -->
<div class="ledger">

    <!-- Baptism requirements -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>Baptism (Infant)</h3>
            <ul class="req-list">
                <li>PSA Birth Certificate of the child</li>
                <li>PSA Marriage Certificate of parents (if married)</li>
                <li>Valid IDs of parents (and godparents, if required)</li>
                <li>Certificate of Sponsorship / Godparent eligibility (if required by the parish)</li>
                <li>Baptismal seminar attendance (parents & godparents)</li>
            </ul>
        </div>
    </div>

    <!-- Confirmation requirements -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>Confirmation</h3>
            <ul class="req-list">
                <li>PSA Birth Certificate</li>
                <li>Baptismal Certificate</li>
                <li>Proof of completion of confirmation catechesis</li>
                <li>Valid ID(s); for minors, parent/guardian consent</li>
            </ul>
        </div>
    </div>

    <!-- First Communion requirements -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>First Holy Communion</h3>
            <ul class="req-list">
                <li>PSA Birth Certificate</li>
                <li>Baptismal Certificate</li>
                <li>Proof of completion of First Communion catechesis</li>
                <li>Valid ID(s); for minors, parent/guardian consent</li>
            </ul>
        </div>
    </div>

    <!-- Marriage requirements -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>Marriage (Church Wedding)</h3>
            <ul class="req-list">
                <li>Latest Baptismal Certificate "For Marriage Purposes" (valid 3–6 months)</li>
                <li>Confirmation Certificate "For Marriage Purposes"</li>
                <li>PSA Marriage License</li>
                <li>CENOMAR from PSA</li>
                <li>Pre-Cana / Family Life seminar certificate</li>
                <li>List of sponsors (ninong/ninang)</li>
                <li>Valid IDs of both parties</li>
            </ul>
        </div>
    </div>

    <!-- Certificate copy requirements -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>Requesting a Copy of a Certificate</h3>
            <ul class="req-list">
                <!-- No online ID upload: identity is verified in person at pickup -->
                <li>Your valid government-issued ID (presented at pickup)</li>
                <li>Name, date, and details of the sacrament as best you know them (book/page/line optional — we can look those up)</li>
                <li>Your Gmail address (to receive your reference number and status updates)</li>
                <li>Pickup in person at the parish office with the same valid ID — payment is made at pickup</li>
            </ul>
        </div>
    </div>

</div>

<!-- Call-to-action buttons -->
<div class="workflow-cta">
    <a href="<?= base_path() ?>/public/certificate-request.php" class="btn">Start a Request</a>
    <a href="<?= base_path() ?>/public/track-request.php" class="btn btn-secondary">Track a Request</a>
</div>

<!-- Render the shared public footer (closing HTML + JS) -->
<?php require_once __DIR__ . '/../includes/footer.php'; ?>