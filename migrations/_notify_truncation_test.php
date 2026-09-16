<?php
/**
 * Verify: notify() with an invalid ENUM type should:
 * 1. INSERT the row with type='' (silent truncation still happens).
 * 2. error_log() should record the mismatch with the attempted type name.
 *
 * We'll write the actual PHP error log to a temp file and check its contents.
 */

$configFile = 'c:/xampp/htdocs/srs-sppo/config/database.php';
$functionsFile = 'c:/xampp/htdocs/srs-sppo/includes/functions.php';
$phpLogPath = 'c:/xampp/php/logs/php_error_log';  // common XAMPP path; fallback below

require_once $configFile;
require_once $functionsFile;

// Redirect error_log to a temp file we control for this test.
$testLogPath = 'c:/xampp/htdocs/srs-sppo/migrations/_notify_test_errorlog.txt';
ini_set('error_log', $testLogPath);

// 1. Use a type NOT in the ENUM.
$badType = 'application_this_should_never_exist_xyz';
echo "Calling notify() with invalid type: '{$badType}'\n";

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
notify($pdo, $badType, 'Test bad type notification', 'This is a test', '/test/path');

// 2. Confirm the row was inserted with type=''.
$notif = $pdo->query("SELECT * FROM notifications WHERE type = '' ORDER BY id DESC LIMIT 1")->fetch();
if ($notif) {
    echo "PASS: Row inserted with type='' (silent truncation confirmed): id={$notif['id']}\n";
} else {
    echo "FAIL: No row with type='' found (unexpected)\n";
}

// 3. Confirm error_log recorded the mismatch.
if (file_exists($testLogPath)) {
    $logContent = file_get_contents($testLogPath);
    if (strpos($logContent, 'SRS-SPPO notify ENUM truncation') !== false
        && strpos($logContent, $badType) !== false) {
        echo "PASS: error_log recorded the truncation mismatch, including attempted type '{$badType}'\n";
        echo "\n--- error_log excerpt ---\n";
        // Show the relevant line(s).
        $lines = explode("\n", $logContent);
        foreach ($lines as $line) {
            if (strpos($line, 'SRS-SPPO notify ENUM truncation') !== false) {
                echo "  " . $line . "\n";
            }
        }
        echo "--- end ---\n";
    } else {
        echo "FAIL: error_log exists but does not contain the truncation message\n";
        echo "Contents:\n" . $logContent . "\n";
    }
} else {
    echo "FAIL: error_log file not created at {$testLogPath}\n";
    // Try other common paths.
    $altPaths = [
        'c:/xampp/php/logs/php_error_log',
        'c:/xampp/apache/logs/php_error_log',
        'c:/xampp/php/php_error_log',
    ];
    foreach ($altPaths as $p) {
        if (file_exists($p)) {
            $c = file_get_contents($p);
            if (strpos($c, 'SRS-SPPO notify ENUM truncation') !== false) {
                echo "  (Found in alternate path: {$p})\n";
                $lines = explode("\n", $c);
                foreach ($lines as $line) {
                    if (strpos($line, 'SRS-SPPO notify ENUM truncation') !== false) {
                        echo "    " . $line . "\n";
                    }
                }
                break;
            }
        }
    }
}

// Cleanup: delete the test row.
if ($notif) {
    $pdo->exec("DELETE FROM notifications WHERE id = {$notif['id']}");
    echo "\nCleaned up: deleted test row id={$notif['id']}\n";
}

// Restore default error_log.
ini_set('error_log', '');