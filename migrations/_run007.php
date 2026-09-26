<?php
/**
 * Developer-only one-off helper for seeding site settings during migration.
 * Run from the project root only when an operator explicitly needs it.
 */
require_once __DIR__ . '/../config/database.php';

$insertOfficeHours = $pdo->prepare(
    "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_value = ?"
);
$insertOfficeHours->execute(
    ["office_hours_text",
    "Monday - Saturday: 8:00 AM - 12:00 NN\nMonday - Saturday: 2:00 PM - 5:00 PM\nSunday: Closed",
    "Monday - Saturday: 8:00 AM - 12:00 NN\nMonday - Saturday: 2:00 PM - 5:00 PM\nSunday: Closed"]
);

$insertOfficeLocation = $pdo->prepare(
    "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_value = ?"
);
$insertOfficeLocation->execute(
    ["office_location_text",
     "St. Patrick's Parish Office\nDingalan, Aurora, Philippines",
     "St. Patrick's Parish Office\nDingalan, Aurora, Philippines"]
);

$count = (int) $pdo->query("SELECT COUNT(*) FROM site_settings")->fetchColumn();
echo "site_settings now has {$count} rows.\n";
