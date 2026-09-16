<?php
/**
 * Pass 2 live verification: confirm notify_sacrament_application_status() writes
 * notification rows with correct application_* types (no silent ENUM truncation).
 * All tests are transaction-wrapped and rolled back.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== Pass 2 notification verification ===\n";
$pass = 0;
$fail = 0;

function test(PDO $pdo, string $label, callable $body): void
{
    global $pass, $fail;
    echo "\n--- $label ---\n";
    $pdo->beginTransaction();
    try {
        $notif = $body($pdo);
        $ok = $notif && $notif['type'] !== '' && str_starts_with($notif['type'], 'application_');
        if ($ok) {
            echo "  PASS\n";
            $pass++;
        } else {
            echo "  FAIL — type was '$notif[type]' (expected 'application_*')\n";
            $fail++;
        }
        if ($ok) {
            echo "    id={$notif['id']}\n";
            echo "    type={$notif['type']}\n";
            echo "    title={$notif['title']}\n";
            echo "    message={$notif['message']}\n";
        }
        $pdo->rollBack();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  FAIL — exception: {$e->getMessage()}\n";
        $fail++;
    }
}

// Get one live row per sacrament type.
$liveRows = $pdo->query(
    "SELECT id, tracking_number, sacrament_type FROM sacrament_applications GROUP BY sacrament_type"
)->fetchAll(PDO::FETCH_ASSOC);

if (count($liveRows) < 4) {
    die("Need live rows in all 4 sacrament types.\n");
}
$byType = [];
foreach ($liveRows as $r) { $byType[$r['sacrament_type']] = $r; }

// 1. Detail-page approved-branch call sites (all 4 sacrament types).
foreach (['baptismal','confirmation','first_communion','marriage'] as $type) {
    $row = $byType[$type];
    test($pdo, "detail approved branch ($type)", function($pdo) use ($row) {
        $pdo->exec("UPDATE sacrament_applications SET status='approved', date_processed=NOW(), processed_by=1 WHERE id={$row['id']}");
        $f = $pdo->query("SELECT sacrament_type, tracking_number FROM sacrament_applications WHERE id={$row['id']}")->fetch();
        notify_sacrament_application_status($pdo, 'approved', $f['sacrament_type'], $f['tracking_number']);
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
}

// 2. Marriage decision='rejected' path (with extra message).
$row = $byType['marriage'];
test($pdo, "marriage rejected branch (with reason)", function($pdo) use ($row) {
    $pdo->exec("UPDATE sacrament_applications SET status='submitted', date_processed=NOW(), processed_by=1 WHERE id={$row['id']}");
    $f = $pdo->query("SELECT sacrament_type, tracking_number FROM sacrament_applications WHERE id={$row['id']}")->fetch();
    notify_sacrament_application_status($pdo, 'rejected', $f['sacrament_type'], $f['tracking_number'], 'QA test: reason captured');
    return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
});

// 3. Admin/records completion paths (both baptismal & marriage).
foreach (['baptismal','marriage'] as $type) {
    $row = $byType[$type];
    test($pdo, "admin/records/$type.php completion", function($pdo) use ($row, $type) {
        $pdo->exec("UPDATE sacrament_applications SET status='approved', date_processed=NOW(), processed_by=1 WHERE id={$row['id']}");
        $f = $pdo->query("SELECT sacrament_type, tracking_number FROM sacrament_applications WHERE id={$row['id']}")->fetch();
        $extra = ($type==='baptismal' ? 'Baptism' : 'Marriage') . ' encoded into register (record #42).';
        notify_sacrament_application_status($pdo, 'completed', $f['sacrament_type'], $f['tracking_number'], $extra);
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
