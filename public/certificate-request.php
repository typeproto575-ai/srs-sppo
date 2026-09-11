<?php
/**
 * =====================================================================
 * SRS-SPPO REQUEST SELECTOR (public hub page)
 * =====================================================================
 * Purpose: Landing page that routes visitors to the right form:
 *            1. Certificate requests  — proof of an already-recorded
 *               sacrament (Baptismal, Confirmation, First Communion,
 *               Marriage).
 *            2. Sacrament applications — enrolling to RECEIVE a sacrament
 *               (Baptism and Marriage only).
 *          Pure navigation; no database access needed beyond the header.
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Subfolder-safe URL prefix for every link below.
$basePath = $basePath ?? base_path();

$pageTitle = 'Request a Certificate or Apply for a Sacrament';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="request-selector">
    <h1>What would you like to do?</h1>

    <!-- Option 1: certificates for sacraments already on record -->
    <div class="option-group">
        <h2>1. Request a Certificate</h2>
        <p>Get proof of a sacrament that already took place.</p>
        <div class="hub-cards">
            <a class="hub-card" href="<?= $basePath ?>/public/certificate-requests/baptismal.php">
                <h3>Baptismal Certificate</h3>
                <p>Proof of baptism recorded in the parish register.</p>
            </a>
            <a class="hub-card" href="<?= $basePath ?>/public/certificate-requests/confirmation.php">
                <h3>Confirmation Certificate</h3>
                <p>Proof of the sacrament of Confirmation.</p>
            </a>
            <a class="hub-card" href="<?= $basePath ?>/public/certificate-requests/first-communion.php">
                <h3>First Communion Certificate</h3>
                <p>Proof of receiving First Communion.</p>
            </a>
            <a class="hub-card" href="<?= $basePath ?>/public/certificate-requests/marriage.php">
                <h3>Marriage Certificate</h3>
                <p>Proof of marriage recorded at this parish.</p>
            </a>
        </div>
    </div>

    <!-- Option 2: applications to receive a sacrament -->
    <div class="option-group">
        <h2>2. Apply for a Sacrament</h2>
        <p>Enroll or apply to receive a sacrament at this parish.</p>
        <div class="hub-cards hub-cards-applications">
            <a class="hub-card hub-card-application" href="<?= $basePath ?>/public/sacrament-applications/baptismal.php">
                <h3>Baptismal Application</h3>
                <p>Apply to have an infant or child baptized here.</p>
            </a>
            <a class="hub-card hub-card-application" href="<?= $basePath ?>/public/sacrament-applications/marriage.php">
                <h3>Marriage Application</h3>
                <p>Start the marriage process — interview, banns, and pre-Cana are arranged with the office.</p>
            </a>
        </div>
    </div>
    <p class="field-hint">Confirmation and First Communion have no application forms by design - they are recorded as received/not-received status flags on your parish record. Enrolment is arranged directly at the parish office.</p>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
