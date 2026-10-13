<?php
/**
 * Dependency-free project quality gate.
 *
 * Run with the PHP executable bundled with XAMPP:
 *   C:\xampp\php\php.exe scripts\quality-check.php
 *
 * The gate performs PHP syntax checks and focused regression tests for the
 * shared workflow rules. It does not connect to the parish database.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$phpBinary = PHP_BINARY;
$failures = 0;
$checks = 0;

function quality_check(bool $condition, string $description): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "[OK] {$description}\n";
        return;
    }
    $failures++;
    echo "[FAIL] {$description}\n";
}

function quality_assert_same(mixed $expected, mixed $actual, string $description): void
{
    quality_check($expected === $actual, $description . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function quality_run_syntax_check(string $phpBinary, string $file): bool
{
    $command = escapeshellarg($phpBinary) . ' -l ' . escapeshellarg($file);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return $exitCode === 0;
}

function quality_is_excluded(string $file): bool
{
    return str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
        || (str_contains($file, DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR)
            && str_starts_with(basename($file), '_'));
}

echo "SRS-SPPO quality gate\n";
echo "PHP: " . PHP_VERSION . " (" . PHP_BINARY . ")\n\n";

$phpFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $file = $fileInfo->getPathname();
    if (!quality_is_excluded($file)) {
        $phpFiles[] = $file;
    }
}
sort($phpFiles);

foreach ($phpFiles as $file) {
    quality_check(
        quality_run_syntax_check($phpBinary, $file),
        'PHP syntax: ' . str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $file)
    );
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';

quality_assert_same(
    true,
    cert_request_transition_valid('pending', 'under_review'),
    'Certificate requests allow pending to under review'
);
quality_assert_same(
    false,
    cert_request_transition_valid('released', 'approved'),
    'Certificate requests reject backward transitions after release'
);
quality_assert_same(
    false,
    cert_request_payment_gate([
        'submission_channel' => 'online',
        'status' => 'awaiting_payment',
        'paid_at' => null,
    ], 'approved')['ok'],
    'Unpaid online certificate requests cannot advance'
);
quality_assert_same(
    true,
    cert_request_payment_gate([
        'submission_channel' => 'online',
        'status' => 'awaiting_payment',
        'paid_at' => '2026-10-14 05:00:00',
    ], 'approved')['ok'],
    'Paid online certificate requests may advance'
);

quality_assert_same(
    true,
    sacrament_application_transition_valid('submitted', 'requirements_pending', 'baptismal'),
    'Baptism applications allow forward workflow transitions'
);
quality_assert_same(
    false,
    sacrament_application_transition_valid('completed', 'approved', 'marriage'),
    'Completed sacrament applications cannot move backward'
);
quality_assert_same(
    false,
    sacrament_application_payment_gate([
        'submission_channel' => 'online',
        'status' => 'awaiting_payment',
        'paid_at' => null,
    ], 'requirements_pending')['ok'],
    'Unpaid online sacrament applications cannot advance'
);

quality_assert_same(
    true,
    safe_internal_return_url('/admin/requests/queue.php?status=pending') !== null,
    'Valid admin return URLs are accepted'
);
quality_assert_same(
    null,
    safe_internal_return_url('https://example.com/admin/queue.php'),
    'External return URLs are rejected'
);
quality_assert_same(
    null,
    safe_internal_return_url('/admin/../../outside.php'),
    'Traversal return URLs are rejected'
);

quality_assert_same(
    true,
    event_times_overlap('09:00:00', '10:00:00', '09:30:00', '10:30:00'),
    'Overlapping schedule events are detected'
);
quality_assert_same(
    false,
    event_times_overlap('09:00:00', '10:00:00', '10:00:00', '11:00:00'),
    'Back-to-back schedule events are allowed'
);

echo "\nChecks: {$checks}\n";
echo "Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
