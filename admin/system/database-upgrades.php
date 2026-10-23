<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/database-upgrades.php';

require_role('secretary');

$pageTitle = 'Database Setup';
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $message = 'Your session timed out. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $migrationKey = filter_input(INPUT_POST, 'migration_key', FILTER_UNSAFE_RAW);
        if (!is_string($migrationKey) || $migrationKey === '') {
            $message = 'Select a database update to apply.';
            $messageType = 'error';
        } else {
            try {
                srs_apply_database_upgrade($pdo, $migrationKey, (int) current_user()['id']);
                $message = 'Database update completed and verified.';
            } catch (Throwable $exception) {
                error_log('SRS-SPPO database upgrade failed: ' . $exception->getMessage());
                $message = 'The update did not finish. Review the database state and server error log before retrying.';
                $messageType = 'error';
            }
            if ($messageType === 'success') {
                try {
                    log_activity(
                        $pdo,
                        (int) current_user()['id'],
                        'database_upgrade_applied',
                        'srs_schema_migrations',
                        null,
                        'Applied database update: ' . $migrationKey
                    );
                } catch (Throwable $exception) {
                    error_log('SRS-SPPO database upgrade audit logging failed: ' . $exception->getMessage());
                    $message = 'The database update completed, but the audit entry could not be saved. Check the server error log.';
                    $messageType = 'error';
                }
            }
        }
    }
}

try {
    $upgrades = srs_database_upgrade_status($pdo);
} catch (Throwable $exception) {
    error_log('SRS-SPPO database upgrade status check failed: ' . $exception->getMessage());
    $upgrades = [];
    $message = 'Database upgrade status could not be read. Check the server error log.';
    $messageType = 'error';
}

require_once __DIR__ . '/../../includes/admin-header.php';
?>

<h1>Database Setup</h1>
<p class="field-hint">
    These updates add database support needed by the current system. They do not run
    automatically. New installations already include them.
</p>

<?php if ($message !== ''): ?>
    <div class="flash flash-<?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>

<div class="flash flash-error">
    Before updating, make a fresh database backup and confirm it can be restored.
    If you are unsure, ask your system administrator before continuing.
</div>

<div class="admin-card">
    <h2>Available updates</h2>
    <p class="field-hint">
        The system checks what your database already has before offering an update.
        This page does not run old SQL files from the project folder.
    </p>

    <?php if ($upgrades === []): ?>
        <p>Upgrade information is unavailable.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Update</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upgrades as $key => $upgrade): ?>
                        <?php
                        $blocked = $upgrade['blockers'] !== [];
                        $state = $blocked
                            ? 'Needs administrator review'
                            : ($upgrade['complete'] ? 'Already up to date' : 'Ready to update');
                        ?>
                        <tr>
                            <td><?= e($upgrade['title']) ?></td>
                            <td>
                                <?= e($upgrade['description']) ?>
                                <?php if ($upgrade['blockers'] !== []): ?>
                                    <ul>
                                        <?php foreach ($upgrade['blockers'] as $blocker): ?>
                                            <li><?= e($blocker) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td><?= e($state) ?></td>
                            <td>
                                <?php if (!$blocked && (!$upgrade['complete'] || !$upgrade['recorded'])): ?>
                                    <form method="post" data-confirm="Apply this database update? Make sure you have a verified backup first.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="migration_key" value="<?= e($key) ?>">
                                        <button type="submit" class="btn">
                                            <?= $upgrade['complete'] ? 'Mark as already updated' : 'Apply update' ?>
                                        </button>
                                    </form>
                                <?php elseif ($upgrade['complete']): ?>
                                    <span><?= $upgrade['recorded'] ? 'Up to date' : 'Already present' ?></span>
                                <?php else: ?>
                                    <span>Not applied</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p class="field-hint">
    If an update needs review, stop and ask your system administrator. Do not import sample
    data or run old SQL files to try to fix the issue.
</p>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
