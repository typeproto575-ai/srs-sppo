<?php
/**
 * =====================================================================
 * SPPO-IS ACCOUNT MANAGEMENT (admin, secretary-only)
 * =====================================================================
 * Purpose: Create/edit/deactivate secretary accounts (full name, username,
 *          email, active flag). Only the secretary role exists in the current
 *          design — the priest role was retired (see PROJECT_CONTEXT.md) —
 *          so the role selector only offers Secretary; passwords are stored
 *          as modern hashes (password_hash()).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Derive the subfolder-safe URL prefix AFTER the helpers are loaded.
// (Was previously hardcoded to '/sppo-is' — breaks if the folder moves.)
$basePath = $basePath ?? base_path();

$pageTitle = 'Account Management';
$message = null;
$messageType = 'success';

$editingUser = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT id, full_name, username, email, role, is_active FROM users WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editingUser = $stmt->fetch();
}

// CSRF: forged or stale POSTs are rejected with an error message — no
// account is created or modified.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $message = 'Security token expired or invalid — please reload the page and try again.';
    $messageType = 'error';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? 'create';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'secretary';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $password = trim($_POST['password'] ?? '');

    // Role is validated strictly: the schema is ENUM('secretary') and the
    // priest role no longer exists in the design, so anything else is junk.
    if ($fullName === '' || $username === '' || $email === '' || $role !== 'secretary') {
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
                if ($action === 'edit' && $id) {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, is_active = ?, password_hash = ? WHERE id = ?');
                        $stmt->execute([$fullName, $username, $email, $role, $isActive, $hash, $id]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, is_active = ? WHERE id = ?');
                        $stmt->execute([$fullName, $username, $email, $role, $isActive, $id]);
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

    if ($messageType === 'success' && empty($message)) {
        $message = 'Account saved.';
    }

    if ($messageType === 'success') {
        redirect_to('/admin/system/accounts.php?saved=1');
    }
}

if (isset($_GET['saved']) && !isset($_GET['edit'])) {
    $message = 'Account saved successfully.';
    $messageType = 'success';
}

$users = $pdo->query('SELECT id, full_name, username, email, role, is_active, created_at FROM users ORDER BY role, full_name')->fetchAll();

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

        <section class="panel-card account-manager-card">
            <h2><?= $editingUser ? 'Edit account' : 'Add new account' ?></h2>
            <form method="post" class="account-form">
                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                <input type="hidden" name="action" value="<?= $editingUser ? 'edit' : 'create' ?>">
                <?php if ($editingUser): ?><input type="hidden" name="id" value="<?= (int) $editingUser['id'] ?>"><?php endif; ?>
                <label>
                    Full name
                    <input type="text" name="full_name" value="<?= e($editingUser['full_name'] ?? '') ?>" required>
                </label>
                <label>
                    Username
                    <input type="text" name="username" value="<?= e($editingUser['username'] ?? '') ?>" required>
                </label>
                <label>
                    Email
                    <input type="email" name="email" value="<?= e($editingUser['email'] ?? '') ?>" required>
                </label>
                <label>
                    Role
                    <!-- Only the secretary role is available: the priest role was
                         removed from the design and from the users.role ENUM. -->
                    <select name="role">
                        <option value="secretary" <?= (($editingUser['role'] ?? 'secretary') === 'secretary') ? 'selected' : '' ?>>Secretary</option>
                    </select>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" value="1" <?= (($editingUser['is_active'] ?? 1) == 1) ? 'checked' : '' ?>>
                    Active account
                </label>
                <label>
                    Password
                    <input type="password" name="password" placeholder="<?= $editingUser ? 'Leave blank to keep current password' : 'Enter a password' ?>">
                </label>
                <div class="form-actions">
                    <button type="submit" class="btn">Save account</button>
                    <?php if ($editingUser): ?><a href="<?= $basePath ?>/admin/system/accounts.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel-card">
            <h2>Existing accounts</h2>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= e($user['full_name']) ?></td>
                            <td><?= e($user['username']) ?></td>
                            <td><?= e($user['email']) ?></td>
                            <td><?= e(ucfirst($user['role'])) ?></td>
                            <td><span class="status-badge <?= $user['is_active'] ? 'status-approved' : 'status-rejected' ?>"><?= $user['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                            <td><?= e(date('M j, Y', strtotime($user['created_at']))) ?></td>
                            <td><a href="<?= $basePath ?>/admin/system/accounts.php?edit=<?= (int) $user['id'] ?>" class="btn btn-small">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
