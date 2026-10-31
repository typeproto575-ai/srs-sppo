<?php
/**
 * Read-only detail view for one unified sacramental record.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';
require_role('secretary');

$basePath = base_path();
$recordType = (string) ($_GET['type'] ?? '');
$recordId = (int) ($_GET['id'] ?? 0);
$returnInput = $_GET['return'] ?? null;
$returnUrl = is_string($returnInput)
    && strpos($returnInput, '\\') === false
    && strpos($returnInput, '//') === false
    && parse_url($returnInput, PHP_URL_SCHEME) === null
        ? safe_internal_return_url($returnInput)
        : null;

$recordTypes = [
    'baptismal' => [
        'sacrament_type' => 'baptism',
        'label' => 'Baptism',
        'edit' => '/admin/records/baptismal.php',
    ],
    'baptism' => [
        'sacrament_type' => 'baptism',
        'label' => 'Baptism',
        'edit' => '/admin/records/baptismal.php',
    ],
    'confirmation' => [
        'sacrament_type' => 'confirmation',
        'label' => 'Confirmation',
        'edit' => '/admin/records/confirmation.php',
    ],
    'first_communion' => [
        'sacrament_type' => 'first_communion',
        'label' => 'First Communion',
        'edit' => '/admin/records/first-communion.php',
    ],
    'marriage' => [
        'sacrament_type' => 'marriage',
        'label' => 'Marriage',
        'edit' => '/admin/records/marriage.php',
    ],
];

$definition = $recordTypes[$recordType] ?? null;
if (!$definition || $recordId <= 0) {
    redirect_to('/admin/records/index.php');
    exit;
}

$record = sd_get_record($pdo, $recordId);
if (!$record || $record['sacrament_type'] !== $definition['sacrament_type']) {
    redirect_to('/admin/records/index.php');
    exit;
}

$historyStmt = $pdo->prepare(
    'SELECT al.id, al.user_id, al.actor_name, al.action, al.target_table,
            al.target_id, al.description, al.reason, al.created_at,
            u.full_name AS user_name
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.target_table = ? AND al.target_id = ?
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT 20'
);
$historyStmt->execute(['sacrament_records', $recordId]);
$recordHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$personLabels = [];
$personIds = array_values(array_unique(array_filter([
    (int) ($record['person_id'] ?? 0),
    (int) ($record['spouse_person_id'] ?? 0),
])));
foreach ($personIds as $index => $personId) {
    $person = sd_get_person($pdo, $personId);
    if ($person) {
        $personLabels[] = [
            'label' => $index === 0 ? ($definition['sacrament_type'] === 'marriage' ? 'Groom' : 'Recipient') : 'Spouse',
            'person' => $person,
        ];
    }
}

$relatedRecords = [];
$seenRelated = [$recordId => true];
foreach ($personIds as $personId) {
    foreach (sd_get_person_records($pdo, $personId) as $recordGroup) {
        $personRecords = is_array($recordGroup) && array_is_list($recordGroup)
            ? $recordGroup
            : [$recordGroup];
        foreach ($personRecords as $relatedRecord) {
            if (!is_array($relatedRecord) || empty($relatedRecord['id'])) {
                continue;
            }
            $relatedId = (int) $relatedRecord['id'];
            if (isset($seenRelated[$relatedId])) {
                continue;
            }
            $seenRelated[$relatedId] = true;

            $relatedType = (string) ($relatedRecord['sacrament_type'] ?? '');
            $relatedTypes = [
                'baptism' => 'baptismal',
                'first_communion' => 'first_communion',
                'confirmation' => 'confirmation',
                'marriage' => 'marriage',
            ];
            if (!isset($relatedTypes[$relatedType])) {
                continue;
            }

            $name = (string) ($relatedRecord['person_name'] ?? '');
            if ($relatedType === 'marriage') {
                $name = trim((string) ($relatedRecord['person_name'] ?? '') . ' & ' . (string) ($relatedRecord['spouse_name'] ?? ''), ' &');
            }
            $relatedRecords[] = [
                'type' => $relatedTypes[$relatedType],
                'id' => $relatedId,
                'name' => $name,
                'label' => sd_type_label($relatedType),
                'event_date' => $relatedRecord['event_date'] ?? null,
            ];
        }
    }
}

function record_view_value($value): string
{
    if ($value === null || $value === '') {
        return 'Not recorded';
    }
    if (is_array($value) || is_object($value)) {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string) $value;
}

function record_view_label(string $value): string
{
    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function record_history_action_label(string $action): string
{
    return ucfirst(str_replace('_', ' ', $action));
}

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
    return $labels[basename($path)] ?? 'Back to All Records';
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
    </div>
</div>

<div class="record-detail-grid">
    <section class="panel-card record-detail-card">
        <p class="field-hint">Read-only view</p>
        <h2><?= e($record['sacrament_type'] === 'marriage'
            ? trim((string) ($record['person_name'] ?? '') . ' & ' . (string) ($record['spouse_name'] ?? ''), ' &')
            : ($record['person_name'] ?? 'Person unavailable')) ?></h2>
        <dl class="record-detail-list">
            <div><dt>Record ID</dt><dd>#<?= (int) $record['id'] ?></dd></div>
            <div><dt>Sacrament</dt><dd><?= e(sd_type_label($record['sacrament_type'])) ?></dd></div>
            <div><dt>Event date</dt><dd><?= e(record_view_value($record['event_date'] ?? null)) ?></dd></div>
            <div><dt>Place</dt><dd><?= e(record_view_value($record['place'] ?? null)) ?></dd></div>
            <div><dt>Minister</dt><dd><?= e(record_view_value($record['minister_name'] ?? null)) ?></dd></div>
            <?php $registerReference = implode(' / ', array_filter([
                $record['entry_no'] ?? null,
                $record['book_no'] ?? null,
                $record['page_no'] ?? null,
                $record['line_no'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '')); ?>
            <div><dt>Entry / Book / Page / Line</dt><dd><?= e($registerReference !== '' ? $registerReference : 'Not recorded') ?></dd></div>
            <div><dt>Source</dt><dd><?= e(record_view_label((string) ($record['source'] ?? ''))) ?></dd></div>
            <?php if ($record['sacrament_type'] === 'baptism'): ?>
                <div><dt>Parents' union</dt><dd><?= e(record_view_label((string) ($record['parents_union_type'] ?? 'Not recorded'))) ?></dd></div>
            <?php endif; ?>
            <div><dt>Book verification</dt><dd><?= !empty($record['book_verified_at']) ? 'Verified on ' . e($record['book_verified_at']) : 'Not verified' ?></dd></div>
            <div><dt>Created</dt><dd><?= e(record_view_value($record['created_at'] ?? null)) ?></dd></div>
            <div><dt>Updated</dt><dd><?= e(record_view_value($record['updated_at'] ?? null)) ?></dd></div>
        </dl>

        <?php if (!empty($record['details'])): ?>
            <p class="record-detail-group-title">Additional details</p>
            <dl class="record-detail-list">
                <?php foreach ($record['details'] as $key => $value): ?>
                    <?php if (strtolower((string) $key) === 'legitimacy') continue; ?>
                    <div><dt><?= e(record_view_label((string) $key)) ?></dt><dd><?= e(record_view_value($value)) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>

        <?php if (!empty($record['remarks'])): ?>
            <p class="record-detail-group-title">Remarks</p>
            <p><?= nl2br(e($record['remarks'])) ?></p>
        <?php endif; ?>

        <?php if (!empty($record['participants'])): ?>
            <p class="record-detail-group-title">Participants</p>
            <dl class="record-detail-list">
                <?php foreach ($record['participants'] as $participant): ?>
                    <div>
                        <dt><?= e(record_view_label((string) $participant['role'])) ?></dt>
                        <dd><?= e($participant['full_name']) ?><?= !empty($participant['residence']) ? ' — ' . e($participant['residence']) : '' ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </section>

    <?php if ($relatedRecords): ?>
        <aside class="panel-card record-detail-card">
            <h2>Related records</h2>
            <ul class="record-person-link-list">
                <?php foreach ($relatedRecords as $related): ?>
                    <?php $relatedUrl = $basePath . '/admin/records/view.php?type=' . rawurlencode($related['type']) . '&id=' . (int) $related['id'] . ($returnUrl ? '&return=' . rawurlencode($returnUrl) : ''); ?>
                    <li class="record-person-link">
                        <div>
                            <span class="field-hint"><?= e($related['label']) ?></span>
                            <a href="<?= e($relatedUrl) ?>"><?= e($related['name'] ?: 'Record #' . (int) $related['id']) ?></a>
                        </div>
                        <small><?= e(record_view_value($related['event_date'])) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>
    <?php endif; ?>

    <aside class="panel-card record-detail-card">
        <h2>Person links</h2>
        <?php if (!$personLabels): ?>
            <p class="field-hint">No shared person identity is linked yet.</p>
        <?php else: ?>
            <?php foreach ($personLabels as $link): ?>
                <div class="record-person-link">
                    <span class="field-hint"><?= e($link['label']) ?></span>
                    <strong><?= e(sd_full_name($link['person'])) ?><?= !empty($link['person']['birth_date']) ? ' — born ' . e($link['person']['birth_date']) : '' ?></strong>
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
                            <?= e($entry['user_name'] ?? $entry['actor_name'] ?? 'System / Public Form') ?>
                            <?php if (!empty($entry['description'])): ?><br><?= e($entry['description']) ?><?php endif; ?>
                            <?php if (!empty($entry['reason'])): ?><br>Reason: <?= e($entry['reason']) ?><?php endif; ?>
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/system/audit-log.php?module=sacrament_records&record_id=' . $recordId) ?>">View full audit log for this record</a>
        <?php endif; ?>
    </aside>
</div>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
