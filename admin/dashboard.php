<?php
/**
 * =====================================================================
 * SPPO-IS ADMIN DASHBOARD
 * =====================================================================
 * Purpose: Landing page after login for the secretary. Shows the numbers
 *          that drive the daily routine:
 *            - pending certificate requests (queue.php)
 *            - new sacrament applications   (applications-queue.php)
 *            - per-registry totals for each sacrament (records pages)
 * Uses the shared admin-header/admin-footer for layout so the sidebar
 * and top bar stay consistent with every other admin page.
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php'; // session + role guard
require_role('secretary');                      // dashboard is secretary-only

$pageTitle = 'Admin Dashboard';

// ---- Quick stats -----------------------------------------------------------
// Count of certificate requests still awaiting processing.
$pendingCount = $pdo->query(
    "SELECT COUNT(*) c FROM certificate_requests WHERE status = 'pending'"
)->fetch()['c'];

// Count of sacrament applications just submitted (awaiting initial review).
$applicationsPendingCount = $pdo->query(
    "SELECT COUNT(*) c FROM sacrament_applications WHERE status = 'submitted'"
)->fetch()['c'];

// ---- Archive registry totals ------------------------------------------------
// Live COUNT(*) per digitised logbook for the registry grid below. Death/burial
// was removed from the design (see PROJECT_CONTEXT.md), so only these four
// registries are shown. Table names come from a hardcoded list (never user
// input), so no parameterisation is needed.
$registryCounts = [];
foreach (['baptismal_records', 'confirmation_records', 'first_communion_records', 'marriage_records'] as $registryTable) {
    $registryCounts[$registryTable] = (int) $pdo->query(
        "SELECT COUNT(*) c FROM {$registryTable}"
    )->fetch()['c'];
}

// Shared top bar + sidebar + opening layout markup.
require_once __DIR__ . '/../includes/admin-header.php';
?>
        <section class="dashboard-shell">
            <div class="dashboard-hero">
                <div>
                    <p class="dashboard-kicker">SECRETARY DASHBOARD</p>
                    <!-- Greeting uses the logged-in staff member's real name (falls back to "Secretary" for safety) -->
                    <h1>Welcome back, <?= e(current_user()['full_name'] ?: 'Secretary') ?></h1>
                    <p class="dashboard-subtitle">Monitor record flows, pending requests, and priority parish actions from one place.</p>
                </div>
                <div class="dashboard-actions">
                    <a href="<?= $basePath ?>/admin/requests/queue.php" class="btn btn-primary">Review Requests</a>
                    <a href="<?= $basePath ?>/admin/system/accounts.php" class="btn btn-secondary btn-dark">Staff Accounts</a>
                </div>
            </div>

            <div class="stat-cards dashboard-metrics">
                <div class="stat-card">
                    <p class="stat-number"><?= (int) $pendingCount ?></p>
                    <p class="stat-label">Pending Certificate Requests</p>
                </div>
                <div class="stat-card">
                    <p class="stat-number"><?= (int) $applicationsPendingCount ?></p>
                    <p class="stat-label">New Sacrament Applications</p>
                </div>
            </div>

            <div class="registry-summary">
                <h2>Sacrament Archive Registry Summary</h2>
                <div class="registry-grid">
                    <div class="registry-card">
                        <h3>Baptismal</h3>
                        <span class="registry-count"><?= (int) $registryCounts['baptismal_records'] ?></span>
                    </div>
                    <div class="registry-card">
                        <h3>Confirmation</h3>
                        <span class="registry-count"><?= (int) $registryCounts['confirmation_records'] ?></span>
                    </div>
                    <div class="registry-card">
                        <h3>First Communion</h3>
                        <span class="registry-count"><?= (int) $registryCounts['first_communion_records'] ?></span>
                    </div>
                    <div class="registry-card">
                        <h3>Marriage</h3>
                        <span class="registry-count"><?= (int) $registryCounts['marriage_records'] ?></span>
                    </div>
                </div>
            </div>

            <div class="dashboard-lower-grid">
                <div class="mini-panel">
                    <h3>Daily priorities</h3>
                    <p>Keep the parish records up to date, clear the queue, and confirm scheduled events.</p>
                    <!-- Distinct from the hero CTA above: points to the applications queue instead of duplicating the certificate queue -->
                    <a href="<?= $basePath ?>/admin/requests/applications-queue.php" class="btn btn-secondary btn-small">Review New Applications</a>
                </div>

                <div class="mini-panel">
                    <h3>Your tools</h3>
                    <div class="tool-list">
                        <a href="<?= $basePath ?>/admin/system/accounts.php">Staff Account Management</a>
                        <a href="<?= $basePath ?>/admin/system/backup.php">Export Database</a>
                    </div>
                </div>
            </div>
        </section>
<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
