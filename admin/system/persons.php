<?php
/**
 * =====================================================================
 * SRS-SPPO PERSONS DIRECTORY (admin)
 * =====================================================================
 * Purpose: Browse the persons identity index (the table the person mapper
 *          links register rows to) and remove a person who is no longer
 *          linked to anything.
 *
 *          Deleting here NEVER touches a register row: it removes the
 *          persons row only, and only when person_delete_if_unlinked()
 *          confirms that no bridge column still points at that person.
 *          Per-person linked rows are inspected on the profile page
 *          (admin/system/person-profile.php).
 *
 * Access: Secretary only (require_role('secretary')).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/person-linking.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$actionError = '';
$success = null;

// --- Delete an unlinked person --------------------------------------------
// The shared helper recounts every bridge table itself, so a stale count on
// this page can never delete a person that something still points at.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!csrf_verify()) {
        $actionError = 'Your session expired. Please try again.';
    } else {
        // The helper reads the row itself (existence check and audit label),
        // so this handler only has to pass the id through.
        $deleteId = (int) ($_POST['person_id'] ?? 0);
        $result = person_delete_if_unlinked($pdo, $deleteId, current_user()['id']);
        if (!$result['ok']) {
            $actionError = $result['error'];
        } else {
            redirect_to('/admin/system/persons.php?deleted=1');
        }
    }
}

if (isset($_GET['deleted'])) $success = 'Person deleted.';

// --- Filter / paginate (shared component) ---------------------------------
// A person row has no natural event date, so the filter bar runs on
// created_at ("when this person record was added") — harmless and still
// meaningful to staff.
$rfConfig = [
    'date_column'    => 'created_at',
    'search_columns' => ["CONCAT_WS(' ', first_name, middle_name, last_name, suffix)", 'maiden_name'],
    'placeholder'    => 'Search person names...',
    'ranges'         => ['year', 'last_year', 'last5'],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM persons WHERE 1=1 {$rf['where']}");
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT p.*,
            CASE WHEN EXISTS (
                SELECT 1 FROM baptismal_records br WHERE br.person_id = p.id
            ) THEN 'baptized_catholic' ELSE NULL END AS derived_baptismal_status
     FROM persons p WHERE 1=1 {$rf['where']}
     ORDER BY last_name ASC, first_name ASC, id ASC LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$persons = $stmt->fetchAll();

// --- Linked-record counts for THIS PAGE's persons only ---------------------
// One GROUP BY query per bridge table (5 total), scoped to the ids on screen,
// so the page cost does not grow with per_page. Ids are (int)-cast and joined
// because PDO cannot bind a parameter list.
$linkCounts = [];
$pageIds = array_map(static fn (array $p): int => (int) $p['id'], $persons);
if ($pageIds !== []) {
    $idList = implode(',', $pageIds);
    foreach (array_merge(person_bridge_sections(), person_bridge_marriage_roles()) as $sec) {
        $countRows = $pdo->query(
            "SELECT {$sec['column']} AS pid, COUNT(*) AS n FROM {$sec['table']}
             WHERE {$sec['column']} IN ({$idList}) GROUP BY {$sec['column']}"
        )->fetchAll();
        foreach ($countRows as $row) {
            $pid = (int) $row['pid'];
            $linkCounts[$pid] = ($linkCounts[$pid] ?? 0) + (int) $row['n'];
        }
    }
}

$pageTitle = 'Persons';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="page-heading">
    <h1>Persons</h1>
</div>

<?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
<?php if ($actionError): ?><div class="alert alert-error"><p><?= e($actionError) ?></p></div><?php endif; ?>

<?php rf_render_bar($basePath . '/admin/system/persons.php', $rfConfig, $rf); ?>

<div class="table-scroll">
<table class="admin-table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Sex</th>
            <th>Birth date</th>
            <th>Baptismal status</th>
            <th>Linked records</th>
            <th>Options</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($persons)): ?>
            <tr>
                <td colspan="6" class="empty-table-row">
                    <div class="empty-state">
                        <h2>No persons found</h2>
                        <p><?= $rf['q'] !== '' ? 'No matches for "' . e($rf['q']) . '".' : 'Person records appear here once register rows are linked to them on the Person Linking page.' ?></p>
                    </div>
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($persons as $person): ?>
                <?php
                    $personId = (int) $person['id'];
                    $linkedCount = $linkCounts[$personId] ?? 0;
                    $personName = trim(implode(' ', array_filter([
                        $person['first_name'], $person['middle_name'], $person['last_name'], $person['suffix'],
                    ])));
                    if (!empty($person['maiden_name'])) $personName .= ' (maiden: ' . $person['maiden_name'] . ')';
                ?>
                <tr>
                    <td><strong><?= e($personName) ?></strong></td>
                    <td><?= $person['sex'] ? e(ucfirst((string) $person['sex'])) : '—' ?></td>
                    <td><?= $person['birth_date'] ? e(date('M j, Y', strtotime($person['birth_date']))) : '—' ?></td>
                    <td><?php $bStatus = (string) ($person['derived_baptismal_status'] ?? ''); ?><?= $bStatus !== '' ? e(ucwords(str_replace('_', ' ', $bStatus))) : 'Unknown' ?></td>
                    <td>
                        <?php if ($linkedCount > 0): ?>
                            <span class="link-status-badge link-status-linked"><?= (int) $linkedCount ?></span>
                        <?php else: ?>
                            <span class="link-status-badge link-status-unlinked">None</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/system/person-profile.php?id=' . $personId) ?>">View profile</a>
                        <?php if ($linkedCount === 0): ?>
                            <form method="post" style="display:inline; margin-left:0.4rem;" data-confirm="Delete this person? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="person_id" value="<?= $personId ?>">
                                <button type="submit" class="btn btn-danger btn-small">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</div>
<?php rf_render_pagination($basePath . '/admin/system/persons.php', $rfTotal, $rf); ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
