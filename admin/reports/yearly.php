<?php
/**
 * =====================================================================
 * SRS-SPPO YEARLY REPORT — redirect shim (admin)
 * =====================================================================
 * Purpose: Old URL /admin/reports/yearly.php now lives at
 *          /admin/reports/index.php?view=yearly. Params (?year=)
 *          are preserved across the redirect.
 * =====================================================================
 */

$year = (int) ($_GET['year'] ?? date('Y'));
header('Location: index.php?view=yearly&year=' . $year, true, 301);
exit;