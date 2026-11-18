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

notify_newly_age_eligible_persons($pdo);

// ---- Search (GET q) -------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$searchResults = [];
if ($q !== '') {
    $searchResults = sd_search_persons($pdo, $q);
}

if (!defined('CERT_PENDING_STATUSES')) {
    define('CERT_PENDING_STATUSES', ['new', 'matched', 'for_signature']);
}
$pendingPlaceholders = implode(',', array_fill(0, count(CERT_PENDING_STATUSES), '?'));
$pendingStmt = $pdo->prepare("SELECT COUNT(*) c FROM certificate_requests WHERE status IN ($pendingPlaceholders)");
$pendingStmt->execute(CERT_PENDING_STATUSES);
$pendingCount = (int) $pendingStmt->fetch()['c'];

$applicationsPendingCount = (int) $pdo->query(
    "SELECT COUNT(*) c FROM sacrament_applications WHERE status = 'new'"
)->fetch()['c'];

// ---- Registry counts (via sacrament-data.php, no raw SQL here) --------------
$registryCounts = sd_count_records_by_type($pdo);
$recordedSacramentsCount = array_sum(array_map('intval', $registryCounts));
$registeredProfilesCount = (int) $pdo->query('SELECT COUNT(*) FROM persons')->fetchColumn();

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
    <section class="dashboard-search" aria-labelledby="dashboard-search-heading">
        <div class="dashboard-section-heading">
            <h2 id="dashboard-search-heading">Find a parishioner</h2>
            <p>Search the master person index, then open the profile to review linked sacramental history.</p>
        </div>
        <form method="get" action="<?= e($basePath) ?>/admin/dashboard.php" class="search-form"
              data-dashboard-person-search data-endpoint="<?= e($basePath) ?>/admin/api/dashboard-person-search.php"
              data-profile-url="<?= e($basePath) ?>/admin/system/person-profile.php"
              data-records-url="<?= e($basePath) ?>/admin/records/index.php"
              data-requests-url="<?= e($basePath) ?>/admin/requests/queue.php">
            <input id="dashboard-person-search" type="search" name="q" value="<?= e($q) ?>"
                   placeholder="Search by name or birth year (e.g., dela Cruz 1985)…" autocomplete="off"
                   aria-label="Search parishioners by name or birth year"
                   aria-controls="dashboard-search-results">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
        <p class="field-hint">For a completed register entry, use Records. For a certificate copy request, use Certificate Requests.</p>
    </section>

    <section id="dashboard-search-results" class="search-results" aria-live="polite"<?= $q === '' ? ' hidden' : '' ?>>
        <?php if ($q !== ''): ?>
            <h2>Search results for "<?= e($q) ?>"</h2>
            <?php if (empty($searchResults)): ?>
                <p class="field-hint">No master person profile matched. Try another name part or birth year. For a register-entry search, open <a href="<?= e($basePath) ?>/admin/records/index.php">Records</a>; for a certificate copy request, open <a href="<?= e($basePath) ?>/admin/requests/queue.php">Certificate Requests</a>.</p>
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
        <?php endif; ?>
    </section>

    <section class="dashboard-work" aria-labelledby="dashboard-work-heading">
        <div class="dashboard-section-heading">
            <h2 id="dashboard-work-heading">Request overview</h2>
            <p>Select a count to open the corresponding filtered queue.</p>
        </div>
        <div class="stat-cards dashboard-metrics">
            <a class="stat-card stat-tone-amber" href="<?= e($basePath) ?>/admin/requests/applications-queue.php?status=new">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h10v4H7z"/><path d="M5 5H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1"/><path d="M8 13h8M8 17h5"/></svg></span>
                <span class="stat-copy"><span class="stat-number"><?= (int) $applicationsPendingCount ?></span><span class="stat-label">Pending pre-applications</span></span>
            </a>
            <a class="stat-card stat-tone-amber" href="<?= e($basePath) ?>/admin/requests/queue.php?status=pending">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                <span class="stat-copy"><span class="stat-number"><?= (int) $pendingCount ?></span><span class="stat-label">Pending certificate requests</span></span>
            </a>
            <a class="stat-card stat-tone-green" href="<?= e($basePath) ?>/admin/records/index.php">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v18H6.5A2.5 2.5 0 0 0 4 22z"/><path d="M4 4v18M8 6h8M8 10h8"/></svg></span>
                <span class="stat-copy"><span class="stat-number"><?= (int) $recordedSacramentsCount ?></span><span class="stat-label">Recorded sacraments</span></span>
            </a>
            <a class="stat-card stat-tone-blue" href="<?= e($basePath) ?>/admin/dashboard.php#dashboard-person-search">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-1a6 6 0 0 1 12 0v1M16 5.5a3 3 0 0 1 0 5.8M18 14a4 4 0 0 1 3 4v2"/></svg></span>
                <span class="stat-copy"><span class="stat-number"><?= (int) $registeredProfilesCount ?></span><span class="stat-label">Registered profiles</span></span>
            </a>
        </div>
    </section>

    <div class="registry-summary">
        <h2>Sacramental records on file</h2>
        <p class="registry-summary-note">Select a sacrament to open its records.</p>
        <div class="registry-grid">
            <a class="registry-card" href="<?= e($basePath) ?>/admin/records/index.php?type=baptismal" aria-label="View Baptism records, <?= (int) ($registryCounts['baptism'] ?? 0) ?> on file">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg></span>
                <h3>Baptism</h3>
                <span class="registry-count"><?= (int) ($registryCounts['baptism'] ?? 0) ?></span>
                <span class="registry-card-action">View records <span aria-hidden="true">→</span></span>
            </a>
            <a class="registry-card" href="<?= e($basePath) ?>/admin/records/index.php?type=confirmation" aria-label="View Confirmation records, <?= (int) ($registryCounts['confirmation'] ?? 0) ?> on file">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
                <h3>Confirmation</h3>
                <span class="registry-count"><?= (int) ($registryCounts['confirmation'] ?? 0) ?></span>
                <span class="registry-card-action">View records <span aria-hidden="true">→</span></span>
            </a>
            <a class="registry-card" href="<?= e($basePath) ?>/admin/records/index.php?type=first_communion" aria-label="View First Communion records, <?= (int) ($registryCounts['first_communion'] ?? 0) ?> on file">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v4a6 6 0 0 0-12 0z"/><path d="M12 13v6M8 21h8"/></svg></span>
                <h3>First Communion</h3>
                <span class="registry-count"><?= (int) ($registryCounts['first_communion'] ?? 0) ?></span>
                <span class="registry-card-action">View records <span aria-hidden="true">→</span></span>
            </a>
            <a class="registry-card" href="<?= e($basePath) ?>/admin/records/index.php?type=marriage" aria-label="View Marriage records, <?= (int) ($registryCounts['marriage'] ?? 0) ?> on file">
                <span class="card-icon" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="13" r="6"/><circle cx="15" cy="13" r="6"/></svg></span>
                <h3>Marriage</h3>
                <span class="registry-count"><?= (int) ($registryCounts['marriage'] ?? 0) ?></span>
                <span class="registry-card-action">View records <span aria-hidden="true">→</span></span>
            </a>
        </div>
    </div><!-- /.registry-summary -->
</section>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>