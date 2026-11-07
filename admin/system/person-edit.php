<?php
/**
 * Edit the shared master identity for one parishioner.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';
require_role('secretary');

$personId = filter_var($_GET['id'] ?? $_POST['person_id'] ?? null, FILTER_VALIDATE_INT);
if (!$personId || $personId < 1) {
    redirect_to('/admin/system/person-profile.php');
}

$person = sd_get_person($pdo, (int) $personId);
if (!$person) {
    redirect_to('/admin/dashboard.php');
}

$errors = [];
$values = $person;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ([
        'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'birth_date',
        'birth_place', 'father_name', 'mother_maiden_name', 'baptismal_status',
        'address', 'contact_number',
    ] as $field) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $reason = trim((string) ($_POST['correction_reason'] ?? ''));

    if (!csrf_verify()) {
        $errors[] = 'Your session token is invalid or expired. Refresh the page and try again.';
    }
    if ($values['first_name'] === '' || $values['last_name'] === '') {
        $errors[] = 'First name and last name are required.';
    }
    if ($values['sex'] !== '' && !in_array($values['sex'], ['male', 'female'], true)) {
        $errors[] = 'Choose a valid sex value.';
    }
    if ($values['baptismal_status'] !== ''
        && !in_array($values['baptismal_status'], ['baptized_catholic', 'baptized_non_catholic', 'unbaptized'], true)) {
        $errors[] = 'Choose a valid baptismal status.';
    }
    if ($values['birth_date'] !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $values['birth_date']);
        if (!$date || $date->format('Y-m-d') !== $values['birth_date']) {
            $errors[] = 'Enter a valid date of birth.';
        }
    }
    if (strlen($reason) < 5 || strlen($reason) > 500) {
        $errors[] = 'A correction reason of at least 5 characters is required.';
    }
    if (($_POST['source_verified'] ?? '') !== '1') {
        $errors[] = 'Confirm that you checked the source information before saving.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $before = sd_person_audit_snapshot($pdo, (int) $personId);
            sd_save_person($pdo, $values, (int) $personId);
            $after = sd_person_audit_snapshot($pdo, (int) $personId);
            sd_log_person_edit(
                $pdo,
                (int) current_user()['id'],
                (int) $personId,
                $before,
                $after,
                $reason
            );
            $pdo->commit();
            redirect_to('/admin/system/person-profile.php?id=' . (int) $personId . '&updated=1');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SRS-SPPO person profile update failed: ' . $e->getMessage());
            $errors[] = 'Could not update this person profile. Check the server error log.';
        }
    }
}

$pageTitle = 'Edit Person Details';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="page-heading">
    <h1>Edit Person Details</h1>
    <a class="btn btn-secondary btn-small" href="<?= e(base_path() . '/admin/system/person-profile.php?id=' . (int) $personId) ?>">Cancel</a>
</div>
<p class="field-hint">Changes here update the shared master profile used by this person's sacramental records.</p>
<div class="notice notice-warning">
    <strong>Verify the source before correcting a profile.</strong>
    <p>For a correction to a name, birth date, birthplace, or parent details, compare the reported information with the official physical parish register before saving. For other details, check the relevant supporting source.</p>
</div>
<?php if ($errors): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form method="post" class="form-grid" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
    <section class="panel-card">
        <h2>Identity</h2>
        <div class="form-grid view-grid">
            <label>First name <input name="first_name" value="<?= e($values['first_name'] ?? '') ?>" required></label>
            <label>Middle name <input name="middle_name" value="<?= e($values['middle_name'] ?? '') ?>"></label>
            <label>Last name <input name="last_name" value="<?= e($values['last_name'] ?? '') ?>" required></label>
            <label>Suffix <input name="suffix" value="<?= e($values['suffix'] ?? '') ?>"></label>
            <label>Sex
                <select name="sex">
                    <option value="">Not specified</option>
                    <option value="female"<?= ($values['sex'] ?? '') === 'female' ? ' selected' : '' ?>>Female</option>
                    <option value="male"<?= ($values['sex'] ?? '') === 'male' ? ' selected' : '' ?>>Male</option>
                </select>
            </label>
            <label>Date of birth <input type="date" name="birth_date" value="<?= e($values['birth_date'] ?? '') ?>"></label>
            <label>Birthplace <input name="birth_place" value="<?= e($values['birth_place'] ?? '') ?>"></label>
            <label>Baptismal status
                <select name="baptismal_status">
                    <option value="">Not specified</option>
                    <option value="baptized_catholic"<?= ($values['baptismal_status'] ?? '') === 'baptized_catholic' ? ' selected' : '' ?>>Baptized Catholic</option>
                    <option value="baptized_non_catholic"<?= ($values['baptismal_status'] ?? '') === 'baptized_non_catholic' ? ' selected' : '' ?>>Baptized (non-Catholic)</option>
                    <option value="unbaptized"<?= ($values['baptismal_status'] ?? '') === 'unbaptized' ? ' selected' : '' ?>>Unbaptized</option>
                </select>
            </label>
            <label>Father's name <input name="father_name" value="<?= e($values['father_name'] ?? '') ?>"></label>
            <label>Mother's maiden name <input name="mother_maiden_name" value="<?= e($values['mother_maiden_name'] ?? '') ?>"></label>
            <label>Address <input name="address" value="<?= e($values['address'] ?? '') ?>"></label>
            <label>Contact number <input name="contact_number" value="<?= e($values['contact_number'] ?? '') ?>"></label>
        </div>
    </section>
    <section class="panel-card">
        <h2>Correction history</h2>
        <p class="field-hint">Each changed field is saved to the append-only audit log with its previous and new value, the reason, and the staff account.</p>
        <label>Reason for correction
            <textarea name="correction_reason" minlength="5" maxlength="500" required><?= e($_POST['correction_reason'] ?? '') ?></textarea>
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="source_verified" value="1" required<?= ($_POST['source_verified'] ?? '') === '1' ? ' checked' : '' ?>>
            I checked the source information. For name, birth, or parent-detail corrections, I compared it with the official physical parish register.
        </label>
        <div class="page-actions">
            <a class="btn btn-secondary" href="<?= e(base_path() . '/admin/system/person-profile.php?id=' . (int) $personId) ?>">Cancel</a>
            <button type="submit" class="btn btn-primary">Save person details</button>
        </div>
    </section>
</form>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
