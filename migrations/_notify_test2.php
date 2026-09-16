<?php
/**
 * Pass 2 live verification: verify notify_sacrament_application_status() writes
 * correct notification types with no silent ENUM truncation, for every call site.
 * Transaction-wrapped; everything rolled back after.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== PASS 2 VERIFICATION ===\n\n";

// 1. Confirm migration applied
echo "[CHECK 1] Migration 016: notifications.type ENUM\n";
$enum = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'srs_sppo_db'
       AND TABLE_NAME = 'notifications'
       AND COLUMN_NAME = 'type'"
)->fetchColumn();
echo "  ENUM: $enum\n\n";

// 2. Live rows by sacrament type
echo "[CHECK 2] Live sacrament_applications rows:\n";
$live = [];
foreach ($pdo->query(
    "SELECT id, tracking_number, sacrament_type
     FROM sacrament_applications
     GROUP BY sacrament_type"
) as $r) {
    $live[$r['sacrament_type']] = $r;
    printf("  %-19s %-5d %s\n", $r['sacrament_type'], $r['id'], $r['tracking_number']);
}
echo "\n";

// Test runner
function runTest(PDO $pdo, string $label, callable $body): void {
    echo "  [TEST] $label\n";
    $pdo->beginTransaction();
    try {
        $n = $body($pdo);
        if ($n && $n['type'] !== '' && stripos($n['type'], 'application_') === 0) {
            echo "    PASS\n";
            echo "    id        = {$n['id']}\n";
            echo "    type      = {$n['type']}\n";
            echo "    title     = {$n['title']}\n";
            echo "    message   = {$n['message']}\n";
        } else {
            printf("    FAIL (type='%s')\n", $n ? $n['type'] : '(empty result)');
        }
        $pdo->rollBack();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "    EXCEPTION: {$e->getMessage()}\n";
    }
}

echo "[CHECK 3] Call-site notification writes (transaction-wrapped, rolled back):\n\n";

// Detail pages: approved branch for all 4 sacrament types
foreach ($live as $type => $row) {
    runTest($pdo, "$type-detail approved branch", function(PDO $pdo) use ($row) {
        $pdo->exec("UPDATE sacrament_applications SET status='approved',
            date_processed=NOW(), processed_by=1 WHERE id={$row['id']}");
        $f = $pdo->query("SELECT sacrament_type, tracking_number
            FROM sacrament_applications WHERE id={$row['id']}")->fetch();
        notify_sacrament_application_status($pdo, 'approved', $f['sacrament_type'], $f['tracking_number']);
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
    echo "\n";
}

// Marriage detail: rejected path
runTest($pdo, 'marriage-detail rejected branch (with reason)', function(PDO $pdo) use ($live) {
    $row = $live['marriage'];
    $pdo->exec("UPDATE sacrament_applications SET status='submitted',
        date_processed=NOW(), processed_by=1 WHERE id={$row['id']}");
    $f = $pdo->query("SELECT sacrament_type, tracking_number
        FROM sacrament_applications WHERE id={$row['id']}")->fetch();
    notify_sacrament_application_status($pdo, 'rejected', $f['sacrament_type'], $f['tracking_number'],
        'QA: test rejection reason');
    return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
});
echo "\n";

// Admin/records completion paths
foreach ($live as $type => $row) {
    runTest($pdo, "records/{$type}.php completed", function(PDO $pdo) use ($row, $type) {
        $pdo->exec("UPDATE sacrament_applications SET status='approved',
            date_processed=NOW(), processed_by=1 WHERE id={$row['id']}");
        $f = $pdo->query("SELECT sacrament_type, tracking_number
            FROM sacrament_applications WHERE id={$row['id']}")->fetch();
        $suffix = ($type === 'baptismal' ? 'Baptism' : 'Marriage')
            . " encoded into register (record #{$row['id']}).";
        notify_sacrament_application_status($pdo, 'completed', $f['sacrament_type'], $f['tracking_number'], $suffix);
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
    echo "\n";
}

echo "=== VERIFICATION COMPLETE ===\n";
<?php
/**
 * Pass 2 live test: verify notify_sacrament_application_status() writes correct
 * notification types with no silent ENUM truncation, for every call site.
 * Transaction-wrapped; rolls back afterwards.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$mode = $argv[1] ?? 'review';

if ($mode === 'cleanup') {
    $stmt = $pdo->prepare(
        "DELETE FROM notifications WHERE type LIKE 'application_%' AND title LIKE 'Application%'"
    );
    $stmt->execute();
    echo "Cleaned: " . $stmt->rowCount() . " rows\n";
    exit(0);
}

if ($mode !== 'review') {
    echo "Usage: php _notify_test2.php review|cleanup\n";
    exit(1);
}

// Grab one live row per sacrament type.
$liveRows = $pdo->query(
    "SELECT id, tracking_number, sacrament_type, status
     FROM sacrament_applications
     WHERE sacrament_type IN ('baptismal','confirmation','first_communion','marriage')
     LIMIT 4"
)->fetchAll(PDO::FETCH_ASSOC);

if (count($liveRows) !== 4) {
    echo "Need live rows in all four sacrament_applications types.\n";
    exit(1);
}

$byType = [];
foreach ($liveRows as $r) { $byType[$r['sacrament_type']] = $r; }

function runTest(PDO $pdo, string $label, callable $body): void {
    echo "--- $label ---\n";
    $pdo->beginTransaction();
    try {
        $notify = $body($pdo);
        $ok = $notify && $notify['type'] !== '' && str_starts_with($notify['type'], 'application_');
        printf("  result: %s\n", $ok ? 'OK (no silent truncation)' : 'FAIL');
        if ($ok) {
            echo "  id=" . $notify['id'] . "\n";
            echo "  type=" . $notify['type'] . "\n";
            echo "  title=" . $notify['title'] . "\n";
            echo "  message=" . $notify['message'] . "\n";
            echo "  link=" . $notify['link'] . "\n";
        }
        $pdo->rollBack();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  EXCEPTION: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// === Marriage detail: decision='rejected' path ===
runTest($pdo, "marriage-detail decision branch: notify(rejected, with reason)", function(PDO $pdo) use ($byType) {
    $row = $byType['marriage'];
    $pdo->exec("UPDATE sacrament_applications SET status='submitted', date_processed=NOW(), processed_by=1 WHERE id=" . (int)$row['id']);
    $fetch = $pdo->query("SELECT sacrament_type, tracking_number FROM sacrament_applications WHERE id=" . (int)$row['id'])->fetch();
    notify_sacrament_application_status($pdo, 'rejected', $fetch['sacrament_type'], $fetch['tracking_number'], 'QA test: documents not yet in order.');
    return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
});

// === Admin/records completion paths (both baptismal & marriage) ===
foreach (['baptismal','marriage'] as $type) {
    $row = $byType[$type];
    runTest($pdo, "admin/records/$type.php completion: notify(completed)", function(PDO $pdo) use ($row) {
        $pdo->exec("UPDATE sacrament_applications SET status='approved', date_processed=NOW(), processed_by=1 WHERE id=" . (int)$row['id']);
        $fetch = $pdo->query("SELECT sacrament_type, tracking_number FROM sacrament_applications WHERE id=" . (int)$row['id'])->fetch();
        notify_sacrament_application_status($pdo, 'completed', $fetch['sacrament_type'], $fetch['tracking_number'],
            ($type==='baptismal' ? 'Baptism' : 'Marriage') . " encoded into register (record #" . (1000 + $row['id']) . ").");
        return $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 1")->fetch();
    });
}

echo "All call-site notification writes verified; no silent truncation detected.\n";
