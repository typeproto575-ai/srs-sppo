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
 *   3) Call rf_parse() BEFORE building the SQL, then combine the fragment:
 *      $rf['where'] is '' when no filter is active, otherwise it already
 *      begins with ' AND ' — append it directly to a base WHERE clause
 *      (never prepend another ' AND ' and never ltrim it, which strips
 *      characters and corrupts the fragment). Execute with $rf['params'];
 *      append "LIMIT {$rf['per_page']} OFFSET {$rf['offset']}".
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

/** SQL fragment for a preset (non-custom) date range, with the column injected. */
function rf_range_sql(string $key, string $col): string
{
    switch ($key) {
        case 'today':     return $col . ' = CURDATE()';
        case '7':         return $col . ' >= CURDATE() - INTERVAL 7 DAY';
        case 'week':      return $col . ' >= CURDATE() - INTERVAL 7 DAY';
        case '30':        return $col . ' >= CURDATE() - INTERVAL 30 DAY';
        case 'month':     return "YEAR($col) = YEAR(CURDATE()) AND MONTH($col) = MONTH(CURDATE())";
        case '90':        return $col . ' >= CURDATE() - INTERVAL 3 MONTH';
        case 'year':      return "YEAR($col) = YEAR(CURDATE())";
        case 'last_year': return "YEAR($col) = YEAR(CURDATE()) - 1";
        case 'last5':     return $col . ' >= CURDATE() - INTERVAL 5 YEAR';
    }
    return '';
}

/** Human-readable label for a preset date range key. */
function rf_range_label(string $key): string
{
    $map = [
        'today'     => 'Today',
        '7'         => 'Last 7 days',
        'week'      => 'Last 7 days',
        '30'        => 'Last 30 days',
        'month'     => 'This month',
        '90'        => 'Last 3 months',
        'year'      => 'This year',
        'last_year' => 'Last year',
        'last5'     => 'Last 5 years',
    ];
    return $map[$key] ?? 'Custom';
}

/** Default quick-range keys shown when a page does not define its own. */
function rf_default_ranges(): array
{
    return ['7', '30', '90', 'year'];
}

/**
 * Read + sanitize every filter input and build the shared WHERE fragment.
 * A group only filters when its enable-checkbox was submitted with the
 * form — unchecked groups are ignored server-side (no-JS safe).
 */
