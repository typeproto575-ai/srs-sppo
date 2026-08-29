<?php
/**
 * =====================================================================
 * SPPO-IS YEARLY REPORT (admin)
 * =====================================================================
 * Purpose: Year-at-a-glance totals per sacrament (records encoded) plus
 *          certificate/application volume — the annual statistical
 *          summary for the parish. Year selectable via ?year=.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Yearly Reports';

$year = (int) ($_GET['year'] ?? date('Y'));

$recordCounts = [];
$recordQueries = [
    'Baptismal'        => "SELECT COUNT(*) c FROM baptismal_records WHERE YEAR(baptism_date) = ?",
    'Confirmation'      => "SELECT COUNT(*) c FROM confirmation_records WHERE YEAR(confirmation_date) = ?",
    'First Communion'   => "SELECT COUNT(*) c FROM first_communion_records WHERE YEAR(communion_date) = ?",
    'Marriage'          => "SELECT COUNT(*) c FROM marriage_records WHERE YEAR(marriage_date) = ?",
];
foreach ($recordQueries as $label => $sql) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$year]);
    $recordCounts[$label] = (int) $stmt->fetch()['c'];
}

$stmt = $pdo->prepare(
    "SELECT sacrament_type, status, COUNT(*) c FROM certificate_requests
     WHERE YEAR(date_submitted) = ? GROUP BY sacrament_type, status"
);
$stmt->execute([$year]);
$certRows = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT sacrament_type, COUNT(*) c FROM sacrament_applications
     WHERE YEAR(date_submitted) = ? GROUP BY sacrament_type"
);
$stmt->execute([$year]);
$appRows = $stmt->fetchAll();

// --- CSV export -------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sppo-is-yearly-report-' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ["SPPO-IS Yearly Report — {$year}"]);
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

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Yearly Reports</h1>

        <form method="get" class="inline-fields filter-form">
            <label>Year <input type="number" name="year" value="<?= (int) $year ?>" min="2000" max="2100"></label>
            <button type="submit" class="btn btn-small">View</button>
            <a href="?year=<?= (int) $year ?>&export=csv" class="btn btn-secondary btn-small">Export CSV</a>
        </form>

        <h2><?= (int) $year ?> Summary</h2>

        <h3>Records Encoded (by sacrament date)</h3>
        <table class="admin-table">
            <thead><tr><th>Sacrament</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($recordCounts as $label => $count): ?>
                    <tr><td><?= e($label) ?></td><td><?= $count ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:2rem;">Certificate Requests</h3>
        <?php if (empty($certRows)): ?>
            <p>No certificate requests this year.</p>
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

        <h3 style="margin-top:2rem;">Sacrament Applications</h3>
        <?php if (empty($appRows)): ?>
            <p>No sacrament applications this year.</p>
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