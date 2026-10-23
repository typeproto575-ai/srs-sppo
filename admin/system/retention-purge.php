<?php
/**
 * =====================================================================
 * SRS-SPPO RETENTION & PURGE (parish secretary only)
 * =====================================================================
 * Two-step retention purge:
 *   1. DRY RUN  — counts what would be touched (zero data changes).
 *   2. CONFIRM  — executes the purge and writes ONE audit_logs line.
 *
 * Rules (see includes/retention-purge.php and config/environment.php):
 *   - Sacramental register entries (*_records, persons) are permanent
 *     and are NEVER purged or anonymized here.
 *   - Submissions: only finished rows older than
 *     SRS_RETENTION_SUBMISSIONS_MONTHS. Open rows are never touched.
 *   - audit_logs: rows older than SRS_RETENTION_AUDIT_MONTHS are
 *     SCRUBBED (ip/user_agent nulled, email stripped), never deleted.
 *   - submission_rate_limits, email_delivery_log, notifications, and
 *     expired/used password_reset_tokens are DELETED.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/retention-purge.php';

require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Retention & Purge';
$selfUrl = $basePath . '/admin/system/retention-purge.php';

// CSRF token (idempotent; csrf_field() would also work).
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// --- Handle the confirmed purge (POST only).
$flash = '';
$flashIsError = false;
$dry = null;
try {
    $dry = retention_dry_run($pdo);
} catch (PDOException $e) {
    error_log('SRS-SPPO retention dry run failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retention_confirmed'])) {
    if (!csrf_verify()) {
        $flash = 'Your session timed out. Please refresh the page and try again.';
        $flashIsError = true;
    } elseif ($dry === null) {
        $flash = 'The purge was not run because the database readiness check failed. Check that migration 038_data_retention_support.sql is applied, then reload this page.';
        $flashIsError = true;
    } else {
        try {
            $affected = retention_execute($pdo, current_user()['id']);
            $flash = 'Retention purge complete. Affected rows: '
                   . implode(', ', array_map(static fn($bucket, $count) => "{$bucket}={$count}", array_keys($affected), $affected))
                   . '.';
        } catch (Throwable $t) {
            error_log('SRS-SPPO retention purge failed: ' . $t->getMessage());
            $flash = 'Purge failed. No changes were committed; check the server error log for details.';
            $flashIsError = true;
        }
    }

    if (!$flashIsError) {
        try {
            $dry = retention_dry_run($pdo);
        } catch (PDOException $e) {
            error_log('SRS-SPPO retention dry run after purge failed: ' . $e->getMessage());
            $dry = null;
        }
    }
}
?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>

<h1>Retention & Purge</h1>
<p class="field-hint">Anonymizes old finished submissions, scrubs old audit rows, and deletes old disposable rows (rate limits, mail log, notifications, expired reset tokens). <strong>Sacramental register entries are permanent and are never touched.</strong></p>

<?php if ($flash !== ''): ?>
    <div class="flash flash-<?= $flashIsError ? 'error' : 'success' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<?php if ($dry === null): ?>
    <div class="flash flash-error">
        Retention tools could not read the required database data. The hosted database may be missing required tables or columns.
        Apply <code>migrations/038_data_retention_support.sql</code> to the hosted database, then reload this page.
        If the migration is already applied, check the server error log for the exact database error.
    </div>
<?php else: ?>
    <h2>1. Dry Run — How Much Would Be Affected?</h2>
    <p class="field-hint">Counts only. <strong>Nothing is changed.</strong> Run a dry run first, then confirm.</p>
    <div class="table-scroll">
        <table class="admin-table" style="max-width:420px;">
            <thead>
                <tr><th>Bucket</th><th>Rows Touched</th></tr>
            </thead>
            <tbody>
                <?php foreach ($dry as $bucket => $count): ?>
                    <tr>
                        <td><?= e(ucwords(str_replace('_', ' ', $bucket))) ?></td>
                        <td><?= (int) $count ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2>2. Confirm Purge</h2>
    <p class="field-hint">This writes <strong>one</strong> audit_logs row with counts only (no personal data). After purging, the same dry run should show <strong>0</strong> affected rows.</p>

    <form autocomplete="off" method="post" style="margin-top:1rem;">
        <?= csrf_field() ?>
        <button type="submit" name="retention_confirmed" value="1" class="btn btn-danger">Execute Retention Purge</button>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
