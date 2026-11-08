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
$family = sd_get_family($pdo, (int) $personId);
$hasConnectedFamily = $family['father'] !== null
    || $family['mother'] !== null
    || !empty($family['children']);

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
    foreach (['father_person_id', 'mother_person_id'] as $field) {
        $rawParentId = trim((string) ($_POST[$field] ?? ''));
        if ($rawParentId === '') {
            $values[$field] = null;
            continue;
        }
        $parentId = filter_var($rawParentId, FILTER_VALIDATE_INT);
        if ($parentId === false || $parentId < 1) {
            $values[$field] = null;
            $errors[] = 'Select parent profiles from the search results.';
            continue;
        }
        $values[$field] = $parentId;
    }
    $reason = trim((string) ($_POST['correction_reason'] ?? ''));

    if (!csrf_verify()) {
        $errors[] = 'Your session token is invalid or expired. Refresh the page and try again.';
    }
    $parentLinkError = sd_validate_parent_profile_links(
        $pdo,
        (int) $personId,
        [$values['father_person_id'], $values['mother_person_id']]
    );
    if ($parentLinkError !== null) {
        $errors[] = $parentLinkError;
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
        <?php if (!$hasConnectedFamily): ?>
            <div class="person-parent-links">
                <h3>Linked parent profiles</h3>
                <p class="field-hint">Link only when you have confirmed the parent's identity. These links are separate from the parent names above.</p>
                <?php foreach ([
                    'father_person_id' => ['label' => 'Father profile', 'search' => 'father_profile_search', 'id' => 'father-profile-search'],
                    'mother_person_id' => ['label' => 'Mother profile', 'search' => 'mother_profile_search', 'id' => 'mother-profile-search'],
                ] as $field => $config): ?>
                    <?php
                    $linkedParent = !empty($values[$field]) ? sd_get_person($pdo, (int) $values[$field]) : null;
                    $selectedLabel = $linkedParent
                        ? sd_full_name($linkedParent)
                        : (!empty($values[$field]) ? 'Unavailable profile #' . (int) $values[$field] : '');
                    $searchValue = $linkedParent
                        ? sd_full_name($linkedParent)
                        : (string) ($_POST[$config['search']] ?? $selectedLabel);
                    ?>
                    <div class="person-parent-link" data-parent-profile-search
                         data-endpoint="<?= e($basePath . '/admin/api/person-search.php') ?>"
                         data-person-id="<?= (int) $personId ?>">
                        <label for="<?= e($config['id']) ?>"><?= e($config['label']) ?></label>
                        <input id="<?= e($config['id']) ?>" type="search" name="<?= e($config['search']) ?>"
                               value="<?= e($searchValue) ?>" placeholder="Search existing person profiles"
                               autocomplete="off" data-parent-profile-query>
                        <input type="hidden" name="<?= e($field) ?>" value="<?= (int) ($values[$field] ?? 0) ?: '' ?>" data-parent-profile-id>
                        <div class="person-parent-link__selection" data-parent-profile-selection aria-live="polite">
                            <?= $linkedParent ? 'Linked to ' . e(sd_full_name($linkedParent)) . ' (#' . (int) $linkedParent['id'] . ')' : (!empty($values[$field]) ? 'Review or clear this unavailable profile link.' : 'No profile linked.') ?>
                        </div>
                        <div class="person-parent-link__results" data-parent-profile-results aria-live="polite"></div>
                        <button type="button" class="btn btn-secondary btn-small" data-parent-profile-clear<?= empty($values[$field]) ? ' hidden' : '' ?>>Clear profile link</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
