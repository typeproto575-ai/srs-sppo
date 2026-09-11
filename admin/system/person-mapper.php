<?php
/**
 * =====================================================================
 * SRS-SPPO PERSON MAPPER (LEGACY RECORD LINKING TOOL)
 * =====================================================================
 * Purpose: Maps legacy record name fields to `persons` entries so that
 *          the same person can be linked across multiple sacrament
 *          records (e.g. a baptismal record and a confirmation record
 *          for the same individual).
 *
 * Access: Secretary only (require_role('secretary')).
 *
 * Security: The $col (column name) is validated against a strict
 *           whitelist before being used in any SQL query, preventing
 *           SQL injection via the 'column' POST parameter.
 * =====================================================================
 */

// Load database connection, shared helpers, and session guard
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Base URL path for building links
$basePath = base_path();
$notice = null;
$errors = [];

// A submitted table/column pair must resolve to one known mapping. This
// prevents a valid column name from being used against the wrong table.
$mappings = [
    'baptismal' => ['table' => 'baptismal_records', 'column' => 'person_id', 'name' => 'child_full_name'],
    'confirmation' => ['table' => 'confirmation_records', 'column' => 'person_id', 'name' => 'confirmand_full_name'],
    'first_communion' => ['table' => 'first_communion_records', 'column' => 'person_id', 'name' => 'communicant_full_name'],
    'marriage_husband' => ['table' => 'marriage_records', 'column' => 'husband_person_id', 'name' => 'husband_full_name'],
    'marriage_wife' => ['table' => 'marriage_records', 'column' => 'wife_person_id', 'name' => 'wife_maiden_name'],
    'death_burial' => ['table' => 'death_burial_records', 'column' => 'person_id', 'name' => 'deceased_full_name'],
];

function mapper_name_parts(string $fullName): array
{
    $tokens = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
    $suffixes = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];
    $suffix = '';
    if ($tokens && in_array(strtolower(end($tokens)), $suffixes, true)) {
        $suffix = array_pop($tokens);
    }
    $first = array_shift($tokens) ?? '';
    $last = array_pop($tokens) ?? '';
    return [$first, implode(' ', $tokens), $last, $suffix];
}

function mapper_normalize(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/\b(jr|sr|ii|iii|iv|v)\.?\b/', '', $value);
    return preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        $action = $_POST['action'] ?? '';
        $section = $_POST['section'] ?? '';
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $personId = (int) ($_POST['person_id'] ?? 0);
        $fullName = trim($_POST['new_person_name'] ?? '');
        $mapping = $mappings[$section] ?? null;

        if (!$mapping || $recordId <= 0) {
            $errors[] = 'Invalid record mapping selected.';
        } elseif ($action === 'assign' && $personId > 0) {
            $personStmt = $pdo->prepare('SELECT id FROM persons WHERE id = ?');
            $personStmt->execute([$personId]);
            if (!$personStmt->fetchColumn()) {
                $errors[] = 'The selected person does not exist.';
            }
        } elseif ($action === 'create_and_assign') {
            if ($fullName === '') $errors[] = 'A full name is required.';
        } else {
            $errors[] = 'Choose an existing person or enter a new full name.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($action === 'create_and_assign') {
                    [$first, $middle, $last, $suffix] = mapper_name_parts($fullName);
                    $stmt = $pdo->prepare(
                        'INSERT INTO persons (first_name, middle_name, last_name, suffix, maiden_name, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())'
                    );
                    $stmt->execute([$first, $middle ?: null, $last ?: null, $suffix ?: null,
                        $section === 'marriage_wife' ? $last : null]);
                    $personId = (int) $pdo->lastInsertId();
                    $actionName = 'create_person_and_map';
                } else {
                    $actionName = 'map_person';
                }

                $sql = "UPDATE `{$mapping['table']}` SET `{$mapping['column']}` = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$personId, $recordId]);
                if ($stmt->rowCount() < 1) throw new RuntimeException('Record was not found or was not changed.');

                log_activity($pdo, current_user()['id'], $actionName, $mapping['table'], $recordId,
                    "Mapped {$mapping['column']} to person_id={$personId}");
                $pdo->commit();
                $notice = 'Person mapping saved.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'The person mapping could not be saved.';
                error_log('SRS-SPPO person mapper: ' . $e->getMessage());
            }
        }
    }
}

