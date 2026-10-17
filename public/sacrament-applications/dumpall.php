<?php
$f = $argv[1] ?? 'confirmation.php';
$path = 'c:/xampp/htdocs/srs-sppo/public/sacrament-applications/' . $f;
$lines = file($path);
foreach ($lines as $i => $l) {
    echo ($i + 1) . '| ' . $l;
}