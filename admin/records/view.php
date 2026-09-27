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
// Where the user came from, when the list page passed it. Already validated as
// an app-relative /admin path by safe_internal_return_url(); null means fall
// back to the All Records index.
$returnUrl = safe_internal_return_url($_GET['return'] ?? null);
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
        'field_groups' => [
            'Child' => [
                'birth_date' => 'Birth date', 'baptism_place' => 'Place of baptism',
                'child_birthplace' => "Child's birthplace",
            ],
            'Parents' => [
                'father_name' => "Father's name", 'father_birthplace' => "Father's birthplace",
                'mother_name' => "Mother's name", 'mother_maiden_name' => "Mother's maiden name",
                'mother_birthplace' => "Mother's birthplace",
                'parents_origin' => "Parents' origin", 'parents_residence' => "Parents' residence",
            ],
            'Ceremony' => [
                'proxy_name' => 'Proxy (if any)', 'minister_name' => 'Minister', 'remarks' => 'Remarks',
            ],
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
        'field_groups' => [
            'Confirmand' => [
                'birthdate' => 'Birth date', 'father_name' => "Father's name",
                'mother_maiden_name' => "Mother's maiden name",
            ],
            'Ceremony' => [
                'sponsor_name' => 'Sponsor', 'minister_name' => 'Minister', 'remarks' => 'Remarks',
            ],
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
        'field_groups' => [
            'Communicant' => [
                'domicile' => 'Residence', 'father_name' => "Father's name",
                'mother_maiden_name' => "Mother's maiden name",
            ],
            'Ceremony' => [
                'minister_name' => 'Minister', 'remarks' => 'Remarks',
            ],
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
        'field_groups' => [
            'Husband' => [
                'husband_full_name' => "Husband's name", 'husband_birthdate' => "Husband's birth date",
                'husband_birthplace' => "Husband's birthplace", 'husband_civil_status' => 'Husband civil status',
                'husband_age' => "Husband's age", 'husband_origin' => 'Husband origin',
                'husband_residence' => 'Husband residence', 'husband_parents_name' => "Husband's parents",
            ],
            'Wife' => [
                'wife_maiden_name' => "Wife's maiden name", 'wife_birthdate' => "Wife's birth date",
                'wife_birthplace' => "Wife's birthplace", 'wife_civil_status' => 'Wife civil status',
                'wife_age' => "Wife's age", 'wife_origin' => 'Wife origin',
                'wife_residence' => 'Wife residence', 'wife_parents_name' => "Wife's parents",
            ],
            'Ceremony' => [
                'officiating_minister' => 'Minister', 'remarks' => 'Remarks',
            ],
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

// Audit trail for this record alone. log_activity() writes target_table = the
// record's own table and target_id = its id, so that pair is the record's
// identity in the log. LIMIT 20 caps the panel; the link at the bottom of it
// reaches the rest through the audit-log deep link. al.id breaks ties for
// entries written in the same second (one encode can log twice).
$historyStmt = $pdo->prepare(
    "SELECT al.*, u.full_name AS user_name
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.target_table = ? AND al.target_id = ?
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT 20"
);
$historyStmt->execute([$definition['table'], $recordId]);
$recordHistory = $historyStmt->fetchAll();

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

// Audit actions are stored snake_case ('baptismal_record_updated',
// 'record_archived'); render them as a sentence instead of dumping the code.
function record_history_action_label(string $action): string
{
    return ucfirst(str_replace('_', ' ', $action));
}

// The button used to always read "Back to All Records" even when it returned
// you to a filtered parish list. Name it after the page it actually goes to,
// so the label always matches where the click will land.
function record_return_label(?string $returnUrl): string
{
    if (!$returnUrl) {
        return 'Back to All Records';
    }
    $labels = [
        'baptismal.php' => 'Back to Baptismal Records',
        'confirmation.php' => 'Back to Confirmation Records',
        'first-communion.php' => 'Back to First Communion Records',
        'marriage.php' => 'Back to Marriage Records',
        'index.php' => 'Back to All Records',
    ];
    $path = parse_url($returnUrl, PHP_URL_PATH) ?: '';
    $file = basename($path);
    return $labels[$file] ?? 'Back to All Records';
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
                <a class="btn btn-secondary btn-small" href="<?= e($basePath . ($returnUrl ?: '/admin/records/index.php')) ?>"><?= e(record_return_label($returnUrl)) ?></a>
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
                </dl>
                <?php foreach ($definition['field_groups'] as $groupLabel => $groupFields): ?>
                    <p class="record-detail-group-title"><?= e($groupLabel) ?></p>
                    <dl class="record-detail-list">
                        <?php foreach ($groupFields as $field => $label): ?>
                            <div><dt><?= e($label) ?></dt><dd><?= e(record_view_value($field, $record[$field] ?? null)) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                <?php endforeach; ?>
            </section>

            <?php if ($relatedRecords): ?>
                <aside class="panel-card record-detail-card">
                    <h2>Related records</h2>
                    <ul class="record-person-link-list">
                        <?php foreach ($relatedRecords as $related): ?>
                            <li class="record-person-link">
                                <div>
                                    <span class="field-hint"><?= e($related['label']) ?></span>
                                    <a href="<?= e($basePath . '/admin/records/view.php?type=' . $related['type'] . '&id=' . (int) $related['id'] . ($returnUrl ? '&return=' . urlencode($returnUrl) : '')) ?>"><?= e($related['name'] ?: 'Record #' . (int) $related['id']) ?></a>
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

            <aside class="panel-card record-detail-card">
                <h2>Record history</h2>
                <?php if (!$recordHistory): ?>
                    <p class="field-hint">No history recorded for this record yet.</p>
                <?php else: ?>
                    <ul class="record-person-link-list record-history-list">
                        <?php foreach ($recordHistory as $entry): ?>
                            <li class="record-person-link">
                                <div>
                                    <span class="field-hint"><?= e(date('M j, Y g:i A', strtotime($entry['created_at']))) ?></span>
                                    <strong><?= e(record_history_action_label($entry['action'])) ?></strong>
                                </div>
                                <small>
                                    <?= e($entry['user_name'] ?? 'System / Public Form') ?>
                                    <?php if (!empty($entry['description'])): ?><br><?= e($entry['description']) ?><?php endif; ?>
                                    <?php if (!empty($entry['reason'])): ?><br>Reason: <?= e($entry['reason']) ?><?php endif; ?>
                                </small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/system/audit-log.php?module=' . urlencode($definition['table']) . '&record_id=' . $recordId) ?>">View full audit log for this record</a>
                <?php endif; ?>
            </aside>
        </div>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
