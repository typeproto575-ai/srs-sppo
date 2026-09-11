<?php
/**
 * =====================================================================
 * SRS-SPPO MONTHLY REPORT (admin)
 * =====================================================================
 * Purpose: Per-month totals — records encoded per sacrament, certificate
 *          requests in/out, and applications received — for the monthly
 *          report to the parish priest/office. Month/year selectable
 *          via the query string (?month=&year=).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Monthly Reports';

$month = (int) ($_GET['month'] ?? date('n'));
$year  = (int) ($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) $month = (int) date('n');

// --- Records encoded this month, per sacrament ----------------------------
$recordCounts = [];
$recordQueries = [
    'Baptismal'        => ["SELECT COUNT(*) c FROM baptismal_records WHERE MONTH(baptism_date) = ? AND YEAR(baptism_date) = ?"],
    'Confirmation'      => ["SELECT COUNT(*) c FROM confirmation_records WHERE MONTH(confirmation_date) = ? AND YEAR(confirmation_date) = ?"],
    'First Communion'   => ["SELECT COUNT(*) c FROM first_communion_records WHERE MONTH(communion_date) = ? AND YEAR(communion_date) = ?"],
    'Marriage'          => ["SELECT COUNT(*) c FROM marriage_records WHERE MONTH(marriage_date) = ? AND YEAR(marriage_date) = ?"],
];
foreach ($recordQueries as $label => [$sql]) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$month, $year]);
    $recordCounts[$label] = (int) $stmt->fetch()['c'];
}

// --- Certificate requests submitted this month, per sacrament + status ----
$stmt = $pdo->prepare(
    "SELECT sacrament_type, status, COUNT(*) c FROM certificate_requests
     WHERE MONTH(date_submitted) = ? AND YEAR(date_submitted) = ?
     GROUP BY sacrament_type, status"
);
$stmt->execute([$month, $year]);
$certRows = $stmt->fetchAll();

// --- Sacrament applications submitted this month, per sacrament -----------
$stmt = $pdo->prepare(
    "SELECT sacrament_type, COUNT(*) c FROM sacrament_applications
     WHERE MONTH(date_submitted) = ? AND YEAR(date_submitted) = ?
     GROUP BY sacrament_type"
);
$stmt->execute([$month, $year]);
$appRows = $stmt->fetchAll();

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
               7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

// --- CSV export (same handler pattern as admin/reports/yearly.php) --------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="srs-sppo-monthly-report-' . $year . '-' . (int) $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ["SRS-SPPO Monthly Report - " . $monthNames[$month] . " - " . $year]);
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
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Monthly Reports</h1>

        <form method="get" class="inline-fields filter-form">
            <label>Month
                <select name="month">
                    <?php foreach ($monthNames as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $month === $num ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Year <input type="number" name="year" value="<?= (int) $year ?>" min="2000" max="2100"></label>
            <button type="submit" class="btn btn-small">View</button>
            <a href="?year=<?= (int) $year ?>&month=<?= (int) $month ?>&export=csv" class="btn btn-secondary btn-small">Export CSV</a>
        </form>

        <h2><?= e($monthNames[$month]) ?> <?= (int) $year ?></h2>

        <h3>Records Encoded (by sacrament date)</h3>
        <table class="admin-table">
            <thead><tr><th>Sacrament</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($recordCounts as $label => $count): ?>
                    <tr><td><?= e($label) ?></td><td><?= $count ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:2rem;">Certificate Requests Submitted</h3>
        <?php if (empty($certRows)): ?>
            <p>No certificate requests submitted this month.</p>
        <?php else: ?>
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
        <?php endif; ?>

        <h3 style="margin-top:2rem;">Sacrament Applications Submitted</h3>
        <?php if (empty($appRows)): ?>
            <p>No sacrament applications submitted this month.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>Sacrament</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($appRows as $row): ?>
                    <tr><td><?= e(humanize_label($row['sacrament_type'])) ?></td><td><?= (int) $row['c'] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';