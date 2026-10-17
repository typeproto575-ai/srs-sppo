<?php
$path = $argv[1] ?? 'confirmation.php';
$content = file_get_contents($path);
$open = substr_count($content, '{');
$close = substr_count($content, '}');
echo "Open: $open  Close: $close  Diff: " . ($open - $close) . PHP_EOL;
