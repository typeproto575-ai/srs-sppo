<?php
/**
 * Lint every PHP file in the project (recursive from project root).
 * Run from the migrations/ directory. Reports only failures, or "all pass".
 */
$php = 'C:\\xampp\\php\\php.exe';
$root = dirname(__DIR__); // project root (parent of migrations/)

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
);

$failures = [];
$total = 0;

foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if ($path === __FILE__) {
        continue; // skip this script itself
    }
    $total++;
    $cmd = $php . ' -l ' . escapeshellarg($path) . ' 2>&1';
    $output = [];
    $retval = null;
    exec($cmd, $output, $retval);
    if ($retval !== 0) {
        $failures[] = [
            'file' => $path,
            'output' => implode("\n", array_filter($output)),
        ];
    }
}

$passed = $total - count($failures);
echo "PHP files linted: $total\n";
echo "Passed: $passed\n";
echo "Failed: " . count($failures) . "\n";

if ($failures) {
    echo "\n=== FAILURES ===\n\n";
    foreach ($failures as $f) {
        echo "FILE: {$f['file']}\n";
        echo "OUTPUT:\n{$f['output']}\n\n";
    }
} else {
    echo "\nAll PHP files pass syntax lint. No failures.\n";
}