<?php
/**
 * Pass 2 live verification script — used for manual check before commit.
 * Transaction-wrapped, rolled back after; only reads notifications table.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== Pass 2 live verification: notify_sacrament_application_status() ===\n\n";

// Show current notifications.type ENUM
$enum = $pdo->query("
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'srs_sppo_db'
      AND TABLE_NAME   = 'notifications'
      AND COLUMN_NAME  = 'type'
")->fetchColumn();
echo "notifications.type ENUM: $enum\n\n";

// Show current distinct notification types
$types = $pdo->query("SELECT DISTINCT type FROM notifications ORDER BY type")->fetchAll(PDO::FETCH_ASSOC);
echo "Existing notification types in DB:\n";
foreach ($types as $t) {
    echo "  {$t['type']}\n";
}
echo "\n";

// Show live sacrament_applications
$sac = $pdo->query("
    SELECT sacrament_type, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
    FROM sacrament_applications
    GROUP BY sacrament_type
    ORDER BY sacrament_type
")->fetchAll(PDO::FETCH_ASSOC);
echo "sacrament_applications (live):\n";
foreach ($sac as $r) {
    echo "  {$r['sacrament_type']}: " . $r['cnt'] . " rows, ids: {$r['ids']}\n";
}
echo "\n";