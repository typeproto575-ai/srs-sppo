<?php
/**
 * =====================================================================
 * SPPO-IS REQUEST SELECTOR (public hub page)
 * =====================================================================
 * Purpose: Static landing page that routes visitors to the right form:
 *            1. Certificate requests  — proof of an already-recorded
 *               sacrament (Baptismal, Confirmation, First Communion,
 *               Marriage).
 *            2. Sacrament applications — enrolling to RECEIVE a sacrament.
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
        <ul class="option-list">
            <li><a href="<?= $basePath ?>/public/certificate-requests/baptismal.php">Baptismal Certificate</a></li>
            <li><a href="<?= $basePath ?>/public/certificate-requests/confirmation.php">Confirmation Certificate</a></li>
            <li><a href="<?= $basePath ?>/public/certificate-requests/first-communion.php">First Communion Certificate</a></li>
            <li><a href="<?= $basePath ?>/public/certificate-requests/marriage.php">Marriage Certificate</a></li>
        </ul>
    </div>

    <!-- Option 2: applications to receive a sacrament -->
    <div class="option-group">
        <h2>2. Apply for a Sacrament</h2>
        <p>Enroll or apply to receive a sacrament at this parish.</p>
        <ul class="option-list">
            <li><a href="<?= $basePath ?>/public/sacrament-applications/baptismal.php">Baptismal Application</a></li>
            <li><a href="<?= $basePath ?>/public/sacrament-applications/confirmation.php">Confirmation Application</a></li>
            <li><a href="<?= $basePath ?>/public/sacrament-applications/first-communion.php">First Communion Application</a></li>
            <li><a href="<?= $basePath ?>/public/sacrament-applications/marriage.php">Marriage Application</a></li>
        </ul>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
