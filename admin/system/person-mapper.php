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
    'baptismal' => [
        'table' => 'baptismal_records', 'column' => 'person_id', 'name' => 'child_full_name',
        'date_sql' => 'COALESCE(birth_date, baptism_date)', 'skip_column' => 'mapper_skipped_at',
    ],
    'confirmation' => [
        'table' => 'confirmation_records', 'column' => 'person_id', 'name' => 'confirmand_full_name',
        'date_sql' => 'birthdate', 'skip_column' => 'mapper_skipped_at',
    ],
    'first_communion' => [
        'table' => 'first_communion_records', 'column' => 'person_id', 'name' => 'communicant_full_name',
        'date_sql' => 'NULL', 'skip_column' => 'mapper_skipped_at',
    ],
    'marriage_husband' => [
        'table' => 'marriage_records', 'column' => 'husband_person_id', 'name' => 'husband_full_name',
        'date_sql' => 'husband_birthdate', 'skip_column' => 'husband_mapper_skipped_at',
    ],
    'marriage_wife' => [
        'table' => 'marriage_records', 'column' => 'wife_person_id', 'name' => 'wife_maiden_name',
        'date_sql' => 'wife_birthdate', 'skip_column' => 'wife_mapper_skipped_at',
    ],
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

function mapper_date_match(?string $recordDate, ?string $personDate): bool
{
    return $recordDate !== null && $recordDate !== '' && $personDate !== null && $personDate !== ''
        && $recordDate === $personDate;
}

function mapper_date_distance(?string $recordDate, ?string $personDate): ?int
{
    if (!$recordDate || !$personDate) return null;
    try {
        return abs((new DateTime($recordDate))->diff(new DateTime($personDate))->days);
    } catch (Throwable $e) {
        return null;
    }
}

function mapper_suggestions(string $displayName, ?string $recordDate, array $allPersons, int $max = 3): array
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
            $dateDistance = mapper_date_distance($recordDate, $p['birth_date'] ?? null);
            $dateConflict = $recordDate !== null && $recordDate !== ''
                && ($p['birth_date'] ?? null) !== null && ($p['birth_date'] ?? '') !== ''
                && $dateDistance > 365;
            $scored[] = [
                'p' => $p,
                'dist' => $dist,
                'date_distance' => $dateDistance,
                'date_match' => mapper_date_match($recordDate, $p['birth_date'] ?? null),
                'date_conflict' => $dateConflict,
            ];
        }
    }
    usort($scored, static function (array $a, array $b): int {
        return [
            $a['date_conflict'] ? 1 : 0,
            $a['date_match'] ? 0 : 1,
            $a['date_distance'] ?? PHP_INT_MAX,
            $a['dist'],
        ] <=> [
            $b['date_conflict'] ? 1 : 0,
            $b['date_match'] ? 0 : 1,
            $b['date_distance'] ?? PHP_INT_MAX,
            $b['dist'],
        ];
    });
    return array_slice($scored, 0, $max);
}

