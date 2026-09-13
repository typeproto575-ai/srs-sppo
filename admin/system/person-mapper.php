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

// Section tabs: only one registry is worked on at a time (the old version
// rendered up to 6 x 200 forms on one page, which was overwhelming).
$activeSection = $_GET['section'] ?? '';
if (!array_key_exists($activeSection, $mappings)) {
    $activeSection = null; // fall back to the first section with rows below
}

$unmatched = [];
foreach ($mappings as $section => $mapping) {
    $sql = "SELECT id, {$mapping['name']} AS display_name FROM {$mapping['table']}
            WHERE {$mapping['column']} IS NULL OR {$mapping['column']} = 0 ORDER BY id DESC LIMIT 50";
    $unmatched[$section] = $pdo->query($sql)->fetchAll();
    if ($activeSection === null && $unmatched[$section]) {
        $activeSection = $section; // default: first register that has unlinked rows
    }
}
if ($activeSection === null) $activeSection = array_key_first($mappings);

// All persons (small parish-scale table) for one-click name suggestions.
$allPersons = $pdo->query(
    'SELECT id, first_name, middle_name, last_name, suffix, maiden_name, birth_date
     FROM persons ORDER BY last_name, first_name LIMIT 2000'
)->fetchAll();

/**
 * Top name-based candidates for a record's display name. Exact normalized
 * matches first, then closest levenshtein matches — displayed as one-click
 * "Assign" buttons. Never auto-assigned; the secretary confirms each one.
 */
function mapper_suggestions(string $displayName, array $allPersons, int $max = 3): array
{
    $needle = mapper_normalize($displayName);
    if ($needle === '') return [];
    $scored = [];
    foreach ($allPersons as $p) {
        $text = mapper_normalize(implode(' ', array_filter([
            $p['first_name'], $p['middle_name'], $p['last_name'], $p['maiden_name'],
        ])));
        if ($text === '') continue;
        $dist = levenshtein($needle, $text);
        if ($dist === 0 || $dist <= max(2, (int) floor(strlen($needle) * 0.25))) {
            $scored[] = ['p' => $p, 'dist' => $dist];
        }
    }
    usort($scored, static fn (array $a, array $b): int => $a['dist'] <=> $b['dist']);
    return array_slice($scored, 0, $max);
}

function mapper_person_label(array $p): string
{
    $label = trim(implode(' ', array_filter([$p['first_name'], $p['middle_name'], $p['last_name'], $p['suffix']])));
    if (!empty($p['maiden_name'])) $label .= ' (maiden: ' . $p['maiden_name'] . ')';
    return $label;
}

$pageTitle = 'Person Mapper';

// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Person Mapper</h1>
        <?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <!-- Plain-language explainer: what mapping is and why it matters -->
        <div class="panel-card">
            <h2>What this page is for</h2>
            <p class="field-hint">
                Each registry row (baptismal, confirmation, first communion, marriage, death) can be
                <strong>linked to a person</strong>. The link lets the system recognise that the child
                baptised in one register and the adult confirmed in another are the <em>same person</em>.
                Nothing is linked automatically — you confirm every assignment below.
            </p>
            <p class="field-hint">
                <strong>How to use it:</strong> pick a register tab, search for the person (or scan the
                one-click suggestions under each row), then <em>Assign</em>. If the person isn't in the
                list yet, use <em>Create &amp; Assign</em> with the full name as written in the register.
            </p>
        </div>

        <!-- Section tabs with unlinked-row counts -->
        <nav class="mapper-tabs">
            <?php foreach ($mappings as $section => $mapping): ?>
                <a class="mapper-tab<?= $section === $activeSection ? ' is-active' : '' ?>"
                   href="?section=<?= e($section) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
                    <?= e(ucwords(str_replace('_', ' ', $section))) ?>
                    <span class="mapper-tab-count"><?= count($unmatched[$section]) ?> unlinked</span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($search !== ''): ?>
            <p class="field-hint">Person search active — the "choose person" lists below only show matches for &ldquo;<?= e($search) ?>&rdquo;.</p>
        <?php endif; ?>

        <form method="get" class="inline-fields" style="margin:1rem 0;">
            <label>Find a person
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, maiden name, or birth date">
            </label>
            <button class="btn btn-small" type="submit">Search</button>
        </form>

        <?php $rows = $unmatched[$activeSection] ?? []; ?>
        <?php if (empty($rows)): ?>
            <p>No unlinked rows in this register. 🎉</p>
        <?php else: ?>
            <p class="field-hint">Showing the newest <?= count($rows) ?> unlinked row<?= count($rows) === 1 ? '' : 's' ?> (older ones appear as these are cleared).</p>
            <?php foreach ($rows as $r): ?>
                <?php $suggestions = mapper_suggestions((string) $r['display_name'], $allPersons); ?>
                <div class="panel-card" style="margin-bottom:0.5rem;">
                    <div>
                        <strong>#<?= (int)$r['id'] ?></strong>
                        <div><?= e($r['display_name'] ?: '—') ?></div>
                    </div>

                    <?php if ($suggestions): ?>
                        <!-- One-click candidates: each button assigns that person to this row -->
                        <div class="mapper-suggestions">
                            <span class="field-hint">Suggested:</span>
                            <?php foreach ($suggestions as $s): ?>
                                <form method="post" class="mapper-suggestion">
                                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                    <input type="hidden" name="action" value="assign">
                                    <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                                    <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="person_id" value="<?= (int)$s['p']['id'] ?>">
                                    <button class="btn btn-secondary btn-small" type="submit" title="Match distance: <?= (int)$s['dist'] ?>">
                                        <?= e(mapper_person_label($s['p'])) ?>
                                    </button>
                                </form>
                                <a href="person-profile.php?id=<?= (int)$s['p']['id'] ?>" class="mapper-profile-link" title="View person profile (all linked records)">&#10697;</a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mapper-actions">
                        <!-- Assign existing person form -->
                        <form method="post" style="display:inline-block; margin-right:0.5rem;">
                            <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                            <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                            <select name="person_id" required>
                                <option value="">— choose person —</option>
                                <?php foreach ($persons as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>"><?= e(mapper_person_label($p)) ?> (id:<?= (int)$p['id'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-small" type="submit">Assign</button>
                        </form>

                        <!-- Create new person + assign form -->
                        <form method="post" style="display:inline-block;">
                            <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                            <input type="hidden" name="action" value="create_and_assign">
                            <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                            <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                            <input type="text" name="new_person_name" placeholder="New person full name" required>
                            <button class="btn btn-secondary btn-small" type="submit">Create &amp; Assign</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php require_once __DIR__ . '/../../includes/admin-footer.php';