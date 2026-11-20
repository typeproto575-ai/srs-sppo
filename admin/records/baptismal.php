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
$warnings = [];
$duplicateMatches = [];
$success = null;
$notice = null;
$editRecord = null;
$editParticipants = [];
$application = null;
$applicationDetails = [];
$linkedParentProfiles = ['father' => null, 'mother' => null, 'children' => []];
$birthplaceNeedsVerification = true;
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
                sr.parents_union_type, sr.parents_marriage_parish, sr.details, sr.remarks, sr.encoded_by,
                p.first_name, p.middle_name, p.last_name, p.suffix, p.birth_date,
                p.birth_place, p.father_name, p.mother_maiden_name, p.address
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $editGodparentNames = ['godfather' => [], 'godmother' => []];
        foreach ($editParticipants as $participant) {
            $role = (string) ($participant['role'] ?? '');
            $name = trim((string) ($participant['full_name'] ?? ''));
            if (isset($editGodparentNames[$role]) && $name !== '') {
                $editGodparentNames[$role][] = $name;
            }
        }
        $warnings = sd_baptism_godparent_presence($editGodparentNames, true)['warnings'];
    }
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
        "SELECT sa.id, sa.request_no, sa.sacrament_type, sa.person_id, sa.status, sa.details,
                (SELECT se.event_date
                 FROM schedule_events se
                 WHERE se.application_id = sa.id AND se.event_type = 'baptism'
                 ORDER BY se.id DESC
                 LIMIT 1) AS scheduled_event_date
         FROM sacrament_applications sa
         WHERE sa.id = ? AND sa.sacrament_type = 'baptism'"
    );
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($application) {
        $applicationDetails = json_decode((string) ($application['details'] ?? ''), true);
        $applicationDetails = is_array($applicationDetails) ? $applicationDetails : [];
        $personId = (int) ($application['person_id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if (!empty($application['scheduled_event_date'])) {
                $formValues['baptism_date'] = (string) $application['scheduled_event_date'];
            }
            if (is_scalar($applicationDetails['confirmed_venue'] ?? null)) {
                $formValues['baptism_place'] = (string) $applicationDetails['confirmed_venue'];
            }
            foreach ([
                'child_birth_date' => 'birth_date',
                'child_birth_place' => 'child_birthplace',
                'parents_union_type' => 'union_type',
                'parents_marriage_parish' => 'parents_marriage_parish',
                'parents_residence' => 'parents_residence',
                'father_religion' => 'father_religion',
                'mother_religion' => 'mother_religion',
            ] as $detailKey => $fieldName) {
                if (isset($applicationDetails[$detailKey]) && is_scalar($applicationDetails[$detailKey])) {
                    $formValues[$fieldName] = (string) $applicationDetails[$detailKey];
                }
            }
            $applicationBirthplace = $applicationDetails['child_birthplace']
                ?? $applicationDetails['child_birth_place']
                ?? $applicationDetails['place_of_birth']
                ?? null;
            if (is_scalar($applicationBirthplace)) {
                $formValues['child_birthplace'] = (string) $applicationBirthplace;
            }
            if (isset($formValues['union_type']) && is_scalar($formValues['union_type'])) {
                $formValues['union_type'] = normalize_parents_union_type((string) $formValues['union_type']);
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
            foreach (sd_application_godparents($applicationDetails) as $godparent) {
                if ($godparent['role'] === 'godfather') {
                    $godfathers[] = $godparent;
                } elseif ($godparent['role'] === 'godmother') {
                    $godmothers[] = $godparent;
                }
            }
            foreach (['godfather' => $godfathers, 'godmother' => $godmothers] as $prefix => $rows) {
                foreach ($rows as $index => $row) {
                    $formValues[$prefix . '_full_name'][$index] = (string) $row['full_name'];
                    $formValues[$prefix . '_residence'][$index] = (string) ($row['residence'] ?? '');
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
if (!$isEdit && $personId === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedId = filter_var($_POST['person_id'] ?? null, FILTER_VALIDATE_INT);
    if ($selectedId && $selectedId > 0) {
        $personId = (int) $selectedId;
    }
}
if ($personId > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, suffix, birth_date,
                birth_place, father_name, mother_maiden_name, address
         FROM persons WHERE id = ?'
    );
    $stmt->execute([$personId]);
    $identity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$identity) {
        $errors[] = 'The selected person profile could not be found.';
        $personId = 0;
    } else {
        $linkedParentProfiles = sd_get_family($pdo, $personId);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $fatherAddress = trim((string) ($linkedParentProfiles['father']['address'] ?? ''));
            $motherAddress = trim((string) ($linkedParentProfiles['mother']['address'] ?? ''));
            if ($fatherAddress !== '' && $fatherAddress === $motherAddress
                && trim((string) ($formValues['parents_residence'] ?? '')) === '') {
                $formValues['parents_residence'] = $fatherAddress;
            }
        }
    }
}
$birthplaceValueForCheck = $formValues['child_birthplace'] ?? ($identity['birth_place'] ?? '');
$birthplaceFormValue = is_scalar($birthplaceValueForCheck) ? trim((string) $birthplaceValueForCheck) : '';
$birthplaceNeedsVerification = is_unknown_place_of_birth($birthplaceFormValue);
$profileBirthplaceNeedsVerification = $identity !== null
    && is_unknown_place_of_birth((string) ($identity['birth_place'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_record']) || isset($_POST['update_record'])) && csrf_verify()) {
    $isPostEdit = isset($_POST['update_record']);
    $postedEditId = (int) ($_POST['edit_id'] ?? 0);
    $birthplaceInput = $_POST['child_birthplace'] ?? '';
    if (!is_string($birthplaceInput)) {
        $errors[] = "The applicant's birthplace must be text.";
        $birthplaceInput = '';
    }
    $birthplaceInput = trim($birthplaceInput);
    $applicantResidenceInput = $_POST['person_address'] ?? '';
    if (!is_string($applicantResidenceInput)) {
        $errors[] = "The applicant's current residence must be text.";
        $applicantResidenceInput = '';
    }
    $applicantResidenceInput = trim($applicantResidenceInput);
    if (mb_strlen($applicantResidenceInput) > 255) {
        $errors[] = "The applicant's current residence must be 255 characters or fewer.";
    }
    if (is_unknown_place_of_birth($birthplaceInput)) {
        $errors[] = "Please enter the child's exact Place of Birth as indicated on the PSA/LCR Birth Certificate.";
    }
    if (($_POST['birthplace_verified'] ?? '') !== '1') {
        $errors[] = "Confirm that you verified the applicant's birthplace against the PSA/LCR Birth Certificate.";
    }
    if (mb_strlen($birthplaceInput) > 150) {
        $errors[] = "The applicant's birthplace must be 150 characters or fewer.";
    }
    $entryNo = trim((string) ($_POST['entry_no'] ?? ''));
    $baptismDate = trim((string) ($_POST['baptism_date'] ?? ''));
    $baptismPlace = trim((string) ($_POST['baptism_place'] ?? ''));
    $bookNo = trim((string) ($_POST['book_no'] ?? ''));
    $pageNo = trim((string) ($_POST['page_no'] ?? ''));
    $lineNo = trim((string) ($_POST['line_no'] ?? ''));
    $unionInput = $_POST['union_type'] ?? '';
    $unionType = is_string($unionInput) ? normalize_parents_union_type($unionInput) : '';
    $parentsMarriageParishInput = $_POST['parents_marriage_parish'] ?? '';
    $parentsMarriageParish = is_string($parentsMarriageParishInput)
        ? trim($parentsMarriageParishInput)
        : '';
    if (!is_string($parentsMarriageParishInput)) {
        $errors[] = "Parents' marriage parish must be text.";
    }
    if ($unionType !== 'canonical') {
        if ($parentsMarriageParish !== '') {
            $errors[] = "Parish of parents' marriage is only applicable to a canonical Catholic marriage.";
        }
        $parentsMarriageParish = '';
    }
    $fatherName = compose_person_name($_POST, 'father');
    $motherName = compose_person_name($_POST, 'mother');
    $ministerName = trim((string) ($_POST['minister_name'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $parentDetails = [];
    foreach (['parents_residence' => 255, 'father_religion' => 150, 'mother_religion' => 150] as $key => $maxLength) {
        $rawValue = $_POST[$key] ?? '';
        if (!is_string($rawValue)) {
            $errors[] = 'Parent and guardian details must be text.';
            continue;
        }
        $value = trim($rawValue);
        if (mb_strlen($value) > $maxLength) {
            $errors[] = 'Parent details must be ' . $maxLength . ' characters or fewer.';
        }
        if ($value !== '') {
            $parentDetails[$key] = $value;
        }
    }
    $godparents = [];
    $godparentNames = ['godfather' => [], 'godmother' => []];
    foreach (['godfather', 'godmother'] as $role) {
        $names = $_POST[$role . '_full_name'] ?? [];
        if (!is_array($names)) {
            $errors[] = 'Enter each godparent as a full name.';
            $names = [];
        }
        if (count($names) > 8) {
            $errors[] = 'No more than 8 ' . ($role === 'godfather' ? 'godfathers' : 'godmothers') . ' can be entered.';
        }
        $residences = $_POST[$role . '_residence'] ?? [];
        if (!is_array($residences)) {
            $residences = [];
        }
        foreach ($names as $index => $name) {
            if (!is_string($name)) {
                $errors[] = 'Each godparent name must be text.';
                continue;
            }
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $godparentNames[$role][] = $name;
            if (mb_strlen($name) > 150) {
                $errors[] = 'Godparent names must be 150 characters or fewer.';
            }
            if (($nameError = validate_full_name($name)) !== null) {
                $errors[] = 'Godparent name: ' . $nameError;
            }
            $residence = $residences[$index] ?? '';
            if (!is_string($residence)) {
                $errors[] = 'Godparent residence must be text.';
                continue;
            }
            $residence = trim($residence);
            if (mb_strlen($residence) > 150) {
                $errors[] = 'Godparent residence must be 150 characters or fewer.';
            }
            $godparents[] = [
                'role' => $role,
                'full_name' => $name,
                'residence' => $residence !== '' ? $residence : null,
                'sort_order' => count($godparents) + 1,
            ];
        }
    }
    $presence = sd_baptism_godparent_presence($godparentNames, $isPostEdit);
    $errors = array_merge($errors, $presence['errors']);
    $warnings = array_merge($warnings, $presence['warnings']);

    if ($baptismDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $baptismDate)
        || !checkdate((int) substr($baptismDate, 5, 2), (int) substr($baptismDate, 8, 2), (int) substr($baptismDate, 0, 4))) {
        $errors[] = 'Enter a valid baptism date.';
    }
    if (!$isPostEdit && !$application && $baptismPlace === '') {
        $errors[] = 'Enter the actual place of baptism.';
    }
    if (!$isPostEdit && $ministerName === '') {
        $errors[] = 'Enter the officiating minister’s full name.';
    }
    if (!is_string($unionInput) || !in_array($unionType, ['', 'canonical', 'civil', 'common_law', 'unmarried'], true)) {
        $errors[] = 'Choose a valid parents’ union type.';
    }
    if (mb_strlen($parentsMarriageParish) > 255) {
        $errors[] = "Parents' marriage parish must be 255 characters or fewer.";
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
            $createdPersonProfile = false;
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
                        'birth_place' => $birthplaceInput,
                        'father_name' => $fatherName,
                        'mother_maiden_name' => $motherName,
                        'address' => $applicantResidenceInput,
                        'baptismal_status' => 'baptized_catholic',
                    ]);
                    $createdPersonProfile = true;
                }
            }
            if (!$createdPersonProfile) {
                $personBefore = sd_person_audit_snapshot($pdo, $savePersonId);
                sd_save_person($pdo, ['birth_place' => $birthplaceInput], $savePersonId);
                $applicantResidenceWasAdded = !$isPostEdit
                    && $applicantResidenceInput !== ''
                    && trim((string) ($identity['address'] ?? '')) === '';
                if ($applicantResidenceWasAdded) {
                    save_person_identity($pdo, $savePersonId, [
                        'first_name' => (string) $identity['first_name'],
                        'last_name' => (string) $identity['last_name'],
                        'address' => $applicantResidenceInput,
                    ]);
                }
                $personAfter = sd_person_audit_snapshot($pdo, $savePersonId);
                $personEditReason = $isPostEdit
                    ? trim((string) ($_POST['reason_change'] ?? ''))
                    : 'Birthplace verified against the PSA/LCR Birth Certificate during baptism record encoding.';
                if ($applicantResidenceWasAdded) {
                    $personEditReason .= ' Added a previously unrecorded current residence during baptism record encoding.';
                }
                sd_log_person_edit(
                    $pdo,
                    (int) current_user()['id'],
                    $savePersonId,
                    $personBefore,
                    $personAfter,
                    $personEditReason
                );
            }

$shiftedCount = 0;
            if (!$isPostEdit && $entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shiftedCount = baptismal_shift_entry_numbers($pdo, $entryNo);
            }
            $recordData = [
                'person_id' => $savePersonId,
                'event_date' => $baptismDate,
                'place' => $baptismPlace ?: null,
                'minister_name' => $ministerName ?: null,
                'entry_no' => $entryNo ?: null,
                'book_no' => $bookNo ?: null,
                'page_no' => $pageNo ?: null,
                'line_no' => $lineNo ?: null,
                'parents_union_type' => $unionType !== '' ? $unionType : null,
                'parents_marriage_parish' => $parentsMarriageParish !== '' ? $parentsMarriageParish : null,
                'details' => $parentDetails !== [] ? $parentDetails : null,
                'remarks' => $remarks ?: null,
            ];
            if ($isPostEdit) {
                $recordId = (int) $editRecord['id'];
                $oldParticipants = $pdo->prepare('SELECT role, full_name, residence, sort_order FROM sacrament_participants WHERE record_id = ?');
                $oldParticipants->execute([$recordId]);
                $oldRecord = array_merge($editRecord, ['participants' => $oldParticipants->fetchAll(PDO::FETCH_ASSOC)]);
                $newRecord = array_merge($recordData, ['participants' => $godparents]);
                $changed = sd_record_changes($oldRecord, $newRecord);
                if ($changed === []) {
                    $pdo->commit();
                    redirect_to($editReturnUrl ?: $selfUrl . '&nochange=1');
                    exit;
                }
            }
            $recordId = sd_save_baptismal_register_record(
                $pdo,
                $recordData,
                $godparents,
                (int) current_user()['id'],
                $isPostEdit ? $recordId : null
            );

            $completedApplicationRequestNo = null;
            if (!$isPostEdit && !empty($_POST['from_application'])) {
                $postedApplicationId = filter_var($_POST['from_application'], FILTER_VALIDATE_INT);
                $applicationLock = $pdo->prepare(
                    "SELECT id, request_no, status FROM sacrament_applications
                     WHERE id = ? AND sacrament_type = 'baptism' FOR UPDATE"
                );
                $applicationLock->execute([(int) $postedApplicationId]);
                $linkedApplication = $applicationLock->fetch(PDO::FETCH_ASSOC);
                if (!$linkedApplication || !in_array($linkedApplication['status'], ['approved', 'scheduled'], true)) {
                    throw new RuntimeException('The linked baptism application must be approved or scheduled before a register entry can complete it.');
                }
                $applicationUpdate = $pdo->prepare(
                    "UPDATE sacrament_applications
                     SET person_id = ?, record_id = ?, status = 'completed', processed_by = ?
                     WHERE id = ? AND sacrament_type = 'baptism'"
                );
                $applicationUpdate->execute([$savePersonId, $recordId, current_user()['id'], (int) $linkedApplication['id']]);
                $completedApplicationRequestNo = (string) $linkedApplication['request_no'];
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
                    notify_sacrament_application_status($pdo, 'completed', 'baptism', $completedApplicationRequestNo);
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
            error_log(sprintf(
                'Baptism record save failed: %s (%s:%d)',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine()
            ));
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
    if (isset($formValues[$prefix . '_full_name']) && is_array($formValues[$prefix . '_full_name'])) {
        ${$variable} = [];
        foreach ($formValues[$prefix . '_full_name'] as $index => $fullName) {
            ${$variable}[$index] = [
                'full_name' => is_scalar($fullName) ? (string) $fullName : '',
                'residence' => is_array($formValues[$prefix . '_residence'] ?? null)
                    ? (string) ($formValues[$prefix . '_residence'][$index] ?? '')
                    : '',
            ];
        }
    } elseif (isset($formValues[$prefix . '_first']) && is_array($formValues[$prefix . '_first'])) {
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
<dialog id="encode-dialog" class="encode-dialog baptism-encode-dialog"<?= ($isEdit || $isNew || $errors || $applicationId) ? ' data-autoshow' : '' ?><?= $editReturnUrl ? ' data-return-url="' . e($basePath . $editReturnUrl) . '"' : '' ?>>
    <div class="dialog-head">
        <h2><?= $isEdit ? 'Edit Baptism Record #' . (int) $editRecord['id'] : 'Encode New Baptism Record' ?></h2>
        <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
    </div>
    <div class="dialog-body">
        <?php if ($application): ?>
            <p class="field-hint">Prefilled from application <?= e($application['request_no']) ?>. After the ceremony, verify the actual date, place, minister, and register details before saving; saving creates the register entry and completes the application.</p>
            <?php if (empty($application['person_id'])): ?>
                <p class="field-hint">Applicant-provided child name: <strong><?= e((string) ($applicationDetails['child_name'] ?? '')) ?></strong>. Split and confirm the name in the editable fields below; it is not split automatically.</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($warnings !== []): ?>
            <div class="alert alert-warning" role="status">
                <ul><?php foreach ($warnings as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form class="baptism-record-entry-form" autocomplete="off" method="post" action="" data-review data-review-title="Review Baptismal Entry" data-review-confirm="Confirm and Save">
            <?= csrf_field() ?>
            <?php if ($isEdit && $editReturnUrl): ?><input type="hidden" name="return" value="<?= e($editReturnUrl) ?>"><?php endif; ?>
            <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
            <div class="person-finder" data-person-finder<?= $isEdit ? ' data-no-clear-selection' : '' ?> data-endpoint="<?= e($basePath . '/admin/api/person-search.php') ?>">
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
                <?php
                $applicantResidenceValue = is_scalar($formValues['person_address'] ?? null)
                    ? (string) $formValues['person_address']
                    : '';
                $canEnterApplicantResidence = !$isEdit
                    && (!$identity || trim((string) ($identity['address'] ?? '')) === '');
                ?>
                <?php if ($identity): ?>
                    <p><strong><?= e(person_identity_label($identity)) ?></strong></p>
                    <p>Birth date: <?= e((string) ($identity['birth_date'] ?? 'Not recorded')) ?></p>
                    <p>Current residence: <?= e(trim((string) ($identity['address'] ?? '')) ?: 'Not recorded') ?></p>
                    <p class="field-hint">Identity and parent details are read-only from the master persons record. Place of birth is confirmed below from the PSA/LCR certificate.</p>
                    <?php if ($canEnterApplicantResidence): ?>
                        <label class="applicant-residence-field">
                            <span class="applicant-residence-label">Applicant's Current Residence <span class="field-hint">(optional)</span></span>
                            <input type="text" name="person_address" maxlength="255" value="<?= e($applicantResidenceValue) ?>">
                        </label>
                        <span class="field-hint">This will fill the linked profile only if its residence is still blank.</span>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="inline-fields structured-name-fields child-identity-name-fields">
                        <label>First Name <span class="required-marker">*</span> <input type="text" name="child_first_name" value="<?= e($formValues['child_first_name'] ?? '') ?>" required></label>
                        <label>Middle Name <input type="text" name="child_middle_name" value="<?= e($formValues['child_middle_name'] ?? '') ?>"></label>
                        <label>Surname <span class="required-marker">*</span> <input type="text" name="child_last_name" value="<?= e($formValues['child_last_name'] ?? '') ?>" required></label>
                        <label>Suffix <input type="text" name="child_suffix" value="<?= e($formValues['child_suffix'] ?? '') ?>"></label>
                        <?php if ($canEnterApplicantResidence): ?>
                            <label class="applicant-residence-field">
                                <span class="applicant-residence-label">Applicant's Current Residence <span class="field-hint">(optional)</span></span>
                                <input type="text" name="person_address" maxlength="255" value="<?= e($applicantResidenceValue) ?>">
                            </label>
                        <?php endif; ?>
                    </div>
                    <label>Birth Date <input type="date" name="birth_date" value="<?= e($formValues['birth_date'] ?? '') ?>"></label>
                    <p class="field-hint">Saving creates a person profile in <code>persons</code>.</p>
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
                <label>Place of Birth <span class="required-marker" aria-hidden="true">*</span>
                    <input type="text" name="child_birthplace" maxlength="150"
                           value="<?= e($birthplaceNeedsVerification ? '' : $birthplaceFormValue) ?>"
                           placeholder="City / Municipality, Province (as shown on PSA/LCR Birth Certificate)"
                           pattern="(?!(?:\s*)(?:[nN]/[aA]|[nN][aA]|[nN]\.[aA]\.|[nN][oO][nN][eE]|[uU][nN][kK][nN][oO][wW][nN]|-)\s*$).+"
                           title="Enter the city or municipality and province shown on the PSA/LCR Birth Certificate. Placeholder values are not accepted."
                           required<?= $birthplaceNeedsVerification ? ' class="birthplace-needs-verification"' : '' ?>>
                    <?php if ($birthplaceNeedsVerification || $profileBirthplaceNeedsVerification): ?>
                        <span class="alert alert-warning birthplace-verification-note" role="status">
                            <?php if ($profileBirthplaceNeedsVerification && !$birthplaceNeedsVerification): ?>
                                The linked profile has no verified birthplace. Confirm the prefilled value against the PSA/LCR certificate; saving updates the shared person profile.
                            <?php else: ?>
                                Enter the exact city or municipality and province from the PSA/LCR Birth Certificate. Placeholder values cannot be saved.
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <span class="field-hint">Verify this value against the PSA/LCR Birth Certificate before saving. It is stored on the shared person profile.</span>
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="birthplace_verified" value="1" required<?= ($_POST['birthplace_verified'] ?? '') === '1' ? ' checked' : '' ?>>
                    I verified the exact birthplace against the applicant's PSA/LCR Birth Certificate.
                </label>
                <div class="inline-fields">
                    <label>Baptism Date <span class="required-marker">*</span> <input type="date" name="baptism_date" value="<?= e($formValues['baptism_date'] ?? $editRecord['event_date'] ?? '') ?>" required></label>
                    <label>Place of Baptism<?= !$isEdit && !$application ? ' *' : '' ?>
                        <input type="text" name="baptism_place" maxlength="150" value="<?= e($formValues['baptism_place'] ?? $editRecord['place'] ?? '') ?>"<?= !$isEdit && !$application ? ' required' : '' ?>>
                    </label>
                </div>
            </fieldset>
            <fieldset>
                <legend>Parents</legend>
                <p class="field-hint">Enter the parents' current residence and religion, together with their union details.</p>
                <?php if ($identity): ?>
                    <p>Father: <?= e((string) ($identity['father_name'] ?? 'Not recorded')) ?></p>
                    <p>Mother’s maiden name: <?= e((string) ($identity['mother_maiden_name'] ?? 'Not recorded')) ?></p>
                    <p class="field-hint">Parent names are part of the person profile and are read-only when a person is linked.</p>
                <?php else: ?>
                    <?= person_name_fields('father', "Father's Name", $nameFormValues, false) ?>
                    <?= person_name_fields('mother', "Mother's Maiden Name", $nameFormValues, false) ?>
                <?php endif; ?>
                <?php
                $parentFieldValues = [];
                foreach (['parents_residence', 'father_religion', 'mother_religion'] as $fieldName) {
                    $value = $formValues[$fieldName] ?? $recordDetails[$fieldName] ?? '';
                    $parentFieldValues[$fieldName] = is_scalar($value) ? (string) $value : '';
                }
                $parentsMarriageParishValue = $formValues['parents_marriage_parish']
                    ?? $editRecord['parents_marriage_parish']
                    ?? '';
                $parentsMarriageParishValue = is_scalar($parentsMarriageParishValue)
                    ? (string) $parentsMarriageParishValue
                    : '';
                ?>
                <div class="baptism-parent-grid">
                    <label>Parents' Current Residence
                        <input type="text" name="parents_residence" maxlength="255" value="<?= e($parentFieldValues['parents_residence']) ?>">
                    </label>
                    <label>Father's Religion
                        <input type="text" name="father_religion" maxlength="150" value="<?= e($parentFieldValues['father_religion']) ?>">
                    </label>
                    <label>Mother's Religion
                        <input type="text" name="mother_religion" maxlength="150" value="<?= e($parentFieldValues['mother_religion']) ?>">
                    </label>
                    <?php
                    $selectedUnionValue = $formValues['union_type'] ?? $editRecord['parents_union_type'] ?? '';
                    $selectedUnionType = is_string($selectedUnionValue)
                        ? normalize_parents_union_type($selectedUnionValue)
                        : '';
                    ?>
                    <label>Parents' Union Type
                        <select name="union_type">
                            <?php foreach (parents_union_type_options() as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $selectedUnionType === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                    </select>
                    </label>
                    <label class="baptism-parent-marriage-parish" data-show-if="union_type=canonical" data-show-if-required="false">
                        Parish of Parents' Marriage (optional)
                        <input type="text" name="parents_marriage_parish" maxlength="255" value="<?= e($parentsMarriageParishValue) ?>">
                    </label>
                </div>
            </fieldset>
            <?php foreach (['godfather' => ['Godfathers', $godfatherRows], 'godmother' => ['Godmothers', $godmotherRows]] as $role => [$legend, $rows]): ?>
                <fieldset>
                    <legend><?= e($legend) ?><?= !$isEdit ? ' <span class="required-marker" aria-hidden="true">*</span>' : '' ?></legend>
                    <?php if (!$isEdit): ?>
                        <p class="field-hint">At least one <?= e($role) ?> is required; up to 8 may be entered.</p>
                    <?php endif; ?>
                    <div data-repeat-group="<?= e($role) ?>" data-repeat-max="8">
                        <?php foreach ($rows as $index => $participant): ?>
                            <div class="godparent-row">
                                <div class="inline-fields">
                                    <label><?= $role === 'godfather' ? 'Godfather full name' : 'Godmother full name' ?>
                                        <input type="text" name="<?= e($role) ?>_full_name[]" maxlength="150" value="<?= e($participant['full_name'] ?? '') ?>" autocomplete="name">
                                    </label>
                                    <label>Current residence <input type="text" name="<?= e($role) ?>_residence[]" maxlength="150" value="<?= e($formValues[$role . '_residence'][$index] ?? $participant['residence'] ?? '') ?>"></label>
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
                <label>Officiating Minister's full name<?= !$isEdit ? ' *' : '' ?>
                    <input type="text" name="minister_name" maxlength="150" value="<?= e($formValues['minister_name'] ?? $editRecord['minister_name'] ?? '') ?>"<?= !$isEdit ? ' required' : '' ?>>
                </label>
                <label>Remarks / Observanda <textarea name="remarks"><?= e($formValues['remarks'] ?? $editRecord['remarks'] ?? '') ?></textarea></label>
<?php if ($isEdit): ?>
<label>Reason for change <textarea name="reason_change" minlength="5" maxlength="500"><?= e($formValues['reason_change'] ?? '') ?></textarea></label>
<p class="field-hint">Required if you change anything. Saving will clear book verification.</p>
<?php endif; ?>
            </fieldset>
            <div class="record-encode-actions">
                <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
                <button type="submit" name="<?= $isEdit ? 'update_record' : 'add_record' ?>" value="1" class="btn btn-primary"><?= $isEdit ? 'Update Record' : 'Save Record' ?></button>
            </div>
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
