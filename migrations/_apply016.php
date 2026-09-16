<?php
/**
 * Apply migration 016: extend notifications.type ENUM with sacrament-application
 * status values used by notify_sacrament_application_status().
 * NOTE: This migration should only be applied ONCE. If the ENUM is already extended,
 * running it again will fail (duplicate values error in MySQL strict mode).
 */
require_once __DIR__ . '/../config/database.php';

try {
    $sql = file_get_contents(__DIR__ . '/016_add_application_notification_types.sql');
    $pdo->exec($sql);
    echo "Migration 016 applied successfully.\n";
} catch (PDOException $e) {
    // Check if this is a duplicate-enum-error (migration already applied)
    if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'duplicate') !== false) {
        echo "Migration 016 appears already applied (duplicate ENUM entries). Skipping.\n";
    } else {
        echo "Migration 016 FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
}