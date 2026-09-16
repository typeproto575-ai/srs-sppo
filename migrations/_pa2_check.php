<?php
/**
 * Pass 2 verification — reads current DB state, no writes.
 */
require_once __DIR__ . '/../config/database.php';

echo "=== Pass 2 final verification ===\n\n";

// 1. notifications.type ENUM
$enum = $pdo->query("
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'srs_sppo_db'
      AND TABLE_NAME   = 'notifications'
      AND COLUMN_NAME  = 'type'
")->fetchColumn();
echo "notifications.type: $enum\n\n";

// 2. Existing app_* types present
$present = $pdo->query("SELECT DISTINCT type FROM notifications WHERE type LIKE 'application_%' ORDER BY type")->fetchAll(PDO::FETCH_ASSOC);
echo "application_* types present in notifications table:\n";
foreach ($present as $r) {
    echo "  {$r['type']}\n";
}
echo "\n";

// 3. sacrament_applications summary (types available for testing)
$sac = $pdo->query("
    SELECT sacrament_type, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
    FROM sacrament_applications GROUP BY sacrament_type ORDER BY sacrament_type
")->fetchAll(PDO::FETCH_ASSOC);
echo "sacrament_applications available for tests:\n";
foreach ($sac as $r) {
    echo "  {$r['sacrament_type']}: {$r['cnt']} rows, ids={$r['ids']}\n";
}
echo "\n";