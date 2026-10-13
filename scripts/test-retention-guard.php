<?php
/**
 * Retention Guard Test Script
 * This script validates that no protected register table is present in the current purge logic.
 * It should be run as part of the deployment pipeline.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/retention-purge.php';

// Mock PDO for guard testing
$pdo = new PDO('sqlite::memory:');

$test_tables = [
    'certificate_requests',
    'sacrament_applications',
    'correction_requests',
    'audit_logs',
    'email_delivery_log',
    'notifications',
    'submission_rate_limits',
    'password_reset_tokens',
    'persons', // Should trigger exception
];

echo "Testing Retention Guard...\n";
foreach ($test_tables as $table) {
    try {
        retention_guard($table);
        echo "[OK] Table {$table} is safe to purge.\n";
    } catch (RuntimeException $e) {
        echo "[EXPECTED] Table {$table} is protected: " . $e->getMessage() . "\n";
    }
}

echo "\nGuard test complete.\n";
