<?php
$f = $argv[1] ?? 'confirmation.php';
$path = 'c:/xampp/htdocs/srs-sppo/public/sacrament-applications/' . $f;
if (!file_exists($path)) { fwrite(STDERR, "missing: $path\n"); exit(1); }
$lines = file($path);
$cnt = 0;
foreach ($lines as $i => $l) {
    echo ($i + 1) . '| ' . $l;
    $cnt++;
    if ($cnt >= 8) break;
}