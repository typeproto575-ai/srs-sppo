<?php
/**
 * =====================================================================
 * SRS-SPPO REPORTS (admin) — monthly + yearly tabs
 * =====================================================================
 * Purpose: Period totals — records encoded per sacrament, certificate
 *          requests in/out, and applications received. View selected
 *          with ?view=monthly|yearly; month/year via query string.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Reports';

// ---- View + period selection -------------------------------------------
$view = ($_GET['view'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
$month = (int) ($_GET['month'] ?? date('n'));
$year  = (int) ($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) $month = (int) date('n');
$isMonthly = $view === 'monthly';

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
               7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

if ($isMonthly) {
    $periodHeading = $monthNames[$month] . ' ' . $year;
    $periodQuery = 'view=monthly&year=' . $year . '&month=' . $month;
    $periodParams = [$month, $year];
} else {
    $periodHeading = $year . ' Summary';
    $periodQuery = 'view=yearly&year=' . $year;
    $periodParams = [$year];
}

// --- Records entered into the parish system this period, per sacrament ---
$recordCounts = [];
$recordQueries = $isMonthly ? [
    'Baptismal' => "SELECT COUNT(*) c FROM baptismal_records WHERE MONTH(date_encoded) = ? AND YEAR(date_encoded) = ?",
    'Confirmation' => "SELECT COUNT(*) c FROM confirmation_records WHERE MONTH(date_encoded) = ? AND YEAR(date_encoded) = ?",
    'First Communion' => "SELECT COUNT(*) c FROM first_communion_records WHERE MONTH(date_encoded) = ? AND YEAR(date_encoded) = ?",
    'Marriage' => "SELECT COUNT(*) c FROM marriage_records WHERE MONTH(date_encoded) = ? AND YEAR(date_encoded) = ?",
] : [
    'Baptismal' => "SELECT COUNT(*) c FROM baptismal_records WHERE YEAR(date_encoded) = ?",
    'Confirmation' => "SELECT COUNT(*) c FROM confirmation_records WHERE YEAR(date_encoded) = ?",
    'First Communion' => "SELECT COUNT(*) c FROM first_communion_records WHERE YEAR(date_encoded) = ?",
    'Marriage' => "SELECT COUNT(*) c FROM marriage_records WHERE YEAR(date_encoded) = ?",
];
foreach ($recordQueries as $label => $sql) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($periodParams);
    $recordCounts[$label] = (int) $stmt->fetch()['c'];
}

// --- Certificate requests submitted this period, per sacrament + status -
$certSql = $isMonthly
    ? "SELECT sacrament_type, status, COUNT(*) c FROM certificate_requests WHERE MONTH(date_submitted) = ? AND YEAR(date_submitted) = ? GROUP BY sacrament_type, status"
    : "SELECT sacrament_type, status, COUNT(*) c FROM certificate_requests WHERE YEAR(date_submitted) = ? GROUP BY sacrament_type, status";
$stmt = $pdo->prepare($certSql);
$stmt->execute($periodParams);
$certRows = $stmt->fetchAll();

// --- Sacrament applications submitted this period, per sacrament --------
$appSql = $isMonthly
    ? "SELECT sacrament_type, COUNT(*) c FROM sacrament_applications WHERE MONTH(date_submitted) = ? AND YEAR(date_submitted) = ? GROUP BY sacrament_type"
    : "SELECT sacrament_type, COUNT(*) c FROM sacrament_applications WHERE YEAR(date_submitted) = ? GROUP BY sacrament_type";
$stmt = $pdo->prepare($appSql);
$stmt->execute($periodParams);
$appRows = $stmt->fetchAll();

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

// --- CSV export (same rows as on screen) ---------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvTitle = $isMonthly
        ? ("SRS-SPPO Monthly Report - " . $monthNames[$month] . " - " . $year)
        : ("SRS-SPPO Yearly Report - " . $year);
    $csvName = $isMonthly
        ? ('srs-sppo-monthly-report-' . $year . '-' . $month . '.csv')
        : ('srs-sppo-yearly-report-' . $year . '.csv');
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $csvName . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [$csvTitle]);
    fputcsv($out, []);
    fputcsv($out, ['Records Encoded', 'Count']);
    foreach ($recordCounts as $label => $count) fputcsv($out, [$label, $count]);
    fputcsv($out, []);
    fputcsv($out, ['Certificate Requests', 'Status', 'Count']);
    foreach ($certRows as $row) fputcsv($out, [$row['sacrament_type'], $row['status'], $row['c']]);
    fputcsv($out, []);
    fputcsv($out, ['Sacrament Applications', 'Count']);
    foreach ($appRows as $row) fputcsv($out, [$row['sacrament_type'], $row['c']]);
    fclose($out);
    exit;
}

// Shared staff shell: top bar + sidebar + <head> (admin-footer closes layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Reports</h1>

        <!-- View tabs: monthly vs yearly -->
        <div class="report-view-tabs">
            <a href="?view=monthly&year=<?= (int) $year ?>&month=<?= (int) $month ?>" class="<?= $isMonthly ? 'active' : '' ?>">Monthly</a>
            <a href="?view=yearly&year=<?= (int) $year ?>" class="<?= !$isMonthly ? 'active' : '' ?>">Yearly</a>
        </div>

        <form autocomplete="off" method="get" class="inline-fields filter-form">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <?php if ($isMonthly): ?>
            <label>Month
                <select name="month">
                    <?php foreach ($monthNames as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $month === $num ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label>Year <input autocomplete="off" type="number" name="year" value="<?= (int) $year ?>" min="2000" max="2100"></label>
            <button type="submit" class="btn btn-small">View</button>
            <a href="?<?= e($periodQuery) ?>&export=csv" class="btn btn-secondary btn-small">Export CSV</a>
        </form>

        <h2><?= e($periodHeading) ?></h2>

        <h3>Records Encoded (by date entered)</h3>
        <div class="table-scroll">
        <table class="admin-table">
            <thead><tr><th>Sacrament</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($recordCounts as $label => $count): ?>
                    <tr><td><?= e($label) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>


        <h3 style="margin-top:2rem;">Certificate Requests Submitted</h3>
        <?php if (empty($certRows)): ?>
            <p>No certificate requests submitted <?= $isMonthly ? 'this month' : 'this year' ?>.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead><tr><th>Sacrament</th><th>Status</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($certRows as $row): ?>
                    <tr>
                        <td><?= e(humanize_label($row['sacrament_type'])) ?></td>
                        <td><span class="status-badge status-<?= e($row['status']) ?>"><?= e(humanize_label($row['status'])) ?></span></td>
                        <td><?= (int) $row['c'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <h3 style="margin-top:2rem;">Sacrament Applications Submitted</h3>
        <?php if (empty($appRows)): ?>
            <p>No sacrament applications submitted <?= $isMonthly ? 'this month' : 'this year' ?>.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead><tr><th>Sacrament</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($appRows as $row): ?>
                    <tr><td><?= e(humanize_label($row['sacrament_type'])) ?></td><td><?= (int) $row['c'] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';
