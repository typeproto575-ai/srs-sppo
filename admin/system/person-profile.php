<?php
/**
 * =====================================================================
 * SRS-SPPO PERSON PROFILE (admin)
 * =====================================================================
 * Purpose: Shows one mapped person and EVERY register row linked to
 *          them across the sacraments the system actually tracks — the
 *          payoff of the person mapper: baptismal, confirmation, first
 *          communion and marriage entries united under one identity.
 *
 *          insert), so there would be nothing to show.
 *
 * Access: Secretary only (require_role('secretary')).
 * Opened from the person mapper ("profile" links beside suggestions).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/person-linking.php';
require_role('secretary');

$basePath = $basePath ?? base_path();

$personId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM persons WHERE id = ?');
$stmt->execute([$personId]);
$person = $stmt->fetch();
if (!$person) {
    redirect_to('/admin/system/person-mapper.php');
}

// Built here (not beside the display code) because the unlink handler below
// needs it for the audit-log description. It only depends on $person.
$personLabel = trim(implode(' ', array_filter([
    $person['first_name'], $person['middle_name'], $person['last_name'], $person['suffix'],
])));
if (!empty($person['maiden_name'])) $personLabel .= ' (maiden: ' . $person['maiden_name'] . ')';

// Bridge table/column mapping now lives in includes/person-linking.php so
// this page, the persons directory and the delete guard all agree on it — one
// source of truth instead of a copy per page.
$sections = person_bridge_sections();
$marriageRoles = person_bridge_marriage_roles();

$birthDateSourceStmt = $pdo->prepare(
    'SELECT source.birth_date
     FROM (
         SELECT birth_date, 1 AS priority, id
         FROM baptismal_records
         WHERE person_id = ? AND birth_date IS NOT NULL
         UNION ALL
         SELECT birthdate AS birth_date, 2 AS priority, id
         FROM confirmation_records
         WHERE person_id = ? AND birthdate IS NOT NULL
         UNION ALL
         SELECT husband_birthdate AS birth_date, 3 AS priority, id
         FROM marriage_records
         WHERE husband_person_id = ? AND husband_birthdate IS NOT NULL
         UNION ALL
         SELECT wife_birthdate AS birth_date, 3 AS priority, id
         FROM marriage_records
         WHERE wife_person_id = ? AND wife_birthdate IS NOT NULL
     ) AS source
     ORDER BY source.priority, source.id
     LIMIT 1'
);
$birthDateSourceStmt->execute([$personId, $personId, $personId, $personId]);
$recordBirthDate = $birthDateSourceStmt->fetchColumn();
$recordBirthDate = is_string($recordBirthDate) && $recordBirthDate !== '' ? $recordBirthDate : null;

$actionError = '';
$profileSex = (string) ($person['sex'] ?? '');
$profileBirthDate = (string) ($recordBirthDate ?? $person['birth_date'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $profileSex = trim((string) ($_POST['sex'] ?? ''));
    if ($recordBirthDate === null) {
        $profileBirthDate = trim((string) ($_POST['birth_date'] ?? ''));
    }

    if (!csrf_verify()) {
        $actionError = 'Your session expired. Please try again.';
    } elseif (!in_array($profileSex, ['', 'male', 'female'], true)) {
        $actionError = 'Choose a valid sex or leave it unspecified.';
    } elseif ($recordBirthDate === null && $profileBirthDate !== ''
        && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $profileBirthDate)
            || !checkdate(
                (int) substr($profileBirthDate, 5, 2),
                (int) substr($profileBirthDate, 8, 2),
                (int) substr($profileBirthDate, 0, 4)
            ))) {
        $actionError = 'Enter a valid birth date.';
    } else {
        if ($recordBirthDate !== null) {
            $stmt = $pdo->prepare('UPDATE persons SET sex = ?, birth_date = ? WHERE id = ?');
            $stmt->execute([$profileSex !== '' ? $profileSex : null, $recordBirthDate, $personId]);
        } else {
            $stmt = $pdo->prepare('UPDATE persons SET sex = ?, birth_date = ? WHERE id = ?');
            $stmt->execute([
                $profileSex !== '' ? $profileSex : null,
                $profileBirthDate !== '' ? $profileBirthDate : null,
                $personId,
            ]);
        }
        log_activity($pdo, current_user()['id'], 'update_person_profile', 'persons', $personId,
            'Updated sex and/or birth date for ' . person_identity_label($person) . '.');
        redirect_to('/admin/system/person-profile.php?id=' . $personId . '&updated=1');
    }
}

// --- Unlink a wrong link, or delete an unlinked person -------------------
// Unlink clears one *_person_id bridge cell (the same bridge the person
// mapper fills in). Table and column names are looked up in the $sections /
// $marriageRoles allow-list above — never taken from POST — so a forged
// section_key cannot reach an arbitrary table. The UPDATE re-asserts that
// the row still points at this person, which makes a double submit or a
// raced change report cleanly instead of silently appearing to succeed.
//
// Delete never touches a register row: it removes the persons row only, and
// only when person_delete_if_unlinked() recounts every bridge on the spot.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlink') {
    if (!csrf_verify()) {
        $actionError = 'Your session expired. Please try again.';
    } else {
        $sectionKey = (string) ($_POST['section_key'] ?? '');
        $unlinkRecordId = (int) ($_POST['record_id'] ?? 0);
        $allSections = array_merge($sections, $marriageRoles);
        if (!isset($allSections[$sectionKey]) || $unlinkRecordId <= 0) {
            $actionError = 'Invalid link selected.';
        } else {
            $sec = $allSections[$sectionKey];
            // Column names come from the allow-list above — never user input.
            $stmt = $pdo->prepare(
                "UPDATE `{$sec['table']}` SET `{$sec['column']}` = NULL WHERE id = ? AND `{$sec['column']}` = ?"
            );
            $stmt->execute([$unlinkRecordId, $personId]);
            if ($stmt->rowCount() < 1) {
                $actionError = 'That link was already removed or no longer matches this person.';
            } else {
                log_activity($pdo, current_user()['id'], 'unmap_person', $sec['table'], $unlinkRecordId,
                    "Removed the link between this record and " . person_identity_label($person) . " (#{$personId}).");
                redirect_to('/admin/system/person-profile.php?id=' . $personId . '&unlinked=1');
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!csrf_verify()) {
        $actionError = 'Your session expired. Please try again.';
    } else {
        // person_delete_if_unlinked() reads the row itself (existence check
        // and audit label), so there is nothing to pre-check here.
        $result = person_delete_if_unlinked($pdo, $personId, current_user()['id']);
        if (!$result['ok']) {
            $actionError = $result['error'];
        } else {
            redirect_to('/admin/system/persons.php?deleted=1');
        }
    }
}

$linked = [];
$totalLinked = 0;
foreach (array_merge($sections, $marriageRoles) as $label => $sec) {
    // Column names come from the hardcoded list above — never user input.
    $s = $pdo->prepare("SELECT id, {$sec['name']} AS display_name, {$sec['date']} AS event_date
                        FROM {$sec['table']} WHERE {$sec['column']} = ? ORDER BY id");
    $s->execute([$personId]);
    $rows = $s->fetchAll();
    $linked[$label] = $rows;
    $totalLinked += count($rows);
}
$activeApplicationStmt = $pdo->prepare(
    "SELECT DISTINCT sacrament_type
     FROM sacrament_applications
     WHERE (person_id = ? OR spouse_person_id = ?)
       AND status NOT IN ('completed', 'rejected', 'cancelled')"
);
$activeApplicationStmt->execute([$personId, $personId]);
$activeApplications = array_fill_keys($activeApplicationStmt->fetchAll(PDO::FETCH_COLUMN), true);
$missingSacraments = [];
foreach ([
    'baptismal' => !empty($linked['Baptismal']),
    'confirmation' => !empty($linked['Confirmation']),
    'first_communion' => !empty($linked['First Communion']),
    'marriage' => !empty($linked['As husband']) || !empty($linked['As wife']),
] as $type => $hasRecord) {
    if (!$hasRecord) {
        $missingSacraments[$type] = isset($activeApplications[$type]);
    }
}

$pageTitle = 'Person Profile';
$baptismalStatus = !empty($linked['Baptismal']) ? 'Baptized Catholic' : 'Unknown';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<p><a href="<?= $basePath ?>/admin/system/person-mapper.php">&larr; Back to Person Mapper</a></p>
<h1><?= e($personLabel) ?></h1>
<?php if ($actionError): ?><p class="alert alert-error"><?= e($actionError) ?></p><?php endif; ?>
<?php if (isset($_GET['unlinked'])): ?><p class="alert alert-success">Link removed. The record is back in the mapping queue.</p><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><p class="alert alert-success">Person details saved.</p><?php endif; ?>
<p class="field-hint">Person #<?= (int) $person['id'] ?><?= $profileBirthDate !== '' ? ' &middot; born ' . e(date('F j, Y', strtotime($profileBirthDate))) : '' ?> &middot; <?= (int) $totalLinked ?> linked register row<?= $totalLinked === 1 ? '' : 's' ?></p>
<section class="panel-card">
    <h2>Person details</h2>
    <p class="field-hint">Baptismal status: <strong><?= e($baptismalStatus) ?></strong>. This is based on a linked baptismal register entry; no linked entry means unknown, not unbaptized.</p>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <label>Sex
            <select name="sex">
                <option value=""<?= $profileSex === '' ? ' selected' : '' ?>>Not specified</option>
                <option value="female"<?= $profileSex === 'female' ? ' selected' : '' ?>>Female</option>
                <option value="male"<?= $profileSex === 'male' ? ' selected' : '' ?>>Male</option>
            </select>
        </label>
        <?php if ($recordBirthDate !== null): ?>
            <p class="field-hint">Birth date: <strong><?= e(date('F j, Y', strtotime($recordBirthDate))) ?></strong> — from linked register record.</p>
        <?php else: ?>
            <label>Birth date
                <input type="date" name="birth_date" value="<?= e($profileBirthDate) ?>">
            </label>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-small">Save details</button>
    </form>
</section>
<?php if ($totalLinked === 0): ?>
    <form method="post" data-confirm="Delete this person? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger btn-small">Delete this person</button>
    </form>
<?php else: ?>
    <p class="field-hint">Remove all <?= (int) $totalLinked ?> linked record<?= $totalLinked === 1 ? '' : 's' ?> above before this person can be deleted.</p>
<?php endif; ?>

<section class="panel-card">
    <h2>Apply for a missing sacrament</h2>
    <?php if ($missingSacraments === []): ?>
        <p class="field-hint">This person has a linked register record for each sacrament.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($missingSacraments as $type => $applicationInProgress): ?>
                <li>
                    <?= e(ucwords(str_replace('_', ' ', $type))) ?>
                    <?php if ($applicationInProgress): ?>
                        <span class="field-hint"> — an application is already in progress.</span>
                    <?php else: ?>
                        <a class="btn btn-small" href="<?= e($basePath . '/admin/requests/walk-in-application.php?sacrament_type=' . rawurlencode($type) . '&person_id=' . $personId) ?>">Apply for Sacrament</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="field-hint">The staff application form will prefill available identity details; complete the remaining requirements and verify them with the applicant.</p>
    <?php endif; ?>
</section>

<?php foreach ($sections as $label => $sec): $rows = $linked[$label]; ?>
    <h2><?= e($label) ?></h2>
    <?php if (empty($rows)): ?>
        <p class="field-hint">No linked rows.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table" style="max-width:700px;">
            <thead><tr><th>Record</th><th>Name as registered</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>#<?= (int) $r['id'] ?></td>
                    <td><?= e($r['display_name'] ?: '—') ?></td>
                    <td><?= e($r['event_date'] ? date('M j, Y', strtotime($r['event_date'])) : '—') ?></td>
                    <td class="row-actions"><a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/view.php?type=' . $sec['type'] . '&id=' . (int) $r['id']) ?>">View record</a>
                        <form method="post" style="display:inline; margin-left:0.4rem;" data-confirm="Remove this link? The record will return to the mapping queue for reassignment.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="unlink">
                            <input type="hidden" name="section_key" value="<?= e($label) ?>">
                            <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-secondary btn-small" type="submit">Remove link</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<h2>Marriage</h2>
<?php foreach ($marriageRoles as $label => $sec): $rows = $linked[$label]; ?>
    <h3><?= e($label) ?></h3>
    <?php if (empty($rows)): ?>
        <p class="field-hint">No linked rows.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table" style="max-width:700px;">
            <thead><tr><th>Record</th><th>Other party</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>#<?= (int) $r['id'] ?></td>
                    <td><?= e($r['display_name'] ?: '—') ?></td>
                    <td><?= e($r['event_date'] ? date('M j, Y', strtotime($r['event_date'])) : '—') ?></td>
                    <td class="row-actions"><a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/view.php?type=marriage&id=' . (int) $r['id']) ?>">View record</a>
                        <form method="post" style="display:inline; margin-left:0.4rem;" data-confirm="Remove this link? The record will return to the mapping queue for reassignment.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="unlink">
                            <input type="hidden" name="section_key" value="<?= e($label) ?>">
                            <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-secondary btn-small" type="submit">Remove link</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
