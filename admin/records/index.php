<?php
/**
 * Unified sacramental records index.
 *
 * This page is a compact discovery workspace. Existing sacrament pages remain
 * the edit surfaces so their detailed validation and register-specific forms
 * stay unchanged.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = base_path();

// This page's own app-relative URL (path + current query string) so the record
// view can send the user back here with the same search, filter and page.
$selfReturn = '/admin/records/index.php' . ((($_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $_SERVER['QUERY_STRING'] : '');
$pageTitle = 'All Records';
$search = trim((string) ($_GET['q'] ?? ''));
$type = (string) ($_GET['type'] ?? '');
$allowedTypes = ['baptismal', 'confirmation', 'first_communion', 'marriage'];
if (!in_array($type, $allowedTypes, true)) {
    $type = '';
}
$fromYear = preg_match('/^\d{4}$/', (string) ($_GET['from'] ?? '')) ? (int) $_GET['from'] : null;
$toYear = preg_match('/^\d{4}$/', (string) ($_GET['to'] ?? '')) ? (int) $_GET['to'] : null;
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 25;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = (string) ($_GET['sort'] ?? 'date_desc');
$allowedSorts = ['book', 'book_desc', 'page', 'page_desc', 'line', 'line_desc', 'name', 'name_desc', 'date_desc', 'date_asc', 'sacrament'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'date_desc';
}

$recordSql = <<<'SQL'
SELECT 'baptismal' AS record_type, id, child_full_name AS record_name,
       baptism_date AS event_date, entry_no, book_no, page_no, line_no,
       person_id, certificate_request_id, child_first_name AS record_first_name,
       child_middle_name AS record_middle_name, child_last_name AS record_last_name,
       child_suffix AS record_suffix, NULL AS spouse_first_name, NULL AS spouse_middle_name,
       NULL AS spouse_last_name, NULL AS spouse_suffix
  FROM baptismal_records WHERE is_archived = 0
UNION ALL
SELECT 'confirmation', id, confirmand_full_name, confirmation_date,
       entry_no, book_no, page_no, line_no, person_id, certificate_request_id,
       confirmand_first_name, confirmand_middle_name, confirmand_last_name,
       confirmand_suffix, NULL, NULL, NULL, NULL
  FROM confirmation_records WHERE is_archived = 0
UNION ALL
SELECT 'first_communion', id, communicant_full_name, communion_date,
       entry_no, book_no, page_no, line_no, person_id, certificate_request_id,
       communicant_first_name, communicant_middle_name, communicant_last_name,
       communicant_suffix, NULL, NULL, NULL, NULL
  FROM first_communion_records WHERE is_archived = 0
UNION ALL
SELECT 'marriage', id,
       CONCAT(husband_full_name, ' & ', wife_maiden_name), marriage_date,
       entry_no, book_no, page_no, line_no, NULL, certificate_request_id,
       husband_first_name, husband_middle_name, husband_last_name, husband_suffix,
       wife_first_name, wife_middle_name, wife_last_name, wife_suffix
  FROM marriage_records WHERE is_archived = 0
SQL;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(record_name LIKE ? OR CAST(id AS CHAR) LIKE ? OR book_no LIKE ? OR page_no LIKE ? OR line_no LIKE ?)';
    for ($i = 0; $i < 5; $i++) {
        $params[] = '%' . $search . '%';
    }
}
if ($type !== '') {
    $where[] = 'record_type = ?';
    $params[] = $type;
}
if ($fromYear !== null) {
    $where[] = 'YEAR(event_date) >= ?';
    $params[] = $fromYear;
}
if ($toYear !== null) {
    $where[] = 'YEAR(event_date) <= ?';
    $params[] = $toYear;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$recordSql}) AS records{$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$orderClauses = [
    'book'       => "CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) ASC",
    'book_desc'  => "CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) DESC",
    'page'       => "CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) ASC",
    'page_desc'  => "CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) DESC",
    'line'       => "CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) ASC",
    'line_desc'  => "CAST(COALESCE(NULLIF(line_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(page_no, ''), '0') AS UNSIGNED) DESC",
    'name'       => 'record_name ASC',
    'name_desc'  => 'record_name DESC',
    'date_desc'  => 'event_date DESC, record_name ASC',
    'date_asc'   => 'event_date ASC, record_name ASC',
    'sacrament'  => 'record_type ASC, record_name ASC',
];
$listStmt = $pdo->prepare(
    "SELECT * FROM ({$recordSql}) AS records{$whereSql}
     ORDER BY {$orderClauses[$sort]} LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$records = $listStmt->fetchAll();

$labels = [
    'baptismal' => 'Baptismal',
    'confirmation' => 'Confirmation',
    'first_communion' => 'First Communion',
    'marriage' => 'Marriage',
];
function unified_records_url(array $overrides = []): string
{
    global $basePath, $search, $type, $fromYear, $toYear, $perPage, $sort;
    $query = array_filter([
        'q' => $search,
        'type' => $type,
        'from' => $fromYear,
        'to' => $toYear,
        'per_page' => $perPage === 25 ? null : $perPage,
        'sort' => $sort === 'date_desc' ? null : $sort,
    ], static fn ($value) => $value !== null && $value !== '');
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $queryString = http_build_query($query);
    return $basePath . '/admin/records/index.php' . ($queryString !== '' ? '?' . e($queryString) : '');
}

function unified_sort_url(string $key): string
{
    global $sort;
    if ($key === 'date_desc' || $key === 'date_asc') {
        $next = $sort === 'date_desc' ? 'date_asc' : 'date_desc';
    } else {
        $next = $sort === $key ? $key . '_desc' : $key;
        if ($sort === $key . '_desc') {
            $next = $key;
        }
    }
    return unified_records_url(['sort' => $next, 'page' => null]);
}

function unified_sort_indicator(string $key): string
{
    global $sort;
    if ($key === 'date_desc' || $key === 'date_asc') {
        $direction = $sort === 'date_desc' ? 'desc' : ($sort === 'date_asc' ? 'asc' : null);
    } else {
        $direction = $sort === $key ? 'asc' : ($sort === $key . '_desc' ? 'desc' : null);
    }
    if ($direction === 'asc') {
        return ' <span class="sort-indicator" aria-hidden="true">↑</span>';
    }
    if ($direction === 'desc') {
        return ' <span class="sort-indicator" aria-hidden="true">↓</span>';
    }
    return ' <span class="sort-indicator sort-indicator-muted" aria-hidden="true">↕</span>';
}

require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <div>
                <p class="hero-eyebrow">Register index</p>
                <h1>All Records</h1>
            </div>
            <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/system/person-mapper.php') ?>">Person Mapper</a>
        </div>
        <p class="field-hint">Search across the digital index, then open the register-specific editor to verify the physical logbook reference.</p>

        <div class="record-filter-bar">
            <form method="get" action="<?= e($basePath . '/admin/records/index.php') ?>" class="search-form" autocomplete="off">
                <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
                <?php if ($fromYear !== null): ?><input type="hidden" name="from" value="<?= (int) $fromYear ?>"><?php endif; ?>
                <?php if ($toYear !== null): ?><input type="hidden" name="to" value="<?= (int) $toYear ?>"><?php endif; ?>
                <?php if ($perPage !== 25): ?><input type="hidden" name="per_page" value="<?= (int) $perPage ?>"><?php endif; ?>
                <?php if ($sort !== 'date_desc'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
                <input autocomplete="off" type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, Record ID, book, page, or line">
                <button type="submit" class="btn btn-small">Search</button>
                <?php if ($search !== ''): ?><a class="btn btn-secondary btn-small" href="<?= e(unified_records_url(['q' => null, 'page' => null])) ?>">Clear</a><?php endif; ?>
            </form>

            <form method="get" action="<?= e($basePath . '/admin/records/index.php') ?>" class="per-page-form" autocomplete="off">
                <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
                <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
                <?php if ($fromYear !== null): ?><input type="hidden" name="from" value="<?= (int) $fromYear ?>"><?php endif; ?>
                <?php if ($toYear !== null): ?><input type="hidden" name="to" value="<?= (int) $toYear ?>"><?php endif; ?>
                <?php if ($sort !== 'date_desc'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
                <label>Show
                    <select name="per_page" onchange="this.form.submit()">
                        <?php foreach ([10, 25, 50] as $choice): ?>
                            <option value="<?= (int) $choice ?>" <?= $perPage === $choice ? 'selected' : '' ?>><?= (int) $choice ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </div>

        <?php if (isset($_GET['archived'])): ?><p class="alert alert-success">Record archived. It no longer appears in active lists.</p><?php endif; ?>
        <div class="record-workspace-summary">
            <strong><?= number_format($totalRows) ?></strong> record<?= $totalRows === 1 ? '' : 's' ?> found
            <?php if ($totalRows > 0): ?>
                <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
            <?php endif; ?>
        </div>

        <div class="table-scroll">
            <table class="admin-table unified-records-table">
                <thead>
                    <tr>
                        <th><a class="table-sort-link<?= $sort === 'book' ? ' is-active' : '' ?>" href="<?= e(unified_sort_url('book')) ?>">Book<?= unified_sort_indicator('book') ?></a></th>
                        <th><a class="table-sort-link<?= $sort === 'page' ? ' is-active' : '' ?>" href="<?= e(unified_sort_url('page')) ?>">Page<?= unified_sort_indicator('page') ?></a></th>
                        <th><a class="table-sort-link<?= $sort === 'line' ? ' is-active' : '' ?>" href="<?= e(unified_sort_url('line')) ?>">Line<?= unified_sort_indicator('line') ?></a></th>
                        <th><a class="table-sort-link<?= $sort === 'name' ? ' is-active' : '' ?>" href="<?= e(unified_sort_url('name')) ?>">Full Name<?= unified_sort_indicator('name') ?></a></th>
                        <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(unified_sort_url($sort === 'date_desc' ? 'date_asc' : 'date_desc')) ?>">Date<?= unified_sort_indicator($sort === 'date_desc' || $sort === 'date_asc' ? $sort : 'date_desc') ?></a></th>
                        <th>
                            <details class="table-filter-menu">
                                <summary class="table-filter-summary<?= $type !== '' ? ' is-active' : '' ?>">SACRAMENT <span class="table-filter-caret">▾</span></summary>
                                <form method="get" action="<?= e($basePath . '/admin/records/index.php') ?>" class="table-filter-form" autocomplete="off">
                                    <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
                                    <?php if ($fromYear !== null): ?><input type="hidden" name="from" value="<?= (int) $fromYear ?>"><?php endif; ?>
                                    <?php if ($toYear !== null): ?><input type="hidden" name="to" value="<?= (int) $toYear ?>"><?php endif; ?>
                                    <?php if ($perPage !== 25): ?><input type="hidden" name="per_page" value="<?= (int) $perPage ?>"><?php endif; ?>
                                    <?php if ($sort !== 'date_desc'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
                                    <select name="type" class="table-filter-select" onchange="this.form.submit()" aria-label="Sacrament">
                                        <option value="">All types</option>
                                        <?php foreach ($labels as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </details>
                        </th>
                        <th>Options</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$records): ?>
                    <tr>
                        <td colspan="7" class="empty-table-row">
                            <div class="empty-state"><h2>No records found</h2><p>Try a broader name, sacrament type, or year range.</p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <?php
                        $reference = $record['book_no'] !== null && $record['book_no'] !== ''
                            ? trim($record['book_no'] . ' / ' . $record['page_no'] . ' / ' . $record['line_no'], ' /')
                            : 'Not recorded';
                        $viewUrl = $basePath . '/admin/records/view.php?type=' . urlencode($record['record_type']) . '&id=' . (int) $record['id'] . '&return=' . urlencode($selfReturn);
                        $recordDisplayName = display_name_with_middle_initial(
                            (string) $record['record_name'],
                            isset($record['record_first_name']) ? (string) $record['record_first_name'] : null,
                            isset($record['record_middle_name']) ? (string) $record['record_middle_name'] : null,
                            isset($record['record_last_name']) ? (string) $record['record_last_name'] : null,
                            isset($record['record_suffix']) ? (string) $record['record_suffix'] : null
                        );
                        if ($record['record_type'] === 'marriage') {
                            $recordDisplayName .= ' & ' . display_name_with_middle_initial(
                                (string) $record['record_name'],
                                isset($record['spouse_first_name']) ? (string) $record['spouse_first_name'] : null,
                                isset($record['spouse_middle_name']) ? (string) $record['spouse_middle_name'] : null,
                                isset($record['spouse_last_name']) ? (string) $record['spouse_last_name'] : null,
                                isset($record['spouse_suffix']) ? (string) $record['spouse_suffix'] : null
                            );
                        }
                        ?>
                        <tr>
                            <td><?= e($record['book_no'] ?: '—') ?></td>
                            <td><?= e($record['page_no'] ?: '—') ?></td>
                            <td><?= e($record['line_no'] ?: '—') ?></td>
                            <td><strong><?= e($recordDisplayName) ?></strong></td>
                            <td><?= $record['event_date'] ? e(date('M j, Y', strtotime($record['event_date']))) : '—' ?></td>
                            <td><span class="record-type-badge record-type-<?= e($record['record_type']) ?>"><?= e($labels[$record['record_type']]) ?></span></td>
                            <td class="row-actions"><a class="btn btn-success btn-small" href="<?= e($basePath . '/admin/certificates/generate.php?record_type=' . urlencode($record['record_type']) . '&record_id=' . (int) $record['id']) ?>">Generate</a> <a class="btn btn-secondary btn-small" href="<?= e($viewUrl) ?>">View record</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="record-pagination" aria-label="Record pages">
                <?php if ($page > 1): ?><a class="btn btn-secondary btn-small" href="<?= e(unified_records_url(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
                <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
                <?php if ($page < $totalPages): ?><a class="btn btn-secondary btn-small" href="<?= e(unified_records_url(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
