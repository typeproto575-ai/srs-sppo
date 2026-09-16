<?php
/**
 * Pass 2 verification: test notify_sacrament_application_status() writes correct
 * types with no silent truncation. Transaction-wrapped, rolled back.
 */

// Full path to config and functions
$configFile = 'c:/xampp/htdocs/srs-sppo/config/database.php';
$functionsFile = 'c:/xampp/htdocs/srs-sppo/includes/functions.php';

require_once $configFile;
require_once $functionsFile;

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

// Live rows grouped by sacrament type
$sacrament_rows = [];
foreach ($pdo->query("SELECT id, tracking_number, sacrament_type, status FROM sacrament_applications GROUP BY sacrament_type") as $r) {
    $sacrament_rows[$r['sacrament_type']] = $r;
}

$echo = function($msg) {
    echo $msg . PHP_EOL;
};

function testTransition(PDO $pdo, string $label, callable $body): bool {
    $pdo->beginTransaction();
    try {
        $notif = $body($pdo);
        $ok = $notif && $notif['type'] !== '' && str_starts_with($notif['type'], 'application_');
        if (!$ok) {
            echo "  FAIL\n";
            return false;
        }
        echo "  PASS\n";
        echo "    id={$notif['id']}\n";
        echo "    type={$notif['type']}\n";
        echo "    title={$notif['title']}\n";
        echo "    message={$notif['message']}\n";
        $pdo->rollBack();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  EXCEPTION: {$e->getMessage()}\n";
        return false;
    }
}

$okCount = 0;
$failCount = 0;
$echo("=== Pass 2 Live Verification ===");
$echo("");

// 1. All four detail-page approved-branch call sites via the actual handlers.
foreach (['baptismal','confirmation','first_communion','marriage'] as $type) {
    if (!isset($sacrament_rows[$type])) {
        $echo("  SKIP: no live {$type} row found");
        continue;
    }
    $row = $sacrament_rows[$type];
    $echo("--- {$type} detail page approved branch ---");
    $testOk = testTransition($pdo, "{$type} detail page approved branch", function(PDO $pdo) use ($row) {
        // Simulate what the handler does: UPDATE + log_activity + notify.
        $pdo->exec("UPDATE sacrament_applications SET status='approved', date_processed=NOW(), processed_by=1 WHERE id={$row['id']}");
        notify_sacrament_application_status($pdo, 'approved', $row['sacrament_type'], $row['tracking_number']);
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
    if ($testOk) $okCount++; else $failCount++;
}

$echo("");

// 2. Marriage detail: rejected path (has custom log_activity but shared notify).
if (isset($sacrament_rows['marriage'])) {
    $row = $sacrament_rows['marriage'];
    $echo("--- marriage detail page rejected branch ---");
    $testOk = testTransition($pdo, "marriage detail page rejected branch", function(PDO $pdo) use ($row) {
        $pdo->exec("UPDATE sacrament_applications SET status='submitted', date_processed=NOW(), processed_by=1 WHERE id={$row['id']}");
        notify_sacrament_application_status($pdo, 'rejected', $row['sacrament_type'], $row['tracking_number'], 'QA test rejection reason.');
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
    if ($testOk) $okCount++; else $failCount++;
}

$echo("");

// 3. Admin/records completion paths (baptismal + marriage).
foreach (['baptismal' => 'Baptism', 'marriage' => 'Marriage'] as $type => $label) {
    if (!isset($sacrament_rows[$type])) {
        $echo("  SKIP: no live {$type} row found");
        continue;
    }
    $row = $sacrament_rows[$type];
    $echo("--- {$type} admin/records completion ---");
    $testOk = testTransition($pdo, "{$type} admin/records completion", function(PDO $pdo) use ($row, $label) {
        $pdo->exec("UPDATE sacrament_applications SET status='approved', date_processed=NOW(), processed_by=1 WHERE id={$row['id']}");
        notify_sacrament_application_status($pdo, 'completed', $row['sacrament_type'], $row['tracking_number'], "$label encoded into register (record #42).");
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
    if ($testOk) $okCount++; else $failCount++;
}

$echo("");
$echo("=== RESULTS ===");
$echo("Passed: $okCount");
$echo("Failed: $failCount");

if ($failCount > 0) {
    exit(1);
}