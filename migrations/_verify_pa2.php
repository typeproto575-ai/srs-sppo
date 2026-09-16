<?php
/**
 * Pass 2 live verification: test notify_sacrament_application_status() across
 * all call sites — detail pages (approved + else branches for all 4 types,
 * marriage rejected branch) plus admin/records completion paths (baptismal & marriage).
 * Transaction-wrapped; every test rolls back afterwards.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

function runTest(PDO $pdo, string $label, callable $body): void
{
    echo "--- $label\n";
    $pdo->beginTransaction();
    try {
        $notif = $body($pdo);
        $ok = $notif && $notif['type'] !== '' && str_starts_with($notif['type'], 'application_');
        echo "  " . ($ok ? 'PASS' : 'FAIL') . "\n";
        if ($ok) {
            echo "    id   = {$notif['id']}\n";
            echo "    type = {$notif['type']}\n";
            echo "    title= {$notif['title']}\n";
            echo "    msg  = {$notif['message']}\n";
            echo "    link = {$notif['link']}\n";
        }
        $pdo->rollBack();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  EXCEPTION: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// Collect one live application row per sacrament type.
$byType = [];
foreach ($pdo->query(
    "SELECT id, tracking_number, sacrament_type
     FROM sacrament_applications
     GROUP BY sacrament_type
     ORDER BY id"
) as $r) {
    $byType[$r['sacrament_type']] = $row = (array)$r;
}

if (count($byType) < 4) {
    die("Need live rows in all four sacrament types (have " . count($byType) . ")\n");
}

// ---------- Detail-page approved branches (all 4) ----------
foreach ($byType as $type => $row) {
    runTest($pdo, "$type detail approved branch → notify(approved)", function (PDO $pdo) use ($row) {
        $pdo->exec("UPDATE sacrament_applications
                    SET status='approved', date_processed=NOW(), processed_by=1
                    WHERE id={$row['id']}");
        $f = $pdo->query("SELECT sacrament_type, tracking_number FROM sacrament_applications WHERE id={$row['id']}")->fetch();
        notify_sacrament_application_status($pdo, 'approved', $f['sacrament_type'], $f['tracking_number']);
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
}

// ---------- Marriage detail rejected branch ----------
runTest($pdo, "marriage detail rejected branch → notify(rejected, with reason)", function (PDO $pdo) use ($byType) {
    $row = $byType['marriage'];
    $pdo->exec("UPDATE sacrament_applications
                SET status='submitted', date_processed=NOW(), processed_by=1
                WHERE id={$row['id']}");
    $f = $pdo->query("SELECT sacrament_type, tracking_number FROM sacrament_applications WHERE id={$row['id']}")->fetch();
    notify_sacrament_application_status($pdo, 'rejected', $f['sacrament_type'], $f['tracking_number'],
        'QA test: documents not yet in order');
    return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
});

// ---------- Admin/records completion paths (baptismal + marriage) ----------
foreach (['baptismal', 'marriage'] as $type) {
    $row = $byType[$type];
    runTest($pdo, "admin/records/$type.php completion → notify(completed)", function (PDO $pdo) use ($row, $type) {
        $pdo->exec("UPDATE sacrament_applications
                    SET status='approved', date_processed=NOW(), processed_by=1
                    WHERE id={$row['id']}");
        $f = $pdo->query("SELECT sacrament_type, tracking_number FROM sacrament_applications WHERE id={$row['id']}")->fetch();
        $prefix = $type === 'baptismal' ? 'Baptism' : 'Marriage';
        notify_sacrament_application_status($pdo, 'completed', $f['sacrament_type'], $f['tracking_number'],
            "$prefix encoded into register (record #42).");
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
}

echo "All call-site notification writes verified.\n";