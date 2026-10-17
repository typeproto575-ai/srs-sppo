<?php
$f = fopen('c:/xampp/htdocs/srs-sppo/public/sacrament-applications/_test3.php', 'w');
fwrite($f, '<?php' . PHP_EOL);
fwrite($f, 'function foo() { }' . PHP_EOL);
fwrite($f, '<?php if ($x === \'y\'): ?>' . PHP_EOL);
fwrite($f, '    <p>Test</p>' . PHP_EOL);
fwrite($f, '<?php endif; ?>' . PHP_EOL);
fclose($f);
