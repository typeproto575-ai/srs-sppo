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
 *          Death / Burial is deliberately NOT listed here yet: the
 *          deceased side has no working person link in practice
 *          (death_burial_records.person_id is never populated on
 *          insert), so there would be nothing to show.
 *
 * Access: Secretary only (require_role('secretary')).
 * Opened from the person mapper ("profile" links beside suggestions).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
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

// Same registry/table mapping the person mapper uses ('mapper' = the
// section key used by person-mapper.php tabs).
$sections = [
    'Baptismal'       => ['type' => 'baptismal',       'table' => 'baptismal_records',       'column' => 'person_id', 'name' => 'child_full_name',       'date' => 'baptism_date'],
    'Confirmation'    => ['type' => 'confirmation',    'table' => 'confirmation_records',    'column' => 'person_id', 'name' => 'confirmand_full_name',  'date' => 'confirmation_date'],
    'First Communion' => ['type' => 'first_communion', 'table' => 'first_communion_records', 'column' => 'person_id', 'name' => 'communicant_full_name', 'date' => 'communion_date'],
];

$marriageRoles = [
    'As husband' => ['type' => 'marriage', 'table' => 'marriage_records', 'column' => 'husband_person_id', 'name' => 'wife_maiden_name', 'date' => 'marriage_date'],
    'As wife'    => ['type' => 'marriage', 'table' => 'marriage_records', 'column' => 'wife_person_id',    'name' => 'husband_full_name', 'date' => 'marriage_date'],
];

// --- Remove a wrong person-to-record link -------------------------------
// Clears one *_person_id bridge cell (the same bridge the person
// mapper fills in). Table and column names are looked up in the $sections /
// $marriageRoles allow-list above — never taken from POST — so a forged
// section_key cannot reach an arbitrary table. The UPDATE re-asserts that
// the row still points at this person, which makes a double submit or a
// raced change report cleanly instead of silently appearing to succeed.
$actionError = '';
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
                    "Removed the link between this record and {$personLabel} (#{$personId}).");
                redirect_to('/admin/system/person-profile.php?id=' . $personId . '&unlinked=1');
            }
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

$pageTitle = 'Person Profile';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<p><a href="<?= $basePath ?>/admin/system/person-mapper.php">&larr; Back to Person Mapper</a></p>
<h1><?= e($personLabel) ?></h1>
<?php if ($actionError): ?><p class="alert alert-error"><?= e($actionError) ?></p><?php endif; ?>
<?php if (isset($_GET['unlinked'])): ?><p class="alert alert-success">Link removed. The record is back in the mapping queue.</p><?php endif; ?>
<p class="field-hint">Person #<?= (int) $person['id'] ?><?= !empty($person['birth_date']) ? ' &middot; born ' . e(date('F j, Y', strtotime($person['birth_date']))) : '' ?> &middot; <?= (int) $totalLinked ?> linked register row<?= $totalLinked === 1 ? '' : 's' ?></p>

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
