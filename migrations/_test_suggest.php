<?php
// Live DB round-trip test of the generate.php WHERE-assembly fix.
require_once __DIR__ . '/../config/database.php';

$base = "WHERE status IN ('approved','ready_for_pickup')";
$cases = [
    'search active'  => [' AND (tracking_number LIKE ? OR sacrament_type LIKE ? OR requester_name LIKE ?)', ['%F%', '%F%', '%F%']],
    'range active'   => [' AND YEAR(date_submitted) = YEAR(CURDATE())', []],
    'both'           => [' AND (tracking_number LIKE ?) AND YEAR(date_submitted) = YEAR(CURDATE())', ['%F%']],
    'no filter'      => ['', []],
];
$ok = true;
foreach ($cases as $label => [$w, $params]) {
    $sql = "SELECT COUNT(*) FROM certificate_requests " . $base . $w;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $n = $stmt->fetchColumn();
        echo "[PASS] $label -> count=$n" . PHP_EOL;
    } catch (PDOException $e) {
        $ok = false;
        echo "[FAIL] $label -> " . $e->getMessage() . PHP_EOL;
    }
}
exit($ok ? 0 : 1);
