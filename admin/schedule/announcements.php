<?php
/**
 * =====================================================================
 * SRS-SPPO ANNOUNCEMENTS MANAGER (admin)
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_announcement' && csrf_verify()) {
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

// --- Update announcement --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_announcement' && csrf_verify()) {
    require_role('secretary');

    $editId   = (int) ($_POST['edit_id'] ?? 0);
    $updTitle = trim($_POST['title'] ?? '');
    $updContent = trim($_POST['content'] ?? '');
    $updDate  = $_POST['event_date'] ?: null;
    $updPublished = isset($_POST['is_published']) ? 1 : 0;

    if ($updTitle === '')   $errors[] = 'Title is required.';
    if ($updContent === '') $errors[] = 'Content is required.';

    if ($editId && empty($errors)) {
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE id = ?");
        $checkStmt->execute([$editId]);
        if ((int) $checkStmt->fetchColumn() === 0) $errors[] = 'That announcement no longer exists.';
    }

    if ($editId && empty($errors)) {
        $stmt = $pdo->prepare(
            "UPDATE announcements SET title = ?, content = ?, event_date = ?, is_published = ? WHERE id = ?"
        );
        $stmt->execute([$updTitle, $updContent, $updDate, $updPublished, $editId]);
        log_activity($pdo, current_user()['id'], 'announcement_updated', 'announcements', $editId, "Updated: {$updTitle}");
        redirect_to('/admin/schedule/announcements.php?updated=1');
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

// --- Edit (?edit=ID opens the shared dialog pre-filled) -------------------------
$editAnnouncement = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editAnnouncement = $stmt->fetch();
    if (!$editAnnouncement) {
        redirect_to('/admin/schedule/announcements.php');
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_announcement']) && csrf_verify()) {
    require_role('secretary');

    $editId      = (int) ($_POST['edit_id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $content    = trim($_POST['content'] ?? '');
    $eventDate  = $_POST['event_date'] ?: null;
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    if ($title === '')   $errors[] = 'Title is required.';
    if ($content === '') $errors[] = 'Content is required.';

    if ($editId && empty($errors)) {
        $stmt = $pdo->prepare(
            "UPDATE announcements SET title = ?, content = ?, event_date = ?, is_published = ? WHERE id = ?"
        );
        $stmt->execute([$title, $content, $eventDate, $isPublished, $editId]);
        log_activity($pdo, current_user()['id'], 'announcement_updated', 'announcements', $editId, "Updated: {$title}");
        redirect_to('/admin/schedule/announcements.php?updated=1');
        exit;
    }
    // Validation failed: stay on the record being edited so errors show in the dialog.
    $editStmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
    $editStmt->execute([$editId]);
    $editAnnouncement = $editStmt->fetch() ?: null;
}


if (isset($_GET['added'])) $success = 'Announcement posted.';
if (isset($_GET['updated'])) $success = 'Announcement updated.';
if (isset($_GET['deleted'])) $success = 'Announcement deleted.';

$selfUrl = $basePath . '/admin/schedule/announcements.php';
$rfConfig = ['date_column' => 'created_at', 'search_columns' => ['title', 'content'], 'placeholder' => 'Search announcement title or content...'];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$sort = $_GET['sort'] ?? 'date_desc';
$allowedSorts = ['title', 'title_desc', 'event_date', 'event_date_desc', 'status', 'status_desc', 'date_desc', 'date_asc'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'date_desc';
$orderClauses = [
    'title' => 'title ASC, id ASC', 'title_desc' => 'title DESC, id DESC',
    'event_date' => 'event_date ASC, id ASC', 'event_date_desc' => 'event_date DESC, id DESC',
    'status' => 'is_published ASC, id ASC', 'status_desc' => 'is_published DESC, id DESC',
    'date_desc' => 'created_at DESC, id DESC', 'date_asc' => 'created_at ASC, id ASC',
];
$orderClause = $orderClauses[$sort];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE 1=1 {$rf['where']}");
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM announcements WHERE 1=1 {$rf['where']} ORDER BY {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$stmt->execute($rf['params']);
$announcements = $stmt->fetchAll();

// Expired = published with an event date already in the past; the secretary
// unpublishes it manually, so the list shows the state with a badge.
$today = date('Y-m-d');
foreach ($announcements as &$annRow) {
    $annRow['is_expired'] = (bool) ($annRow['event_date'] && $annRow['event_date'] < $today);
}
unset($annRow);
$isEdit = $editAnnouncement !== null;
$isSecretary = (current_user()['role'] ?? '') === 'secretary';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Announcements</h1>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

                <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <?php if ($isSecretary): ?>
        <div class="accounts-toolbar">
            <button type="button" class="btn" data-open-dialog="#announcement-dialog">+ Post Announcement</button>
        </div>
        <?php endif; ?>
        <?php if (empty($announcements)): ?>
            <p>No announcements yet.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead><tr><th><a class="table-sort-link<?= str_starts_with($sort, 'title') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'title', $sort)) ?>">Title<?= admin_sort_indicator('title', $sort) ?></a></th><th><a class="table-sort-link<?= str_starts_with($sort, 'event_date') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'event_date', $sort)) ?>">Event Date<?= admin_sort_indicator('event_date', $sort) ?></a></th><th><a class="table-sort-link<?= str_starts_with($sort, 'status') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'status', $sort)) ?>">Status<?= admin_sort_indicator('status', $sort) ?></a></th><th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'date', $sort)) ?>">Posted<?= admin_sort_indicator('date', $sort) ?></a></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($announcements as $a): ?>
                    <tr>
                        <td><?= e($a['title']) ?><br><small><?= e(mb_strimwidth($a['content'], 0, 80, '…')) ?></small></td>
                        <td><?= $a['event_date'] ? e(date('M j, Y', strtotime($a['event_date']))) : '—' ?></td>
                        <td><?= $a['is_published'] ? '<span class="status-badge status-approved">Published</span>' : '<span class="status-badge status-pending">Draft</span>' ?><?= ($a['is_expired'] ?? false) ? ' <span class="status-badge status-rejected">Expired</span>' : '' ?></td>
                        <td><?= e(date('M j, Y', strtotime($a['created_at']))) ?></td>
                        <td class="row-actions">
                            <?php if ($isSecretary): ?>
                            <a class="btn btn-small" href="<?= $basePath ?>/admin/schedule/announcements.php?edit=<?= (int) $a['id'] ?>">Edit</a>
                            <form autocomplete="off" method="post" style="display:inline;">
                                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                <input type="hidden" name="announcement_id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" name="toggle_publish" value="1" class="btn btn-secondary btn-small"><?= $a['is_published'] ? 'Unpublish' : 'Publish' ?></button>
                            </form>
                            <form autocomplete="off" method="post" style="display:inline;" data-confirm="Delete this announcement?">
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
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>

        <!-- Add/Edit announcement dialog (same records encode-dialog style) -->
        <?php if ($isSecretary): ?>
        <dialog id="announcement-dialog" class="encode-dialog"<?= ($isEdit || !empty($errors)) ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $isEdit ? 'Edit announcement — ' . e($editAnnouncement['title']) : 'Post new announcement' ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>
            <div class="dialog-body">
                <form autocomplete="off" method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $isEdit ? 'update_announcement' : 'add_announcement' ?>">
                    <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editAnnouncement['id'] ?>"><?php endif; ?>
                    <label>Title * <input autocomplete="off" type="text" name="title" value="<?= e($_POST['title'] ?? ($editAnnouncement['title'] ?? '')) ?>" required></label>
                    <label>Content * <textarea autocomplete="off" name="content" required><?= e($_POST['content'] ?? ($editAnnouncement['content'] ?? '')) ?></textarea></label>
                    <label>Related Event Date (optional) <input autocomplete="off" type="date" name="event_date" value="<?= e($_POST['event_date'] ?? ($editAnnouncement['event_date'] ?? '')) ?>"></label>
                    <label class="checkbox-label">
                        <input autocomplete="off" type="checkbox" name="is_published" value="1" <?= (($_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['is_published']) : (bool) ($editAnnouncement['is_published'] ?? true))) ? 'checked' : '' ?>>
                        Publish immediately (visible on the public landing page)
                    </label>
                    <div class="form-actions">
                        <button type="submit" value="1" class="btn"><?= $isEdit ? 'Save changes' : 'Post Announcement' ?></button>
                        <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
                    </div>
                </form>
            </div>
        </dialog>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';