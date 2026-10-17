<?php
$f = $argv[1] ?? 'confirmation.php';
$path = 'c:/xampp/htdocs/srs-sppo/public/sacrament-applications/' . $f;
$lines = file($path);
$start = max(0, count($lines) - 20);
for ($i = $start; $i < count($lines); $i++) {
    echo ($i + 1) . '| ' . $lines[$i];
}