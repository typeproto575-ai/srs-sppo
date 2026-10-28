<?php
/**
 * SRS-SPPO baptismal register.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/person-linking.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$selfUrl = $basePath . '/admin/records/baptismal.php';
$selfReturn = '/admin/records/baptismal.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$pageTitle = 'Baptismal Records';
$errors = [];
$success = null;
$editRecord = null;
$editParticipants = [];
$application = null;
$applicationDetails = [];
$applicationId = (int) ($_GET['from_application'] ?? $_POST['from_application'] ?? 0);
$personId = 0;
$identity = null;
$formValues = $_POST;
$isEdit = isset($_GET['edit']);

function baptismal_next_logbook_numbers(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT entry_no, book_no, page_no, line_no
         FROM sacrament_records
        WHERE sacrament_type = 'baptism' AND source = 'this_parish'
         ORDER BY id DESC LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['entry_no' => '', 'book_no' => 'I', 'page_no' => '1', 'line_no' => '1'];
    }

    $parts = register_parse_entry_no((string) ($row['entry_no'] ?? ''));
    $entryNo = $parts ? register_build_entry_no($parts, $parts['number'] + 1) : '';
    $bookRaw = trim((string) ($row['book_no'] ?? ''));
    $pageRaw = trim((string) ($row['page_no'] ?? ''));
    $lineRaw = trim((string) ($row['line_no'] ?? ''));
    $book = register_roman_to_int($bookRaw);
    if ($book === null && ctype_digit($bookRaw)) {
        $book = (int) $bookRaw;
    }
    if ($book === null || !ctype_digit($pageRaw) || !ctype_digit($lineRaw)) {
        return ['entry_no' => $entryNo, 'book_no' => 'I', 'page_no' => '1', 'line_no' => '1'];
    }
    $page = (int) $pageRaw;
    $line = (int) $lineRaw + 1;
    if ($line > REGISTER_LINES_PER_PAGE) {
        $line = 1;
        $page++;
    }
    if ($page > REGISTER_PAGES_PER_BOOK) {
        $page = 1;
        $book++;
    }
    return [
        'entry_no' => $entryNo,
        'book_no' => register_roman_numeral($book),
        'page_no' => (string) $page,
        'line_no' => (string) $line,
    ];
}

function baptismal_shift_entry_numbers(PDO $pdo, string $entryNo): int
{
    $incoming = register_parse_entry_no($entryNo);
    if (!$incoming) {
        return 0;
    }
    $stmt = $pdo->prepare(
        "SELECT id, entry_no FROM sacrament_records
         WHERE sacrament_type = 'baptism' AND source = 'this_parish' AND entry_no LIKE ?"
    );
    $stmt->execute([$incoming['prefix'] . '%']);
    $update = $pdo->prepare('UPDATE sacrament_records SET entry_no = ? WHERE id = ?');
    $shifted = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parts = register_parse_entry_no((string) $row['entry_no']);
        if (!$parts || $parts['prefix'] !== $incoming['prefix'] || $parts['number'] < $incoming['number']) {
            continue;
        }
        $update->execute([
            register_build_entry_no($parts, $parts['number'] + 1),
            (int) $row['id'],
        ]);
        $shifted++;
    }
    return $shifted;
}

if ($isEdit) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if (!$editId || $editId < 1) {
        redirect_to($selfUrl);
    }
    $stmt = $pdo->prepare(
        "SELECT sr.id, sr.sacrament_type, sr.person_id, sr.source, sr.event_date, sr.place,
                sr.minister_name, sr.entry_no, sr.book_no, sr.page_no, sr.line_no,
                sr.parents_union_type, sr.details, sr.remarks, sr.encoded_by,
                p.first_name, p.middle_name, p.last_name, p.suffix, p.birth_date,
                p.birth_place, p.father_name, p.mother_maiden_name
         FROM sacrament_records sr
         JOIN persons p ON p.id = sr.person_id
         WHERE sr.id = ? AND sr.sacrament_type = 'baptism'"
    );
    $stmt->execute([(int) $editId]);
    $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRecord) {
        redirect_to($selfUrl);
    }
    $personId = (int) $editRecord['person_id'];
    $stmt = $pdo->prepare(
        'SELECT role, full_name, residence, sort_order
         FROM sacrament_participants WHERE record_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([(int) $editRecord['id']]);
    $editParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $formValues = array_merge($editRecord, is_array($_POST) ? $_POST : []);
    $recordDetails = json_decode((string) ($editRecord['details'] ?? ''), true);
    $recordDetails = is_array($recordDetails) ? $recordDetails : [];
    foreach ($recordDetails as $key => $value) {
        if (!array_key_exists($key, $formValues)) {
            $formValues[$key] = $value;
        }
    }
} elseif ($applicationId > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, request_no, sacrament_type, person_id, status, details
         FROM sacrament_applications
         WHERE id = ? AND sacrament_type = 'baptism'"
    );
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($application) {
        $applicationDetails = json_decode((string) ($application['details'] ?? ''), true);
        $applicationDetails = is_array($applicationDetails) ? $applicationDetails : [];
        $personId = (int) ($application['person_id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            foreach ([
                'child_birth_date' => 'birth_date',
                'child_birth_place' => 'child_birthplace',
                'parents_union_type' => 'union_type',
                'father_birthplace' => 'father_birthplace',
                'mother_birthplace' => 'mother_birthplace',
                'parents_origin' => 'parents_origin',
                'parents_residence' => 'parents_residence',
                'proxy_name' => 'proxy_name',
            ] as $detailKey => $fieldName) {
                if (isset($applicationDetails[$detailKey]) && is_scalar($applicationDetails[$detailKey])) {
                    $formValues[$fieldName] = (string) $applicationDetails[$detailKey];
                }
            }
            foreach (['father_name' => 'father', 'mother_maiden_name' => 'mother'] as $detailKey => $prefix) {
                $name = trim((string) ($applicationDetails[$detailKey] ?? ''));
                if ($name !== '') {
                    $formValues[$prefix . '_first'] = $name;
                }
            }
            $godfathers = [];
            $godmothers = [];
            foreach (($applicationDetails['godparents'] ?? []) as $godparent) {
                if (!is_array($godparent)) {
                    continue;
                }
                $role = (string) ($godparent['role'] ?? '');
                $name = trim((string) ($godparent['full_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if ($role === 'godfather') {
                    $godfathers[] = $godparent;
                } elseif ($role === 'godmother') {
                    $godmothers[] = $godparent;
                }
            }
            foreach (['godfather' => $godfathers, 'godmother' => $godmothers] as $prefix => $rows) {
                foreach ($rows as $index => $row) {
                    $formValues[$prefix . '_first'][$index] = (string) $row['full_name'];
                    $formValues[$prefix . '_residence'][$index] = '';
                }
            }
        }
    } else {
        $errors[] = 'The requested baptism application could not be found.';
        $applicationId = 0;
    }
}

if (!$isEdit && $personId === 0 && !empty($_GET['person_id'])) {
    $selectedId = filter_var($_GET['person_id'], FILTER_VALIDATE_INT);
    if ($selectedId && $selectedId > 0) {
        $personId = (int) $selectedId;
    }
}
if ($personId > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, suffix, birth_date,
                birth_place, father_name, mother_maiden_name
         FROM persons WHERE id = ?'
    );
    $stmt->execute([$personId]);
    $identity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$identity) {
        $errors[] = 'The selected person profile could not be found.';
        $personId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_record']) || isset($_POST['update_record'])) && csrf_verify()) {
    $isPostEdit = isset($_POST['update_record']);
    $postedEditId = (int) ($_POST['edit_id'] ?? 0);
    $entryNo = trim((string) ($_POST['entry_no'] ?? ''));
    $baptismDate = trim((string) ($_POST['baptism_date'] ?? ''));
    $baptismPlace = trim((string) ($_POST['baptism_place'] ?? ''));
    $bookNo = trim((string) ($_POST['book_no'] ?? ''));
    $pageNo = trim((string) ($_POST['page_no'] ?? ''));
    $lineNo = trim((string) ($_POST['line_no'] ?? ''));
    $unionType = trim((string) ($_POST['union_type'] ?? ''));
    $fatherName = compose_person_name($_POST, 'father');
    $motherName = compose_person_name($_POST, 'mother');
    $ministerName = compose_person_name($_POST, 'minister');
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $parentDetails = [];
    foreach (['father_birthplace', 'mother_birthplace', 'parents_origin', 'parents_residence', 'proxy_name'] as $key) {
        $value = trim((string) ($_POST[$key] ?? ''));
        if ($value !== '') {
            $parentDetails[$key] = $value;
        }
    }
    $godparents = [];
    foreach (['godfather', 'godmother'] as $role) {
        $names = compose_person_name_rows_by_index($_POST, $role);
        $residences = $_POST[$role . '_residence'] ?? [];
        if (!is_array($residences)) {
            $residences = [];
        }
        foreach ($names as $index => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $godparents[] = [
                'role' => $role,
                'full_name' => $name,
                'residence' => trim((string) ($residences[$index] ?? '')) ?: null,
                'sort_order' => count($godparents) + 1,
            ];
        }
    }

    if ($baptismDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $baptismDate)
        || !checkdate((int) substr($baptismDate, 5, 2), (int) substr($baptismDate, 8, 2), (int) substr($baptismDate, 0, 4))) {
        $errors[] = 'Enter a valid baptism date.';
    }
    if (!in_array($unionType, ['', 'legitimate', 'civil', 'natural'], true)) {
        $errors[] = 'Choose a valid parents’ union type.';
    }
    if ($isPostEdit) {
        if (!$editRecord || $postedEditId !== (int) $editRecord['id']) {
            $errors[] = 'Invalid baptism record selected for update.';
        }
    } elseif (!$identity && (trim((string) ($_POST['child_first_name'] ?? '')) === ''
        || trim((string) ($_POST['child_last_name'] ?? '')) === '')) {
        $errors[] = 'Enter the child’s first and last name.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            if ($isPostEdit) {
                $savePersonId = (int) $editRecord['person_id'];
            } else {
                $savePersonId = posted_person_id($pdo, 'person_id');
                if ($savePersonId === null) {
                    $savePersonId = save_person_identity($pdo, null, [
                        'first_name' => trim((string) ($_POST['child_first_name'] ?? '')),
                        'middle_name' => trim((string) ($_POST['child_middle_name'] ?? '')),
                        'last_name' => trim((string) ($_POST['child_last_name'] ?? '')),
                        'suffix' => trim((string) ($_POST['child_suffix'] ?? '')),
                        'birth_date' => trim((string) ($_POST['birth_date'] ?? '')),
                        'birth_place' => trim((string) ($_POST['child_birthplace'] ?? '')),
                        'father_name' => $fatherName,
                        'mother_maiden_name' => $motherName,
                        'baptismal_status' => 'baptized_catholic',
                    ]);
                }
            }

            if (!$isPostEdit && $entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                baptismal_shift_entry_numbers($pdo, $entryNo);
            }
            if ($isPostEdit) {
                $recordId = (int) $editRecord['id'];
                $stmt = $pdo->prepare(
                    "UPDATE sacrament_records
                     SET person_id = ?, source = 'this_parish', event_date = ?, place = ?, minister_name = ?,
                         entry_no = ?, book_no = ?, page_no = ?, line_no = ?, parents_union_type = ?,
                         legitimacy = NULL, details = ?, remarks = ?, encoded_by = ?
                     WHERE id = ? AND sacrament_type = 'baptism'"
                );
                $stmt->execute([
                    $savePersonId, $baptismDate, $baptismPlace ?: null, $ministerName ?: null,
                    $entryNo ?: null, $bookNo ?: null, $pageNo ?: null, $lineNo ?: null,
                    $unionType !== '' ? $unionType : null,
                    $parentDetails !== [] ? json_encode($parentDetails, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                    $remarks ?: null, current_user()['id'], $recordId,
                ]);
                $pdo->prepare('DELETE FROM sacrament_participants WHERE record_id = ?')->execute([$recordId]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO sacrament_records
                        (sacrament_type, person_id, source, event_date, place, minister_name,
                         entry_no, book_no, page_no, line_no, parents_union_type, legitimacy,
                         details, remarks, encoded_by)
                     VALUES ('baptism', ?, 'this_parish', ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)"
                );
                $stmt->execute([
                    $savePersonId, $baptismDate, $baptismPlace ?: null, $ministerName ?: null,
                    $entryNo ?: null, $bookNo ?: null, $pageNo ?: null, $lineNo ?: null,
                    $unionType !== '' ? $unionType : null,
                    $parentDetails !== [] ? json_encode($parentDetails, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                    $remarks ?: null, current_user()['id'],
                ]);
                $recordId = (int) $pdo->lastInsertId();
            }

            $participantInsert = $pdo->prepare(
                'INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($godparents as $godparent) {
                $participantInsert->execute([
                    $recordId, $godparent['role'], $godparent['full_name'],
                    $godparent['residence'], $godparent['sort_order'],
                ]);
            }

            if (!$isPostEdit && !empty($_POST['from_application'])) {
                $postedApplicationId = filter_var($_POST['from_application'], FILTER_VALIDATE_INT);
                $applicationLock = $pdo->prepare(
                    "SELECT id, status FROM sacrament_applications
                     WHERE id = ? AND sacrament_type = 'baptism' FOR UPDATE"
                );
                $applicationLock->execute([(int) $postedApplicationId]);
                $linkedApplication = $applicationLock->fetch(PDO::FETCH_ASSOC);
                if (!$linkedApplication) {
                    throw new RuntimeException('The linked baptism application could not be found.');
                }
                $allowedNext = application_queue_next_statuses((string) $linkedApplication['status']);
                if (in_array('completed', $allowedNext, true)) {
                    $applicationUpdate = $pdo->prepare(
                        "UPDATE sacrament_applications
                         SET person_id = ?, record_id = ?, status = 'completed', processed_by = ?
                         WHERE id = ? AND sacrament_type = 'baptism'"
                    );
                    $applicationUpdate->execute([$savePersonId, $recordId, current_user()['id'], (int) $linkedApplication['id']]);
                } else {
                    $applicationUpdate = $pdo->prepare(
                        "UPDATE sacrament_applications
                         SET person_id = ?, record_id = ?
                         WHERE id = ? AND sacrament_type = 'baptism'"
                    );
                    $applicationUpdate->execute([$savePersonId, $recordId, (int) $linkedApplication['id']]);
                }
            }

            log_activity(
                $pdo,
                current_user()['id'],
                $isPostEdit ? 'baptism_record_updated' : 'baptism_record_encoded',
                'sacrament_records',
                $recordId,
                $isPostEdit ? 'Baptism record updated.' : 'Baptism record encoded.'
            );
            $pdo->commit();
            redirect_to($selfUrl . ($isPostEdit ? '?updated=1' : '?added=1'));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Baptism record save failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

$editRecord = $editRecord ?: null;
$isEdit = $editRecord !== null;
$identity = $identity ?: null;
$suggest = $isEdit
    ? ['entry_no' => '', 'book_no' => '', 'page_no' => '', 'line_no' => '']
    : baptismal_next_logbook_numbers($pdo);
$nameFormValues = $formValues;
if ($identity) {
    foreach (['father' => 'father_name', 'mother' => 'mother_maiden_name'] as $prefix => $key) {
        $parts = structured_name_parts((string) ($identity[$key] ?? ''));
        foreach (['first', 'middle', 'last', 'suffix'] as $index => $part) {
            $nameFormValues[$prefix . '_' . $part] ??= $parts[$index];
        }
    }
}
if ($isEdit) {
    foreach (['father' => 'father_name', 'mother' => 'mother_maiden_name', 'minister' => 'minister_name'] as $prefix => $key) {
        $parts = structured_name_parts((string) ($editRecord[$key] ?? ''));
        foreach (['first', 'middle', 'last', 'suffix'] as $index => $part) {
            $nameFormValues[$prefix . '_' . $part] ??= $parts[$index];
        }
    }
}
if (isset($_GET['added'])) {
    $success = 'Baptismal record saved.';
}
if (isset($_GET['updated'])) {
    $success = 'Baptismal record updated.';
}

$rfConfig = [
    'date_column' => 'sr.event_date',
    'search_columns' => [
        "CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix)",
        'p.father_name', 'p.mother_maiden_name', 'sr.minister_name',
    ],
    'placeholder' => 'Search child, parents, minister...',
    'ranges' => ['year', 'last_year', 'last5'],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$allowedSorts = ['register', 'book', 'book_desc', 'page', 'page_desc', 'line', 'line_desc', 'name', 'name_desc', 'date_desc', 'date_asc'];
$sort = (string) ($_GET['sort'] ?? 'register');
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'register';
}
$orderBy = match ($sort) {
    'date_desc' => 'sr.event_date DESC, p.last_name, p.first_name',
    'date_asc' => 'sr.event_date ASC, p.last_name, p.first_name',
    'page_desc' => "CAST(COALESCE(NULLIF(sr.page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(sr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(sr.line_no, ''), '0') AS UNSIGNED) DESC",
    'page' => "CAST(COALESCE(NULLIF(sr.page_no, ''), '0') AS UNSIGNED), CAST(COALESCE(NULLIF(sr.book_no, ''), '0') AS UNSIGNED), CAST(COALESCE(NULLIF(sr.line_no, ''), '0') AS UNSIGNED)",
    'line_desc' => "CAST(COALESCE(NULLIF(sr.line_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(sr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(sr.page_no, ''), '0') AS UNSIGNED) DESC",
    'line' => "CAST(COALESCE(NULLIF(sr.line_no, ''), '0') AS UNSIGNED), CAST(COALESCE(NULLIF(sr.book_no, ''), '0') AS UNSIGNED), CAST(COALESCE(NULLIF(sr.page_no, ''), '0') AS UNSIGNED)",
    'name_desc' => 'p.last_name DESC, p.first_name DESC, sr.book_no DESC',
    'name' => 'p.last_name, p.first_name, sr.book_no',
    'book_desc' => "CAST(COALESCE(NULLIF(sr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(sr.page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(sr.line_no, ''), '0') AS UNSIGNED) DESC",
    default => "CAST(COALESCE(NULLIF(sr.book_no, ''), '0') AS UNSIGNED), CAST(COALESCE(NULLIF(sr.page_no, ''), '0') AS UNSIGNED), CAST(COALESCE(NULLIF(sr.line_no, ''), '0') AS UNSIGNED)",
};
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM sacrament_records sr
     JOIN persons p ON p.id = sr.person_id
     WHERE sr.sacrament_type = 'baptism' {$rf['where']}"
);
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare(
    "SELECT sr.id, sr.event_date, sr.book_no, sr.page_no, sr.line_no,
            p.first_name, p.middle_name, p.last_name, p.suffix
     FROM sacrament_records sr
     JOIN persons p ON p.id = sr.person_id
     WHERE sr.sacrament_type = 'baptism' {$rf['where']}
     ORDER BY {$orderBy} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
$persons = !$identity && !$isEdit ? person_identity_choices($pdo) : [];
$isSecretary = (current_user()['role'] ?? '') === 'secretary';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="admin-list-page">
    <div class="page-heading">
        <h1>Baptismal Records</h1>
        <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/index.php') ?>">Back to All Records</a>
        <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
    </div>
    <p class="field-hint">The physical baptismal logbook remains canonical. Register entries are linked to read-only master person profiles.</p>
    <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
    <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a href="<?= e(baptismal_sort_url('book')) ?>">Book<?= baptismal_sort_indicator('book') ?></a></th>
                    <th><a href="<?= e(baptismal_sort_url('page')) ?>">Page<?= baptismal_sort_indicator('page') ?></a></th>
                    <th><a href="<?= e(baptismal_sort_url('line')) ?>">Line<?= baptismal_sort_indicator('line') ?></a></th>
                    <th><a href="<?= e(baptismal_sort_url('name')) ?>">Name<?= baptismal_sort_indicator('name') ?></a></th>
                    <th><a href="<?= e(baptismal_sort_url('date_desc')) ?>">Baptism Date<?= baptismal_sort_indicator('date_desc') ?></a></th>
                    <th>Person</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$records): ?>
                    <tr><td colspan="7" class="empty-table-row">No baptismal records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <?php $displayName = trim(implode(' ', array_filter([$record['first_name'], $record['middle_name'], $record['last_name'], $record['suffix']]))); ?>
                        <tr>
                            <td><?= e($record['book_no'] ?: '—') ?></td>
                            <td><?= e($record['page_no'] ?: '—') ?></td>
                            <td><?= e($record['line_no'] ?: '—') ?></td>
                            <td><strong><?= e($displayName) ?></strong></td>
                            <td><?= e(date('M j, Y', strtotime($record['event_date']))) ?></td>
                            <td><span class="link-status-badge link-status-linked">Linked</span></td>
                            <td class="row-actions">
                                <a class="btn btn-secondary btn-small" href="<?= e($selfUrl . '?edit=' . (int) $record['id']) ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php rf_render_pagination($selfUrl, $rfTotal, $rf); ?>
</div>

<?php
$recordDetails = $isEdit ? (json_decode((string) ($editRecord['details'] ?? ''), true) ?: []) : [];
$suggestedEntry = $isEdit ? (string) ($editRecord['entry_no'] ?? '') : (string) $suggest['entry_no'];
$gfRows = [];
$gmRows = [];
foreach ($editParticipants as $participant) {
    $target = $participant['role'] === 'godmother' ? 'godmother' : 'godfather';
    ${$target . 'Rows'}[] = $participant;
}
foreach (['godfather' => 'godfatherRows', 'godmother' => 'godmotherRows'] as $prefix => $variable) {
    if (isset($formValues[$prefix . '_first']) && is_array($formValues[$prefix . '_first'])) {
        ${$variable} = array_fill(0, person_name_row_count($formValues, $prefix), []);
        foreach (${$variable} as $index => &$unusedRow) {
            $unusedRow = ['full_name' => compose_person_name_rows_by_index($formValues, $prefix)[$index] ?? '', 'residence' => $formValues[$prefix . '_residence'][$index] ?? ''];
        }
        unset($unusedRow);
    }
    if (!${$variable}) {
        ${$variable} = [['full_name' => '', 'residence' => '']];
    }
}
?>
<dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || $errors || $applicationId) ? ' data-autoshow' : '' ?>>
    <div class="dialog-head">
        <h2><?= $isEdit ? 'Edit Baptism Record #' . (int) $editRecord['id'] : 'Encode New Baptism Record' ?></h2>
        <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
    </div>
    <div class="dialog-body">
        <?php if ($application): ?>
            <p class="field-hint">Prefilled from application <?= e($application['request_no']) ?>. The linked application person, if any, is shown read-only.</p>
            <?php if (empty($application['person_id'])): ?>
                <p class="field-hint">Applicant-provided child name: <strong><?= e((string) ($applicationDetails['child_name'] ?? '')) ?></strong>. Split and confirm the name in the editable fields below; it is not split automatically.</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$isEdit && !$identity && $persons): ?>
            <form method="get" action="<?= e($selfUrl) ?>" class="inline-fields">
                <?php if ($applicationId): ?><input type="hidden" name="from_application" value="<?= $applicationId ?>"><?php endif; ?>
                <label>Use an existing person profile
                    <select name="person_id">
                        <option value="">Create a new profile</option>
                        <?php foreach ($persons as $personOption): ?>
                            <?php $optionName = trim(implode(' ', array_filter([$personOption['first_name'], $personOption['middle_name'], $personOption['last_name'], $personOption['suffix']]))); ?>
                            <option value="<?= (int) $personOption['id'] ?>"><?= e($optionName . (!empty($personOption['birth_date']) ? ' — born ' . $personOption['birth_date'] : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-secondary btn-small">Load profile</button>
            </form>
        <?php endif; ?>
        <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form autocomplete="off" method="post" action="" data-review data-review-title="Review Baptismal Entry" data-review-confirm="Confirm and Save">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
            <?php if ($identity): ?><input type="hidden" name="person_id" value="<?= (int) $identity['id'] ?>"><?php endif; ?>
            <?php if ($applicationId): ?><input type="hidden" name="from_application" value="<?= $applicationId ?>"><?php endif; ?>
            <input type="hidden" name="entry_no" value="<?= e($formValues['entry_no'] ?? $suggestedEntry) ?>">
            <fieldset>
                <legend>Logbook Reference</legend>
                <div class="inline-fields">
                    <label>Book No. <input type="text" name="book_no" value="<?= e($formValues['book_no'] ?? $editRecord['book_no'] ?? $suggest['book_no']) ?>" readonly></label>
                    <label>Page <input type="text" name="page_no" value="<?= e($formValues['page_no'] ?? $editRecord['page_no'] ?? $suggest['page_no']) ?>" readonly></label>
                    <label>Line <input type="text" name="line_no" value="<?= e($formValues['line_no'] ?? $editRecord['line_no'] ?? $suggest['line_no']) ?>" readonly></label>
                </div>
            </fieldset>
            <fieldset>
                <legend>Child identity</legend>
                <?php if ($identity): ?>
                    <p><strong><?= e(person_identity_label($identity)) ?></strong></p>
                    <p>Birth date: <?= e((string) ($identity['birth_date'] ?? 'Not recorded')) ?></p>
                    <p>Birthplace: <?= e((string) ($identity['birth_place'] ?? 'Not recorded')) ?></p>
                    <p class="field-hint">Identity is read-only from the master persons record.</p>
                <?php else: ?>
                    <div class="inline-fields structured-name-fields">
                        <label>First Name <span class="required-marker">*</span> <input type="text" name="child_first_name" value="<?= e($formValues['child_first_name'] ?? '') ?>" required></label>
                        <label>Middle Name <input type="text" name="child_middle_name" value="<?= e($formValues['child_middle_name'] ?? '') ?>"></label>
                        <label>Surname <span class="required-marker">*</span> <input type="text" name="child_last_name" value="<?= e($formValues['child_last_name'] ?? '') ?>" required></label>
                        <label>Suffix <input type="text" name="child_suffix" value="<?= e($formValues['child_suffix'] ?? '') ?>"></label>
                    </div>
                    <label>Birth Date <input type="date" name="birth_date" value="<?= e($formValues['birth_date'] ?? '') ?>"></label>
                    <label>Birthplace <input type="text" name="child_birthplace" value="<?= e($formValues['child_birthplace'] ?? '') ?>"></label>
                    <p class="field-hint">Saving creates a person profile in <code>persons</code>. A selected profile above is never updated here.</p>
                <?php endif; ?>
                <div class="inline-fields">
                    <label>Baptism Date <span class="required-marker">*</span> <input type="date" name="baptism_date" value="<?= e($formValues['baptism_date'] ?? $editRecord['event_date'] ?? '') ?>" required></label>
                    <label>Place of Baptism <input type="text" name="baptism_place" value="<?= e($formValues['baptism_place'] ?? $editRecord['place'] ?? '') ?>"></label>
                </div>
            </fieldset>
            <fieldset>
                <legend>Parents</legend>
                <?php if ($identity): ?>
                    <p>Father: <?= e((string) ($identity['father_name'] ?? 'Not recorded')) ?></p>
                    <p>Mother’s maiden name: <?= e((string) ($identity['mother_maiden_name'] ?? 'Not recorded')) ?></p>
                    <p class="field-hint">Parent names are part of the person profile and are read-only when a person is linked.</p>
                <?php else: ?>
                    <?= person_name_fields('father', "Father's Name", $nameFormValues, false) ?>
                    <?= person_name_fields('mother', "Mother's Maiden Name", $nameFormValues, false) ?>
                <?php endif; ?>
                <div class="inline-fields">
                    <label>Father's Birthplace <input type="text" name="father_birthplace" value="<?= e($formValues['father_birthplace'] ?? $recordDetails['father_birthplace'] ?? '') ?>"></label>
                    <label>Mother's Birthplace <input type="text" name="mother_birthplace" value="<?= e($formValues['mother_birthplace'] ?? $recordDetails['mother_birthplace'] ?? '') ?>"></label>
                    <label>Parents' Origin <input type="text" name="parents_origin" value="<?= e($formValues['parents_origin'] ?? $recordDetails['parents_origin'] ?? '') ?>"></label>
                    <label>Parents' Residence <input type="text" name="parents_residence" value="<?= e($formValues['parents_residence'] ?? $recordDetails['parents_residence'] ?? '') ?>"></label>
                    <label>Proxy / Guardian Name <input type="text" name="proxy_name" value="<?= e($formValues['proxy_name'] ?? $recordDetails['proxy_name'] ?? '') ?>"></label>
                </div>
                <label>Parents' Union Type
                    <select name="union_type">
                        <?php foreach (['' => '-- Not specified --', 'legitimate' => 'Legitimate (Church Marriage)', 'civil' => 'Civil', 'natural' => 'Natural (Not Married)'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($formValues['union_type'] ?? $editRecord['parents_union_type'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </fieldset>
            <?php foreach (['godfather' => ['Godfathers (Ninong)', $godfatherRows], 'godmother' => ['Godmothers (Ninang)', $godmotherRows]] as $role => [$legend, $rows]): ?>
                <fieldset>
                    <legend><?= e($legend) ?></legend>
                    <div data-repeat-group="<?= e($role) ?>" data-repeat-max="8">
                        <?php foreach ($rows as $index => $participant): ?>
                            <?php
                            $parts = structured_name_parts((string) ($participant['full_name'] ?? ''));
                            $rowValues = $formValues;
                            foreach (['first', 'middle', 'last', 'suffix'] as $partIndex => $part) {
                                $rowValues[$role . '_' . $part][$index] ??= $parts[$partIndex];
                            }
                            ?>
                            <div class="godparent-row">
                                <div class="inline-fields">
                                    <?= person_name_fields($role, $role === 'godfather' ? 'Godfather Name' : 'Godmother Name', $rowValues, false, $index) ?>
                                    <label>Residence <input type="text" name="<?= e($role) ?>_residence[]" value="<?= e($formValues[$role . '_residence'][$index] ?? $participant['residence'] ?? '') ?>"></label>
                                </div>
                                <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $index === 0 ? ' hidden' : '' ?>>Remove</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="<?= e($role) ?>">Add another <?= e($role) ?></button>
                </fieldset>
            <?php endforeach; ?>
            <fieldset>
                <legend>Other</legend>
                <?= person_name_fields('minister', 'Officiating Minister', $nameFormValues, false) ?>
                <label>Remarks / Observanda <textarea name="remarks"><?= e($formValues['remarks'] ?? $editRecord['remarks'] ?? '') ?></textarea></label>
            </fieldset>
            <button type="submit" name="<?= $isEdit ? 'update_record' : 'add_record' ?>" value="1" class="btn btn-primary"><?= $isEdit ? 'Update Record' : 'Save Record' ?></button>
            <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
        </form>
    </div>
</dialog>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>

<?php
function baptismal_sort_url(string $key): string
{
    global $selfUrl, $rf, $sort;
    if ($key === 'date_desc' || $key === 'date_asc') {
        $next = $sort === 'date_desc' ? 'date_asc' : 'date_desc';
    } else {
        $next = $sort === $key ? $key . '_desc' : $key;
        if ($sort === $key . '_desc') {
            $next = $key;
        }
    }
    $query = array_filter([
        'q' => $rf['q'] !== '' ? $rf['q'] : null,
        'range' => $rf['range'] !== '' ? $rf['range'] : null,
        'date_from' => $rf['range'] === 'custom' ? $rf['from'] : null,
        'date_to' => $rf['range'] === 'custom' ? $rf['to'] : null,
        'per_page' => $rf['per_page'] !== 10 ? (int) $rf['per_page'] : null,
        'sort' => $next,
    ], static fn ($value) => $value !== null && $value !== '');
    return $selfUrl . ($query ? '?' . http_build_query($query) : '');
}

function baptismal_sort_indicator(string $key): string
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
?>
