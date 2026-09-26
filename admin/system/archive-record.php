<?php
/** Protected non-destructive archive/restore action for sacramental records. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = base_path();
$allowed = [
    'baptismal_records' => 'Baptismal record',
    'confirmation_records' => 'Confirmation record',
    'first_communion_records' => 'First Communion record',
    'marriage_records' => 'Marriage record',
    'death_burial_records' => 'Death/Burial status record',
];

function archive_record_safe_return(?string $return): ?string
{
    $value = trim((string) ($return ?? ''));
    if ($value === '') {
        return null;
    }

    if (preg_match('#^/admin(?:/[A-Za-z0-9._-]+)+\.php(?:\?.*)?$#', $value) !== 1) {
        return null;
    }

    return $value;
}

$message = '';
$error = '';
$table = (string) ($_GET['table'] ?? $_POST['table'] ?? '');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'archive');
if (!in_array($action, ['archive', 'restore'], true)) {
    $action = 'archive';
}
$return = archive_record_safe_return($_GET['return'] ?? $_POST['return'] ?? null);
$record = null;
$recordName = null;
$recordDate = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Security token expired. Reload and try again.';
    }

    $table = (string) ($_POST['table'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? 'archive');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $confirm = (string) ($_POST['confirm'] ?? '') === '1';
    $return = archive_record_safe_return($_POST['return'] ?? null);

    if (!$error && (!isset($allowed[$table]) || $id < 1)) {
        $error = 'A valid record must be selected.';
    }
    if (!$error && $reason === '') {
        $error = 'A reason is required.';
    }
    if (!$error && !$confirm) {
        $error = 'Please confirm this action before saving.';
    }

    if (!$error) {
        $check = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $check->execute([$id]);
        $record = $check->fetch();

        if (!$record) {
            $error = 'Record not found.';
        } elseif ($action === 'archive' && (int) ($record['is_archived'] ?? 0) === 1) {
            $error = 'This record is already archived.';
        } elseif ($action === 'restore' && (int) ($record['is_archived'] ?? 0) === 0) {
            $error = 'This record is already active.';
        } else {
            $archive = $action === 'archive' ? 1 : 0;
            $stmt = $pdo->prepare(
                "UPDATE {$table} SET is_archived = ?, archived_at = " . ($archive ? 'NOW()' : 'NULL') . ", archived_by = " . ($archive ? '?' : 'NULL') . ", archive_reason = " . ($archive ? '?' : 'NULL') . " WHERE id = ?"
            );
            $params = $archive ? [$archive, current_user()['id'], $reason, $id] : [$archive, $id];
            $stmt->execute($params);

            log_activity(
                $pdo,
                current_user()['id'],
                $archive ? 'record_archived' : 'record_restored',
                $table,
                $id,
                $archive ? 'Record archived.' : 'Record restored.',
                $reason
            );

            if ($action === 'archive') {
                redirect_to('/admin/records/index.php?archived=1');
            }

            $redirectTarget = $return ?: '/admin/records/index.php';
            $redirectTarget .= (strpos($redirectTarget, '?') === false ? '?' : '&') . 'restored=1';
            redirect_to($redirectTarget);
        }
    }
}

if ($table !== '' && isset($allowed[$table]) && $id > 0) {
    $lookup = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
    $lookup->execute([$id]);
    $record = $lookup->fetch();

    if ($record) {
        $recordName = (string) ($record['child_full_name'] ?? $record['confirmand_full_name'] ?? $record['communicant_full_name'] ?? $record['husband_full_name'] ?? 'Record');
        if ($table === 'marriage_records') {
            $recordName = trim((string) ($record['husband_full_name'] ?? '') . ' & ' . (string) ($record['wife_maiden_name'] ?? ''));
            if ($recordName === '&') {
                $recordName = 'Marriage record';
            }
        }
        $recordDate = $record['baptism_date'] ?? $record['confirmation_date'] ?? $record['communion_date'] ?? $record['marriage_date'] ?? $record['date_of_death'] ?? null;
        if ($recordDate && is_string($recordDate)) {
            $recordDate = date('F j, Y', strtotime($recordDate));
        }
    } else {
        $error = 'Record not found.';
    }
}

$pageTitle = ucfirst($action) . ' Record';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<h1><?= ucfirst(e($action)) ?> record</h1>
<p class="field-hint">Review the record below, add the archive reason, and confirm the action before it is saved.</p>
<?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?>

<?php if ($record): ?>
    <form method="post" class="panel-card" style="max-width:42rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="table" value="<?= e($table) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <?php if ($return): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>

        <div class="panel-card" style="margin-bottom:1rem; border:1px solid #dfe4ea; background:#f7f8fa; padding:1rem; border-radius:8px;">
            <p><strong>Record type:</strong> <?= e($allowed[$table] ?? 'Record') ?></p>
            <p><strong>Record name:</strong> <?= e($recordName ?: 'Not recorded') ?></p>
            <p><strong>Record date:</strong> <?= e($recordDate ?: 'Not recorded') ?></p>
            <p><strong>Record ID:</strong> #<?= (int) $id ?></p>
            <p><strong>Current status:</strong> <?= (int) ($record['is_archived'] ?? 0) === 1 ? 'Archived' : 'Active' ?></p>
        </div>

        <label>
            Reason
            <textarea name="reason" rows="3" maxlength="500" required><?= e($_POST['reason'] ?? '') ?></textarea>
        </label>

        <label style="display:flex; align-items:center; gap:0.5rem; margin-top:1rem;">
            <input type="checkbox" name="confirm" value="1" <?= (($_POST['confirm'] ?? '') === '1') ? 'checked' : '' ?>>
            I confirm I want to <?= e($action) ?> this record.
        </label>

        <div style="margin-top:1rem; display:flex; gap:0.75rem; flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">Confirm <?= ucfirst($action) ?></button>
            <?php if ($return): ?>
                <a class="btn btn-secondary" href="<?= e(base_path() . $return) ?>">Cancel</a>
            <?php else: ?>
                <a class="btn btn-secondary" href="<?= e($basePath . '/admin/records/index.php') ?>">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
<?php else: ?>
    <p class="field-hint">No record matched the supplied table and ID.</p>
    <p><a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/index.php') ?>">Back to records</a></p>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
