<?php
/**
 * =====================================================================
 * SRS-SPPO ADMIN DASHBOARD
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

// --- Needs-attention counts -------------------------------------------------
// Certificates printed/generated and waiting to be claimed at the counter.
$readyForPickupCount = (int) $pdo->query(
    "SELECT COUNT(*) c FROM certificate_requests WHERE status = 'ready_for_pickup'"
)->fetch()['c'];

// Applications mid-review (documents / interview / banns / seminar /
// ready-for-approval) — the secretary's active work, distinct from freshly
// submitted ones. (Applications are only ever linked to the queue page
// unfiltered, because the queue filters by one exact status at a time.)
$underReviewCount = (int) $pdo->query(
    "SELECT COUNT(*) c FROM sacrament_applications
     WHERE status IN ('documents_pending','interview_scheduled','banns_in_progress','seminar_pending','ready_for_approval')"
)->fetch()['c'];

// ---- Archive registry totals ------------------------------------------------
// Live COUNT(*) per digitised logbook for the registry grid below. Death/burial
// is tracked as a simplified status table, so counting its rows still reflects
// how many deceased parishioners have been recorded, even though it is not a
// full sacrament register and does not export certificates.
// Table names come from a hardcoded list (never user input), so no
// parameterisation is needed.
$registryCounts = [];
foreach (['baptismal_records', 'confirmation_records', 'first_communion_records', 'marriage_records', 'death_burial_records'] as $registryTable) {
    $registryCounts[$registryTable] = (int) $pdo->query(
        "SELECT COUNT(*) c FROM {$registryTable}"
    )->fetch()['c'];
}

// Shared top bar + sidebar + opening layout markup.
require_once __DIR__ . '/../includes/admin-header.php';

// ---- Demo password warning ------------------------------------------------
// If the logged-in user still uses the known default demo password, show a
// persistent (non-blocking) banner urging them to change it under Account Management.
$showDemoPasswordWarning = false;
if ((current_user()['username'] ?? null) === 'secretary') {
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([current_user()['id']]);
    $hash = $stmt->fetchColumn();
    if ($hash && password_verify('SPPOsecretary2026', $hash)) {
        $showDemoPasswordWarning = true;
    }
}
?>

<?php if ($showDemoPasswordWarning): ?>
            <!-- Persistent warning banner for accounts still using the demo password -->
            <div class="alert alert-warning demo-password-warning" role="alert">
                <strong>Security notice:</strong> Your account is still using the default demo password.
                <a href="<?= $basePath ?>/admin/system/accounts.php">Change your password now</a> to protect parish records.
            </div>
<?php endif; ?>
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
                <!-- Each stat card is a clickable shortcut: clock icon -> certificate queue, inbox icon -> applications queue -->
                <a class="stat-card" href="<?= $basePath ?>/admin/requests/queue.php?status=pending">
                    <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                    <p class="stat-number"><?= (int) $pendingCount ?></p>
                    <p class="stat-label">Pending Certificate Requests</p>
                </a>
                <a class="stat-card" href="<?= $basePath ?>/admin/requests/applications-queue.php?status=submitted">
                    <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></span>
                    <p class="stat-number"><?= (int) $applicationsPendingCount ?></p>
                    <p class="stat-label">New Sacrament Applications</p>
                </a>
                <!-- Needs-attention cards: claim-ready output and in-progress reviews -->
                <a class="stat-card" href="<?= $basePath ?>/admin/requests/queue.php?status=ready_for_pickup">
                    <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
                    <p class="stat-number"><?= (int) $readyForPickupCount ?></p>
                    <p class="stat-label">Ready for Pickup</p>
                </a>
                <a class="stat-card" href="<?= $basePath ?>/admin/requests/applications-queue.php">
                    <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                    <p class="stat-number"><?= (int) $underReviewCount ?></p>
                    <p class="stat-label">Applications In Progress</p>
                </a>
            </div>

            <div class="registry-summary">
                <h2>Sacrament Archive Registry Summary</h2>
                <div class="registry-grid">
                    <!-- Every registry card links straight to its records page; icons are inline SVG (no external library) -->
                    <a class="registry-card" href="<?= $basePath ?>/admin/records/baptismal.php">
                        <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg></span>
                        <h3>Baptismal</h3>
                        <span class="registry-count"><?= (int) $registryCounts['baptismal_records'] ?></span>
                    </a>
                    <a class="registry-card" href="<?= $basePath ?>/admin/records/confirmation.php">
                        <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
                        <h3>Confirmation</h3>
                        <span class="registry-count"><?= (int) $registryCounts['confirmation_records'] ?></span>
                    </a>
                    <a class="registry-card" href="<?= $basePath ?>/admin/records/first-communion.php">
                        <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v4a6 6 0 0 1-12 0z"/><path d="M12 13v6M8 21h8"/></svg></span>
                        <h3>First Communion</h3>
                        <span class="registry-count"><?= (int) $registryCounts['first_communion_records'] ?></span>
                    </a>
                    <a class="registry-card" href="<?= $basePath ?>/admin/records/marriage.php">
                        <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="13" r="6"/><circle cx="15" cy="13" r="6"/></svg></span>
                        <h3>Marriage</h3>
                        <span class="registry-count"><?= (int) $registryCounts['marriage_records'] ?></span>
                    </a>
                    <a class="registry-card" href="<?= $basePath ?>/admin/records/death-burial.php">
                        <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M7 8h10"/></svg></span>
                        <h3>Death / Burial Status</h3>
                        <span class="registry-count"><?= (int) $registryCounts['death_burial_records'] ?></span>
                    </a>
                </div>
            </div><!-- /.registry-summary -->

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