// Search includes maiden names and uses a normalized PHP score for minor
// typos. Suggestions are never auto-assigned; the secretary confirms them.
$search = trim($_GET['q'] ?? '');
$persons = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $pdo->prepare(
        "SELECT id, first_name, middle_name, last_name, suffix, maiden_name, birth_date
         FROM persons
         WHERE CONCAT_WS(' ', first_name, middle_name, last_name, suffix) LIKE ?
            OR maiden_name LIKE ? OR birth_date LIKE ?
         ORDER BY last_name, first_name LIMIT 100"
    );
    $stmt->execute([$like, $like, $like]);
    $persons = $stmt->fetchAll();
    $needle = mapper_normalize($search);
    usort($persons, static function (array $a, array $b) use ($needle): int {
        $aText = mapper_normalize(implode(' ', array_filter([$a['first_name'], $a['middle_name'], $a['last_name'], $a['maiden_name']])));
        $bText = mapper_normalize(implode(' ', array_filter([$b['first_name'], $b['middle_name'], $b['last_name'], $b['maiden_name']])));
        return levenshtein($needle, $aText) <=> levenshtein($needle, $bText);
    });
} else {
    $persons = $pdo->query(
        'SELECT id, first_name, middle_name, last_name, suffix, maiden_name, birth_date
         FROM persons ORDER BY last_name, first_name LIMIT 100'
    )->fetchAll();
}

$unmatched = [];
foreach ($mappings as $section => $mapping) {
    $sql = "SELECT id, {$mapping['name']} AS display_name FROM {$mapping['table']}
            WHERE {$mapping['column']} IS NULL OR {$mapping['column']} = 0 ORDER BY id DESC LIMIT 200";
    $unmatched[$section] = $pdo->query($sql)->fetchAll();
}

$pageTitle = 'Person Mapper';

// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Person Mapper</h1>
        <?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <p class="field-hint">Map legacy record name fields to `persons` entries. Create a new person if no match exists.</p>

        <form method="get" class="inline-fields" style="margin:1rem 0;">
            <label>Find a person
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, maiden name, or birth date">
            </label>
            <button class="btn btn-small" type="submit">Search</button>
        </form>

        <?php foreach ($unmatched as $section => $rows): ?>
            <section style="margin-top:1.25rem;">
                <h2><?= e(ucwords(str_replace(['_','marriage'],' ', $section))) ?></h2>
                <?php if (empty($rows)): ?>
                    <p>No unmatched rows.</p>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <div class="panel-card" style="margin-bottom:0.5rem; display:flex; align-items:center; justify-content:space-between;">
                            <div style="flex:1;">
                                <strong>#<?= (int)$r['id'] ?></strong>
                                <div><?= e($r['display_name'] ?: '—') ?></div>
                            </div>
                            <div class="mapper-actions">
                                <!-- Assign existing person form -->
                                <form method="post" style="display:inline-block; margin-right:0.5rem;">
                                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                    <input type="hidden" name="action" value="assign">
                                    <input type="hidden" name="section" value="<?= e($section) ?>">
                                    <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                                    <select name="person_id" required>
                                        <option value="">— choose person —</option>
                                        <?php foreach ($persons as $p): ?>
                                            <?php $personLabel = trim(implode(' ', array_filter([$p['first_name'], $p['middle_name'], $p['last_name'], $p['suffix']]))); ?>
                                            <?php if ($p['maiden_name']): ?><?php $personLabel .= ' (maiden: ' . $p['maiden_name'] . ')'; ?><?php endif; ?>
                                            <option value="<?= (int)$p['id'] ?>"><?= e($personLabel) ?> (id:<?= (int)$p['id'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-small" type="submit">Assign</button>
                                </form>

                                <!-- Create new person + assign form -->
                                <form method="post" style="display:inline-block;">
                                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                    <input type="hidden" name="action" value="create_and_assign">
                                    <input type="hidden" name="section" value="<?= e($section) ?>">
                                    <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                                    <input type="text" name="new_person_name" placeholder="New person full name" required>
                                    <button class="btn btn-secondary btn-small" type="submit">Create & Assign</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

    <?php require_once __DIR__ . '/../../includes/admin-footer.php';