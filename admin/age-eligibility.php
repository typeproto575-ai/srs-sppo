<?php
/**
 * Advisory staff review list for age-based First Communion and Confirmation alerts.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sacrament-data.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$labels = [
    'first_communion' => 'First Communion',
    'confirmation' => 'Confirmation',
];
$type = (string) ($_GET['type'] ?? 'first_communion');
if (!isset($labels[$type])) {
    $type = 'first_communion';
}
$ageGroup = (string) ($_GET['age_group'] ?? 'all');
$minimumAge = null;
$filterError = null;
if ($ageGroup === 'custom') {
    $minimumValue = trim((string) ($_GET['min_age'] ?? ''));
    if ($minimumValue !== '' && preg_match('/^\d{1,3}$/', $minimumValue)) {
        $minimumAge = (int) $minimumValue;
    }
}

$peopleByType = [];
$ranges = [];
foreach ($labels as $sacramentType => $label) {
    $peopleByType[$sacramentType] = sd_get_age_eligible_persons($pdo, $sacramentType);
    $ranges[$sacramentType] = sacrament_eligibility_range($pdo, $sacramentType);
}
try {
    $filteredPeopleByType = [];
    foreach ($peopleByType as $sacramentType => $candidates) {
        $filteredPeopleByType[$sacramentType] = sd_filter_age_eligible_persons(
            $candidates,
            $ageGroup,
            $minimumAge
        );
    }
} catch (InvalidArgumentException $e) {
    $filterError = $e->getMessage();
    $filteredPeopleByType = array_fill_keys(array_keys($labels), []);
}
$people = $filteredPeopleByType[$type];
$range = $ranges[$type];
$totalPeople = count($people);
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 25;
}
$totalPages = max(1, (int) ceil($totalPeople / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;
$people = array_slice($people, $offset, $perPage);
$paginationUrl = static function (int $targetPage) use ($basePath, $type, $ageGroup, $perPage): string {
    $params = [
        'type' => $type,
        'age_group' => $ageGroup,
        'per_page' => $perPage,
        'page' => $targetPage,
    ];
    if ($ageGroup === 'custom') {
        $params['min_age'] = (string) ($_GET['min_age'] ?? '');
    }

    return $basePath . '/admin/age-eligibility.php?' . http_build_query($params);
};
$ageGroupLabels = [
    'all' => 'All ages',
    'custom' => 'Custom minimum age',
];
$tabQuery = ['age_group' => $ageGroup, 'per_page' => $perPage];
if ($ageGroup === 'custom') {
    $tabQuery['min_age'] = (string) ($_GET['min_age'] ?? '');
}
$pageTitle = 'Age Eligibility Review';
require_once __DIR__ . '/../includes/admin-header.php';
?>

<section class="age-eligibility-page" aria-label="Age eligibility review">
    <header class="age-eligibility-heading">
        <div class="age-eligibility-heading__copy">
            <p class="age-eligibility-eyebrow">Secretary review</p>
            <h1>Age Eligibility Review</h1>
            <p>A focused shortlist of baptized people who have reached the configured minimum age and do not yet have this sacrament recorded.</p>
        </div>
        <div class="age-eligibility-heading__stat" aria-label="<?= $totalPeople ?> people shown">
            <strong><?= $totalPeople ?></strong>
            <span>people shown</span>
        </div>
    </header>

    <aside class="age-eligibility-notice">
        <span class="age-eligibility-notice__mark" aria-hidden="true">i</span>
        <div>
            <strong>For secretary review only</strong>
            <p>Age is an advisory reminder, not approval or confirmation of readiness. Confirm prerequisites and parish requirements before proceeding.</p>
        </div>
    </aside>

    <nav class="age-eligibility-tabs" aria-label="Filter by sacrament">
        <?php foreach ($labels as $sacramentType => $label): ?>
            <a href="<?= e($basePath . '/admin/age-eligibility.php?' . http_build_query(array_merge(['type' => $sacramentType], $tabQuery))) ?>"
               class="age-eligibility-tab<?= $type === $sacramentType ? ' is-active' : '' ?>"
               <?= $type === $sacramentType ? 'aria-current="page"' : '' ?>>
                <span class="age-eligibility-tab__label"><?= e($label) ?></span>
                <span class="age-eligibility-tab__meta">
                    <strong class="age-eligibility-tab__count"><?= count($filteredPeopleByType[$sacramentType]) ?></strong>
                    <span>to review</span>
                </span>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="get" class="age-eligibility-filters" aria-label="Filter by age">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
        <div class="age-eligibility-filters__heading">
            <div>
                <span class="age-eligibility-filters__eyebrow">Narrow the list</span>
                <strong>Filter by age</strong>
            </div>
            <span>Filters don’t change eligibility</span>
        </div>
        <div class="age-eligibility-filters__controls">
            <label class="age-eligibility-filter-field" for="age-group">
                <span>Age group</span>
                <select id="age-group" name="age_group" data-age-group-select aria-controls="custom-age-range">
                    <?php foreach ($ageGroupLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $ageGroup === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div id="custom-age-range" class="age-eligibility-custom-range" data-custom-age-fields role="group" aria-labelledby="custom-age-range-heading">
                <strong id="custom-age-range-heading">Custom minimum age</strong>
                <div class="age-eligibility-custom-range__inputs">
                    <label>Minimum age <input type="number" name="min_age" min="0" max="120" value="<?= e((string) ($_GET['min_age'] ?? '')) ?>"></label>
                </div>
            </div>
            <div class="age-eligibility-filters__actions">
                <button type="submit" class="btn btn-primary">Apply filter</button>
                <?php if ($ageGroup !== 'all'): ?>
                    <a class="age-eligibility-clear" href="<?= e($basePath . '/admin/age-eligibility.php?' . http_build_query(['type' => $type])) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </div>
        <p class="age-eligibility-filter-hint">Preset groups: 7–9, 10–12, 13–17, and 18+. Select Custom range to enter your own bounds.</p>
    </form>

    <section class="panel-card age-eligibility-results" aria-labelledby="age-eligibility-results-heading">
        <div class="age-eligibility-results__heading">
            <div>
                <p class="person-profile-section__eyebrow"><?= e($labels[$type]) ?> · <?= e($ageGroupLabels[$ageGroup] ?? 'Age group') ?></p>
                <h2 id="age-eligibility-results-heading">People to review</h2>
            </div>
            <div class="age-eligibility-results__tools">
                <p><strong><?= $totalPeople ?></strong> matching candidates · minimum age <?= (int) $range['min_age'] ?></p>
                <form method="get" action="<?= e($basePath . '/admin/age-eligibility.php') ?>" class="per-page-form">
                    <input type="hidden" name="type" value="<?= e($type) ?>">
                    <input type="hidden" name="age_group" value="<?= e($ageGroup) ?>">
                    <?php if ($ageGroup === 'custom'): ?>
                        <input type="hidden" name="min_age" value="<?= e((string) ($_GET['min_age'] ?? '')) ?>">
                    <?php endif; ?>
                    <label for="age-eligibility-per-page">Show
                        <select id="age-eligibility-per-page" name="per_page" onchange="this.form.submit()">
                            <?php foreach ([10, 25, 50] as $choice): ?>
                                <option value="<?= (int) $choice ?>"<?= $perPage === $choice ? ' selected' : '' ?>><?= (int) $choice ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>
        </div>

        <?php if ($filterError !== null): ?>
            <p class="alert alert-error" role="alert"><?= e($filterError) ?></p>
        <?php elseif ($people === []): ?>
            <div class="age-eligibility-empty">
                <span class="age-eligibility-empty__mark" aria-hidden="true">0</span>
                <strong>No people match this age filter</strong>
                <p>Try another age group or clear the filter to see all candidates.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="admin-table age-eligibility-table">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Date of birth</th>
                            <th>Age</th>
                            <th>Baptism record</th>
                            <th>Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($people as $person): ?>
                            <tr>
                                <td><strong><?= e($person['full_name']) ?></strong></td>
                                <td><?= e(date('M j, Y', strtotime((string) $person['birth_date']))) ?></td>
                                <td><span class="age-eligibility-age"><?= (int) $person['age'] ?></span></td>
                                <td><span class="age-eligibility-baptism"><span aria-hidden="true">✓</span> Recorded</span></td>
                                <td><a class="age-eligibility-open" href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $person['id']) ?>">Open profile <span aria-hidden="true">→</span></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <nav class="record-pagination age-eligibility-pagination" aria-label="Age eligibility pages">
                <span class="pagination-info">Showing <?= $totalPeople === 0 ? 0 : $offset + 1 ?>&ndash;<?= min($totalPeople, $offset + $perPage) ?> of <?= $totalPeople ?> people</span>
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-btns">
                        <?php if ($page > 1): ?>
                            <a class="btn btn-secondary btn-small" href="<?= e($paginationUrl($page - 1)) ?>">Previous</a>
                        <?php endif; ?>
                        <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
                        <?php if ($page < $totalPages): ?>
                            <a class="btn btn-secondary btn-small" href="<?= e($paginationUrl($page + 1)) ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</section>

<script>
document.querySelectorAll('[data-age-group-select]').forEach(function (select) {
    var customFields = document.querySelector('[data-custom-age-fields]');
    if (!customFields) return;
    var updateCustomFields = function () {
        customFields.hidden = select.value !== 'custom';
    };
    updateCustomFields();
    select.addEventListener('change', function () {
        updateCustomFields();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
