<?php
$path = $argv[1] ?? 'confirmation.php';
$lines = file($path, FILE_IGNORE_NEW_LINES);
echo "Total lines: " . count($lines) . PHP_EOL;
echo "Line 212: [" . ($lines[211] ?? 'MISSING') . "]" . PHP_EOL;
echo "Line 213: [" . ($lines[212] ?? 'MISSING') . "]" . PHP_EOL;
echo "Line 214: [" . ($lines[213] ?? 'MISSING') . "]" . PHP_EOL;
