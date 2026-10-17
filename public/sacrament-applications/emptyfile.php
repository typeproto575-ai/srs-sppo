<?php
$f = $argv[1] ?? 'confirmation.php';
$path = 'c:/xampp/htdocs/srs-sppo/public/sacrament-applications/' . $f;
file_put_contents($path, "<?php\n");
echo "truncated\n";