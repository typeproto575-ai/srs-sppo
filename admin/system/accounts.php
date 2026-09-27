<?php
/**
 * =====================================================================
 * SRS-SPPO ACCOUNT MANAGEMENT (secretary and Parish Administrator)
 * =====================================================================
 * Purpose: Create/edit/deactivate secretary accounts (full name, username,
 *          email, active flag). Passwords are stored as modern hashes
 *          (password_hash()). Parish Administrator account changes remain
 *          protected by the role checks below.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Derive the subfolder-safe URL prefix AFTER the helpers are loaded.
// (Was previously hardcoded to '/srs-sppo' — breaks if the folder moves.)
$basePath = $basePath ?? base_path();

$pageTitle = 'Account Management';
$message = null;
$messageType = 'success';

$editingUser = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT id, full_name, username, email, role, is_active FROM users WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editingUser = $stmt->fetch() ?: null;
    if (!$editingUser) {
        $message = 'That account no longer exists.';
        $messageType = 'error';
    }
}
if (!$editingUser && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit' && (int) ($_POST['id'] ?? 0) > 0) {
    $stmt = $pdo->prepare('SELECT id, full_name, username, email, role, is_active FROM users WHERE id = ?');
    $stmt->execute([(int) $_POST['id']]);
    $editingUser = $stmt->fetch() ?: null;
}

// CSRF: forged or stale POSTs are rejected with an error message — no
// account is created or modified.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $message = 'Security token expired or invalid — please reload the page and try again.';
    $messageType = 'error';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? 'create';
    $currentUserId = (int) current_user()['id'];

    // Helper: true when deactivating or deleting $userId would leave the
    // parish office without a single active staff account.
    $isLastActive = function (int $userId) use ($pdo): bool {
        $check = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE is_active = 1 AND id != ?');
        $check->execute([$userId]);
        return (int) $check->fetch()['c'] === 0;
    };
    $isLastActiveAdmin = function (int $userId) use ($pdo): bool {
        $check = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE role = 'parish_admin' AND is_active = 1 AND id != ?");
        $check->execute([$userId]);
        return (int) $check->fetch()['c'] === 0;
    };

    if ($action === 'toggle' || $action === 'delete') {
        // Quick single-card actions from the account cards.
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $stmt = $pdo->prepare('SELECT id, full_name, role, is_active FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            $message = 'That account no longer exists.';
            $messageType = 'error';
        } elseif ($id === $currentUserId) {
            $message = 'You cannot delete or deactivate your own account while signed in.';
            $messageType = 'error';
        } elseif ($target['role'] === 'parish_admin' && !is_parish_admin()) {
            $message = 'Only a Parish Administrator may change another Parish Administrator account.';
            $messageType = 'error';
        } elseif ($target['role'] === 'parish_admin' && (int) $target['is_active'] === 1 && $isLastActiveAdmin($id)) {
            $message = 'Cannot remove this account — it is the last active Parish Administrator. Assign another administrator first.';
            $messageType = 'error';
        } elseif ((int) $target['is_active'] === 1 && $isLastActive($id)) {
            $message = 'Cannot remove this account — it is the last active staff account. Create or activate another first.';
            $messageType = 'error';
        } elseif ($action === 'toggle') {
            $newState = ((int) $target['is_active'] === 1) ? 0 : 1;
            $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$newState, $id]);
            log_activity($pdo, $currentUserId, 'account_status_changed', 'users', $id,
                ((int) $newState === 1) ? 'Account activated' : 'Account deactivated');
            $message = 'Account ' . ((int) $newState === 1 ? 'activated' : 'deactivated') . ' successfully.';
        } else {
            try {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                log_activity($pdo, $currentUserId, 'account_deleted', 'users', $id,
                    'Deleted account: ' . $target['full_name']);
                $message = 'Account deleted successfully.';
            } catch (PDOException $ex) {
                $message = 'Could not delete this account (it is still referenced by system history). Deactivate it instead.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'bulk') {
        // Bulk bar: apply one action to every selected card. Your own
        // account and the last-active-staff rule are protected per account,
        // and skipped rows are reported so nothing happens silently.
        $bulkAction = $_POST['bulk_action'] ?? '';
        $ids = array_values(array_unique(array_diff(
            array_map('intval', (array) ($_POST['bulk_ids'] ?? [])),
            [$currentUserId]
        )));

        if (!in_array($bulkAction, ['activate', 'deactivate', 'delete'], true) || empty($ids)) {
            $message = 'Select at least one account first (your own account is always excluded).';
            $messageType = 'error';
        } else {
            $done = 0;
            $skipped = 0;
            foreach ($ids as $uid) {
                $stmt = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = ?');
                $stmt->execute([$uid]);
                $target = $stmt->fetch();
                if (!$target) { $skipped++; continue; }

                if ($target['role'] === 'parish_admin' && !is_parish_admin()) { $skipped++; continue; }
                if ($bulkAction === 'activate') {
                    if ((int) $target['is_active'] === 1) { $skipped++; continue; }
                    $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$uid]);
                } elseif ($bulkAction === 'deactivate') {
                    if ((int) $target['is_active'] === 0 || ($target['role'] === 'parish_admin' && $isLastActiveAdmin($uid)) || $isLastActive($uid)) { $skipped++; continue; }
                    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$uid]);
                } else {
                    if (($target['role'] === 'parish_admin' && (int) $target['is_active'] === 1 && $isLastActiveAdmin($uid)) || ((int) $target['is_active'] === 1 && $isLastActive($uid))) { $skipped++; continue; }
                    try {
                        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                    } catch (PDOException $ex) { $skipped++; continue; }
                }
                log_activity($pdo, $currentUserId, 'account_bulk_' . $bulkAction, 'users', $uid,
                    'Bulk ' . $bulkAction . ' applied');
                $done++;
            }
            $message = 'Bulk ' . $bulkAction . ': ' . $done . ' account(s) updated'
                . ($skipped > 0 ? ', ' . $skipped . ' skipped (protected or already in that state).' : '.');
        }
    } else {
    // ---- Create / edit (the account dialog form) ------------------------
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $role = $_POST['role'] ?? 'secretary';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $password = trim($_POST['password'] ?? '');

    if ($action === 'edit' && $id < 1) {
        $message = 'A valid account must be selected for editing.';
        $messageType = 'error';
    }

    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'parish_admin'")->fetchColumn();
    $canManageAdminRole = is_parish_admin() || ($adminCount === 0 && $id && (int) $id === $currentUserId);
    // Only an existing administrator may assign the administrator role. For
    // first-time setup, the current staff account may promote itself once.
    // When a new or replacement password is supplied it must pass the staff
    // password policy (12+ chars, upper + lower + digit — see
    // validate_password_strength() in functions.php; matches the minlength
    // hint on the form itself).
    $passwordIssue = $password !== '' ? validate_password_strength($password) : null;
    if ($messageType === 'error') {
        // Keep the submitted form open so the user can correct the problem.
    } elseif ($passwordIssue !== null) {
        $message = $passwordIssue;
        $messageType = 'error';
    } elseif ($fullName === '' || $username === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['secretary', 'parish_admin'], true) || ($role === 'parish_admin' && !$canManageAdminRole)) {
        $message = 'Please complete all required fields.';
        $messageType = 'error';
    } else {
        $duplicateUser = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $duplicateUser->execute([$username, $id ?? 0]);
        if ($duplicateUser->fetch()) {
            $message = 'That username is already taken.';
            $messageType = 'error';
        } else {
            $duplicateEmail = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $duplicateEmail->execute([$email, $id ?? 0]);
            if ($duplicateEmail->fetch()) {
                $message = 'That email address is already in use.';
                $messageType = 'error';
            } else {
                $existingRole = null;
                if ($action === 'edit' && $id) {
                    $old = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
                    $old->execute([$id]);
                    $oldRow = $old->fetch();
                    $existingRole = $oldRow['role'] ?? null;
                    if (!$oldRow) {
                        $message = 'That account no longer exists.';
                        $messageType = 'error';
                    } elseif ($existingRole === 'parish_admin' && !is_parish_admin()) {
                        $message = 'Only a Parish Administrator may edit another Parish Administrator account.';
                        $messageType = 'error';
                    } elseif ($existingRole === 'parish_admin' && $isLastActiveAdmin($id) && ($isActive === 0 || $role !== 'parish_admin')) {
                        $message = 'Cannot deactivate or demote the last active Parish Administrator. Assign another administrator first.';
                        $messageType = 'error';
                    }
                }
                if ($isActive === 0 && $action === 'edit' && $id) {
                    // Prevent deactivating the last active admin account.
                    $lastActiveCheck = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE is_active = 1 AND id != ?');
                    $lastActiveCheck->execute([$id]);
                    if ((int) $lastActiveCheck->fetch()['c'] === 0) {
                        $message = 'Cannot deactivate this account — it is the last active staff account. Create or activate another first.';
                        $messageType = 'error';
                    }
                }
                if ($messageType !== 'error') {
                if ($action === 'edit' && $id) {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, is_active = ?, password_hash = ?, auth_version = auth_version + CASE WHEN role <> ? OR ? = 1 THEN 1 ELSE 0 END WHERE id = ?');
                        $stmt->execute([$fullName, $username, $email, $role, $isActive, $hash, $role, 1, $id]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, is_active = ?, auth_version = auth_version + CASE WHEN role <> ? THEN 1 ELSE 0 END WHERE id = ?');
                        $stmt->execute([$fullName, $username, $email, $role, $isActive, $role, $id]);
                    }
                    $message = 'Account updated successfully.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($password === '') {
                        $message = 'A password is required for new accounts.';
                        $messageType = 'error';
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$fullName, $username, $email, $hash, $role, $isActive]);
                        $message = 'Account created successfully.';
                    }
                }
                }
        }
    }
    }
    }

    if ($messageType === 'success' && empty($message)) {
        $message = 'Account saved.';
    }

    // Create/edit redirect back with a clean form state; card actions
    // (toggle/delete/bulk) simply re-render with their result message.
    if ($messageType === 'success' && in_array($action, ['create', 'edit'], true)) {
        redirect_to('/admin/system/accounts.php?saved=1');
    }
}

if (isset($_GET['saved']) && !isset($_GET['edit'])) {
    $message = 'Account saved successfully.';
    $messageType = 'success';
}

$selfUrl = $basePath . '/admin/system/accounts.php';
$filterRole = in_array(($_GET['role'] ?? ''), ['secretary', 'parish_admin'], true) ? $_GET['role'] : '';
$filterStatus = in_array(($_GET['status'] ?? ''), ['1', '0'], true) ? $_GET['status'] : '';

$rfConfig = [
    'date_column' => 'created_at',
    'search_columns' => ['full_name', 'username', 'email'],
    'placeholder' => 'Search name, username, or email...',
    'filters' => [
        'role' => ['label' => 'Role', 'options' => ['secretary' => 'Secretary', 'parish_admin' => 'Parish Administrator']],
        'status' => ['label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Inactive']],
    ],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$extraWhere = '';
if ($filterRole !== '') { $extraWhere .= ' AND role = ?'; $rf['params'][] = $filterRole; }
if ($filterStatus !== '') { $extraWhere .= ' AND is_active = ?'; $rf['params'][] = $filterStatus; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE 1=1 {$rf['where']} {$extraWhere}");
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT id, full_name, username, email, role, is_active, created_at
     FROM users
     WHERE 1=1 {$rf['where']} {$extraWhere}
     ORDER BY role, full_name
     LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$users = $stmt->fetchAll();

// The add/edit dialog reopens automatically when editing via ?edit=ID or
// when a dialog submission failed validation (so entered values survive).
$dialogAutoshow = $editingUser !== null
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'error'
        && in_array(($_POST['action'] ?? ''), ['create', 'edit'], true));

// Checked state of the "Active account" checkbox inside the dialog:
// a POST re-render honors the submitted value, otherwise the stored/default.
$activeChecked = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? isset($_POST['is_active'])
    : (($editingUser['is_active'] ?? 1) == 1);
$selectedRole = $_POST['role'] ?? ($editingUser['role'] ?? 'secretary');

$extraHead = '';
?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
        <section class="admin-hero">
            <div>
                <p class="hero-eyebrow">System tools</p>
                <h1>Account Management</h1>
                <p class="hero-copy">Create staff accounts, update access, and manage active/inactive status.</p>
            </div>
        </section>

        <?php if ($message): ?>
            <p class="alert <?= $messageType === 'error' ? 'alert-error' : 'alert-success' ?>"><?= e($message) ?></p>
        <?php endif; ?>

        <div class="accounts-toolbar">
            <button type="button" class="btn" data-open-dialog="#account-dialog">+ Add Account</button>
        </div>

        <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>

        <!-- Bulk actions: the checkboxes on the cards feed this form via form="bulk-form" -->
        <form autocomplete="off" method="post" id="bulk-form" class="bulk-bar" data-confirm="Apply this action to the selected accounts?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk">
            <span class="bulk-count" id="bulk-count">0 selected</span>
            <button type="submit" name="bulk_action" value="activate" class="btn btn-small btn-secondary" disabled>Activate selected</button>
            <button type="submit" name="bulk_action" value="deactivate" class="btn btn-small btn-secondary" disabled>Deactivate selected</button>
            <button type="submit" name="bulk_action" value="delete" class="btn btn-small btn-danger" disabled data-confirm="Delete the selected accounts permanently? This cannot be undone.">Delete selected</button>
        </form>

        <section class="accounts-grid">
            <?php foreach ($users as $user): ?>
                <?php $isSelf = ((int) $user['id'] === (int) current_user()['id']); ?>
                <div class="panel-card account-card<?= $user['is_active'] ? '' : ' account-inactive' ?>">
                    <div class="account-head">
                        <?php $initial = function_exists('mb_substr') ? mb_substr($user['full_name'], 0, 1) : substr($user['full_name'], 0, 1); $initial = function_exists('mb_strtoupper') ? mb_strtoupper($initial) : strtoupper($initial); ?>
                        <span class="account-avatar"><?= e($initial) ?></span>
                        <div class="account-id">
                            <h3><?= e($user['full_name']) ?><?= $isSelf ? ' <span class="you-badge">you</span>' : '' ?></h3>
                            <p class="account-username">@<?= e($user['username']) ?></p>
                        </div>
                        <span class="status-badge <?= $user['is_active'] ? 'status-approved' : 'status-rejected' ?>"><?= $user['is_active'] ? 'Active' : 'Inactive' ?></span>
                    </div>
                    <p class="account-email"><?= e($user['email']) ?></p>
                    <p class="account-meta"><?= e($user['role'] === 'parish_admin' ? 'Parish Administrator' : 'Secretary') ?> &middot; added <?= e(date('M j, Y', strtotime($user['created_at']))) ?></p>
                    <div class="account-actions">
                        <?php if (!$isSelf): ?>
                            <label class="account-select"><input autocomplete="off" type="checkbox" name="bulk_ids[]" value="<?= (int) $user['id'] ?>" form="bulk-form"> Select</label>
                        <?php endif; ?>
                        <a href="<?= $basePath ?>/admin/system/accounts.php?edit=<?= (int) $user['id'] ?>" class="btn btn-small">Edit</a>
                        <?php if (!$isSelf): ?>
                            <form autocomplete="off" method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                <button type="submit" class="btn btn-small <?= $user['is_active'] ? 'btn-warning' : 'btn-success' ?>"><?= $user['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                            <form autocomplete="off" method="post" class="inline-form" data-confirm="Delete this account permanently? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <!-- Add / Edit account dialog: opened by "+ Add Account" or ?edit=ID.
             Reopens automatically (data-autoshow) when a submission fails
             validation so the entered values are not lost. -->
        <dialog id="account-dialog" class="encode-dialog"<?= $dialogAutoshow ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $editingUser ? 'Edit account — ' . e($editingUser['full_name']) : 'Add new account' ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>
            <div class="dialog-body">
                <?php if ($dialogAutoshow && $messageType === 'error' && $message): ?>
                    <p class="alert alert-error"><?= e($message) ?></p>
                <?php endif; ?>
                <form autocomplete="off" method="post" class="account-form">
                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                    <input type="hidden" name="action" value="<?= $editingUser ? 'edit' : 'create' ?>">
                    <?php if ($editingUser): ?><input type="hidden" name="id" value="<?= (int) $editingUser['id'] ?>"><?php endif; ?>
                    <label>
                        Full name
                        <input autocomplete="off" type="text" name="full_name" value="<?= e($_POST['full_name'] ?? $editingUser['full_name'] ?? '') ?>" required>
                    </label>
                    <label>
                        Username
                        <input autocomplete="off" type="text" name="username" value="<?= e($_POST['username'] ?? $editingUser['username'] ?? '') ?>" required>
                    </label>
                    <label>
                        Email
                        <input autocomplete="off" type="email" name="email" value="<?= e($_POST['email'] ?? $editingUser['email'] ?? '') ?>" required>
                    </label>
                    <label>
                        Role
                        <select name="role">
                            <option value="secretary" <?= $selectedRole === 'secretary' ? 'selected' : '' ?>>Secretary</option>
                            <?php if (is_parish_admin() || ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'parish_admin'")->fetchColumn() === 0 && $editingUser && (int) $editingUser['id'] === (int) current_user()['id'] )): ?>
                            <option value="parish_admin" <?= $selectedRole === 'parish_admin' ? 'selected' : '' ?>>Parish Administrator</option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label class="checkbox-row">
                        <input autocomplete="off" type="checkbox" name="is_active" value="1" <?= $activeChecked ? 'checked' : '' ?>>
                        Active account
                    </label>
                    <label>
                        Password
                        <div class="password-wrap">
                            <input type="password" name="password" minlength="12" autocomplete="off" placeholder="<?= $editingUser ? 'Leave blank to keep current password' : 'Enter a password (min. 12 chars, mixed case + number)' ?>">
                            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                                <!-- Eye: password hidden (initial state) -->
                                <svg class="eye-on" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <!-- Eye with slash: password visible (starts hidden) -->
                                <svg class="eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden>
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                    <path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            </button>
                        </div>
                        <!-- Staff password policy, visible where the password is typed -->
                        <small class="field-hint">At least 12 characters with an uppercase letter, a lowercase letter, and a number.</small>
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn">Save account</button>
                        <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
                    </div>
                </form>
            </div>
        </dialog>

        <script>
        // Account cards: live selection counter + guarded bulk delete.
        (function () {
            var boxes = document.querySelectorAll('input[name="bulk_ids[]"]');
            var count = document.getElementById('bulk-count');
            var form = document.getElementById('bulk-form');
            function refresh() {
                var n = 0;
                boxes.forEach(function (b) { if (b.checked) n++; });
                if (count) count.textContent = n + ' selected';
                if (form) form.classList.toggle('bulk-active', n > 0);
                if (form) form.querySelectorAll('button[name="bulk_action"]').forEach(function (button) { button.disabled = n === 0; });
            }
            boxes.forEach(function (b) { b.addEventListener('change', refresh); });
            refresh();
            if (form) {
                form.addEventListener('submit', function (ev) {
                    var btn = document.activeElement;
                    if (btn && btn.value === 'delete' && !window.confirm('Delete ALL selected accounts permanently? This cannot be undone.')) {
                        ev.preventDefault();
                    }
                });
            }
        })();
        </script>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
