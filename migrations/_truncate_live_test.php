<?php
/**
 * Live test: call notify() with an invalid ENUM type, confirm the row stores
 * type='' (silent truncation), confirm error_log() recorded the mismatch with
 * the attempted type name, then delete the test row.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$invalidType = 'test_invalid_type_' . uniqid();

echo "Calling notify() with invalid type: '{$invalidType}'\n";

// Call notify with invalid type.
notify($pdo, $invalidType, 'Test notification', 'Testing truncation detection.', '/test');

// Fetch the inserted row back.
$stmt = $pdo->query('SELECT id, type, title, message, link FROM notifications ORDER BY id DESC LIMIT 1');
$row = $stmt->fetch();

if ($row) {
    echo "Row inserted: id={$row['id']}\n";
    echo "Stored type: '" . var_export($row['type'], true) . "'\n";
    echo "Expected:    '" . var_export($invalidType, true) . "'\n";
    echo "Match: " . ($row['type'] === $invalidType ? 'YES (no truncation)' : 'NO (truncation happened)') . "\n";

    if ($row['type'] !== $invalidType) {
        echo "CONFIRMED: silent truncation to '" . var_export($row['type'], true) . "' as expected.\n";
    }
} else {
    echo "ERROR: no row found after notify().\n";
    exit(1);
}

// Read the error log.
$logPath = ini_get('error_log') ?: 'C:\\xampp\\php\\logs\\php_error_log';
echo "\nChecking error log: {$logPath}\n";

if (file_exists($logPath)) {
    $log = file_get_contents($logPath);
    $needle = 'SRS-SPPO notify ENUM truncation: attempted type="' . $invalidType . '"';
    $pos = strpos($log, $needle);
    if ($pos !== false) {
        // Extract the matching line.
        $lines = explode("\n", $log);
        foreach ($lines as $line) {
            if (strpos($line, $needle) !== false) {
                echo "error_log RECORDED the truncation:\n";
                echo "  " . $line . "\n";
                break;
            }
        }
    } else {
        echo "WARNING: no truncation message found in error_log for type '{$invalidType}'.\n";
        echo "(The row was stored as '" . var_export($row['type'], true) . "' though, so truncation did happen.)\n";
    }
} else {
    echo "WARNING: error_log not found at {$logPath}\n";
}

// Clean up: delete the test row.
$stmt = $pdo->prepare('DELETE FROM notifications WHERE id = ?');
$stmt->execute([(int) $row['id']]);
echo "\nCleaned up row id={$row['id']}. Remaining notifications: " . $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() . "\n";

echo "\nDone.\n";
<?php
/**
 * Live test: call notify() with a type NOT in the ENUM,
 * confirm silent truncation to '', confirm error_log recorded the mismatch,
 * then delete the test row.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$testType = 'bogus_enum_value_for_live_test';
$testTitle = 'Truncation live-test notification';
$testMessage = 'This notification is deliberately created with an invalid ENUM type to verify the post-INSERT truncation detection in notify().';
$testLink = '/test/link';

echo "Calling notify() with bogus type: {$testType}\n";

// Capture error_log output if we can redirect it.
// error_log() writes to the SAPI error log; we'll check the file separately.
notify($pdo, $testType, $testTitle, $testMessage, $testLink);

$row = $pdo->query("SELECT id, type FROM notifications WHERE title = '{$testTitle}'")->fetch();

echo "Inserted row: id={$row['id']}, stored type=" . var_export($row['type'], true) . "\n";

if ($row['type'] === '') {
    echo "CONFIRMED: type stored as empty string (silent truncation), as expected with non-strict sql_mode.\n";
} else {
    echo "UNEXPECTED: type is not empty. ENUM may already contain this value, or strict mode is on.\n";
}

// Check error_log file
$logFile = 'C:\\xampp\\php\\logs\\php_error_log';
if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    if (strpos($content, 'SRS-SPPO notify ENUM truncation') !== false) {
        echo "CONFIRMED: error_log recorded the ENUM truncation detection.\n";
        // Extract the relevant line
        $lines = explode("\n", $content);
        $relevant = array_filter($lines, function($l) use ($testTitle) {
            return strpos($l, 'SRS-SPPO notify ENUM truncation') !== false && strpos($l, $testTitle) !== false;
        });
        foreach ($relevant as $line) {
            echo "LOG LINE: {$line}\n";
        }
    } else {
        echo "WARNING: error_log file exists but does NOT contain the truncation message.\n";
    }
} else {
    echo "WARNING: error_log file not found at {$logFile}.\n";
}

// Clean up
$pdo->exec("DELETE FROM notifications WHERE id = {$row['id']}");
echo "Cleaned up test row id={$row['id']}.\n";
echo "Remaining test rows with title '{$testTitle}': " . $pdo->query("SELECT COUNT(*) FROM notifications WHERE title = '{$testTitle}'")->fetchColumn() . "\n";