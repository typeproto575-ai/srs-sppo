<?php
/**
 * =====================================================================
 * SRS-SPPO PUBLIC PAGE CONTENT EDITOR (parish secretary)
 * =====================================================================
 * Purpose: Let the secretary edit the text shown on the public home page
 *          (hero heading/eyebrow/subtitle, office hours, location) without
 *          touching code. Backed by the site_settings table.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Public Page Content';
$message     = null;
$messageType = 'success';

// --- Check that the table exists -------------------------------------------
$tableExists = false;
try {
    $tableExists = (bool) $pdo->query(
        "SELECT 1 FROM information_schema.tables " .
        "WHERE table_schema = DATABASE() AND table_name = 'site_settings'"
    )->fetch();
} catch (\Throwable $e) {
    $tableExists = false;
}

if (!$tableExists) {
    $message     = 'The site settings table does not exist yet. Run migration 007 first.';
    $messageType = 'error';
}

// --- Load settings into an associative array --------------------------------
$settings = [];
if ($tableExists) {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings ORDER BY setting_id");
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// --- Field definitions (key => label/type) ----------------------------------
$fieldDefs = [
    'hero_heading_text'    => ['label' => 'Heading',    'type' => 'text'],
    'hero_eyebrow_text'    => ['label' => 'Eyebrow',   'type' => 'text',
                                 'hint' => 'Small text above the heading'],
    'hero_subtitle_text'   => ['label' => 'Subtitle',  'type' => 'text'],
    'office_hours_text'    => ['label' => 'Office Hours', 'type' => 'textarea', 'rows' => 4],
    'office_location_text' => ['label' => 'Location',  'type' => 'textarea', 'rows' => 2],
];

// --- CRUD guard: save on POST -----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $message     = 'Security token expired or invalid — please reload the page and try again.';
        $messageType = 'error';
    } else {
        require_role('secretary');
        $currentUserId = (int) current_user()['id'];

        $updated = [];
        foreach ($fieldDefs as $key => $def) {
            if (isset($_POST[$key])) {
                $value = ($def['type'] === 'textarea') ? $_POST[$key] : trim($_POST[$key]);
                if ($tableExists) {
                    $stmt = $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?");
                    $stmt->execute([$value, $key]);
                }
                $settings[$key] = $value;
                $updated[] = $key;
            }
        }

        if (!empty($updated)) {
            if ($tableExists) {
                log_activity($pdo, $currentUserId, 'site_settings_updated', 'site_settings', null,
                    'Updated public page content: ' . implode(', ', $updated));
            }
            $message     = 'Public page content updated successfully.';
            $messageType = 'success';
        }
    }
}

require_once __DIR__ . '/../../includes/admin-header.php';
?>


        <h1>Public Page Content</h1>
        <p class="field-hint">Edit the text shown on the public home page. Changes take effect immediately after saving.</p>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$tableExists): ?>
            <div class="alert alert-error">
                <strong>The site settings table is missing.</strong> Run <code>migrations/007_site_settings.sql</code> against the database first.
            </div>
        <?php elseif ($tableExists): ?>
        <form method="post" class="settings-form" data-confirm="Save these settings? They apply to the whole system immediately.">
            <?= csrf_field() ?>

            <fieldset class="admin-card">
                <legend>Hero Section</legend>
                <label>
                    <?= $fieldDefs['hero_heading_text']['label'] ?>
                    <input type="text" name="hero_heading_text" value="<?= e($settings['hero_heading_text']) ?>" class="full-width">
                </label>
                <label>
                    <?= $fieldDefs['hero_eyebrow_text']['label'] ?>
                    <span class="field-hint"><?= $fieldDefs['hero_eyebrow_text']['hint'] ?></span>
                    <input type="text" name="hero_eyebrow_text" value="<?= e($settings['hero_eyebrow_text']) ?>" class="full-width">
                </label>
                <label>
                    <?= $fieldDefs['hero_subtitle_text']['label'] ?>
                    <input type="text" name="hero_subtitle_text" value="<?= e($settings['hero_subtitle_text']) ?>" class="full-width">
                </label>
            </fieldset>

            <fieldset class="admin-card">
                <legend>Office Information</legend>
                <label>
                    <?= $fieldDefs['office_hours_text']['label'] ?>
                    <span class="field-hint">One line per entry, uses newlines</span>
                    <textarea name="office_hours_text" rows="<?= $fieldDefs['office_hours_text']['rows'] ?>" class="full-width"><?= e($settings['office_hours_text']) ?></textarea>
                </label>
                <label>
                    <?= $fieldDefs['office_location_text']['label'] ?>
                    <span class="field-hint">One line per entry, uses newlines</span>
                    <textarea name="office_location_text" rows="<?= $fieldDefs['office_location_text']['rows'] ?>" class="full-width"><?= e($settings['office_location_text']) ?></textarea>
                </label>
            </fieldset>

            <div class="form-actions">
                <button type="submit" class="btn">Save Changes</button>
                <a href="<?= $basePath ?>/" target="_blank" rel="noopener" class="btn btn-secondary">Preview Public Page</a>
                <a href="<?= $basePath ?>/admin/dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </form>
        <?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
