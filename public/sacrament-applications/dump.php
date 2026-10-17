<?php
$f = $argv[1] ?? 'confirmation.php';
$path = 'c:/xampp/htdocs/srs-sppo/public/sacrament-applications/' . $f;
if (!file_exists($path)) { fwrite(STDERR, "missing: $path\n"); exit(1); }
$lines = file($path);
foreach ($lines as $i => $l) {
    echo str_pad($i + 1, 4, ' ', STR_PAD_LEFT) . '| ' . trim($l) . "\n";
}