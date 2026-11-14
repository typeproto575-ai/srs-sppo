<?php
/**
 * SRS-SPPO baptismal register.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/person-linking.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';
require_role('secretary');
require_once __DIR__ . '/../../includes/record-editor-route.php';
redirect_legacy_record_editor('baptism');

$basePath = $basePath ?? base_path();
$selfUrl = '/admin/records/index.php?type=baptismal';
$selfReturn = '/admin/records/index.php?type=baptismal';
$editReturnInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['return'] ?? null) : ($_GET['return'] ?? null);
$editReturnUrl = is_string($editReturnInput) ? safe_internal_return_url($editReturnInput) : null;
$pageTitle = 'Baptismal Record';
$errors = [];
$duplicateMatches = [];
$success = null;
$notice = null;
$editRecord = null;
$editParticipants = [];
$application = null;
$applicationDetails = [];
$applicationId = (int) ($_GET['from_application'] ?? $_POST['from_application'] ?? 0);
$personId = 0;
$identity = null;
$formValues = $_POST;
$isEdit = isset($_GET['edit']);
$isNew = isset($_GET['new']);
$pageTitle = $isEdit ? 'Edit Baptism Record' : 'New Baptism Record';

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
                    $parts = structured_name_parts($name);
                    foreach (['first', 'middle', 'last', 'suffix'] as $index => $part) {
                        $formValues[$prefix . '_' . $part] = $parts[$index];
                    }
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
    $ministerName = trim((string) ($_POST['minister_name'] ?? ''));
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
        $reasonChange = trim((string) ($_POST['reason_change'] ?? ''));
        if (strlen($reasonChange) < 5 || strlen($reasonChange) > 500) {
            $errors[] = 'A reason of at least 5 characters is required for a correction.';
        }
    } elseif (!$identity && (trim((string) ($_POST['child_first_name'] ?? '')) === ''
        || trim((string) ($_POST['child_last_name'] ?? '')) === '')) {
        $errors[] = 'Enter the child’s first and last name.';
    }
    if (!$isPostEdit && !$identity && posted_person_id($pdo, 'person_id') === null) {
        $duplicateMatches = sd_find_possible_person_matches($pdo, [
            'first_name' => $_POST['child_first_name'] ?? '',
            'middle_name' => $_POST['child_middle_name'] ?? '',
            'last_name' => $_POST['child_last_name'] ?? '',
            'suffix' => $_POST['child_suffix'] ?? '',
            'birth_date' => $_POST['birth_date'] ?? '',
        ]);
        if ($duplicateMatches && (string) ($_POST['confirm_new_person'] ?? '') !== '1') {
            $errors[] = 'Possible matching person profiles were found. Select the correct profile or confirm that a new profile is still needed.';
        }
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $auditBefore = $isPostEdit
                ? sd_record_audit_snapshot($pdo, (int) $editRecord['id'])
                : null;
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

$shiftedCount = 0;
            if (!$isPostEdit && $entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shiftedCount = baptismal_shift_entry_numbers($pdo, $entryNo);
            }
            if ($isPostEdit) {
                $recordId = (int) $editRecord['id'];
                $oldParticipants = $pdo->prepare('SELECT role, full_name, residence, sort_order FROM sacrament_participants WHERE record_id = ?');
                $oldParticipants->execute([$recordId]);
                $oldRecord = array_merge($editRecord, ['participants' => $oldParticipants->fetchAll(PDO::FETCH_ASSOC)]);
                $newRecord = [
                    'person_id' => $savePersonId,
                    'source' => 'this_parish',
                    'event_date' => $baptismDate,
                    'place' => $baptismPlace ?: null,
                    'minister_name' => $ministerName ?: null,
                    'entry_no' => $entryNo ?: null,
                    'book_no' => $bookNo ?: null,
                    'page_no' => $pageNo ?: null,
                    'line_no' => $lineNo ?: null,
                    'parents_union_type' => $unionType !== '' ? $unionType : null,
                    'details' => $parentDetails !== [] ? $parentDetails : null,
                    'remarks' => $remarks ?: null,
                    'participants' => $godparents,
                ];
                $changed = sd_record_changes($oldRecord, $newRecord);
                if ($changed === []) {
                    $pdo->commit();
                    redirect_to($editReturnUrl ?: $selfUrl . '&nochange=1');
                    exit;
                }
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

            $completedApplicationRequestNo = null;
            if (!$isPostEdit && !empty($_POST['from_application'])) {
                $postedApplicationId = filter_var($_POST['from_application'], FILTER_VALIDATE_INT);
                $applicationLock = $pdo->prepare(
                    "SELECT id, request_no, status FROM sacrament_applications
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
                    $completedApplicationRequestNo = (string) $linkedApplication['request_no'];
                } else {
                    $applicationUpdate = $pdo->prepare(
                        "UPDATE sacrament_applications
                         SET person_id = ?, record_id = ?
                         WHERE id = ? AND sacrament_type = 'baptism'"
                    );
                    $applicationUpdate->execute([$savePersonId, $recordId, (int) $linkedApplication['id']]);
                }
            }

            if ($isPostEdit) {
                sd_log_record_edit(
                    $pdo,
                    (int) current_user()['id'],
                    $recordId,
                    $auditBefore ?? [],
                    sd_record_audit_snapshot($pdo, $recordId),
                    $reasonChange
                );
            } else {
                log_activity($pdo, current_user()['id'], 'baptism_record_encoded', 'sacrament_records', $recordId, 'Baptism record encoded.');
            }
            $pdo->commit();
            if ($completedApplicationRequestNo !== null) {
                try {
                    notify_sacrament_application_status($pdo, 'completed', 'baptism', $completedApplicationRequestNo, "Baptism encoded into register (record #{$recordId}).");
                } catch (Throwable $e) {
                    error_log('Baptism application completion notification failed: ' . $e->getMessage());
                }
            }
            $redirectQuery = $isPostEdit ? 'updated=1' : 'added=1';
            if ($shiftedCount > 0) {
                $redirectQuery .= '&shifted=' . $shiftedCount;
            }
            if ($completedApplicationRequestNo !== null) {
                $redirectQuery .= '&completed_app=' . urlencode($completedApplicationRequestNo);
            }
            redirect_to($isPostEdit && $editReturnUrl ? $editReturnUrl : $selfUrl . '&' . $redirectQuery);
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
    foreach (['father' => 'father_name', 'mother' => 'mother_maiden_name'] as $prefix => $key) {
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
if (isset($_GET['nochange'])) {
    $notice = 'No changes were made.';
}

$isSecretary = (current_user()['role'] ?? '') === 'secretary';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="page-heading">
    <h1><?= $isEdit ? 'Edit Baptism Record' : 'New Baptism Record' ?></h1>
    <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/index.php?type=baptismal') ?>">Back to Records</a>
</div>
<?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-info"><p><?= e($notice) ?></p></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php
$recordDetails = $isEdit ? (json_decode((string) ($editRecord['details'] ?? ''), true) ?: []) : [];
$suggestedEntry = $isEdit ? (string) ($editRecord['entry_no'] ?? '') : (string) $suggest['entry_no'];
$godfatherRows = [];
$godmotherRows = [];
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
<dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || $isNew || $errors || $applicationId) ? ' data-autoshow' : '' ?><?= $editReturnUrl ? ' data-return-url="' . e($basePath . $editReturnUrl) . '"' : '' ?>>
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
        <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form autocomplete="off" method="post" action="" data-review data-review-title="Review Baptismal Entry" data-review-confirm="Confirm and Save">
            <?= csrf_field() ?>
            <?php if ($isEdit && $editReturnUrl): ?><input type="hidden" name="return" value="<?= e($editReturnUrl) ?>"><?php endif; ?>
            <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
            <div class="person-finder" data-person-finder data-endpoint="<?= e($basePath . '/admin/api/person-search.php') ?>">
                <label>Find possible people
                    <input autocomplete="off" type="search" data-person-search placeholder="Enter the name first, then search">
                </label>
                <input type="hidden" name="person_id" data-person-id value="<?= e($_POST['person_id'] ?? ($identity['id'] ?? '')) ?>">
                <div class="person-finder-selection" data-person-selection aria-live="polite"></div>
                <div class="person-finder-results" data-person-results></div>
                <p class="field-hint">Search the master person index to avoid duplicate profiles. Choose the correct match to fill the identity fields; review those details, then save the sacrament record.</p>
            </div>
            <?php if ($identity): ?><p class="field-hint">Person identity and parent details are read-only from the linked master profile.</p><?php endif; ?>
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
                    <?php if ($duplicateMatches): ?>
                        <div class="alert alert-warning">
                            <strong>Possible existing profiles</strong>
                            <ul><?php foreach ($duplicateMatches as $match): ?>
                                <li><?= e(person_identity_label($match)) ?></li>
                            <?php endforeach; ?></ul>
                            <label><input type="checkbox" name="confirm_new_person" value="1"<?= (($_POST['confirm_new_person'] ?? '') === '1') ? ' checked' : '' ?>> I reviewed these matches and still need a separate person profile.</label>
                        </div>
                    <?php endif; ?>
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
                <label>Officiating Minister (full name) <span class="field-hint">(optional)</span>
                    <input type="text" name="minister_name" maxlength="150" value="<?= e($formValues['minister_name'] ?? $editRecord['minister_name'] ?? '') ?>">
                </label>
                <label>Remarks / Observanda <textarea name="remarks"><?= e($formValues['remarks'] ?? $editRecord['remarks'] ?? '') ?></textarea></label>
<?php if ($isEdit): ?>
<label>Reason for change <textarea name="reason_change" minlength="5" maxlength="500"><?= e($formValues['reason_change'] ?? '') ?></textarea></label>
<p class="field-hint">Required if you change anything. Saving will clear book verification.</p>
<?php endif; ?>
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
