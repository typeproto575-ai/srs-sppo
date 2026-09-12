<?php
/**
 * =====================================================================
 * SRS-SPPO RECORD LIST — SHARED FILTER POPUP + PAGINATION
 * =====================================================================
 * Used by the secretary's records pages (baptismal, confirmation,
 * first-communion, marriage) to filter and page through the register.
 *
 * Design (per secretary feedback):
 *   - Search is a standalone, always-visible control with its own button.
 *   - One "Filters" button opens a panel with the date choices (All dates /
 *     quick ranges / custom year range); a single Apply button runs them.
 *   - Raw From/To typing is replaced by quick ranges (last 7 days /
 *     30 days / 3 months / this year) plus an optional custom year range.
 *   - A per-page selector (5/10/25/50) and real pagination replace the
 *     fixed "latest 100 rows" listing.
 *
 * Page contract:
 *   1) Define the config BEFORE requiring this file:
 *        $rfConfig = ['date_column' => 'baptism_date',
 *                     'search_columns' => ['child_full_name', ...]];
 *      Column names come from the page (never user input), so they may
 *      safely include table aliases like 'fc.communion_date'.
 *   2) require_once this file, then: $rf = rf_parse($rfConfig);
 *   3) Build COUNT + list queries with "{$rf['where']}" (empty when no
 *      filter is active) and $rf['params']; append
 *      "LIMIT {$rf['per_page']} OFFSET {$rf['offset']}".
 *   4) In the HTML: rf_render_bar($selfUrl, $rfConfig, $rf); before the
 *      table, rf_render_pagination($selfUrl, $totalRows, $rf); after it.
 * =====================================================================
 */

