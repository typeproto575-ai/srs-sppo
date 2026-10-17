<?php
$dir = 'c:/xampp/htdocs/srs-sppo/migrations/';
$table = $argv[1] ?? 'sacrament_applications';
$pat = '/\bCREATE TABLE(?: IF NOT EXISTS)?\s+["\x60]?' . preg_quote($table, '/') . '/\i/';
foreach (scandir($dir) as $f) {
    if (strtolower(substr($f, -4)) !== '.sql') continue;
    $lines = file($dir . $f);
    $buf = '';
    foreach ($lines as $i => $l) {
        $buf .= $l;
        if (preg_match($pat, $buf)) {
            echo "==== $f ====\n";
            echo $buf;
            $buf = '';
        } else {
            if (strlen($buf) > 30000) { $buf = ''; }
        }
    }
}