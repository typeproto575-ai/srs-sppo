<?php
require_once __DIR__ . '/../config/database.php';
echo "=== Current notifications inventory ===\n";
$rows = $pdo->query("SELECT type, COUNT(*) as cnt FROM notifications GROUP BY type ORDER BY type")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  {$r['type']}: {$r['cnt']} rows\n";
}
echo "\nTotal: " . $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn() . " rows\n";
