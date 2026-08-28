<?php
/**
 * =====================================================================
 * SPPO-IS ANNOUNCEMENTS MANAGER (admin)
 * =====================================================================
 * Purpose: Create/edit/delete the announcements shown on the public
 *          home page (add / edit / delete handlers below, newest
 *          first, only is_published rows go public).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Announcements';
$errors  = [];
$success = null;

// --- Add ------------------------------------------------------------------
// CSRF guard: every POST below must carry the session token (see
// csrf_field() inside each form). Failures render an error and change
// nothing.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid - please reload the page and try again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_announcement']) && csrf_verify()) {
    require_role('secretary');

    $title     = trim($_POST['title'] ?? '');
    $content   = trim($_POST['content'] ?? '');
    $eventDate = $_POST['event_date'] ?: null;
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    if ($title === '')   $errors[] = 'Title is required.';
    if ($content === '') $errors[] = 'Content is required.';

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            "INSERT INTO announcements (title, content, event_date, is_published, posted_by) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$title, $content, $eventDate, $isPublished, current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        log_activity($pdo, current_user()['id'], 'announcement_posted', 'announcements', $id, "Posted: {$title}");
        redirect_to('/admin/schedule/announcements.php?added=1');
        exit;
    }
}

// --- Toggle publish / delete -----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_publish']) && csrf_verify()) {
    require_role('secretary');
    $id = (int) ($_POST['announcement_id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE announcements SET is_published = NOT is_published WHERE id = ?");
        $stmt->execute([$id]);
        log_activity($pdo, current_user()['id'], 'announcement_visibility_toggled', 'announcements', $id, null);
    }
    redirect_to('/admin/schedule/announcements.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement']) && csrf_verify()) {
    require_role('secretary');
    $id = (int) ($_POST['announcement_id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->execute([$id]);
        log_activity($pdo, current_user()['id'], 'announcement_deleted', 'announcements', $id, null);
    }
    redirect_to('/admin/schedule/announcements.php');
    exit;
}

if (isset($_GET['added'])) $success = 'Announcement posted.';

$stmt = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC");
$announcements = $stmt->fetchAll();
$isSecretary = current_user()['role'] === 'secretary';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= e($pageTitle) ?> | SPPO-IS</title>
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css">
</head>
<body>
<div class="admin-layout">
    <main class="admin-content">
        <p><a href="<?= $basePath ?>/admin/dashboard.php">&larr; Back to Dashboard</a></p>
        <h1>Announcements</h1>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <?php if (empty($announcements)): ?>
            <p>No announcements yet.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>Title</th><th>Event Date</th><th>Status</th><th>Posted</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($announcements as $a): ?>
                    <tr>
                        <td><?= e($a['title']) ?><br><small><?= e(mb_strimwidth($a['content'], 0, 80, '…')) ?></small></td>
                        <td><?= $a['event_date'] ? e(date('M j, Y', strtotime($a['event_date']))) : '—' ?></td>
                        <td><?= $a['is_published'] ? '<span class="status-badge status-approved">Published</span>' : '<span class="status-badge status-pending">Draft</span>' ?></td>
                        <td><?= e(date('M j, Y', strtotime($a['created_at']))) ?></td>
                        <td>
                            <?php if ($isSecretary): ?>
                            <form method="post" style="display:inline;">
                                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                <input type="hidden" name="announcement_id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" name="toggle_publish" value="1" class="btn btn-secondary btn-small"><?= $a['is_published'] ? 'Unpublish' : 'Publish' ?></button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this announcement?');">
                                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                <input type="hidden" name="announcement_id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" name="delete_announcement" value="1" class="btn btn-secondary btn-small">Delete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ($isSecretary): ?>
        <h2 style="margin-top:2.5rem;">Post New Announcement</h2>
        <form method="post" action="">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <fieldset>
                <legend>New Announcement</legend>
                <label>Title * <input type="text" name="title" required></label>
                <label>Content * <textarea name="content" required></textarea></label>
                <label>Related Event Date (optional) <input type="date" name="event_date"></label>
                <label class="checkbox-label">
                    <input type="checkbox" name="is_published" value="1" checked>
                    Publish immediately (visible on the public landing page)
                </label>
            </fieldset>
            <button type="submit" name="add_announcement" value="1" class="btn">Post Announcement</button>
        </form>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
