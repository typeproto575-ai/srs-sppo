<?php
$path = $argv[1] ?? 'confirmation.php';
$content = file_get_contents($path);
$lines = explode("\n", $content);
$open = 0; $close = 0;
echo "Line | Open | Close | Diff\n";
echo "-----+--------+-------+------\n";
for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    // Remove PHP tags so we only count braces inside PHP code
    $line = preg_replace('/\<\?php/', '', $line);
    $line = preg_replace('/\?\>/', '', $line);
    $open += substr_count($line, '{');
    $close += substr_count($line, '}');
    if ($i % 10 === 0 || $open !== $close) {
        echo str_pad($i + 1, 5) . " | " . str_pad($open, 5) . " | " . str_pad($close, 5) . " | " . ($open - $close) . "\n";
    }
}
echo "-----+--------+-------+------\n";
echo "TOTAL open=$open close=$close diff=" . ($open - $close) . PHP_EOL;