function rf_parse(array $config): array
{
    $search = trim($_GET['q'] ?? '');
    // Reference numbers are generated uppercase; normalize reference-shaped searches without changing name searches.
    if (preg_match('/^SPPO(-[A-Z]+)?-\d{4}-\d{6}(-[A-Z0-9]{4,6})?$/i', $search)) {
        $search = strtoupper($search);
    }
    $rf = [
        'q'        => $search,
        'range'    => $_GET['range'] ?? '',
        'from'     => trim($_GET['date_from'] ?? ''),
        'to'       => trim($_GET['date_to'] ?? ''),
        'per_page' => (int) ($_GET['per_page'] ?? 10),
        'page'     => max(1, (int) ($_GET['page'] ?? 1)),
    ];

    // Only the page's own quick ranges (plus custom) are accepted; any other
    // value is ignored. Unknown range keys fall back to empty (no filter).
    $allowedRanges = $config['ranges'] ?? rf_default_ranges();
    if (!in_array($rf['range'], $allowedRanges, true) && $rf['range'] !== 'custom') $rf['range'] = '';
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
        if ($rf['range'] === 'custom') {
            if ($rf['from'] !== '') { $clauses[] = 'YEAR(' . $dateCol . ') >= ?'; $params[] = (int) $rf['from']; }
            if ($rf['to'] !== '')   { $clauses[] = 'YEAR(' . $dateCol . ') <= ?'; $params[] = (int) $rf['to']; }
        } else {
            $rangeSql = rf_range_sql($rf['range'], $dateCol);
            if ($rangeSql !== '') $clauses[] = $rangeSql;
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
    $placeholder = $config['placeholder'] ?? 'Search name, entry no., minister…';
    $extraFilters = $config['filters'] ?? [];
    ?>
        <div class="record-filter-bar">
            <!-- Standalone search: always visible; runs on Enter or the button.
                 Hidden inputs keep the active date + extra filters when searching. -->
            <form autocomplete="off" method="get" action="<?= e($selfUrl) ?>" class="search-form">
                <?php if ($dOn): ?>
                    <input type="hidden" name="range" value="<?= e($rf['range']) ?>">
                    <?php if ($rf['range'] === 'custom'): ?>
                        <input type="hidden" name="date_from" value="<?= e($rf['from']) ?>">
                        <input type="hidden" name="date_to" value="<?= e($rf['to']) ?>">
                    <?php endif; ?>
                <?php endif; ?>
                <?php foreach ($extraFilters as $fKey => $fDef): ?>
                    <?php if (trim($_GET[$fKey] ?? '') !== ''): ?><input type="hidden" name="<?= e($fKey) ?>" value="<?= e($_GET[$fKey]) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <input autocomplete="off" type="search" name="q" value="<?= e($rf['q']) ?>" placeholder="<?= e($placeholder) ?>">
                <button type="submit" class="btn btn-small">Search</button>
                <?php if ($rf['q'] !== ''): $clearGet = array_diff_key($_GET, ['q' => 1, 'page' => 1]); ?>
                    <a href="<?= e($selfUrl . ($clearGet ? '?' . http_build_query($clearGet) : '')) ?>" class="btn btn-secondary btn-small">Clear</a>
                <?php endif; ?>
            </form>

            <!-- Never auto-opened: after Apply the panel stays closed, and the
                 badge on the button shows when a date filter is active. -->
            <details class="filter-popup">
                <summary class="filter-summary">Filters<?= $dOn ? ' <span class="filter-count">1</span>' : '' ?></summary>
                <form autocomplete="off" method="get" action="<?= e($selfUrl) ?>" class="filter-panel">
                    <!-- The search term rides along so applying a date choice
                         never clears the current search. -->
                    <?php if ($rf['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($rf['q']) ?>"><?php endif; ?>
                    <p class="filter-title">Date</p>
                    <div class="filter-body">
                        <label class="filter-option"><input autocomplete="off" type="radio" name="range" value="" <?= $rf['range'] === '' ? 'checked' : '' ?>> All dates</label>
                        <?php foreach (($config['ranges'] ?? rf_default_ranges()) as $rk): ?>
                            <label class="filter-option"><input autocomplete="off" type="radio" name="range" value="<?= e($rk) ?>" <?= $rf['range'] === $rk ? 'checked' : '' ?>> <?= e(rf_range_label($rk)) ?></label>
                        <?php endforeach; ?>
                        <label class="filter-option"><input autocomplete="off" type="radio" name="range" value="custom" <?= $rf['range'] === 'custom' ? 'checked' : '' ?>> Custom range</label>
                        <div class="filter-custom-dates">
                            <label>From <input autocomplete="off" type="number" name="date_from" min="1900" max="2100" step="1" placeholder="YYYY" value="<?= e($rf['from']) ?>"></label>
                            <label>To <input autocomplete="off" type="number" name="date_to" min="1900" max="2100" step="1" placeholder="YYYY" value="<?= e($rf['to']) ?>"></label>
                        </div>
                    </div>
                    <?php foreach ($extraFilters as $fKey => $fDef): ?>
                        <p class="filter-title"><?= e($fDef['label'] ?? ucwords(str_replace('_', ' ', $fKey))) ?></p>
                        <div class="filter-body">
                            <label class="filter-option"><input autocomplete="off" type="radio" name="<?= e($fKey) ?>" value="" <?= (trim($_GET[$fKey] ?? '') === '') ? 'checked' : '' ?>> All</label>
                            <?php foreach (($fDef['options'] ?? []) as $optVal => $optLabel): ?>
                                <label class="filter-option"><input autocomplete="off" type="radio" name="<?= e($fKey) ?>" value="<?= e($optVal) ?>" <?= (trim($_GET[$fKey] ?? '') === (string) $optVal) ? 'checked' : '' ?>> <?= e($optLabel) ?></label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="filter-panel-actions">
                        <button type="submit" class="btn btn-small">Apply Filters</button>
                    </div>
                </form>
            </details>

            <!-- Per-page selector: hidden inputs carry the active search and
                 date filter so switching page size never loses either. -->
            <form autocomplete="off" method="get" action="<?= e($selfUrl) ?>" class="per-page-form">
                <?php if ($rf['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($rf['q']) ?>"><?php endif; ?>
                <?php if ($dOn): ?><input type="hidden" name="range" value="<?= e($rf['range']) ?>"><?php endif; ?>
                <?php if ($dOn && $rf['range'] === 'custom'): ?><input type="hidden" name="date_from" value="<?= e($rf['from']) ?>"><input type="hidden" name="date_to" value="<?= e($rf['to']) ?>"><?php endif; ?>
                <?php foreach ($extraFilters as $fKey => $fDef): ?>
                    <?php if (trim($_GET[$fKey] ?? '') !== ''): ?><input type="hidden" name="<?= e($fKey) ?>" value="<?= e($_GET[$fKey]) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <label>Show
                    <select name="per_page" onchange="this.form.submit()">
                        <?php foreach (rf_per_page_choices() as $c): ?>
                            <option value="<?= (int) $c ?>" <?= $rf['per_page'] === $c ? 'selected' : '' ?>><?= (int) $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>

            <?php
            // Clear-all link: drop every filter param and return to the plain
            // page. Rendered only when a search, date, extra, or custom
            // per-page choice is active.
            $chipActive = $rf['q'] !== '' || $rf['range'] !== '' || $rf['per_page'] !== 10;
            foreach (($config['filters'] ?? []) as $fKey => $fDef) {
                if (trim($_GET[$fKey] ?? '') !== '') { $chipActive = true; break; }
            }
            if ($chipActive):
            ?>
                <a href="<?= e($selfUrl) ?>" class="btn btn-secondary btn-small" title="Remove all filters">Clear All</a>
            <?php endif; ?>
        </div>

    <?php
    // ---- Active filter chips -----------------------------------------------
    // Removable chips below the bar showing each active filter (search term,
    // date range, extra page filters). Each chip links back to the same page
    // with only that one filter removed, so users drop one filter at a time.
    $chips = [];
    $baseParams = [];
    if ($rf['per_page'] !== 10) $baseParams['per_page'] = $rf['per_page'];
    if ($rf['q'] !== '') {
        $p = $baseParams;
        if ($rf['range'] !== '') $p['range'] = $rf['range'];
        if ($rf['range'] === 'custom') {
            if ($rf['from'] !== '') $p['date_from'] = $rf['from'];
            if ($rf['to'] !== '') $p['date_to'] = $rf['to'];
        }
        foreach (($config['filters'] ?? []) as $fKey => $fDef) {
            $v = trim($_GET[$fKey] ?? '');
            if ($v !== '') $p[$fKey] = $v;
        }
        $chips[] = ['label' => 'Search: "' . $rf['q'] . '"', 'url' => $selfUrl . ($p ? '?' . http_build_query($p) : '')];
    }
    if ($rf['range'] !== '') {
        $rangeLabel = rf_range_label($rf['range']);
        if ($rf['range'] === 'custom') {
            $rangeLabel = 'Years'
                . ($rf['from'] !== '' ? ' ' . $rf['from'] : '')
                . '–'
                . ($rf['to'] !== '' ? $rf['to'] : '');
        }
        $p = $baseParams;
        if ($rf['q'] !== '') $p['q'] = $rf['q'];
        foreach (($config['filters'] ?? []) as $fKey => $fDef) {
            $v = trim($_GET[$fKey] ?? '');
            if ($v !== '') $p[$fKey] = $v;
        }
        $chips[] = ['label' => 'Date: ' . $rangeLabel, 'url' => $selfUrl . ($p ? '?' . http_build_query($p) : '')];
    }

    // Extra page-declared filters (e.g. status, sacrament on the queues):
    // each active one gets its own chip whose URL keeps everything else.
    foreach (($config['filters'] ?? []) as $fKey => $fDef) {
        $extraVal = trim($_GET[$fKey] ?? '');
        if ($extraVal === '') continue;
        $extraLabel = $extraVal;
        foreach (($fDef['options'] ?? []) as $optVal => $optLabel) {
            if ((string) $optVal === $extraVal) { $extraLabel = $optLabel; break; }
        }
        if ($extraLabel === $extraVal) $extraLabel = ucwords(str_replace('_', ' ', $extraVal));
        $p = $baseParams;
        if ($rf['q'] !== '') $p['q'] = $rf['q'];
        if ($rf['range'] !== '') $p['range'] = $rf['range'];
        if ($rf['range'] === 'custom') {
            if ($rf['from'] !== '') $p['date_from'] = $rf['from'];
            if ($rf['to'] !== '') $p['date_to'] = $rf['to'];
        }
        foreach (($config['filters'] ?? []) as $otherKey => $otherDef) {
            if ($otherKey === $fKey) continue;
            $ov = trim($_GET[$otherKey] ?? '');
            if ($ov !== '') $p[$otherKey] = $ov;
        }
        $chips[] = ['label' => ($fDef['label'] ?? ucwords($fKey)) . ': ' . $extraLabel, 'url' => $selfUrl . ($p ? '?' . http_build_query($p) : '')];
    }

    // (per-page is a display choice, not a filter — no chip for it.)
    if ($chips):
    ?>
        <div class="active-filter-chips">
            <?php foreach ($chips as $chip): ?>
                <a href="<?= e($chip['url']) ?>" class="filter-chip" title="Remove this filter"><?= e($chip['label']) ?> &times;</a>
            <?php endforeach; ?>
        </div>
    <?php
    endif;
    ?>
    <?php
}

/** "Showing X–Y of Z" + first / prev / numbered / next / last links. */
function rf_render_pagination(string $selfUrl, int $total, array $rf): void
{
    if ($total === 0) return;
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
