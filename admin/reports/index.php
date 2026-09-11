<?php
/**
 * =====================================================================
 * SRS-SPPO UNIFIED REPORTS (admin)
 * =====================================================================
 * Purpose: Single report hub replacing the separate monthly/yearly pages.
 *          Period selector (month / quarter / year) with sacrament and
 *          status filters — the specific slice the secretary needs
 *          without bouncing between pages.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Reports';

$periodType = $_GET['period'] ?? 'month';
if (!in_array($periodType, ['month', 'quarter', 'year'], true)) $periodType = 'month';

$year  = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
$quarter = (int) ($_GET['quarter'] ?? ceil(date('n') / 3));

if ($month < 1 || $month > 12) $month = (int) date('n');
if ($quarter < 1 || $quarter > 4) $quarter = 1;

$filterSacrament = $_GET['sacrament'] ?? '';
$filterStatus = $_GET['status'] ?? '';

if ($periodType === 'year') {
    $dateFrom = "{$year}-01-01";
    $dateTo   = "{$year}-12-31";
    $periodLabel = (string) $year;
} elseif ($periodType === 'quarter') {

// --- Records encoded per sacrament ---
$recordLabels = ['Baptismal' => 'baptismal_records', 'Confirmation' => 'confirmation_records', 'First Communion' => 'first_communion_records', 'Marriage' => 'marriage_records'];
$recordDateCols = ['Baptismal' => 'baptism_date', 'Confirmation' => 'confirmation_date', 'First Communion' => 'communion_date', 'Marriage' => 'marriage_date'];
$recordCounts = [];
foreach ($recordLabels as $label => $table) {
    $col = $recordDateCols[$label];
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM {$table} WHERE {$col} >= ? AND {$col} <= ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $recordCounts[$label] = (int) $stmt->fetch()['c'];
}

// --- Certificate requests per sacrament + status ---
$certWhere = ['date_submitted >= ?', 'date_submitted <= ?'];
$certParams = [$dateFrom, $dateTo];
if ($filterSacrament !== '') { $certWhere[] = 'sacrament_type = ?'; $certParams[] = $filterSacrament; }
if ($filterStatus !== '') { $certWhere[] = 'status = ?'; $certParams[] = $filterStatus; }
$certSql = "SELECT sacrament_type, status, COUNT(*) c FROM certificate_requests WHERE " . implode(' AND ', $certWhere) . " GROUP BY sacrament_type, status";
$stmt = $pdo->prepare($certSql);
$stmt->execute($certParams);
$certRows = $stmt->fetchAll();

// --- Sacrament applications per sacrament + status ---
$appWhere = ['date_submitted >= ?', 'date_submitted <= ?'];
$appParams = [$dateFrom, $dateTo];
if ($filterSacrament !== '') { $appWhere[] = 'sacrament_type = ?'; $appParams[] = $filterSacrament; }
if ($filterStatus !== '') { $appWhere[] = 'status = ?'; $appParams[] = $filterStatus; }
$appSql = "SELECT sacrament_type, status, COUNT(*) c FROM sacrament_applications WHERE " . implode(' AND ', $appWhere) . " GROUP BY sacrament_type, status";
$stmt = $pdo->prepare($appSql);
$stmt->execute($appParams);
$appRows = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/admin-header.php';
    $qStart = ($quarter - 1) * 3 + 1;
    $qEnd   = $quarter * 3;
?>
        <h1>Reports</h1>

        <form method="get" class="inline-fields filter-form">
            <label>Period
                <select name="period" onchange="this.form.submit()">
                    <option value="month" <?= $periodType === 'month' ? 'selected' : '' ?>>Monthly</option>
                    <option value="quarter" <?= $periodType === 'quarter' ? 'selected' : '' ?>>Quarterly</option>
                    <option value="year" <?= $periodType === 'year' ? 'selected' : '' ?>>Yearly</option>
                </select>
            </label>
            <?php if ($periodType === 'month'): ?>
                <label>Month
                    <select name="month" onchange="this.form.submit()">
                        <?php foreach ($monthNames as $num => $name): ?>
                            <option value="<?= $num ?>" <?= $month === $num ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <?php if ($periodType === 'quarter'): ?>
                <label>Quarter
                    <select name="quarter" onchange="this.form.submit()">
                        <?php for ($q = 1; $q <= 4; $q++): ?>
                            <option value="<?= $q ?>" <?= $quarter === $q ? 'selected' : '' ?>><?= $q ?><?= $q === 1 ? 'st' : ($q === 2 ? 'nd' : ($q === 3 ? 'rd' : 'th')) ?> Quarter</option>
                        <?php endfor; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label>Year <input type="number" name="year" value="<?= (int) $year ?>" min="2000" max="2100" style="width:6rem;"></label>
            <label>Sacrament
                <select name="sacrament" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach ($sacramentOptions as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filterSacrament === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <select name="status" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach ($statusOptions as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

        <h3 style="margin-top:2rem;">Certificate Requests</h3>
        <?php if (empty($certRows)): ?>
            <p>No certificate requests match this filter.</p>
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
            <p>No sacrament applications match this filter.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>Sacrament</th><th>Status</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($appRows as $row): ?>
                    <tr>
                        <td><?= e(humanize_label($row['sacrament_type'])) ?></td>
                        <td><span class="status-badge status-<?= e($row['status']) ?>"><?= e(humanize_label($row['status'])) ?></span></td>
                        <td><?= (int) $row['c'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    <?php require_once __DIR__ . '/../../includes/admin-footer.php';
            <button type="submit" class="btn btn-small">Apply</button>
        </form>

        <h2 style="margin-top:1.5rem;"><?= e($periodLabel) ?></h2>

        <h3>Records Encoded (by sacrament date)</h3>
        <table class="admin-table">
            <thead><tr><th>Sacrament</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($recordCounts as $label => $count): ?>
                    <tr><td><?= e($label) ?></td><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    $dateFrom = "{$year}-" . str_pad($qStart, 2, '0', STR_PAD_LEFT) . "-01";
    $qEndMonth = date('t', mktime(0, 0, 0, $qEnd, 1, $year));
    $dateTo = "{$year}-" . str_pad($qEnd, 2, '0', STR_PAD_LEFT) . "-{$qEndMonth}";
    $quarterLabels = ['1' => '1st', '2' => '2nd', '3' => '3rd', '4' => '4th'];
    $periodLabel = "{$quarterLabels[$quarter]} Quarter {$year}";
} else {
    $dateFrom = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
    $lastDay = date('t', mktime(0, 0, 0, $month, 1, $year));
    $dateTo  = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-{$lastDay}";
    $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
    $periodLabel = "{$monthNames[$month]} {$year}";
}

function humanize_label(string $s): string {
    return ucwords(str_replace('_', ' ', $s));
}

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
$sacramentOptions = ['baptismal', 'confirmation', 'first_communion', 'marriage'];
$statusOptions = ['pending', 'under_review', 'approved', 'ready_for_pickup', 'released', 'rejected'];