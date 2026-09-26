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

$personLabel = trim(implode(' ', array_filter([
    $person['first_name'], $person['middle_name'], $person['last_name'], $person['suffix'],
])));
if (!empty($person['maiden_name'])) $personLabel .= ' (maiden: ' . $person['maiden_name'] . ')';

$pageTitle = 'Person Profile';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<p><a href="<?= $basePath ?>/admin/system/person-mapper.php">&larr; Back to Person Mapper</a></p>
<h1><?= e($personLabel) ?></h1>
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
                    <td class="row-actions"><a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/view.php?type=' . $sec['type'] . '&id=' . (int) $r['id']) ?>">View record</a></td>
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
                    <td class="row-actions"><a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/view.php?type=marriage&id=' . (int) $r['id']) ?>">View record</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