if (!function_exists('rf_parse')) {

/** Allowed rows-per-page choices. */
function rf_per_page_choices(): array
{
    return [5, 10, 25, 50];
}

/**
 * Read + sanitize every filter input and build the shared WHERE fragment.
 * A group only filters when its enable-checkbox was submitted with the
 * form — unchecked groups are ignored server-side (no-JS safe).
 */
function rf_parse(array $config): array
{
    $rf = [
        'q'        => trim($_GET['q'] ?? ''),
        'range'    => $_GET['range'] ?? '',
        'from'     => trim($_GET['date_from'] ?? ''),
        'to'       => trim($_GET['date_to'] ?? ''),
        'per_page' => (int) ($_GET['per_page'] ?? 10),
        'page'     => max(1, (int) ($_GET['page'] ?? 1)),
    ];

    // Only the fixed choices are accepted for quick ranges and page size.
    if (!in_array($rf['range'], ['7', '30', '90', 'year', 'custom'], true)) $rf['range'] = '';
    if (!in_array($rf['per_page'], rf_per_page_choices(), true)) $rf['per_page'] = 10;
    $rf['offset'] = ($rf['page'] - 1) * $rf['per_page'];

    // Year bounds are only trusted as plain four-digit years.
    foreach (['from', 'to'] as $k) {
        if ($rf[$k] !== '' && !preg_match('/^\d{4}$/', $rf[$k])) $rf[$k] = '';
    }

    $clauses = [];
    $params = [];
    $dateCol = $config['date_column'];

    // Text search: a standalone always-visible control, so it applies
    // whenever a search term was submitted.
    if ($rf['q'] !== '') {
        $parts = [];
        foreach ($config['search_columns'] as $col) {
            $parts[] = $col . ' LIKE ?';
            $params[] = '%' . $rf['q'] . '%';
        }
        $clauses[] = '(' . implode(' OR ', $parts) . ')';
    }

    // Date range (applies whenever one of the choices is selected;
    // "All dates" submits an empty value and clears the filter).
    if ($rf['range'] !== '') {
        if ($rf['range'] === '7')    $clauses[] = $dateCol . ' >= CURDATE() - INTERVAL 7 DAY';
        if ($rf['range'] === '30')   $clauses[] = $dateCol . ' >= CURDATE() - INTERVAL 30 DAY';
        if ($rf['range'] === '90')   $clauses[] = $dateCol . ' >= CURDATE() - INTERVAL 3 MONTH';
        if ($rf['range'] === 'year') $clauses[] = 'YEAR(' . $dateCol . ') = YEAR(CURDATE())';
        if ($rf['range'] === 'custom') {
            if ($rf['from'] !== '') { $clauses[] = 'YEAR(' . $dateCol . ') >= ?'; $params[] = (int) $rf['from']; }
            if ($rf['to'] !== '')   { $clauses[] = 'YEAR(' . $dateCol . ') <= ?'; $params[] = (int) $rf['to']; }
        }
    }

    $rf['where']  = $clauses === [] ? '' : ' AND ' . implode(' AND ', $clauses);
    $rf['params'] = $params;
    $rf['active'] = $clauses !== [];
    return $rf;
}

/** Rebuild the current query string with a different page number. */
function rf_page_url(string $selfUrl, int $page): string
{
    $get = $_GET;
    $get['page'] = $page;
    return $selfUrl . '?' . e(http_build_query($get));
}

/** Search bar (standalone) + Filters panel + per-page selector. */
function rf_render_bar(string $selfUrl, array $config, array $rf): void
{
    $dOn = $rf['range'] !== '';
    ?>
        <div class="record-filter-bar">
            <!-- Standalone search: always visible; runs on Enter or the button.
                 Hidden inputs keep the active date filter when searching. -->
            <form method="get" action="<?= e($selfUrl) ?>" class="search-form">
                <?php if ($dOn): ?>
                    <input type="hidden" name="range" value="<?= e($rf['range']) ?>">
                    <?php if ($rf['range'] === 'custom'): ?>
                        <input type="hidden" name="date_from" value="<?= e($rf['from']) ?>">
                        <input type="hidden" name="date_to" value="<?= e($rf['to']) ?>">
                    <?php endif; ?>
                <?php endif; ?>
                <input type="search" name="q" value="<?= e($rf['q']) ?>" placeholder="Search name, entry no., minister&hellip;">
                <button type="submit" class="btn btn-small">Search</button>
                <?php if ($rf['q'] !== ''): $clearGet = array_diff_key($_GET, ['q' => 1, 'page' => 1]); ?>
                    <a href="<?= e($selfUrl . ($clearGet ? '?' . http_build_query($clearGet) : '')) ?>" class="btn btn-secondary btn-small">Clear</a>
                <?php endif; ?>
            </form>

            <!-- Never auto-opened: after Apply the panel stays closed, and the
                 badge on the button shows when a date filter is active. -->
            <details class="filter-popup">
                <summary class="filter-summary">Filters<?= $dOn ? ' <span class="filter-count">1</span>' : '' ?></summary>
                <form method="get" action="<?= e($selfUrl) ?>" class="filter-panel">
                    <!-- The search term rides along so applying a date choice
                         never clears the current search. -->
                    <?php if ($rf['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($rf['q']) ?>"><?php endif; ?>
                    <p class="filter-title">Date</p>
                    <div class="filter-body">
                        <label class="filter-option"><input type="radio" name="range" value="" <?= $rf['range'] === '' ? 'checked' : '' ?>> All dates</label>
                        <label class="filter-option"><input type="radio" name="range" value="7" <?= $rf['range'] === '7' ? 'checked' : '' ?>> Last 7 days</label>
                        <label class="filter-option"><input type="radio" name="range" value="30" <?= $rf['range'] === '30' ? 'checked' : '' ?>> Last 30 days</label>
                        <label class="filter-option"><input type="radio" name="range" value="90" <?= $rf['range'] === '90' ? 'checked' : '' ?>> Last 3 months</label>
                        <label class="filter-option"><input type="radio" name="range" value="year" <?= $rf['range'] === 'year' ? 'checked' : '' ?>> This year</label>
                        <label class="filter-option"><input type="radio" name="range" value="custom" <?= $rf['range'] === 'custom' ? 'checked' : '' ?>> Custom range</label>
                        <div class="filter-custom-dates">
                            <label>From <input type="number" name="date_from" min="1900" max="2100" step="1" placeholder="YYYY" value="<?= e($rf['from']) ?>"></label>
                            <label>To <input type="number" name="date_to" min="1900" max="2100" step="1" placeholder="YYYY" value="<?= e($rf['to']) ?>"></label>
                        </div>
                    </div>
                    <div class="filter-panel-actions">
                        <button type="submit" class="btn btn-small">Apply Filters</button>
                    </div>
                </form>
            </details>

            <!-- Per-page selector: hidden inputs carry the active search and
                 date filter so switching page size never loses either. -->
            <form method="get" action="<?= e($selfUrl) ?>" class="per-page-form">
                <?php if ($rf['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($rf['q']) ?>"><?php endif; ?>
                <?php if ($dOn): ?><input type="hidden" name="range" value="<?= e($rf['range']) ?>"><?php endif; ?>
                <?php if ($dOn && $rf['range'] === 'custom'): ?><input type="hidden" name="date_from" value="<?= e($rf['from']) ?>"><input type="hidden" name="date_to" value="<?= e($rf['to']) ?>"><?php endif; ?>
                <label>Show
                    <select name="per_page" onchange="this.form.submit()">
                        <?php foreach (rf_per_page_choices() as $c): ?>
                            <option value="<?= (int) $c ?>" <?= $rf['per_page'] === $c ? 'selected' : '' ?>><?= (int) $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </div>
    <?php
}

/** "Showing X–Y of Z" + first / prev / numbered / next / last links. */
function rf_render_pagination(string $selfUrl, int $total, array $rf): void
{
    $pages = max(1, (int) ceil($total / $rf['per_page']));
    $from  = $total === 0 ? 0 : $rf['offset'] + 1;
    $to    = min($total, $rf['offset'] + $rf['per_page']);
        ?>
        <div class="record-pagination">
            <span class="pagination-info">Showing <?= (int) $from ?>&ndash;<?= (int) $to ?> of <?= (int) $total ?> record<?= $total === 1 ? '' : 's' ?></span>
            <?php if ($pages > 1): ?>
                <nav class="pagination-btns">
                    <?php if ($rf['page'] > 1): ?>
                        <a class="btn btn-secondary btn-small" href="<?= rf_page_url($selfUrl, 1) ?>">&laquo; First</a>
                        <a class="btn btn-secondary btn-small" href="<?= rf_page_url($selfUrl, $rf['page'] - 1) ?>">&larr; Prev</a>
                    <?php endif; ?>
                    <?php
                    // A five-number window centred on the current page.
                    $start = max(1, $rf['page'] - 2);
                    $end   = min($pages, $start + 4);
                    $start = max(1, $end - 4);
                    for ($p = $start; $p <= $end; $p++): ?>
                        <?php if ($p === $rf['page']): ?>
                            <span class="pagination-current"><?= (int) $p ?></span>
                        <?php else: ?>
                            <a class="pagination-link" href="<?= rf_page_url($selfUrl, $p) ?>"><?= (int) $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($rf['page'] < $pages): ?>
                        <a class="btn btn-secondary btn-small" href="<?= rf_page_url($selfUrl, $rf['page'] + 1) ?>">Next &rarr;</a>
                        <a class="btn btn-secondary btn-small" href="<?= rf_page_url($selfUrl, $pages) ?>">Last &raquo;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    <?php
}

}
