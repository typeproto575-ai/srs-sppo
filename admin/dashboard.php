<?php
/**
 * =====================================================================
 * SRS-SPPO ADMIN DASHBOARD
 * =====================================================================
 * Purpose: Landing page after login for the secretary. Search-first home:
 *   - name search box (top) -> person profile
 *   - quick stats: pending requests, new applications, ready, in progress
 *   - sacrament registry counts
 * Uses the shared admin-header/admin-footer for layout so the sidebar
 * and top bar stay consistent with every other admin page.
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sacrament-data.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Admin Dashboard';

// ---- Search (GET q) -------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$searchResults = [];
if ($q !== '') {
    $searchResults = sd_search_persons($pdo, $q);
}

// ---- Quick stats -----------------------------------------------------------
$pendingCount = (int) $pdo->query(
    "SELECT COUNT(*) c FROM certificate_requests WHERE status = 'new'"
)->fetch()['c'];

$applicationsPendingCount = (int) $pdo->query(
    "SELECT COUNT(*) c FROM sacrament_applications WHERE status = 'new'"
)->fetch()['c'];

$readyForPickupCount = (int) $pdo->query(
    "SELECT COUNT(*) c FROM certificate_requests WHERE status = 'ready'"
)->fetch()['c'];

$underReviewCount = (int) $pdo->query(
    "SELECT COUNT(*) c FROM sacrament_applications
     WHERE status IN ('reviewing','requirements_pending','approved','scheduled')"
)->fetch()['c'];

// ---- Registry counts (via sacrament-data.php, no raw SQL here) --------------
$registryCounts = sd_count_records_by_type($pdo);

require_once __DIR__ . '/../includes/admin-header.php';
?>

<section class="dashboard-shell">
    <div class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">SECRETARY DASHBOARD</p>
            <h1>Welcome back, <?= e(current_user()['full_name'] ?: 'Secretary') ?></h1>
            <p class="dashboard-subtitle">Search a person first, then review pending requests and registry counts below.</p>
        </div>
        <div class="dashboard-actions">
            <a href="<?= e($basePath) ?>/admin/requests/queue.php" class="btn btn-primary">Review Requests</a>
        </div>
    </div>

    <!-- Search form at the very top -->
    <section class="dashboard-search" style="margin-bottom:1.5rem;">
        <form method="get" action="<?= e($basePath) ?>/admin/dashboard.php" class="search-form" style="display:flex;gap:.5rem;align-items:center;">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by name or birth year, e.g. dela Cruz 1985" autofocus style="flex:1;padding:.5rem .75rem;">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </section>

    <?php if ($q !== ''): ?>
        <section class="search-results" style="margin-bottom:1.5rem;">
            <h2>Search results for "<?= e($q) ?>"</h2>
            <?php if (empty($searchResults)): ?>
                <p class="field-hint">No match. <a href="<?= e($basePath) ?>/admin/records/record-form.php?type=baptism">Add as a new baptism</a>.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Birth date</th>
                                <th>Birth place</th>
                                <th>Baptism</th>
                                <th>First Communion</th>
                                <th>Confirmation</th>
                                <th>Marriage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($searchResults as $p): ?>
                                <tr>
                                    <td><a href="<?= e($basePath) ?>/admin/system/person-profile.php?id=<?= (int) $p['id'] ?>"><?= e($p['full_name']) ?></a></td>
                                    <td><?= e($p['birth_date'] ? date('M j, Y', strtotime($p['birth_date'])) : '—') ?></td>
                                    <td><?= e($p['birth_place'] ?: '—') ?></td>
                                    <td><?= !empty($p['has_baptism']) ? '✓' : '—' ?></td>
                                    <td><?= !empty($p['has_first_communion']) ? '✓' : '—' ?></td>
                                    <td><?= !empty($p['has_confirmation']) ? '✓' : '—' ?></td>
                                    <td><?= !empty($p['has_marriage']) ? '✓' : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="stat-cards dashboard-metrics">
        <a class="stat-card stat-tone-amber" href="<?= e($basePath) ?>/admin/requests/queue.php?status=pending">
            <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
            <p class="stat-number"><?= (int) $pendingCount ?></p>
            <p class="stat-label">Pending Certificate Requests</p>
        </a>
        <a class="stat-card stat-tone-amber" href="<?= e($basePath) ?>/admin/requests/applications-queue.php?status=new">
            <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></span>
            <p class="stat-number"><?= (int) $applicationsPendingCount ?></p>
            <p class="stat-label">New Sacrament Applications</p>
        </a>
        <a class="stat-card stat-tone-blue" href="<?= e($basePath) ?>/admin/requests/queue.php?status=ready">
            <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
            <p class="stat-number"><?= (int) $readyForPickupCount ?></p>
            <p class="stat-label">Ready for Pickup</p>
        </a>
        <a class="stat-card stat-tone-green" href="<?= e($basePath) ?>/admin/requests/applications-queue.php">
            <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
            <p class="stat-number"><?= (int) $underReviewCount ?></p>
            <p class="stat-label">Applications In Progress</p>
        </a>
    </div>

    <div class="registry-summary">
        <h2>Sacrament Archive Registry Summary</h2>
        <div class="registry-grid">
            <div class="registry-card">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg></span>
                <h3>Baptism</h3>
                <span class="registry-count"><?= (int) ($registryCounts['baptism'] ?? 0) ?></span>
            </div>
            <div class="registry-card">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
                <h3>Confirmation</h3>
                <span class="registry-count"><?= (int) ($registryCounts['confirmation'] ?? 0) ?></span>
            </div>
            <div class="registry-card">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v4a6 6 0 0 0-12 0z"/><path d="M12 13v6M8 21h8"/></svg></span>
                <h3>First Communion</h3>
                <span class="registry-count"><?= (int) ($registryCounts['first_communion'] ?? 0) ?></span>
            </div>
            <div class="registry-card">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="13" r="6"/><circle cx="15" cy="13" r="6"/></svg></span>
                <h3>Marriage</h3>
                <span class="registry-count"><?= (int) ($registryCounts['marriage'] ?? 0) ?></span>
            </div>
        </div>
    </div><!-- /.registry-summary -->
</section>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>