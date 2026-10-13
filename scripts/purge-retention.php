#!/usr/bin/env php
<?php
/**
 * =====================================================================
 * SRS-SPPO RETENTION PURGE — CLI launcher
 * =====================================================================
 * Purges old submissions, scrubs old audit rows, and deletes old
 * disposable rows, following the periods configured in
 * config/environment.php (via getenv with defaults: 60/36/30).
 *
 * Sacramental register entries (*_records, persons) are NEVER touched.
 *
 * Usage:
 *   php scripts/purge-retention.php --dry-run
 *   php scripts/purge-retention.php --confirm
 *   php scripts/purge-retention.php --confirm --yes
 *   php scripts/purge-retention.php --help
 *
 * The execute path writes ONE audit_logs row ('retention_purged') with
 * counts only — no personal data.
 *
 * NOTE: For scheduled runs, only run this as the same MariaDB user used
 * by the web server (so $pdo below, created by config/database.php,
 * reaches MariaDB). When run from Task Scheduler / cron, give the
 * scheduled task the database credentials if you are not using the
 * XAMPP defaults.
 * =====================================================================
 */

if (PHP_SAPI !== 'cli') {
    fwrite(
        STDERR,
        "ERROR: this script must be run from the command line (PHP CLI).\n"
    );
    exit(1);
}

// --- Help text ---------------------------------------------------------
$help = <<<'EOT'
SRS-SPPO Retention Purge (CLI)

Usage:
  php scripts/purge-retention.php [options]

Options:
  --dry-run      Show what would be purged (counts only, changes nothing).
  --confirm      Execute the purge. Requires --dry-run first, or --yes.
  --yes          Skip the confirmation prompt when used with --confirm.
  --help         Show this help message.

Environment (optional overrides):
  SRS_DB_HOST        database host (default: localhost)
  SRS_DB_NAME        database name (default: srs_sppo_db)
  SRS_DB_USER        database user (default: root)
  SRS_DB_PASS        database password (default: '')
  SRS_RETENTION_SUBMISSIONS_MONTHS  submissions purge period (default: 60)
  SRS_RETENTION_AUDIT_MONTHS        audit scrub period (default: 36)
  SRS_RETENTION_RATELIMIT_DAYS      rate-limit delete period (default: 30)

Exit codes:
  0  success
  1  bad arguments, purge failed, or the confirmation step did not run
EOT;

$opts = getopt('', ['dry-run', 'confirm', 'yes', 'help']);

if (isset($opts['help'])) {
    echo $help;
    exit(0);
}

$dryRun = isset($opts['dry-run']);
$confirm = isset($opts['confirm']);
$yes = isset($opts['yes']);

if ($confirm && !$yes && !$dryRun) {
    // Interactive guard: never execute without a prior dry run unless --yes.
    fwrite(STDOUT, "Dry run required before confirm (use --dry-run first).\n");
    echo $help;
    exit(1);
}

// --- Load dependencies -------------------------------------------------
require_once __DIR__ . '/../config/database.php'; // creates $pdo
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/retention-purge.php';

// Actor: CLI runs as the system (audit_logs.user_id is nullable, so NULL
// is fine). The 'retention_purged' audit line is counts-only.
$actorId = null;

// --- Define the active periods for reporting --------------------------
$c = retention_cutoffs();

if ($dryRun) {
    $counts = retention_dry_run($pdo);
    fwrite(STDOUT, "RETENTION DRY RUN — what would be touched:\n");
    foreach ($counts as $bucket => $n) {
        fwrite(STDOUT, sprintf("  - %-28s %d rows\n", $bucket, (int) $n));
    }
    fwrite(STDOUT, sprintf("\nRetention periods: submissions=%d months, audit=%d months, rate-limit=%d days\n",
        $c['sub_months'], $c['audit_months'], $c['rate_days']));
    fwrite(STDOUT, "Nothing was changed.\n");
    exit(0);
}

if ($confirm) {
    if (!$yes) {
        fwrite(STDOUT, "WARNING: this will permanently anonymize purged rows and delete disposable rows.\n");
        fwrite(STDOUT, "A dry run of the same periods shows these counts:\n");
        $preview = retention_dry_run($pdo);
        foreach ($preview as $bucket => $n) {
            fwrite(STDOUT, sprintf("  - %-28s %d rows\n", $bucket, (int) $n));
        }
        fwrite(STDOUT, "Execute now? [y/N]: ");
        $handle = fopen('php://stdin', 'r');
        $line = fgets($handle);
        fclose($handle);
        $line = trim($line);
        if (!in_array(strtolower($line), ['y', 'yes'], true)) {
            fwrite(STDOUT, "Aborted by user.\n");
            exit(0);
        }
    }

    try {
        $affected = retention_execute($pdo, $actorId);
        fwrite(STDOUT, "Retention purge complete.\n");
        foreach ($affected as $bucket => $n) {
            fwrite(STDOUT, sprintf("  - %-28s %d rows\n", $bucket, (int) $n));
        }
        exit(0);
    } catch (Throwable $t) {
        fwrite(STDERR, "Purge failed: " . $t->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "No action taken.\n");
echo $help;
exit(0);
