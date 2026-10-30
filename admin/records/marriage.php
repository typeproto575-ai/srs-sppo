<?php
/**
 * =====================================================================
 * SRS-SPPO MARRIAGE RECORDS (admin register)
 * =====================================================================
 * Purpose: Digital mirror of the physical marriage register: encode
 *          entries from the logbook, list/search, and serve as the
 *          source of truth for marriage certificate requests.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/person-linking.php';
require_role('secretary');

class MarriageSamePersonException extends RuntimeException
{
}

$basePath = $basePath ?? base_path();
$selfReturn = '/admin/records/marriage.php' . ((($_SERVER['QUERY_STRING'] ?? '') !== '') ? '?' . $_SERVER['QUERY_STRING'] : '');
$pageTitle = 'Marriage Records';
$errors = [];
$success = null;
$fromApplicationId = 0;
$application = null;
$applicationDetails = [];

function marriage_load_person_identity(PDO $pdo, int $personId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, suffix, birth_date,
                birth_place, father_name, mother_maiden_name, baptismal_status
         FROM persons WHERE id = ?'
    );
    $stmt->execute([$personId]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    return $person ?: null;
}

function marriage_identity_name(array $identity): string
{
    return trim(implode(' ', array_filter([
        $identity['first_name'] ?? '',
        $identity['middle_name'] ?? '',
        $identity['last_name'] ?? '',
        $identity['suffix'] ?? '',
    ], static fn ($value) => trim((string) $value) !== '')));
}

if (!empty($_GET['from_application']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $applicationStmt = $pdo->prepare(
        "SELECT id AS application_id, request_no, status, person_id, spouse_person_id,
                preferred_date, details
         FROM sacrament_applications
         WHERE id = ? AND sacrament_type = 'marriage'
         LIMIT 1"
    );
    $applicationStmt->execute([(int) $_GET['from_application']]);
    $application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
    if ($application && in_array($application['status'], ['approved', 'scheduled'], true)) {
        $fromApplicationId = (int) $application['application_id'];
        $applicationDetails = json_decode((string) ($application['details'] ?? ''), true);
        $applicationDetails = is_array($applicationDetails) ? $applicationDetails : [];
        $_POST['groom_person_id'] = (string) ($application['person_id'] ?? '');
        $_POST['bride_person_id'] = (string) ($application['spouse_person_id'] ?? '');
        foreach (['groom', 'bride'] as $party) {
            $applicationPersonId = $party === 'groom'
                ? $application['person_id']
                : $application['spouse_person_id'];
            if (empty($applicationPersonId)) {
                $name = trim((string) ($applicationDetails[$party]['full_name'] ?? ''));
                [$first, $middle, $last, $suffix] = structured_name_parts($name);
                $_POST[$party . '_first_name'] = $first;
                $_POST[$party . '_middle_name'] = $middle;
                $_POST[$party . '_last_name'] = $last;
                $_POST[$party . '_suffix'] = $suffix;
                $_POST[$party . '_birth_date'] = (string) ($applicationDetails[$party]['birth_date'] ?? '');
                $_POST[$party . '_birth_place'] = (string) ($applicationDetails[$party]['birthplace'] ?? '');
            }
        }
        $_POST['marriage_date'] = (string) ($application['preferred_date'] ?? '');
        $_POST['remarks'] = 'Encoded from application ' . $application['request_no'];
    } elseif ($application) {
        $errors[] = 'Application ' . $application['request_no'] . ' must be approved or scheduled before it can be encoded into the register.';
        $application = null;
    } else {
        $errors[] = 'The requested marriage application could not be found.';
    }
}

$editRecord = null;
$editWitnesses = [];
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if (!$editId || $editId < 1) {
        redirect_to('/admin/records/marriage.php');
        exit;
    }
    $editStmt = $pdo->prepare(
        "SELECT sr.id, sr.person_id, sr.spouse_person_id, sr.event_date AS marriage_date,
                sr.place, sr.minister_name, sr.entry_no, sr.book_no, sr.page_no, sr.line_no,
                sr.details, sr.remarks, sr.encoded_by,
                groom.id AS groom_linked_id, groom.first_name AS groom_first_name,
                groom.middle_name AS groom_middle_name, groom.last_name AS groom_last_name,
                groom.suffix AS groom_suffix, groom.birth_date AS groom_birth_date,
                groom.birth_place AS groom_birth_place, groom.father_name AS groom_father_name,
                groom.mother_maiden_name AS groom_mother_maiden_name,
                groom.baptismal_status AS groom_baptismal_status,
                bride.id AS bride_linked_id, bride.first_name AS bride_first_name,
                bride.middle_name AS bride_middle_name, bride.last_name AS bride_last_name,
                bride.suffix AS bride_suffix, bride.birth_date AS bride_birth_date,
                bride.birth_place AS bride_birth_place, bride.father_name AS bride_father_name,
                bride.mother_maiden_name AS bride_mother_maiden_name,
                bride.baptismal_status AS bride_baptismal_status
         FROM sacrament_records sr
         JOIN persons groom ON groom.id = sr.person_id
         LEFT JOIN persons bride ON bride.id = sr.spouse_person_id
         WHERE sr.id = ? AND sr.sacrament_type = 'marriage'"
    );
    $editStmt->execute([(int) $editId]);
    $editRecord = $editStmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRecord) {
        redirect_to('/admin/records/marriage.php');
        exit;
    }
    $witnessStmt = $pdo->prepare(
        "SELECT full_name, residence
         FROM sacrament_participants
         WHERE record_id = ? AND role = 'witness'
         ORDER BY sort_order, id"
    );
    $witnessStmt->execute([(int) $editRecord['id']]);
    $editWitnesses = $witnessStmt->fetchAll(PDO::FETCH_ASSOC);
}
$isEdit = $editRecord !== null;

$groomIdentity = null;
$brideIdentity = null;
if ($isEdit) {
    if (!empty($editRecord['groom_linked_id'])) {
        $groomIdentity = [
            'id' => $editRecord['groom_linked_id'],
            'first_name' => $editRecord['groom_first_name'],
            'middle_name' => $editRecord['groom_middle_name'],
            'last_name' => $editRecord['groom_last_name'],
            'suffix' => $editRecord['groom_suffix'],
            'birth_date' => $editRecord['groom_birth_date'],
            'birth_place' => $editRecord['groom_birth_place'],
            'father_name' => $editRecord['groom_father_name'],
            'mother_maiden_name' => $editRecord['groom_mother_maiden_name'],
            'baptismal_status' => $editRecord['groom_baptismal_status'],
        ];
    }
    if (!empty($editRecord['spouse_person_id'])) {
        $brideIdentity = !empty($editRecord['bride_linked_id']) ? [
            'id' => $editRecord['bride_linked_id'],
            'first_name' => $editRecord['bride_first_name'],
            'middle_name' => $editRecord['bride_middle_name'],
            'last_name' => $editRecord['bride_last_name'],
            'suffix' => $editRecord['bride_suffix'],
            'birth_date' => $editRecord['bride_birth_date'],
            'birth_place' => $editRecord['bride_birth_place'],
            'father_name' => $editRecord['bride_father_name'],
            'mother_maiden_name' => $editRecord['bride_mother_maiden_name'],
            'baptismal_status' => $editRecord['bride_baptismal_status'],
        ] : null;
    }
}

if (!$isEdit && !empty($_POST['groom_person_id'])) {
    $groomIdentity = marriage_load_person_identity($pdo, (int) $_POST['groom_person_id']);
    if (!$groomIdentity) $errors[] = 'The selected groom person profile could not be found.';
}
if (!$isEdit && !empty($_POST['bride_person_id'])) {
    $brideIdentity = marriage_load_person_identity($pdo, (int) $_POST['bride_person_id']);
    if (!$brideIdentity) $errors[] = 'The selected bride person profile could not be found.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid - please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['add_record']) || isset($_POST['update_record']))
    && csrf_verify()) {
    require_role('secretary');
    $isUpdate = isset($_POST['update_record']);
    $editId = (int) ($_POST['edit_id'] ?? 0);
    $entryNo = trim((string) ($_POST['entry_no'] ?? ''));
    $marriageDate = trim((string) ($_POST['marriage_date'] ?? ''));
    $place = trim((string) ($_POST['place'] ?? ''));
    $ministerName = compose_person_name($_POST, 'minister');
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $licenseRef = trim((string) ($_POST['license_ref'] ?? ''));
    $detailsByParty = [];
    foreach (['groom', 'bride'] as $party) {
        $age = trim((string) ($_POST[$party . '_age'] ?? ''));
        $detailsByParty[$party] = [
            'civil_status' => trim((string) ($_POST[$party . '_civil_status'] ?? '')),
            'age' => $age === '' ? null : (int) $age,
            'origin' => trim((string) ($_POST[$party . '_origin'] ?? '')),
            'residence' => trim((string) ($_POST[$party . '_residence'] ?? '')),
        ];
    }
    $witnessNames = compose_person_name_rows_by_index($_POST, 'witness');
    $witnessResidences = $_POST['witness_residence'] ?? [];
    if (!is_array($witnessResidences)) $witnessResidences = [];

    if ($marriageDate === '') $errors[] = 'Marriage date is required.';
    if ($isUpdate && (!$editRecord || $editId !== (int) $editRecord['id'])) {
        $errors[] = 'Invalid record selected for update.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $lockedRecord = null;
            if ($isUpdate) {
                $recordLock = $pdo->prepare(
                    "SELECT id, person_id, spouse_person_id, details
                     FROM sacrament_records
                     WHERE id = ? AND sacrament_type = 'marriage'
                     FOR UPDATE"
                );
                $recordLock->execute([$editId]);
                $lockedRecord = $recordLock->fetch(PDO::FETCH_ASSOC);
                if (!$lockedRecord) throw new InvalidArgumentException('The marriage record could not be found.');
            }

            $applicationId = filter_var($_POST['from_application'] ?? null, FILTER_VALIDATE_INT);
            $linkedApplication = null;
            if (!$isUpdate && $applicationId && $applicationId > 0) {
                $applicationLock = $pdo->prepare(
                    "SELECT id, request_no, status, person_id, spouse_person_id
                     FROM sacrament_applications
                     WHERE id = ? AND sacrament_type = 'marriage'
                     FOR UPDATE"
                );
                $applicationLock->execute([(int) $applicationId]);
                $linkedApplication = $applicationLock->fetch(PDO::FETCH_ASSOC);
                if (!$linkedApplication || !in_array($linkedApplication['status'], ['approved', 'scheduled'], true)) {
                    throw new InvalidArgumentException('This application must be approved or scheduled before it can be encoded.');
                }
            }

            $partyIds = [];
            $identities = [];
            foreach (['groom' => 'male', 'bride' => 'female'] as $party => $sex) {
                $postedId = posted_person_id($pdo, $party . '_person_id');
                $recordId = $isUpdate ? (int) ($lockedRecord[$party === 'groom' ? 'person_id' : 'spouse_person_id'] ?? 0) : 0;
                $applicationPersonId = $linkedApplication
                    ? (int) ($linkedApplication[$party === 'groom' ? 'person_id' : 'spouse_person_id'] ?? 0)
                    : 0;
                $personId = $recordId ?: ($applicationPersonId ?: ($postedId ?? 0));
                if ($personId > 0) {
                    $identity = marriage_load_person_identity($pdo, $personId);
                    if (!$identity) throw new InvalidArgumentException('The linked person profile could not be found.');
                } else {
                    $firstName = trim((string) ($_POST[$party . '_first_name'] ?? ''));
                    $middleName = trim((string) ($_POST[$party . '_middle_name'] ?? ''));
                    $lastName = trim((string) ($_POST[$party . '_last_name'] ?? ''));
                    $suffix = trim((string) ($_POST[$party . '_suffix'] ?? ''));
                    if ($firstName === '' || $lastName === '') {
                        throw new InvalidArgumentException('Enter the first and last name for both parties.');
                    }
                    $personId = save_person_identity($pdo, null, [
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'last_name' => $lastName,
                        'suffix' => $suffix,
                        'sex' => $sex,
                        'birth_date' => trim((string) ($_POST[$party . '_birth_date'] ?? '')) ?: null,
                        'birth_place' => trim((string) ($_POST[$party . '_birth_place'] ?? '')),
                        'father_name' => compose_person_name($_POST, $party . '_father'),
                        'mother_maiden_name' => compose_person_name($_POST, $party . '_mother'),
                    ]);
                    $identity = marriage_load_person_identity($pdo, $personId);
                    if (!$identity) throw new RuntimeException('The newly created person profile could not be loaded.');
                }
                $partyIds[$party] = $personId;
                $identities[$party] = $identity;
            }
            if ($partyIds['groom'] === $partyIds['bride']) {
                throw new MarriageSamePersonException('The groom and bride must be different persons.');
            }

            if ($isUpdate) {
                $existingDetails = json_decode((string) ($lockedRecord['details'] ?? ''), true);
                $details = is_array($existingDetails) ? $existingDetails : [];
            } else {
                $details = [];
            }
            foreach ($detailsByParty as $party => $partyDetails) {
                $details[$party] = array_merge(
                    is_array($details[$party] ?? null) ? $details[$party] : [],
                    $partyDetails
                );
            }
            if ($licenseRef !== '') {
                $details['license_ref'] = $licenseRef;
            } elseif (!$isUpdate) {
                unset($details['license_ref']);
            } else {
                unset($details['license_ref']);
            }
            $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($detailsJson === false) throw new RuntimeException('Marriage record details could not be encoded.');

            $shifted = 0;
            if (!$isUpdate) {
                $logbook = register_next_logbook_numbers($pdo, 'marriage', true);
                if ($entryNo === '') $entryNo = $logbook['entry_no'];
                if ($entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                    $shifted = register_shift_entry_numbers($pdo, 'marriage', $entryNo);
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO sacrament_records
                        (sacrament_type, person_id, spouse_person_id, source, event_date, place,
                         minister_name, entry_no, book_no, page_no, line_no, details, remarks, encoded_by)
                     VALUES ('marriage', ?, ?, 'this_parish', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $partyIds['groom'], $partyIds['bride'], $marriageDate,
                    $place !== '' ? $place : null, $ministerName !== '' ? $ministerName : null,
                    $entryNo !== '' ? $entryNo : null, $logbook['book_no'],
                    $logbook['page_no'], $logbook['line_no'], $detailsJson,
                    $remarks !== '' ? $remarks : null, current_user()['id'],
                ]);
                $recordId = (int) $pdo->lastInsertId();
                $action = 'marriage_record_encoded';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE sacrament_records
                     SET person_id = ?, spouse_person_id = ?, event_date = ?, place = ?,
                         minister_name = ?, entry_no = ?,
                         book_no = ?, page_no = ?, line_no = ?, details = ?, remarks = ?, encoded_by = ?
                     WHERE id = ? AND sacrament_type = 'marriage'"
                );
                $stmt->execute([
                    $partyIds['groom'], $partyIds['bride'], $marriageDate,
                    $place !== '' ? $place : null,
                    $ministerName !== '' ? $ministerName : null,
                    $entryNo !== '' ? $entryNo : null,
                    trim((string) ($_POST['book_no'] ?? '')),
                    trim((string) ($_POST['page_no'] ?? '')),
                    trim((string) ($_POST['line_no'] ?? '')),
                    $detailsJson, $remarks !== '' ? $remarks : null,
                    current_user()['id'], $editId,
                ]);
                $recordId = $editId;
                $action = 'marriage_record_updated';
            }

            $pdo->prepare("DELETE FROM sacrament_participants WHERE record_id = ? AND role = 'witness'")
                ->execute([$recordId]);
            $maxOrderStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(sort_order), -1)
                 FROM sacrament_participants
                 WHERE record_id = ? AND role <> 'witness'"
            );
            $maxOrderStmt->execute([$recordId]);
            $nextOrder = (int) $maxOrderStmt->fetchColumn() + 1;
            $witnessStmt = $pdo->prepare(
                "INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
                 VALUES (?, 'witness', ?, ?, ?)"
            );
            foreach ($witnessNames as $index => $name) {
                $name = trim((string) $name);
                if ($name === '') continue;
                $residence = isset($witnessResidences[$index]) && is_string($witnessResidences[$index])
                    ? trim($witnessResidences[$index])
                    : '';
                $witnessStmt->execute([$recordId, $name, $residence !== '' ? $residence : null, $nextOrder++]);
            }

            $coupleName = marriage_identity_name($identities['groom']) . ' & ' . marriage_identity_name($identities['bride']);
            log_activity(
                $pdo,
                current_user()['id'],
                $action,
                'sacrament_records',
                $recordId,
                ($isUpdate ? 'Marriage record updated for ' : 'Marriage record encoded for ') . $coupleName
                    . (!$isUpdate && $shifted > 0 ? " - {$shifted} later entries renumbered" : '')
            );

            $applicationRequestNo = null;
            if ($linkedApplication) {
                $completed = $pdo->prepare(
                    "UPDATE sacrament_applications
                     SET status = 'completed', record_id = ?, processed_by = ?,
                         person_id = ?, spouse_person_id = ?
                     WHERE id = ? AND sacrament_type = 'marriage'
                       AND status IN ('approved', 'scheduled')"
                );
                $completed->execute([
                    $recordId, current_user()['id'], $partyIds['groom'],
                    $partyIds['bride'], (int) $linkedApplication['id'],
                ]);
                if ($completed->rowCount() !== 1) {
                    throw new RuntimeException('The marriage application could not be completed.');
                }
                $applicationRequestNo = (string) $linkedApplication['request_no'];
                log_activity(
                    $pdo,
                    current_user()['id'],
                    'application_recorded_in_register',
                    'sacrament_applications',
                    (int) $linkedApplication['id'],
                    "Marriage encoded into register (record #{$recordId}); application completed."
                );
            }
            $pdo->commit();
            if ($applicationRequestNo !== null) {
                try {
                    notify_sacrament_application_status(
                        $pdo,
                        'completed',
                        'marriage',
                        $applicationRequestNo,
                        "Marriage encoded into register (record #{$recordId})."
                    );
                } catch (Throwable $e) {
                    error_log('Marriage application completion notification failed: ' . $e->getMessage());
                }
            }
            redirect_to('/admin/records/marriage.php?' . ($isUpdate ? 'updated=1' : 'added=1')
                . (!$isUpdate && $shifted > 0 ? '&shifted=' . $shifted : '')
                . ($applicationRequestNo !== null ? '&app_completed=1' : ''));
            exit;
        } catch (MarriageSamePersonException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('SRS-SPPO marriage record save failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

$groomId = (int) ($editRecord['person_id'] ?? ($_POST['groom_person_id'] ?? 0));
$brideId = (int) ($editRecord['spouse_person_id'] ?? ($_POST['bride_person_id'] ?? 0));
if (!$groomIdentity && $groomId > 0) $groomIdentity = marriage_load_person_identity($pdo, $groomId);
if (!$brideIdentity && $brideId > 0) $brideIdentity = marriage_load_person_identity($pdo, $brideId);

$prerequisiteWarnings = [];
foreach (['groom' => $groomIdentity, 'bride' => $brideIdentity] as $party => $identity) {
    if (!$identity || empty($identity['id'])) continue;
    $baptismStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM sacrament_records
         WHERE person_id = ? AND sacrament_type = 'baptism'"
    );
    $baptismStmt->execute([(int) $identity['id']]);
    if ((int) $baptismStmt->fetchColumn() === 0
        && ($identity['baptismal_status'] ?? null) !== 'baptized_catholic') {
        $prerequisiteWarnings[] = ucfirst($party) . ' has no baptism record or baptized Catholic status recorded.';
    }
}

$suggest = $isEdit
    ? ['entry_no' => '', 'book_no' => '', 'page_no' => '', 'line_no' => '']
    : register_next_logbook_numbers($pdo, 'marriage');
$recordNameFormValues = $_POST;
$ministerParts = $isEdit ? structured_name_parts((string) ($editRecord['minister_name'] ?? '')) : ['', '', '', ''];
foreach (['minister' => $ministerParts] as $prefix => $parts) {
    foreach (['first', 'middle', 'last', 'suffix'] as $index => $part) {
        $recordNameFormValues[$prefix . '_' . $part] ??= $parts[$index];
    }
}
if (isset($_GET['added'])) $success = 'Marriage record saved.' . (isset($_GET['shifted']) ? ' Following entries were renumbered to make room.' : '') . (isset($_GET['app_completed']) ? ' The linked marriage application was marked completed.' : '');
if (isset($_GET['updated'])) $success = 'Marriage record updated.';
if (isset($_GET['deleted'])) $success = 'Marriage record deleted.';

$rfConfig = [
    'date_column' => 'mr.marriage_date',
    'search_columns' => ['mr.groom_name', 'mr.bride_name', 'mr.minister_name'],
    'placeholder' => 'Search spouse names or minister...',
    'ranges' => ['year', 'last_year', 'last5'],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$allowedSorts = ['register', 'book', 'book_desc', 'page', 'page_desc', 'line', 'line_desc', 'name', 'name_desc', 'date_desc', 'date_asc'];
$sort = $_GET['sort'] ?? 'register';
if (!in_array($sort, $allowedSorts, true)) $sort = 'register';

if ($sort === 'date_desc') {
    $orderClause = 'ORDER BY mr.marriage_date DESC, mr.groom_name ASC';
} elseif ($sort === 'date_asc') {
    $orderClause = 'ORDER BY mr.marriage_date ASC, mr.groom_name ASC';
} elseif ($sort === 'page_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(mr.page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(mr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(mr.line_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'page') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(mr.page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(mr.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(mr.line_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'line_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(mr.line_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(mr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(mr.page_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'line') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(mr.line_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(mr.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(mr.page_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'name_desc') {
    $orderClause = "ORDER BY CONCAT(mr.groom_name, ' & ', mr.bride_name) DESC, CAST(COALESCE(NULLIF(mr.book_no, ''), '0') AS UNSIGNED) DESC";
} elseif ($sort === 'name') {
    $orderClause = "ORDER BY CONCAT(mr.groom_name, ' & ', mr.bride_name) ASC, CAST(COALESCE(NULLIF(mr.book_no, ''), '0') AS UNSIGNED) ASC";
} elseif ($sort === 'book_desc') {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(mr.book_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(mr.page_no, ''), '0') AS UNSIGNED) DESC, CAST(COALESCE(NULLIF(mr.line_no, ''), '0') AS UNSIGNED) DESC";
} else {
    $orderClause = "ORDER BY CAST(COALESCE(NULLIF(mr.book_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(mr.page_no, ''), '0') AS UNSIGNED) ASC, CAST(COALESCE(NULLIF(mr.line_no, ''), '0') AS UNSIGNED) ASC";
}

function marriage_sort_url(string $key): string
{
    global $basePath, $rf, $sort;
    if ($key === 'date_desc' || $key === 'date_asc') {
        $next = $sort === 'date_desc' ? 'date_asc' : 'date_desc';
    } else {
        $next = $sort === $key ? $key . '_desc' : $key;
        if ($sort === $key . '_desc') $next = $key;
    }
    $query = array_filter([
        'q' => $rf['q'] !== '' ? $rf['q'] : null,
        'range' => $rf['range'] !== '' ? $rf['range'] : null,
        'date_from' => $rf['range'] === 'custom' ? $rf['from'] : null,
        'date_to' => $rf['range'] === 'custom' ? $rf['to'] : null,
        'per_page' => $rf['per_page'] !== 10 ? (int) $rf['per_page'] : null,
        'sort' => $next,
    ], static fn ($value) => $value !== null && $value !== '');
    $queryString = http_build_query($query);
    return $basePath . '/admin/records/marriage.php' . ($queryString !== '' ? '?' . $queryString : '');
}

function marriage_sort_indicator(string $key): string
{
    global $sort;
    if ($key === 'date_desc' || $key === 'date_asc') {
        $direction = $sort === 'date_desc' ? 'desc' : ($sort === 'date_asc' ? 'asc' : null);
    } else {
        $direction = $sort === $key ? 'asc' : ($sort === $key . '_desc' ? 'desc' : null);
    }
    if ($direction === 'asc') return ' <span class="sort-indicator" aria-hidden="true">↑</span>';
    if ($direction === 'desc') return ' <span class="sort-indicator" aria-hidden="true">↓</span>';
    return ' <span class="sort-indicator sort-indicator-muted" aria-hidden="true">↕</span>';
}

$marriageRowsSql = "
    SELECT sr.id, sr.person_id, sr.spouse_person_id, sr.event_date AS marriage_date,
           sr.place, sr.minister_name, sr.entry_no, sr.book_no, sr.page_no, sr.line_no,
           sr.details, sr.remarks,
           CONCAT_WS(' ', groom.first_name, groom.middle_name, groom.last_name, groom.suffix) AS groom_name,
           groom.first_name AS groom_first_name, groom.middle_name AS groom_middle_name,
           groom.last_name AS groom_last_name, groom.suffix AS groom_suffix,
           CONCAT_WS(' ', bride.first_name, bride.middle_name, bride.last_name, bride.suffix) AS bride_name,
           bride.first_name AS bride_first_name, bride.middle_name AS bride_middle_name,
           bride.last_name AS bride_last_name, bride.suffix AS bride_suffix,
           groom.id AS groom_linked_id, bride.id AS bride_linked_id
    FROM sacrament_records sr
    JOIN persons groom ON groom.id = sr.person_id
    LEFT JOIN persons bride ON bride.id = sr.spouse_person_id
    WHERE sr.sacrament_type = 'marriage'";
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM ({$marriageRowsSql}) mr
     WHERE 1 = 1 {$rf['where']}"
);
$countStmt->execute($rf['params']);
$rfTotal = (int) $countStmt->fetchColumn();
$listStmt = $pdo->prepare(
    "SELECT mr.id, mr.person_id, mr.spouse_person_id, mr.marriage_date, mr.place,
            mr.minister_name, mr.entry_no, mr.book_no, mr.page_no, mr.line_no,
            mr.details, mr.remarks, mr.groom_name, mr.groom_first_name,
            mr.groom_middle_name, mr.groom_last_name, mr.groom_suffix,
            mr.bride_name, mr.bride_first_name, mr.bride_middle_name,
            mr.bride_last_name, mr.bride_suffix, mr.groom_linked_id, mr.bride_linked_id
     FROM ({$marriageRowsSql}) mr
     WHERE 1 = 1 {$rf['where']} {$orderClause}
     LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$listStmt->execute($rf['params']);
$records = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$isSecretary = (current_user()['role'] ?? '') === 'secretary';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <h1>Marriage Records</h1>
            <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/index.php') ?>">Back to All Records</a>
            <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
        </div>
        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php rf_render_bar($basePath . '/admin/records/marriage.php', $rfConfig, $rf); ?>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Marriage register records; scroll horizontally to view all columns">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= $sort === 'book' ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url('book')) ?>">Book<?= marriage_sort_indicator('book') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'page' ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url('page')) ?>">Page<?= marriage_sort_indicator('page') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'line' ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url('line')) ?>">Line<?= marriage_sort_indicator('line') ?></a></th>
                    <th><a class="table-sort-link<?= $sort === 'name' ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url('name')) ?>">Full Name<?= marriage_sort_indicator('name') ?></a></th>
                    <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(marriage_sort_url($sort === 'date_desc' ? 'date_asc' : 'date_desc')) ?>">Date of Marriage<?= marriage_sort_indicator($sort === 'date_desc' || $sort === 'date_asc' ? $sort : 'date_desc') ?></a></th>
                    <th>Linked</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($records === []): ?>
                    <tr><td colspan="7" class="empty-table-row"><div class="empty-state"><h2>No marriage records found</h2><p><?= $rf['q'] !== '' ? 'No matches for "' . e($rf['q']) . '".' : 'New records will appear here after they are encoded.' ?></p></div></td></tr>
                <?php else: ?>
                    <?php foreach ($records as $rec): ?>
                        <?php
                        $groomName = display_name_with_middle_initial(
                            (string) $rec['groom_name'],
                            (string) ($rec['groom_first_name'] ?? ''),
                            (string) ($rec['groom_middle_name'] ?? ''),
                            (string) ($rec['groom_last_name'] ?? ''),
                            (string) ($rec['groom_suffix'] ?? '')
                        );
                        $brideName = display_name_with_middle_initial(
                            (string) $rec['bride_name'],
                            (string) ($rec['bride_first_name'] ?? ''),
                            (string) ($rec['bride_middle_name'] ?? ''),
                            (string) ($rec['bride_last_name'] ?? ''),
                            (string) ($rec['bride_suffix'] ?? '')
                        );
                        $groomLinked = !empty($rec['groom_linked_id']);
                        $brideLinked = !empty($rec['bride_linked_id']);
                        $linkStatus = ($groomLinked && $brideLinked) ? 'linked' : (($groomLinked || $brideLinked) ? 'partial' : 'unlinked');
                        $linkLabels = ['linked' => 'Linked', 'partial' => 'Partially linked', 'unlinked' => 'Unlinked'];
                        ?>
                        <tr>
                            <td><?= e($rec['book_no'] ?: '-') ?></td>
                            <td><?= e($rec['page_no'] ?: '-') ?></td>
                            <td><?= e($rec['line_no'] ?: '-') ?></td>
                            <td><strong><?= e($groomName . ' & ' . $brideName) ?></strong></td>
                            <td><?= e(date('M j, Y', strtotime((string) $rec['marriage_date']))) ?></td>
                            <td><span class="link-status-badge link-status-<?= e($linkStatus) ?>"><?= e($linkLabels[$linkStatus]) ?></span></td>
                            <td class="row-actions">
                                <a href="<?= e($basePath . '/admin/records/view.php?type=marriage&id=' . (int) $rec['id'] . '&return=' . urlencode($selfReturn)) ?>" class="btn btn-secondary btn-small">View record</a>
                                <a href="<?= e($basePath . '/admin/records/marriage.php?edit=' . (int) $rec['id']) ?>" class="btn btn-secondary btn-small">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($basePath . '/admin/records/marriage.php', $rfTotal, $rf); ?>

        <dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || $errors !== [] || $fromApplicationId) ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $isEdit ? 'Edit Entry #' . (int) $editRecord['id'] : 'Encode New Entry' ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>
            <div class="dialog-body">
                <?php if ($isEdit): ?><p class="field-hint"><a href="<?= e($basePath . '/admin/records/marriage.php') ?>">Cancel edit - back to adding a new entry.</a></p><?php endif; ?>
                <?php if ($errors !== []): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <?php if ($prerequisiteWarnings !== []): ?><div class="alert alert-warning"><p>Prerequisite warnings (these do not prevent saving):</p><ul><?php foreach ($prerequisiteWarnings as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form autocomplete="off" method="post" action="" data-review data-review-title="Review Marriage Entry" data-review-confirm="Confirm and Save">
                    <?= csrf_field() ?>
                    <div class="alert alert-error" data-same-person-error role="alert" hidden>The groom and bride must be different persons.</div>
                    <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
                    <input type="hidden" name="entry_no" value="<?= e($_POST['entry_no'] ?? $editRecord['entry_no'] ?? $suggest['entry_no']) ?>">
                    <?php if ($fromApplicationId): ?>
                        <input type="hidden" name="from_application" value="<?= (int) $fromApplicationId ?>">
                        <p class="field-hint">Pre-filled from marriage application #<?= (int) $fromApplicationId ?>. Saving marks the application completed.</p>
                    <?php endif; ?>
                    <fieldset>
                        <legend>Logbook Reference</legend>
                        <div class="inline-fields">
                            <label>Book No. <input autocomplete="off" type="text" name="book_no" value="<?= e($_POST['book_no'] ?? $editRecord['book_no'] ?? $suggest['book_no']) ?>"<?= !$isEdit ? ' readonly' : '' ?>></label>
                            <label>Page <input autocomplete="off" type="text" name="page_no" value="<?= e($_POST['page_no'] ?? $editRecord['page_no'] ?? $suggest['page_no']) ?>"<?= !$isEdit ? ' readonly' : '' ?>></label>
                            <label>Line <input autocomplete="off" type="text" name="line_no" value="<?= e($_POST['line_no'] ?? $editRecord['line_no'] ?? $suggest['line_no']) ?>"<?= !$isEdit ? ' readonly' : '' ?>></label>
                        </div>
                    </fieldset>
                    <?php foreach ([
                        'groom' => ['label' => 'Groom', 'identity' => $groomIdentity, 'id' => $groomId],
                        'bride' => ['label' => 'Bride', 'identity' => $brideIdentity, 'id' => $brideId],
                    ] as $party => $partyData): ?>
                        <?php
                        $identity = $partyData['identity'];
                        $linked = $partyData['id'] > 0;
                        $partyParts = $identity
                            ? [(string) ($identity['first_name'] ?? ''), (string) ($identity['middle_name'] ?? ''), (string) ($identity['last_name'] ?? ''), (string) ($identity['suffix'] ?? '')]
                            : [
                                (string) ($_POST[$party . '_first_name'] ?? ''),
                                (string) ($_POST[$party . '_middle_name'] ?? ''),
                                (string) ($_POST[$party . '_last_name'] ?? ''),
                                (string) ($_POST[$party . '_suffix'] ?? ''),
                            ];
                        ?>
                        <fieldset>
                            <legend><?= e($partyData['label']) ?></legend>
                            <?php if ($linked): ?>
                                <div class="field-hint">
                                    <p><strong><?= e($partyData['label']) ?> identity (read-only)</strong></p>
                                    <p>Name: <?= e($identity ? marriage_identity_name($identity) : 'Person profile #' . $partyData['id'] . ' could not be loaded') ?></p>
                                    <p>Birth date: <?= e((string) ($identity['birth_date'] ?? 'Not recorded')) ?></p>
                                    <p>Birth place: <?= e((string) ($identity['birth_place'] ?? 'Not recorded')) ?></p>
                                    <p>Father: <?= e((string) ($identity['father_name'] ?? 'Not recorded')) ?></p>
                                    <p>Mother: <?= e((string) ($identity['mother_maiden_name'] ?? 'Not recorded')) ?></p>
                                </div>
                            <?php else: ?>
                                <div class="inline-fields structured-name-fields">
                                    <label>First Name <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="<?= e($party) ?>_first_name" value="<?= e($_POST[$party . '_first_name'] ?? $partyParts[0]) ?>" required></label>
                                    <label>Middle Name <span class="field-hint">(optional)</span> <input autocomplete="off" type="text" name="<?= e($party) ?>_middle_name" value="<?= e($_POST[$party . '_middle_name'] ?? $partyParts[1]) ?>"></label>
                                    <label>Surname <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="text" name="<?= e($party) ?>_last_name" value="<?= e($_POST[$party . '_last_name'] ?? $partyParts[2]) ?>" required></label>
                                    <label>Suffix <input autocomplete="off" type="text" name="<?= e($party) ?>_suffix" value="<?= e($_POST[$party . '_suffix'] ?? $partyParts[3]) ?>"></label>
                                </div>
                                <div class="inline-fields">
                                    <label>Birth date <input autocomplete="off" type="date" name="<?= e($party) ?>_birth_date" value="<?= e($_POST[$party . '_birth_date'] ?? '') ?>"></label>
                                    <label>Birth place <input autocomplete="off" type="text" name="<?= e($party) ?>_birth_place" value="<?= e($_POST[$party . '_birth_place'] ?? '') ?>"></label>
                                </div>
                                <?= person_name_fields($party . '_father', $partyData['label'] . "'s Father", $_POST, false) ?>
                                <?= person_name_fields($party . '_mother', $partyData['label'] . "'s Mother", $_POST, false) ?>
                                <div class="person-finder" data-person-finder data-name-prefix="<?= e($party) ?>" data-birth-field="<?= e($party) ?>_birth_date" data-birth-place-field="<?= e($party) ?>_birth_place" data-endpoint="<?= e($basePath . '/admin/api/person-search.php') ?>">
                                    <label>Find an existing person
                                        <input autocomplete="off" type="search" data-person-search placeholder="Search the master person index">
                                    </label>
                                    <button type="button" class="btn btn-secondary btn-small" data-person-find>Find person</button>
                                    <input type="hidden" name="<?= e($party) ?>_person_id" data-person-id value="<?= e($_POST[$party . '_person_id'] ?? '') ?>">
                                    <div class="person-finder-selection" data-person-selection aria-live="polite"></div>
                                    <div class="person-finder-results" data-person-results></div>
                                    <p class="field-hint">If there is no match, saving creates a master person profile for this party.</p>
                                </div>
                            <?php endif; ?>
                        </fieldset>
                    <?php endforeach; ?>
                    <fieldset>
                        <legend>Marriage Details</legend>
                        <label>Marriage Date <span class="required-marker" aria-hidden="true">*</span> <input autocomplete="off" type="date" name="marriage_date" value="<?= e($_POST['marriage_date'] ?? $editRecord['marriage_date'] ?? '') ?>" required></label>
                        <label>Place <input autocomplete="off" type="text" name="place" maxlength="150" value="<?= e($_POST['place'] ?? $editRecord['place'] ?? 'St. Patrick Parish') ?>"></label>
                        <?php foreach (['groom' => 'Groom', 'bride' => 'Bride'] as $party => $label): ?>
                            <?php
                            $details = json_decode((string) ($editRecord['details'] ?? ''), true);
                            $details = is_array($details) ? $details : [];
                            $partyDetails = is_array($details[$party] ?? null) ? $details[$party] : [];
                            ?>
                            <h3><?= e($label) ?> register details</h3>
                            <div class="inline-fields">
                                <label>Civil Status <input autocomplete="off" type="text" name="<?= e($party) ?>_civil_status" value="<?= e($_POST[$party . '_civil_status'] ?? $partyDetails['civil_status'] ?? '') ?>"></label>
                                <label>Age <input autocomplete="off" type="number" min="0" max="120" name="<?= e($party) ?>_age" value="<?= e($_POST[$party . '_age'] ?? $partyDetails['age'] ?? '') ?>"></label>
                            </div>
                            <div class="inline-fields">
                                <label>Origin <input autocomplete="off" type="text" name="<?= e($party) ?>_origin" value="<?= e($_POST[$party . '_origin'] ?? $partyDetails['origin'] ?? '') ?>"></label>
                                <label>Residence <input autocomplete="off" type="text" name="<?= e($party) ?>_residence" value="<?= e($_POST[$party . '_residence'] ?? $partyDetails['residence'] ?? '') ?>"></label>
                            </div>
                        <?php endforeach; ?>
                        <label>Marriage License Reference <input autocomplete="off" type="text" name="license_ref" value="<?= e($_POST['license_ref'] ?? $details['license_ref'] ?? '') ?>"></label>
                        <?= person_name_fields('minister', 'Officiating Minister', $recordNameFormValues, false) ?>
                    </fieldset>
                    <?php
                    $witnessRows = array_key_exists('witness_first', $_POST)
                        ? array_fill(0, person_name_row_count($_POST, 'witness'), '')
                        : array_column($editWitnesses, 'full_name');
                    $witnessResidences = $_POST['witness_residence'] ?? array_column($editWitnesses, 'residence');
                    if (!is_array($witnessRows)) $witnessRows = [];
                    if (!is_array($witnessResidences)) $witnessResidences = [];
                    $witnessRows = array_values($witnessRows);
                    $witnessResidences = array_values($witnessResidences);
                    if ($witnessRows === []) $witnessRows = [''];
                    $witnessFormValues = $_POST;
                    foreach ($witnessRows as $index => $rowName) {
                        $parts = structured_name_parts(is_string($rowName) ? $rowName : '');
                        foreach (['first', 'middle', 'last', 'suffix'] as $partIndex => $part) {
                            $witnessFormValues['witness_' . $part][$index] ??= $parts[$partIndex];
                        }
                    }
                    ?>
                    <fieldset>
                        <legend>Witnesses</legend>
                        <div data-repeat-group="witness" data-repeat-max="2">
                            <?php foreach ($witnessRows as $index => $rowName): ?>
                                <div class="godparent-row">
                                    <div class="inline-fields">
                                        <?= person_name_fields('witness', 'Witness Name', $witnessFormValues, false, $index) ?>
                                        <label>Residence <input autocomplete="off" type="text" name="witness_residence[]" value="<?= e(isset($witnessResidences[$index]) && is_string($witnessResidences[$index]) ? $witnessResidences[$index] : '') ?>"></label>
                                    </div>
                                    <button type="button" class="link-button repeat-remove" data-repeat-remove<?= $index === 0 ? ' hidden' : '' ?>>Remove</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-secondary repeat-add" data-repeat-add="witness">Add another witness</button>
                    </fieldset>
                    <fieldset>
                        <legend>Other</legend>
                        <label>Remarks <textarea autocomplete="off" name="remarks"><?= e($_POST['remarks'] ?? $editRecord['remarks'] ?? '') ?></textarea></label>
                    </fieldset>
                    <?php if ($isEdit): ?>
                        <button type="submit" name="update_record" value="1" class="btn btn-primary">Update Record</button>
                    <?php else: ?>
                        <button type="submit" name="add_record" value="1" class="btn">Save Record</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
                </form>
            </div>
        </dialog>
        <script>
            (function () {
                var form = document.querySelector('#encode-dialog form[data-review]');
                if (!form) return;
                var message = form.querySelector('[data-same-person-error]');
                function checkSamePerson(event) {
                    if (form.getAttribute('data-reviewed') === '1') return;
                    var groomId = (form.querySelector('[name="groom_person_id"]') || {}).value || '';
                    var brideId = (form.querySelector('[name="bride_person_id"]') || {}).value || '';
                    if (groomId !== '' && groomId === brideId) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        message.hidden = false;
                    } else {
                        message.hidden = true;
                    }
                }
                form.addEventListener('submit', checkSamePerson, true);
                form.addEventListener('input', function () { message.hidden = true; });
                form.addEventListener('change', function () { message.hidden = true; });
            })();
        </script>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
