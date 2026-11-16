<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = base_path();
$pageTitle = 'Funeral Records';
$search = trim((string) ($_GET['q'] ?? ''));
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 25;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

function funeral_index_url(array $overrides = []): string
{
    global $basePath, $search, $perPage;
    $query = array_filter([
        'q' => $search !== '' ? $search : null,
        'per_page' => $perPage !== 25 ? $perPage : null,
    ], static fn($value): bool => $value !== null && $value !== '');
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $queryString = http_build_query($query);
    return $basePath . '/admin/funerals/index.php' . ($queryString !== '' ? '?' . $queryString : '');
}

$whereSql = '';
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '\\_%') . '%';
    $whereSql = "WHERE CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ?
                  OR COALESCE(fr.book_no, '') LIKE ?
                  OR COALESCE(fr.page_no, '') LIKE ?
                  OR COALESCE(fr.line_no, '') LIKE ?";
    $params = [$like, $like, $like, $like];
}

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM funeral_records fr
     JOIN persons p ON p.id = fr.person_id
     {$whereSql}"
);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT fr.id, fr.date_of_death, fr.age_at_death, fr.service_type, fr.service_date,
            fr.service_time, fr.place_of_burial, fr.book_no, fr.page_no, fr.line_no,
            CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) AS person_name
     FROM funeral_records fr
     JOIN persons p ON p.id = fr.person_id
     {$whereSql}
     ORDER BY fr.date_of_death DESC, p.last_name, p.first_name, fr.id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$records = $listStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="page-heading">
    <div>
        <p class="hero-eyebrow">Liber Defunctorum</p>
        <h1>Funeral Records</h1>
    </div>
    <a class="btn btn-primary" href="<?= e($basePath . '/admin/funerals/form.php') ?>">Add Funeral Record</a>
</div>

<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success"><p>Funeral record saved.</p></div>
<?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success"><p>Funeral record updated.</p></div>
<?php endif; ?>

<p class="field-hint">Staff-only death register. Each person can have one funeral record.</p>
<form method="get" class="record-filters">
    <label>Search by name or Book / Page / Line
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, book, page, or line">
    </label>
    <label>Rows per page
        <select name="per_page">
            <?php foreach ([10, 25, 50] as $choice): ?>
                <option value="<?= $choice ?>"<?= $perPage === $choice ? ' selected' : '' ?>><?= $choice ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn btn-secondary" type="submit">Search</button>
    <?php if ($search !== ''): ?><a class="btn btn-secondary" href="<?= e($basePath . '/admin/funerals/index.php') ?>">Clear</a><?php endif; ?>
</form>

<p class="field-hint">
    <?= $totalRows ?> record<?= $totalRows === 1 ? '' : 's' ?> found.
    <?php if ($totalRows > 0): ?> Showing <?= $offset + 1 ?>-<?= min($offset + $perPage, $totalRows) ?>.<?php endif; ?>
</p>
<div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Date of Death</th>
                <th scope="col">Age</th>
                <th scope="col">Service</th>
                <th scope="col">Burial place</th>
                <th scope="col">Book / Page / Line</th>
                <th scope="col">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($records === []): ?>
                <tr><td colspan="7">No funeral records found.</td></tr>
            <?php else: ?>
                <?php foreach ($records as $record): ?>
                    <?php
                    $serviceLabel = $record['service_type'] === 'mass' ? 'Mass' : 'Blessing';
                    $serviceDetails = $serviceLabel;
                    if (!empty($record['service_date'])) {
                        $serviceDetails .= ' - ' . $record['service_date'];
                    }
                    if (!empty($record['service_time'])) {
                        $serviceDetails .= ' ' . substr((string) $record['service_time'], 0, 5);
                    }
                    $bookReference = 'Book ' . ($record['book_no'] ?: '-')
                        . ' / Page ' . ($record['page_no'] ?: '-')
                        . ' / Line ' . ($record['line_no'] ?: '-');
                    ?>
                    <tr>
                        <td><?= e($record['person_name']) ?></td>
                        <td><?= e($record['date_of_death']) ?></td>
                        <td><?= $record['age_at_death'] === null ? '-' : (int) $record['age_at_death'] ?></td>
                        <td><?= e($serviceDetails) ?></td>
                        <td><?= e($record['place_of_burial'] ?? '-') ?></td>
                        <td><?= e($bookReference) ?></td>
                        <td>
                            <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/funerals/form.php?id=' . (int) $record['id']) ?>">Edit</a>
                            <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/funerals/certification.php?id=' . (int) $record['id']) ?>">Print certification</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Funeral record pages">
        <?php if ($page > 1): ?>
            <a href="<?= e(funeral_index_url(['page' => $page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="<?= e(funeral_index_url(['page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
