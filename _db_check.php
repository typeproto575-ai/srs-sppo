<?php
require_once 'config/database.php';

echo "=== Database Check ===\n\n";

// Check site_settings table
try {
    $count = $pdo->query("SELECT COUNT(*) FROM site_settings")->fetchColumn();
    echo "site_settings table: EXISTS ($count rows)\n";
    $rows = $pdo->query("SELECT * FROM site_settings")->fetchAll();
    foreach ($rows as $row) {
        echo "  - {$row['setting_key']}: {$row['setting_value']}\n";
    }
} catch (Exception $e) {
    echo "site_settings table: MISSING\n";
}

echo "\n";

// Check announcements
try {
    $count = $pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
    echo "announcements table: $count rows\n";
    $rows = $pdo->query("SELECT title, event_date, is_published FROM announcements ORDER BY event_date DESC LIMIT 5")->fetchAll();
    foreach ($rows as $row) {
        echo "  - {$row['title']} ({$row['event_date']}) published={$row['is_published']}\n";
    }
} catch (Exception $e) {
    echo "announcements table: ERROR - " . $e->getMessage() . "\n";
}

echo "\n";

// Check schedule_events
try {
    $count = $pdo->query("SELECT COUNT(*) FROM schedule_events")->fetchColumn();
    echo "schedule_events table: $count rows\n";
    $rows = $pdo->query("SELECT title, event_type, event_date FROM schedule_events ORDER BY event_date ASC LIMIT 10")->fetchAll();
    foreach ($rows as $row) {
        echo "  - {$row['title']} [{$row['event_type']}] on {$row['event_date']}\n";
    }
} catch (Exception $e) {
    echo "schedule_events table: ERROR - " . $e->getMessage() . "\n";
}