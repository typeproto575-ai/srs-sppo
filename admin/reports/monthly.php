<?php
/**
 * =====================================================================
 * SRS-SPPO MONTHLY REPORT — redirect shim (admin)
 * =====================================================================
 * Purpose: Old URL /admin/reports/monthly.php now lives at
 *          /admin/reports/index.php?view=monthly. Params (?month=&year=)
 *          are preserved across the redirect.
 * =====================================================================
 */

$month = (int) ($_GET['month'] ?? date('n'));
$year  = (int) ($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) $month = (int) date('n');
header('Location: index.php?view=monthly&month=' . $month . '&year=' . $year, true, 301);
exit;