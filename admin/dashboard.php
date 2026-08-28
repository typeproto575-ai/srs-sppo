<?php
/**
 * =====================================================================
 * SPPO-IS ADMIN DASHBOARD
 * =====================================================================
 * Purpose: Landing page after login for the secretary. Shows the two
 *          numbers that drive the daily routine:
 *            - pending certificate requests (queue.php)
 *            - new sacrament applications   (applications-queue.php)
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

// Shared top bar + sidebar + opening layout markup.
require_once __DIR__ . '/../includes/admin-header.php';
?>
        <h1>Dashboard</h1>
        <!-- Two headline counters; each links into its work queue -->
        <div class="stat-cards">
            <div class="stat-card">
                <p class="stat-number"><?= (int) $pendingCount ?></p>
                <p class="stat-label">Pending Certificate Requests</p>
            </div>
            <div class="stat-card">
                <p class="stat-number"><?= (int) $applicationsPendingCount ?></p>
                <p class="stat-label">New Sacrament Applications</p>
            </div>
        </div>
        <!-- Future: recent activity feed and quick actions -->
<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
