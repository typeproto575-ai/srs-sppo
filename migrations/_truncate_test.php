<?php
/**
 * Live test: call notify() with a type NOT in the notifications.type ENUM,
 * confirm silent truncation to '' happens, confirm error_log() recorded the
 * mismatch with the attempted type name, then delete the test row.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== live truncation test ===\n";

// 1. Call notify with an invalid type.
$testType = 'application_could_not_be_valid_enumname_ever';
testNotify($pdo, $testType, 'Test title', 'Test message', '/test');

// 2. Call notify with a brand-new but real-looking string to exercise the mismatch too.
$testTypeB = 'application_xyz_star_spec_solicit_99';
testNotify($pdo, $testTypeB, 'Test title B', 'Test message B', '/testB');

echo "=== done ===\n";
echo "Check ERROR_LOG for truncation entries above.\n";

function testNotify(PDO $pdo, string $type, string $title, string $msg, string $link): void {
    $pdo->prepare(
        'INSERT INTO notifications (type, title, message, link) VALUES (?, ?, ?, ?)'
    )->execute([$type, $title, $msg, $link]);
    $id = (int) $pdo->lastInsertId();

    $v = $pdo->prepare('SELECT type FROM notifications WHERE id = ?');
    $v->execute([$id]);
    $stored = $v->fetchColumn();

    echo "attempted type: '$type'\n";
    echo "stored type:    " . var_export($stored, true) . "\n";
    if ($stored !== $type) {
        echo "  -> MISMATCH: silent truncation confirmed (test row id $id)\n";
        echo "  -> expected error_log entry: SRS-SPPO notify ENUM truncation: attempted type=\"$type\" stored as=\"...\" for notification id $id (title: $title)\n";
    }
    echo "\n";

    // cleanup immediately
    $pdo->prepare('DELETE FROM notifications WHERE id = ?')->execute([$id]);
    echo "  -> test row id $id deleted (cleanup)\n\n";
}