function mapper_row_sql(array $mapping, bool $skipped): string
{
    $skipClause = $skipped
        ? "{$mapping['skip_column']} IS NOT NULL"
        : "{$mapping['skip_column']} IS NULL";
    return "SELECT id, {$mapping['name']} AS display_name, {$mapping['date_sql']} AS mapper_date
            FROM {$mapping['table']}
            WHERE ({$mapping['column']} IS NULL OR {$mapping['column']} = 0)
              AND {$skipClause}";
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

        if ($action === 'bulk_confirm_high_confidence') {
            if (!$mapping) {
                $errors[] = 'Invalid record mapping selected.';
            } else {
                try {
                    $allPersonsForBulk = $pdo->query(
                        'SELECT id, first_name, middle_name, last_name, suffix, maiden_name, birth_date FROM persons'
                    )->fetchAll();
                    $bulkRows = $pdo->query(mapper_row_sql($mapping, false))->fetchAll();
                    $eligible = [];
                    foreach ($bulkRows as $bulkRow) {
                        $top = mapper_suggestions((string) $bulkRow['display_name'], $bulkRow['mapper_date'], $allPersonsForBulk, 1)[0] ?? null;
                        if ($top && $top['dist'] === 0
                            && (!$bulkRow['mapper_date'] || mapper_date_match($bulkRow['mapper_date'], $top['p']['birth_date'] ?? null))) {
                            $eligible[] = [$bulkRow, $top['p']];
                        }
                    }
                    $pdo->beginTransaction();
                    $update = $pdo->prepare(
                        "UPDATE `{$mapping['table']}` SET `{$mapping['column']}` = ? WHERE id = ?
                          AND (`{$mapping['column']}` IS NULL OR `{$mapping['column']}` = 0)
                          AND `{$mapping['skip_column']}` IS NULL"
                    );
                    foreach ($eligible as [$bulkRow, $person]) {
                        $update->execute([(int) $person['id'], (int) $bulkRow['id']]);
                        if ($update->rowCount() === 1) {
                            log_activity($pdo, current_user()['id'], 'map_person', $mapping['table'], (int) $bulkRow['id'],
                                "Mapped {$mapping['column']} to person_id=" . (int) $person['id']);
                        }
                    }
                    $pdo->commit();
                    $notice = count($eligible) . ' high-confidence mapping' . (count($eligible) === 1 ? '' : 's') . ' confirmed.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = 'The high-confidence mappings could not be saved.';
                    error_log('SRS-SPPO person mapper bulk mapping: ' . $e->getMessage());
                }
            }
        } elseif ($action === 'skip' || $action === 'unskip') {
            if (!$mapping || $recordId <= 0) {
                $errors[] = 'Invalid record mapping selected.';
            } else {
                $value = $action === 'skip' ? 'NOW()' : 'NULL';
                $stmt = $pdo->prepare("UPDATE `{$mapping['table']}` SET `{$mapping['skip_column']}` = {$value}
                    WHERE id = ? AND (`{$mapping['column']}` IS NULL OR `{$mapping['column']}` = 0)");
                $stmt->execute([$recordId]);
                $notice = $action === 'skip' ? 'Row skipped for now.' : 'Row returned to the mapping queue.';
            }
        } elseif (!$mapping || $recordId <= 0) {
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

// Section tabs: only one registry is worked on at a time (the old version
// rendered up to 6 x 200 forms on one page, which was overwhelming).
$activeSection = $_GET['section'] ?? ($_POST['section'] ?? '');
$highlight = (int) ($_GET['highlight'] ?? 0);
if (!array_key_exists($activeSection, $mappings)) {
    $activeSection = null; // fall back to the first section with rows below
}

$sort = ($_GET['sort'] ?? 'oldest') === 'newest' ? 'DESC' : 'ASC';
$unmatched = [];
$skipped = [];
$counts = [];
foreach ($mappings as $section => $mapping) {
    $sql = mapper_row_sql($mapping, false) . " ORDER BY id {$sort} LIMIT 50";
    $unmatched[$section] = $pdo->query($sql)->fetchAll();
    $skipped[$section] = $pdo->query(mapper_row_sql($mapping, true) . " ORDER BY id ASC LIMIT 50")->fetchAll();
    $countStmt = $pdo->query("SELECT
        SUM(({$mapping['column']} IS NULL OR {$mapping['column']} = 0) AND {$mapping['skip_column']} IS NULL),
        SUM(({$mapping['column']} IS NULL OR {$mapping['column']} = 0) AND {$mapping['skip_column']} IS NOT NULL)
        FROM {$mapping['table']}");
    $counts[$section] = array_map('intval', $countStmt->fetch(PDO::FETCH_NUM));
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

function mapper_person_label(array $p): string
{
    $label = trim(implode(' ', array_filter([$p['first_name'], $p['middle_name'], $p['last_name'], $p['suffix']])));
    if (!empty($p['maiden_name'])) $label .= ' (maiden: ' . $p['maiden_name'] . ')';
    return $label;
}

$pageTitle = 'Person Linking';

// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Person Linking</h1>
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
                         href="?section=<?= e($section) ?>">
                    <?= e(ucwords(str_replace('_', ' ', $section))) ?>
                    <span class="mapper-tab-count"><?= $counts[$section][0] ?> unlinked<?= $counts[$section][1] ? ' · ' . $counts[$section][1] . ' skipped' : '' ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php $rows = $unmatched[$activeSection] ?? []; ?>
        <?php $skippedRows = $skipped[$activeSection] ?? []; ?>
        <?php $activeMapping = $mappings[$activeSection]; ?>
        <?php $highConfidenceCount = 0; ?>
        <?php foreach ($rows as $candidateRow): ?>
            <?php $candidateTop = mapper_suggestions((string) $candidateRow['display_name'], $candidateRow['mapper_date'], $allPersons, 1)[0] ?? null; ?>
            <?php if ($candidateTop && $candidateTop['dist'] === 0 && (!$candidateRow['mapper_date'] || mapper_date_match($candidateRow['mapper_date'], $candidateTop['p']['birth_date'] ?? null))) $highConfidenceCount++; ?>
        <?php endforeach; ?>
        <div class="mapper-toolbar" style="display:flex; gap:0.75rem; align-items:end; flex-wrap:wrap; margin:1rem 0;">
            <div>
                <span class="field-hint" style="display:block;">Queue order</span>
                <a class="btn btn-small<?= $sort === 'ASC' ? ' is-active' : '' ?>" href="?section=<?= e($activeSection) ?>&sort=oldest">Oldest first</a>
                <a class="btn btn-small<?= $sort === 'DESC' ? ' is-active' : '' ?>" href="?section=<?= e($activeSection) ?>&sort=newest">Newest first</a>
            </div>
            <?php if ($highConfidenceCount > 0): ?>
                <form autocomplete="off" method="post" onsubmit="return confirm('Confirm all <?= $highConfidenceCount ?> high-confidence match<?= $highConfidenceCount === 1 ? '' : 'es' ?>? Each row will be assigned to its exact matching person.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bulk_confirm_high_confidence">
                    <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                    <button class="btn btn-primary btn-small" type="submit">Confirm all <?= $highConfidenceCount ?> high-confidence matches</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (empty($rows)): ?>
            <p>No unlinked rows in this register.</p>
        <?php else: ?>
            <p class="field-hint">Showing up to <?= count($rows) ?> unlinked, not-skipped row<?= count($rows) === 1 ? '' : 's' ?>, <?= $sort === 'ASC' ? 'oldest first' : 'newest first' ?>.</p>
            <?php foreach ($rows as $r): ?>
                <?php $suggestions = mapper_suggestions((string) $r['display_name'], $r['mapper_date'], $allPersons); ?>
                <?php $isHighConfidence = !empty($suggestions) && $suggestions[0]['dist'] === 0 && (!$r['mapper_date'] || mapper_date_match($r['mapper_date'], $suggestions[0]['p']['birth_date'] ?? null)); ?>
                <div class="panel-card<?= $isHighConfidence ? ' mapper-high-confidence' : '' ?>" style="margin-bottom:0.5rem;">
                    <div>
                        <strong>#<?= (int)$r['id'] ?></strong>
                        <div><?= e($r['display_name'] ?: '—') ?></div>
                        <?php if ($r['mapper_date']): ?><span class="field-hint">Date signal: <?= e($r['mapper_date']) ?></span><?php endif; ?>
                        <?php if ($isHighConfidence): ?><span class="field-hint"><strong>High confidence:</strong> exact name<?= $r['mapper_date'] ? ' and date' : '' ?> match</span><?php endif; ?>
                    </div>

                    <?php if ($suggestions): ?>
                        <!-- One-click candidates: each button assigns that person to this row -->
                        <div class="mapper-suggestions">
                            <span class="field-hint">Suggested:</span>
                            <?php foreach ($suggestions as $s): ?>
                                <form autocomplete="off" method="post" class="mapper-suggestion">
                                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                    <input type="hidden" name="action" value="assign">
                                    <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                                    <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="person_id" value="<?= (int)$s['p']['id'] ?>">
                                    <button class="btn btn-secondary btn-small" type="submit" title="Name distance: <?= (int)$s['dist'] ?><?= $s['date_distance'] !== null ? '; date distance: ' . (int)$s['date_distance'] . ' days' : '' ?>">
                                        <?= e(mapper_person_label($s['p'])) ?>
                                    </button>
                                </form>
                                <a href="person-profile.php?id=<?= (int)$s['p']['id'] ?>" class="mapper-profile-link" title="View person profile (all linked records)">&#10697;</a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mapper-actions">
                        <!-- Assign existing person form -->
                        <form autocomplete="off" method="post" class="mapper-action-form mapper-person-assign-form" data-mapper-person-finder data-endpoint="<?= e($basePath . '/admin/api/person-search.php') ?>">
                            <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                            <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                            <div class="mapper-person-search-row">
                                <input autocomplete="off" type="search" data-mapper-person-search placeholder="Search possible person">
                                <button class="btn btn-secondary btn-small" type="button" data-mapper-person-find>Find</button>
                                <button class="btn btn-small" type="submit" data-mapper-person-assign disabled>Assign</button>
                            </div>
                            <input type="hidden" name="person_id" data-mapper-person-id value="">
                            <div class="mapper-person-selection field-hint" data-mapper-person-selection aria-live="polite"></div>
                            <div class="mapper-person-results" data-mapper-person-results></div>
                        </form>

                        <!-- Create new person + assign form -->
                        <form autocomplete="off" method="post" class="mapper-action-form">
                            <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                            <input type="hidden" name="action" value="create_and_assign">
                            <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                            <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                            <input autocomplete="off" type="text" name="new_person_name" value="<?= e($r['display_name']) ?>" aria-label="Person name" required>
                            <button class="btn btn-secondary btn-small" type="submit">Create Person &amp; Assign</button>
                        </form>
                        <form autocomplete="off" method="post" class="mapper-action-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="skip">
                            <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                            <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-small" type="submit">Skip for now</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($highlight > 0 && !isset($foundHighlight)): ?>
            <div class="alert alert-warning" role="alert">
                <i class="fa fa-exclamation-triangle"></i> The requested record (ID: <?= $highlight ?>) was not found in the current mapper view. It may already be linked or filtered out.
            </div>
        <?php endif; ?>

        <?php if (!empty($skippedRows)): ?>
            <details style="margin-top:1.5rem;" class="<?= ($highlight > 0) ? 'open' : '' ?>">
                <summary><?= $counts[$activeSection][1] ?> skipped row<?= $counts[$activeSection][1] === 1 ? '' : 's' ?> — show them</summary>
                <?php foreach ($skippedRows as $r): ?>
                    <div class="panel-card" style="margin:0.5rem 0;">
                        <div><strong>#<?= (int)$r['id'] ?></strong><div><?= e($r['display_name'] ?: '—') ?></div></div>
                        <form autocomplete="off" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="unskip">
                            <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                            <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-small" type="submit">Unskip</button>
                        </form>
                        <?php if ($r['id'] === $highlight): ?>
                            <div class="alert alert-info" style="margin-top:0.5rem; font-size:0.8rem;">This is the record you linked from the view page.</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </details>
        <?php elseif (($counts[$activeSection][1] ?? 0) > 0): ?>
            <p class="field-hint"><?= $counts[$activeSection][1] ?> skipped row<?= $counts[$activeSection][1] === 1 ? '' : 's' ?> exist<?= $counts[$activeSection][1] === 1 ? 's' : '' ?> but could not be loaded.</p>
        <?php endif; ?>

    <?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const highlightId = <?= (int)$highlight ?>;
            if (highlightId > 0) {
                const target = document.getElementById('mapper-row-' + highlightId);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    target.classList.add('mapper-row-highlighted');
                }
            }
        });
    </script>
