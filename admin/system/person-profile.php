<?php
/**
 * =====================================================================
 * SRS-SPPO PERSON PROFILE (admin)
 * =====================================================================
 * Purpose: Shows one mapped person and EVERY register row linked to
 *          them across all sacraments — the payoff of the person
 *          mapper: baptised/confirmed/communicant/married/deceased
 *          entries united under one identity.
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
    'Baptismal'          => ['mapper' => 'baptismal',         'table' => 'baptismal_records',       'column' => 'person_id',         'name' => 'child_full_name',       'date' => 'baptism_date'],
    'Confirmation'       => ['mapper' => 'confirmation',      'table' => 'confirmation_records',    'column' => 'person_id',         'name' => 'confirmand_full_name',  'date' => 'confirmation_date'],
    'First Communion'    => ['mapper' => 'first_communion',   'table' => 'first_communion_records', 'column' => 'person_id',         'name' => 'communicant_full_name', 'date' => 'communion_date'],
    'Marriage (husband)' => ['mapper' => 'marriage_husband',  'table' => 'marriage_records',        'column' => 'husband_person_id', 'name' => 'husband_full_name',     'date' => 'marriage_date'],
    'Marriage (wife)'    => ['mapper' => 'marriage_wife',     'table' => 'marriage_records',        'column' => 'wife_person_id',    'name' => 'wife_maiden_name',      'date' => 'marriage_date'],
    'Death / Burial'     => ['mapper' => 'death_burial',      'table' => 'death_burial_records',    'column' => 'person_id',         'name' => 'deceased_full_name',    'date' => 'date_of_death'],
];

$linked = [];
$totalLinked = 0;
foreach ($sections as $label => $sec) {
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
        <table class="admin-table" style="max-width:700px;">
            <thead><tr><th>Record</th><th>Name as registered</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>#<?= (int) $r['id'] ?></td>
                    <td><?= e($r['display_name'] ?: '—') ?></td>
                    <td><?= e($r['event_date'] ? date('M j, Y', strtotime($r['event_date'])) : '—') ?></td>
                    <td><a class="btn btn-secondary btn-small" href="<?= $basePath ?>/admin/system/person-mapper.php?section=<?= e($sec['mapper']) ?>">Open mapper section</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
