<?php
/**
 * Database connection (PDO) for SPPO-IS.
 * XAMPP defaults assumed -- change credentials for production.
 */

$DB_HOST = 'localhost';
$DB_NAME = 'sppo_is_db';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // In production, log this instead of echoing it.
    die('Database connection failed: ' . $e->getMessage());
}
