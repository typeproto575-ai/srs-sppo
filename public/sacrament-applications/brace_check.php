<?php
$path = $argv[1] ?? 'confirmation.php';
$lines = file($path);
$open = 0; $close = 0;
$inPhp = false;
$results = [];
foreach ($lines as $i => $line) {
    $line = trim($line);
    if (strpos($line, '<?php') === 0 || strpos($line, '<?=')) { $inPhp = true; }
    if ($inPhp && $line !== '') {
        $open += substr_count($line, '{');
        $close += substr_count($line, '}');
        if ($open < 0) { $open = 0; }
    }
    if (strpos($line, '?>') === 0) { $inPhp = false; }
    if (($i + 1) % 20 === 0) {
        $results[] = sprintf("%5d | open=%d close=%d diff=%d", $i + 1, $open, $close, $open - $close);
    }
}
echo "TOTAL open=$open close=$close diff=" . ($open - $close) . PHP_EOL;
foreach ($results as $r) { echo $r . PHP_EOL; }
