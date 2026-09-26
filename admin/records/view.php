<?php
/**
 * Read-only detail view for one unified sacramental record.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/person-linking.php';
require_role('secretary');

$basePath = base_path();
$recordType = (string) ($_GET['type'] ?? '');
$recordId = (int) ($_GET['id'] ?? 0);
$mapperSection = [
    'baptismal' => 'baptismal',
    'confirmation' => 'confirmation',
    'first_communion' => 'first_communion',
    'marriage' => 'marriage_husband'
][$recordType] ?? null;


$recordDefinitions = [
    'baptismal' => [
        'label' => 'Baptismal',
        'date_label' => 'Date of Baptism',
        'table' => 'baptismal_records',
        'name' => 'child_full_name',
        'date' => 'baptism_date',
        'edit' => '/admin/records/baptismal.php',
        'person_columns' => ['person_id' => 'Person identity'],
        'fields' => [
            'birth_date' => 'Birth date', 'baptism_place' => 'Place of baptism',
            'father_name' => "Father's name", 'mother_name' => "Mother's name",
            'minister_name' => 'Minister', 'remarks' => 'Remarks',
            'baptismal_date' => 'Baptismal date',
        ],
    ],
    'confirmation' => [
        'label' => 'Confirmation',
        'date_label' => 'Date of Confirmation',
        'table' => 'confirmation_records',
        'name' => 'confirmand_full_name',
        'date' => 'confirmation_date',
        'edit' => '/admin/records/confirmation.php',
        'person_columns' => ['person_id' => 'Person identity'],
        'fields' => [
            'birthdate' => 'Birth date', 'father_name' => "Father's name",
            'mother_maiden_name' => "Mother's maiden name", 'sponsor_name' => 'Sponsor',
            'minister_name' => 'Minister', 'remarks' => 'Remarks',
        ],
    ],
    'first_communion' => [
        'label' => 'First Communion',
        'date_label' => 'Date of First Communion',
        'table' => 'first_communion_records',
        'name' => 'communicant_full_name',
        'date' => 'communion_date',
        'edit' => '/admin/records/first-communion.php',
        'person_columns' => ['person_id' => 'Person identity'],
        'fields' => [
            'domicile' => 'Residence', 'father_name' => "Father's name",
            'mother_maiden_name' => "Mother's maiden name", 'minister_name' => 'Minister',
            'remarks' => 'Remarks',
        ],
    ],
    'marriage' => [
        'label' => 'Marriage',
        'date_label' => 'Date of Marriage',
        'table' => 'marriage_records',
        'name' => null,
        'date' => 'marriage_date',
        'edit' => '/admin/records/marriage.php',
        'person_columns' => ['husband_person_id' => 'Husband identity', 'wife_person_id' => 'Wife identity'],
        'fields' => [
            'husband_full_name' => "Husband's name", 'wife_maiden_name' => "Wife's maiden name",
            'husband_birthdate' => "Husband's birth date", 'husband_civil_status' => 'Husband civil status',
            'husband_age' => "Husband's age", 'husband_origin' => 'Husband origin', 'husband_residence' => 'Husband residence',
            'husband_parents_name' => "Husband's parents", 'wife_birthdate' => "Wife's birth date",
            'wife_civil_status' => 'Wife civil status', 'wife_age' => "Wife's age", 'wife_origin' => 'Wife origin',
            'wife_residence' => 'Wife residence', 'wife_parents_name' => "Wife's parents",
            'officiating_minister' => 'Minister', 'remarks' => 'Remarks',
            'marriage_date' => 'Marriage date',
        ],
    ],
];

$definition = $recordDefinitions[$recordType] ?? null;
if (!$definition || $recordId <= 0) {
    redirect_to('/admin/records/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM {$definition['table']} WHERE id = ? AND is_archived = 0");
$stmt->execute([$recordId]);
$record = $stmt->fetch();
if (!$record) {
    redirect_to('/admin/records/index.php');
    exit;
}

$personLabels = [];
foreach ($definition['person_columns'] as $column => $label) {
    $personId = (int) ($record[$column] ?? 0);
    if ($personId > 0) {
        $personStmt = $pdo->prepare('SELECT id, first_name, middle_name, last_name, suffix, maiden_name, birth_date FROM persons WHERE id = ?');
        $personStmt->execute([$personId]);
        if ($person = $personStmt->fetch()) {
            $personLabels[$column] = ['label' => $label, 'person' => $person];
        }
    }
}

$relatedRecords = [];
$linkedPersonIds = [];
foreach ($definition['person_columns'] as $column => $label) {
    $personId = (int) ($record[$column] ?? 0);
    if ($personId > 0) {
        $linkedPersonIds[] = $personId;
    }
}
$linkedPersonIds = array_values(array_unique($linkedPersonIds));

if (!empty($linkedPersonIds)) {
    $seenRelated = [];
    $singleTableChecks = [
        'baptismal' => ['table' => 'baptismal_records', 'name_field' => 'child_full_name', 'date_field' => 'baptism_date', 'person_field' => 'person_id', 'label' => 'Baptismal'],
        'confirmation' => ['table' => 'confirmation_records', 'name_field' => 'confirmand_full_name', 'date_field' => 'confirmation_date', 'person_field' => 'person_id', 'label' => 'Confirmation'],
        'first_communion' => ['table' => 'first_communion_records', 'name_field' => 'communicant_full_name', 'date_field' => 'communion_date', 'person_field' => 'person_id', 'label' => 'First Communion'],
        'marriage' => ['table' => 'marriage_records', 'name_field' => 'CONCAT(husband_full_name, " & ", wife_maiden_name)', 'date_field' => 'marriage_date', 'person_field' => '(husband_person_id)', 'label' => 'Marriage'],
    ];

    foreach ($singleTableChecks as $type => $meta) {
        if ($type === $recordType) {
            continue;
        }

        $personPlaceholders = implode(',', array_fill(0, count($linkedPersonIds), '?'));
        $query = "SELECT id, {$meta['name_field']} AS name, {$meta['date_field']} AS event_date
                  FROM {$meta['table']}
                  WHERE is_archived = 0 AND {$meta['person_field']} IN ({$personPlaceholders}) AND id != ?
                  ORDER BY {$meta['date_field']} DESC, id DESC LIMIT 10";

        $stmt = $pdo->prepare($query);
        $params = array_merge($linkedPersonIds, [(int) $recordId]);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $key = $type . ':' . (int) $row['id'];
            if (isset($seenRelated[$key])) {
                continue;
            }
            $seenRelated[$key] = true;
            $relatedRecords[] = [
                'type' => $type,
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'label' => $meta['label'],
                'date_field' => $recordDefinitions[$type]['date'] ?? $meta['date_field'],
                'date_value' => $row['event_date'],
            ];
        }
    }

    $marriageStmt = $pdo->prepare(
        "SELECT id, CONCAT(husband_full_name, ' & ', wife_maiden_name) AS name, marriage_date AS event_date
         FROM marriage_records
         WHERE is_archived = 0 AND (husband_person_id IN ({$personPlaceholders}) OR wife_person_id IN ({$personPlaceholders})) AND id != ?
         ORDER BY marriage_date DESC, id DESC LIMIT 10"
    );
    $marriageParams = array_merge($linkedPersonIds, $linkedPersonIds, [(int) $recordId]);
    $marriageStmt->execute($marriageParams);
    while ($row = $marriageStmt->fetch()) {
        $key = 'marriage:' . (int) $row['id'];
        if (isset($seenRelated[$key])) {
            continue;
        }
        $seenRelated[$key] = true;
        $relatedRecords[] = [
            'type' => 'marriage',
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'label' => 'Marriage',
            'date_field' => 'marriage_date',
            'date_value' => $row['event_date'],
        ];
    }
}

function record_view_value(string $field, $value): string
{
    if ($value === null || $value === '') return 'Not recorded';
    if ($field === 'is_deceased') return (int) $value === 1 ? 'Deceased' : 'Alive';
    if (in_array($field, ['birth_date', 'birthdate', 'baptism_date', 'confirmation_date', 'communion_date', 'marriage_date', 'date_of_death', 'husband_birthdate', 'wife_birthdate'], true)) {
        return date('M j, Y', strtotime((string) $value));
    }
    return (string) $value;
}

$pageTitle = $definition['label'] . ' Record';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <div>
                <p class="hero-eyebrow">Record details</p>
                <h1><?= e($definition['label']) ?> record</h1>
            </div>
            <div class="page-actions">
                <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/index.php') ?>">Back to All Records</a>
                <a class="btn btn-primary btn-small" href="<?= e($basePath . $definition['edit'] . '?edit=' . $recordId) ?>">Edit record</a>
                <?php
                $archiveReturn = urlencode('/admin/records/view.php?type=' . $recordType . '&id=' . $recordId);
                $archiveUrl = $basePath . '/admin/system/archive-record.php?table=' . urlencode($definition['table']) . '&id=' . $recordId . '&return=' . $archiveReturn;
                ?>
                <a class="btn btn-secondary btn-small" href="<?= e($archiveUrl) ?>">Archive record</a>
            </div>
        </div>
        <?php if (isset($_GET['restored'])): ?><p class="alert alert-success">Record restored.</p><?php endif; ?>

        <div class="record-detail-grid">
            <section class="panel-card record-detail-card">
                <p class="field-hint">Read-only view</p>
                <h2><?= e($definition['name'] ? $record[$definition['name']] : ($record['husband_full_name'] . ' & ' . $record['wife_maiden_name'])) ?></h2>
                <dl class="record-detail-list">
                    <div><dt>Record ID</dt><dd>#<?= (int) $record['id'] ?></dd></div>
                    <div><dt><?= e($definition['date_label']) ?></dt><dd><?= e(record_view_value($definition['date'], $record[$definition['date']] ?? null)) ?></dd></div>
                    <div><dt>Book / Page / Line</dt><dd><?= e(trim(($record['book_no'] ?? '') . ' / ' . ($record['page_no'] ?? '') . ' / ' . ($record['line_no'] ?? ''), ' /') ?: 'Not recorded') ?></dd></div>
                    <?php foreach ($definition['fields'] as $field => $label): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= e(record_view_value($field, $record[$field] ?? null)) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </section>

            <?php if ($relatedRecords): ?>
                <aside class="panel-card record-detail-card">
                    <h2>Related records</h2>
                    <ul class="record-person-link-list">
                        <?php foreach ($relatedRecords as $related): ?>
                            <li class="record-person-link">
                                <div>
                                    <span class="field-hint"><?= e($related['label']) ?></span>
                                    <a href="<?= e($basePath . '/admin/records/view.php?type=' . $related['type'] . '&id=' . (int) $related['id']) ?>"><?= e($related['name'] ?: 'Record #' . (int) $related['id']) ?></a>
                                </div>
                                <small><?= e(record_view_value($related['date_field'], $related['date_value'])) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </aside>
            <?php endif; ?>

            <aside class="panel-card record-detail-card">
                <h2>Person links</h2>
                <?php if (!$personLabels): ?>
                    <p class="field-hint">No shared person identity is linked yet.</p>
                    <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/system/person-mapper.php?section=' . urlencode($mapperSection) . '&highlight=' . (int)$recordId) ?>">Link in Person Mapper</a>
                <?php else: ?>
                    <?php foreach ($personLabels as $link): ?>
                        <div class="record-person-link">
                            <span class="field-hint"><?= e($link['label']) ?></span>
                            <strong><?= e(person_identity_label($link['person'])) ?></strong>
                            <a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $link['person']['id']) ?>">View person profile</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </aside>
        </div>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
