<?php
/**
 * =====================================================================
 * SPPO-IS "HOW IT WORKS" (public explainer page)
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
    <span class="eyebrow">How a Request Works</span>
    <h1>From asking, to receiving — the same record, now easier to find.</h1>
    <p>St. Patrick Parish has kept these records by hand for generations, entered line by line in the register. This is how we keep doing that, with a faster way in.</p>
</section>

<!-- Step-by-step ledger (one row per step) -->
<div class="ledger">

    <!-- Step 1: choose request type -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>Choose what you need</h3>
            <p>A certificate for a sacrament that already happened, or an application to receive one — Baptismal, Confirmation, First Communion, or Marriage.</p>
        </div>
    </div>

    <!-- Step 2: provide known details -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>Fill in what you know</h3>
            <p>Name, date, and the people involved. If you don't know the book, page, or line number from the register, that's fine — the secretary looks that up for you.</p>
        </div>
    </div>

    <!-- Step 3: staff verification -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>We check the record</h3>
            <p>The secretary matches your request against the register. Anything unusual is flagged for a closer look, never rejected outright without review.</p>
        </div>
    </div>

    <!-- Step 4: notification -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>Keep your tracking number</h3>
            <p>When you submit, you'll receive a tracking number. Keep it safe — you can check your request's status any time with that number.</p>
        </div>
    </div>

    <!-- Step 5: pickup (identity verified here — no online uploads) -->
    <div class="ledger-row">
        <span class="ledger-no"></span>
        <div>
            <h3>Pick it up in person</h3>
            <p>Bring a valid ID to the parish office. Payment is made at pickup, same as always — there's no online payment involved.</p>
        </div>
    </div>

</div>

<!-- Two route cards: pick the flow that matches the visitor's need -->
<div class="path-cards">
    <div class="path-card">
        <h3>Certificate Request</h3>
        <p>You need proof of a sacrament that already took place — for school, employment, travel, or marriage requirements.</p>
        <a href="<?= $basePath ?>/public/certificate-request.php" class="btn btn-secondary">Request a Certificate</a>
    </div>
    <div class="path-card">
        <h3>Sacrament Application</h3>
        <p>You're applying to receive a sacrament here — enrolling a child for Confirmation or First Communion, or applying to be married at this parish.</p>
        <a href="<?= $basePath ?>/public/certificate-request.php" class="btn btn-secondary">Start an Application</a>
    </div>
</div>

<!-- Final calls-to-action -->
<div class="workflow-cta">
    <a href="<?= $basePath ?>/public/certificate-request.php" class="btn">Start a Request</a>
    <a href="<?= $basePath ?>/public/track-request.php" class="btn btn-secondary">Track a Request</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
