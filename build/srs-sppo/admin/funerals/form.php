<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/person-linking.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';
require_once __DIR__ . '/../../includes/funeral-data.php';
require_role('secretary');

$basePath = base_path();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$rawId = $isPost ? ($_POST['id'] ?? $_GET['id'] ?? '') : ($_GET['id'] ?? '');
$rawId = is_scalar($rawId) ? (string) $rawId : '';
$editId = 0;
if ((string) $rawId !== '') {
    $validatedId = filter_var($rawId, FILTER_VALIDATE_INT);
    if ($validatedId === false || $validatedId < 1) {
        redirect_to('/admin/funerals/index.php');
        exit;
    }
    $editId = (int) $validatedId;
}

$editRecord = null;
if ($editId > 0) {
    $recordStmt = $pdo->prepare(
        'SELECT fr.*, p.first_name, p.middle_name, p.last_name, p.suffix,
                p.birth_date, p.birth_place, p.father_name, p.mother_maiden_name
         FROM funeral_records fr
         JOIN persons p ON p.id = fr.person_id
         WHERE fr.id = ?
         LIMIT 1'
    );
    $recordStmt->execute([$editId]);
    $editRecord = $recordStmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRecord) {
        redirect_to('/admin/funerals/index.php');
        exit;
    }
}

$isEdit = $editRecord !== null;
$createMode = $isPost
    ? (funeral_input_string($_POST['create_person'] ?? '') === '1' && !$isEdit)
    : false;
$errors = [];
$duplicateFuneralId = 0;
$newPersonMatches = [];

$personId = 0;
$invalidPersonId = false;
if ($isPost) {
    if (!$createMode) {
        $rawPersonId = funeral_input_string($_POST['person_id'] ?? '');
        if ($rawPersonId !== '') {
            $validatedPersonId = filter_var($rawPersonId, FILTER_VALIDATE_INT);
            if ($validatedPersonId === false || $validatedPersonId < 1) {
                $invalidPersonId = true;
            } else {
                $personId = (int) $validatedPersonId;
            }
        }
    }
} elseif ($isEdit) {
    $personId = (int) $editRecord['person_id'];
} else {
    $requestedPersonId = filter_var($_GET['person_id'] ?? null, FILTER_VALIDATE_INT);
    $personId = $requestedPersonId !== false && $requestedPersonId !== null && $requestedPersonId > 0
        ? (int) $requestedPersonId
        : 0;
}

$selectedPerson = null;
if ($personId > 0 && !$createMode) {
    $personStmt = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, suffix, birth_date, birth_place,
                father_name, mother_maiden_name
         FROM persons
         WHERE id = ?
         LIMIT 1'
    );
    $personStmt->execute([$personId]);
    $selectedPerson = $personStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$marriageSuggestion = $selectedPerson
    ? funeral_person_marriage_suggestion($pdo, $personId)
    : ['has_marriage_record' => false, 'spouse_name' => ''];

$newPerson = [
    'first_name' => funeral_input_string($_POST['new_first_name'] ?? ''),
    'middle_name' => funeral_input_string($_POST['new_middle_name'] ?? ''),
    'last_name' => funeral_input_string($_POST['new_last_name'] ?? ''),
    'suffix' => funeral_input_string($_POST['new_suffix'] ?? ''),
    'birth_date' => funeral_input_string($_POST['new_birth_date'] ?? ''),
    'birth_place' => funeral_input_string($_POST['new_birth_place'] ?? ''),
    'father_name' => funeral_input_string($_POST['new_father_name'] ?? ''),
    'mother_maiden_name' => funeral_input_string($_POST['new_mother_maiden_name'] ?? ''),
];

$sacramentOptions = ['baptism', 'confirmation', 'marriage', 'anointing'];
$savedSacraments = [];
if ($isEdit) {
    $decodedSacraments = json_decode((string) ($editRecord['sacraments_received'] ?? ''), true);
    if (is_array($decodedSacraments)) {
        foreach ($sacramentOptions as $sacrament) {
            if (in_array($sacrament, $decodedSacraments, true) || !empty($decodedSacraments[$sacrament])) {
                $savedSacraments[] = $sacrament;
            }
        }
    } elseif (empty($editRecord['sacraments_received']) && $selectedPerson) {
        $savedSacraments = funeral_suggested_sacraments($pdo, $personId);
    }
} elseif ($selectedPerson) {
    $savedSacraments = funeral_suggested_sacraments($pdo, $personId);
}

$values = [
    'date_of_death' => (string) ($editRecord['date_of_death'] ?? ''),
    'date_of_burial' => (string) ($editRecord['date_of_burial'] ?? ''),
    'age_at_death' => ($editRecord['age_at_death'] ?? null) === null
        ? ''
        : (string) $editRecord['age_at_death'],
    'civil_status' => (string) ($editRecord['civil_status'] ?? ''),
    'spouse_name' => (string) ($editRecord['spouse_name'] ?? ''),
    'parents_name' => (string) ($editRecord['parents_name'] ?? ''),
    'sacraments_received' => $savedSacraments,
    'cause_of_death' => (string) ($editRecord['cause_of_death'] ?? ''),
    'place_of_burial' => (string) ($editRecord['place_of_burial'] ?? ''),
    'service_type' => (string) ($editRecord['service_type'] ?? ''),
    'service_date' => (string) ($editRecord['service_date'] ?? ''),
    'service_time' => substr((string) ($editRecord['service_time'] ?? ''), 0, 5),
    'minister_name' => (string) ($editRecord['minister_name'] ?? ''),
    'book_no' => (string) ($editRecord['book_no'] ?? ''),
    'page_no' => (string) ($editRecord['page_no'] ?? ''),
    'line_no' => (string) ($editRecord['line_no'] ?? ''),
    'contact_number' => (string) ($editRecord['contact_number'] ?? ''),
    'contact_email' => (string) ($editRecord['contact_email'] ?? ''),
];
if ($isPost) {
    foreach ([
        'date_of_death', 'date_of_burial', 'age_at_death', 'civil_status', 'spouse_name',
        'parents_name', 'cause_of_death', 'place_of_burial', 'service_type', 'service_date',
        'service_time', 'minister_name', 'book_no', 'page_no', 'line_no', 'contact_number',
        'contact_email',
    ] as $field) {
        $values[$field] = funeral_input_string($_POST[$field] ?? '');
    }
    $postedSacraments = $_POST['sacraments_received'] ?? [];
    $values['sacraments_received'] = is_array($postedSacraments)
        ? array_values(array_intersect(
            $sacramentOptions,
            array_map(
                static fn($value): string => is_scalar($value) ? (string) $value : '',
                $postedSacraments
            )
        ))
        : [];
} elseif ($selectedPerson) {
    if (!$isEdit) {
        $parents = [];
        if (trim((string) ($selectedPerson['father_name'] ?? '')) !== '') {
            $parents[] = 'Father: ' . trim((string) $selectedPerson['father_name']);
        }
        if (trim((string) ($selectedPerson['mother_maiden_name'] ?? '')) !== '') {
            $parents[] = 'Mother: ' . trim((string) $selectedPerson['mother_maiden_name']);
        }
        $values['parents_name'] = implode('; ', $parents);
        $values['spouse_name'] = $marriageSuggestion['spouse_name'];
        $values['civil_status'] = $marriageSuggestion['has_marriage_record'] ? 'married' : '';
    } else {
        if ($values['spouse_name'] === '') {
            $values['spouse_name'] = $marriageSuggestion['spouse_name'];
        }
        if ($values['civil_status'] === '' && $marriageSuggestion['has_marriage_record']) {
            $values['civil_status'] = 'married';
        }
    }
}

$newPersonBirthDate = null;
if ($createMode && $newPerson['birth_date'] !== '') {
    $newPersonBirthDate = funeral_parse_date($newPerson['birth_date']);
}
$personBirthDate = $createMode
    ? ($newPersonBirthDate ? $newPerson['birth_date'] : null)
    : (is_array($selectedPerson) && !empty($selectedPerson['birth_date'])
        ? (string) $selectedPerson['birth_date']
        : null);
$existingServiceDate = (string) ($editRecord['service_date'] ?? '');
$existingServiceTime = substr((string) ($editRecord['service_time'] ?? ''), 0, 5);
$serviceScheduleUnchanged = $isEdit
    && $values['service_date'] === $existingServiceDate
    && $values['service_time'] === $existingServiceTime;

if ($isPost) {
    if (!csrf_verify()) {
        $errors[] = 'Your session token is invalid or expired. Refresh the page and try again.';
    } else {
        foreach ([
            'parents_name' => 'parents name',
            'cause_of_death' => 'cause of death',
            'place_of_burial' => 'place of burial',
            'minister_name' => 'minister name',
            'book_no' => 'book number',
            'page_no' => 'page number',
            'line_no' => 'line number',
            'contact_number' => 'contact number',
            'contact_email' => 'family contact email address',
            'new_first_name' => 'new person first name',
            'new_middle_name' => 'new person middle name',
            'new_last_name' => 'new person last name',
            'new_suffix' => 'new person suffix',
            'new_birth_date' => 'new person birth date',
            'new_birth_place' => 'new person birthplace',
            'new_father_name' => 'new person father name',
            'new_mother_maiden_name' => 'new person mother name',
        ] as $field => $label) {
            if (array_key_exists($field, $_POST) && !is_scalar($_POST[$field])) {
                $errors[] = "Enter a valid {$label}.";
            }
        }

        if ($createMode) {
            if ($newPerson['first_name'] === '' || $newPerson['last_name'] === '') {
                $errors[] = 'Enter the new person\'s first and last name.';
            }
            if ($newPerson['birth_date'] !== '' && $newPersonBirthDate === null) {
                $errors[] = 'Enter a valid date of birth for the new person.';
            }
            if ($newPerson['first_name'] !== '' && $newPerson['last_name'] !== '') {
                $newPersonMatches = sd_find_possible_person_matches($pdo, [
                    'first_name' => $newPerson['first_name'],
                    'middle_name' => $newPerson['middle_name'],
                    'last_name' => $newPerson['last_name'],
                    'suffix' => $newPerson['suffix'],
                    'birth_date' => $newPersonBirthDate ? $newPerson['birth_date'] : '',
                ]);
                if ($newPersonMatches !== [] && funeral_input_string($_POST['confirm_new_person'] ?? '') !== '1') {
                    $errors[] = 'Possible existing profiles match this person. Review them before creating a new profile.';
                }
            }
        } elseif (!$selectedPerson) {
            $errors[] = $invalidPersonId
                ? 'Select a valid person profile or create a new profile for the deceased.'
                : 'Search for an existing profile or choose "Create a person profile" to enter the deceased before saving.';
        } else {
            $duplicateCheck = $pdo->prepare(
                'SELECT id FROM funeral_records WHERE person_id = ? AND id <> ? LIMIT 1'
            );
            $duplicateCheck->execute([$personId, $editId]);
            $duplicateFuneralId = (int) ($duplicateCheck->fetchColumn() ?: 0);
            if ($duplicateFuneralId > 0) {
                $errors[] = 'This person already has a death record.';
            }
        }

        $errors = array_merge($errors, funeral_validate($_POST, $personBirthDate));
        if (($values['service_date'] === '') !== ($values['service_time'] === '')
            && !$serviceScheduleUnchanged) {
            $errors[] = 'Choose both a service date and an available service time.';
        }
        if (($emailError = funeral_contact_email_error($values['contact_email'])) !== null) {
            $errors[] = $emailError;
        }

        $lengthLimits = [
            'spouse_name' => 150,
            'parents_name' => 255,
            'cause_of_death' => 255,
            'place_of_burial' => 150,
            'minister_name' => 150,
            'book_no' => 20,
            'page_no' => 20,
            'line_no' => 20,
            'contact_number' => 30,
        ];
        foreach ($lengthLimits as $field => $limit) {
            if (strlen($values[$field]) > $limit) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be {$limit} characters or fewer.";
            }
        }
        foreach ([
            'first_name' => 150,
            'middle_name' => 100,
            'last_name' => 100,
            'suffix' => 20,
            'birth_place' => 150,
            'father_name' => 150,
            'mother_maiden_name' => 150,
        ] as $field => $limit) {
            if ($createMode && strlen($newPerson[$field]) > $limit) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be {$limit} characters or fewer.";
            }
        }

        if ($errors === []) {
            $scheduleEmailDue = funeral_schedule_notification_due($isEdit ? $editRecord : null, $values);
            $targetPersonId = $personId;
            try {
                $pdo->beginTransaction();
                if ($createMode) {
                    $targetPersonId = save_person_identity($pdo, null, $newPerson);
                }

                $oldPersonId = 0;
                if ($isEdit) {
                    $editLock = $pdo->prepare('SELECT person_id FROM funeral_records WHERE id = ? FOR UPDATE');
                    $editLock->execute([$editId]);
                    $lockedPersonId = $editLock->fetchColumn();
                    if ($lockedPersonId === false) {
                        throw new RuntimeException('The death record no longer exists.');
                    }
                    $oldPersonId = (int) $lockedPersonId;
                }

                $duplicateStmt = $pdo->prepare(
                    'SELECT id FROM funeral_records WHERE person_id = ? AND id <> ? LIMIT 1 FOR UPDATE'
                );
                $duplicateStmt->execute([$targetPersonId, $editId]);
                $duplicateFuneralId = (int) ($duplicateStmt->fetchColumn() ?: 0);
                if ($duplicateFuneralId > 0) {
                    $pdo->rollBack();
                    $errors[] = 'This person already has a death record.';
                } else {
                    if ($values['service_date'] !== '' && !$serviceScheduleUnchanged) {
                        $serviceEndTime = date(
                            'H:i',
                            strtotime('1970-01-01 ' . $values['service_time'] . ':00') + 60 * 60
                        );
                        if (!parish_schedule_slot_is_available(
                            $pdo,
                            $values['service_date'],
                            $values['service_time'],
                            $serviceEndTime,
                            0,
                            $editId
                        )) {
                            throw new InvalidArgumentException('That 60-minute service time is no longer available. Choose an open time from the schedule list.');
                        }
                    }

                    $nullable = static fn(string $key): ?string => $values[$key] === '' ? null : $values[$key];
                    $age = $values['age_at_death'] === '' ? null : (int) $values['age_at_death'];
                    $sacramentsJson = json_encode(
                        $values['sacraments_received'],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                    );
                    $recordValues = [
                        $targetPersonId,
                        $values['date_of_death'],
                        $nullable('date_of_burial'),
                        $age,
                        $nullable('civil_status'),
                        $nullable('spouse_name'),
                        $nullable('parents_name'),
                        $sacramentsJson,
                        $nullable('cause_of_death'),
                        $nullable('place_of_burial'),
                        $values['service_type'],
                        $nullable('service_date'),
                        $nullable('service_time'),
                        $nullable('minister_name'),
                        $nullable('book_no'),
                        $nullable('page_no'),
                        $nullable('line_no'),
                        $nullable('contact_number'),
                        $nullable('contact_email'),
                    ];

                    if ($isEdit) {
                        $update = $pdo->prepare(
                            'UPDATE funeral_records
                             SET person_id = ?, date_of_death = ?, date_of_burial = ?, age_at_death = ?,
                                 civil_status = ?, spouse_name = ?, parents_name = ?, sacraments_received = ?,
                                 cause_of_death = ?, place_of_burial = ?, service_type = ?, service_date = ?,
                                 service_time = ?, minister_name = ?, book_no = ?, page_no = ?, line_no = ?,
                                 contact_number = ?, contact_email = ?
                             WHERE id = ?'
                        );
                        $update->execute(array_merge($recordValues, [$editId]));
                        $recordId = $editId;
                        if ($oldPersonId !== $targetPersonId) {
                            $pdo->prepare('UPDATE persons SET is_deceased = 0, death_date = NULL WHERE id = ?')
                                ->execute([$oldPersonId]);
                        }
                    } else {
                        $insert = $pdo->prepare(
                            'INSERT INTO funeral_records
                                (person_id, date_of_death, date_of_burial, age_at_death, civil_status,
                                 spouse_name, parents_name, sacraments_received, cause_of_death, place_of_burial,
                                 service_type, service_date, service_time, minister_name, book_no, page_no,
                                 line_no, contact_number, contact_email, encoded_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $insert->execute(array_merge($recordValues, [(int) current_user()['id']]));
                        $recordId = (int) $pdo->lastInsertId();
                    }

                    $pdo->prepare('UPDATE persons SET is_deceased = 1, death_date = ? WHERE id = ?')
                        ->execute([$values['date_of_death'], $targetPersonId]);

                    log_activity(
                        $pdo,
                        (int) current_user()['id'],
                        $isEdit ? 'funeral_record_updated' : 'funeral_record_created',
                        'funeral_records',
                        $recordId,
                        'Funeral record ' . ($isEdit ? 'updated' : 'created') . " for person #{$targetPersonId}."
                    );
                    $pdo->commit();

                    $scheduleEmailStatus = null;
                    if ($scheduleEmailDue) {
                        $nameParts = $createMode
                            ? [$newPerson['first_name'], $newPerson['middle_name'], $newPerson['last_name'], $newPerson['suffix']]
                            : [
                                $selectedPerson['first_name'] ?? '',
                                $selectedPerson['middle_name'] ?? '',
                                $selectedPerson['last_name'] ?? '',
                                $selectedPerson['suffix'] ?? '',
                            ];
                        $notificationPersonName = trim(implode(' ', array_filter(
                            $nameParts,
                            static fn(string $part): bool => trim($part) !== ''
                        )));
                        try {
                            $scheduleEmailStatus = mail_death_record_schedule_update(
                                $values['contact_email'],
                                $notificationPersonName,
                                $values['service_type'],
                                $values['service_date'],
                                $values['service_time'],
                                $pdo,
                                $recordId
                            ) ? 'sent' : 'failed';
                        } catch (InvalidArgumentException $e) {
                            error_log('Death record schedule email failed: ' . $e->getMessage());
                            $scheduleEmailStatus = 'failed';
                        }
                    }

                    $redirectQuery = $isEdit ? 'updated=1' : 'added=1';
                    if ($scheduleEmailStatus !== null) {
                        $redirectQuery .= '&schedule_email=' . $scheduleEmailStatus;
                    }
                    redirect_to('/admin/funerals/index.php?' . $redirectQuery);
                    exit;
                }
            } catch (InvalidArgumentException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getCode() === '23000') {
                    $duplicateLookup = $pdo->prepare('SELECT id FROM funeral_records WHERE person_id = ? LIMIT 1');
                    $duplicateLookup->execute([$targetPersonId]);
                    $duplicateFuneralId = (int) ($duplicateLookup->fetchColumn() ?: 0);
                    if ($duplicateFuneralId > 0 && $duplicateFuneralId !== $editId) {
                        $errors[] = 'This person already has a death record.';
                    } else {
                        error_log('Funeral record save failed: ' . $e->getMessage());
                        $errors[] = 'Could not save this death record. Check the server error log.';
                    }
                } else {
                    error_log('Funeral record save failed: ' . $e->getMessage());
                    $errors[] = 'Could not save this death record. Check the server error log.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Funeral record save failed: ' . $e->getMessage());
                $errors[] = 'Could not save this death record. Check the server error log.';
            }
        }
    }
}

$ageIsAuto = trim((string) ($_POST['age_at_death'] ?? '')) === ''
    && (!$isEdit || $editRecord['age_at_death'] === null);
if ($values['age_at_death'] === '' && $personBirthDate !== null) {
    $calculatedAge = funeral_calculated_age($personBirthDate, $values['date_of_death']);
    if ($calculatedAge !== null) {
        $values['age_at_death'] = (string) $calculatedAge;
        $ageIsAuto = true;
    }
}

$formatParents = static function (?array $person): string {
    if ($person === null) {
        return '';
    }
    $parents = [];
    if (trim((string) ($person['father_name'] ?? '')) !== '') {
        $parents[] = 'Father: ' . trim((string) $person['father_name']);
    }
    if (trim((string) ($person['mother_maiden_name'] ?? '')) !== '') {
        $parents[] = 'Mother: ' . trim((string) $person['mother_maiden_name']);
    }
    return implode('; ', $parents);
};

if (!$isPost && $isEdit && $values['parents_name'] === '') {
    $values['parents_name'] = $formatParents($selectedPerson);
}
$personDisplayName = $createMode
    ? trim(implode(' ', array_filter([
        $newPerson['first_name'], $newPerson['middle_name'], $newPerson['last_name'], $newPerson['suffix'],
    ])))
    : ($selectedPerson ? trim(implode(' ', array_filter([
        $selectedPerson['first_name'], $selectedPerson['middle_name'], $selectedPerson['last_name'], $selectedPerson['suffix'],
    ]))) : '');
$supportNames = [
    'person_first_name' => $selectedPerson['first_name'] ?? '',
    'person_middle_name' => $selectedPerson['middle_name'] ?? '',
    'person_last_name' => $selectedPerson['last_name'] ?? '',
    'person_suffix' => $selectedPerson['suffix'] ?? '',
    'birth_date' => $selectedPerson['birth_date'] ?? ($createMode ? $newPerson['birth_date'] : ''),
];
foreach ([
    'father' => (string) ($selectedPerson['father_name'] ?? ''),
    'mother' => (string) ($selectedPerson['mother_maiden_name'] ?? ''),
] as $side => $fullName) {
    foreach (['first', 'middle', 'last', 'suffix'] as $index => $part) {
        $supportNames[$side . '_' . $part] = structured_name_parts($fullName)[$index];
    }
}

$pageTitle = $isEdit ? 'Edit Death Record' : 'New Death Record';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="funeral-form-page">
<div class="page-heading funeral-form-heading">
    <div>
        <p class="hero-eyebrow">Liber Defunctorum</p>
        <h1><?= $isEdit ? 'Edit Death Record #' . $editId : 'New Death Record' ?></h1>
    </div>
    <div class="page-heading-actions">
        <a class="btn btn-primary" href="#death-service-schedule">Go to service schedule</a>
        <a class="btn btn-secondary" href="<?= e($basePath . '/admin/funerals/index.php') ?>">Back to Death Records</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
        <?php if ($duplicateFuneralId > 0): ?>
            <p><a href="<?= e($basePath . '/admin/funerals/form.php?id=' . $duplicateFuneralId) ?>">Open existing death record #<?= $duplicateFuneralId ?></a></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($isEdit && isset($_GET['updated'])): ?>
    <div class="alert alert-success"><p>Death record updated.</p></div>
<?php endif; ?>

<form method="post" class="funeral-record-form" data-funeral-form>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $editId > 0 ? $editId : '' ?>">
    <input type="hidden" name="create_person" value="<?= $createMode ? '1' : '0' ?>" data-create-person-flag>

    <section class="panel-card funeral-form-card funeral-person-card">
        <h2>Person</h2>
        <p class="field-hint">Linking an existing profile is optional. If no profile exists, create one here to encode this walk-in record; the deceased's name and details can be entered manually.</p>
        <div class="person-finder" data-person-finder data-name-prefix="person" data-birth-field="birth_date"
             data-endpoint="<?= e($basePath . '/admin/api/person-search.php') ?>"
             data-details-endpoint="<?= e($basePath . '/admin/api/funeral-person-details.php') ?>"<?= $createMode ? ' hidden' : '' ?>>
            <label>Search the master person index
                <input type="search" data-person-search autocomplete="off" placeholder="Enter a name to find a person">
            </label>
            <button type="button" class="btn btn-secondary btn-small" data-person-find>Search people</button>
            <input type="hidden" name="person_id" value="<?= $personId > 0 ? $personId : '' ?>" data-person-id>
            <input type="hidden" name="person_first_name" value="<?= e($supportNames['person_first_name']) ?>">
            <input type="hidden" name="person_middle_name" value="<?= e($supportNames['person_middle_name']) ?>">
            <input type="hidden" name="person_last_name" value="<?= e($supportNames['person_last_name']) ?>">
            <input type="hidden" name="person_suffix" value="<?= e($supportNames['person_suffix']) ?>">
            <input type="hidden" name="birth_date" value="<?= e($supportNames['birth_date']) ?>" data-person-birth>
            <?php foreach (['father', 'mother'] as $side): ?>
                <?php foreach (['first', 'middle', 'last', 'suffix'] as $part): ?>
                    <input type="hidden" name="<?= e($side . '_' . $part) ?>" value="<?= e($supportNames[$side . '_' . $part]) ?>">
                <?php endforeach; ?>
            <?php endforeach; ?>
            <div class="person-finder-selection" data-person-selection aria-live="polite">
                <?php if ($selectedPerson): ?><strong>Selected: <?= e($personDisplayName) ?></strong><?php endif; ?>
            </div>
            <div class="person-finder-results" data-person-results aria-live="polite"></div>
        </div>
        <p class="field-hint funeral-person-autofill-status" data-person-autofill-status aria-live="polite" hidden></p>
        <?php if (!$isEdit): ?>
            <button type="button" class="btn btn-secondary btn-small funeral-create-person-toggle" data-create-person-toggle>
                <?= $createMode ? 'Search for an existing person instead' : 'No profile found? Create the person\'s profile' ?>
            </button>
            <fieldset class="funeral-create-person-panel" data-create-person-panel<?= $createMode ? '' : ' hidden' ?>>
                <legend>Create a person profile</legend>
                <div class="funeral-create-person-grid">
                    <label class="funeral-new-first">First name *
                        <input type="text" name="new_first_name" maxlength="150" value="<?= e($newPerson['first_name']) ?>"<?= $createMode ? ' required' : '' ?>>
                    </label>
                    <label class="funeral-new-middle">Middle name
                        <input type="text" name="new_middle_name" maxlength="100" value="<?= e($newPerson['middle_name']) ?>">
                    </label>
                    <label class="funeral-new-last">Last name *
                        <input type="text" name="new_last_name" maxlength="100" value="<?= e($newPerson['last_name']) ?>"<?= $createMode ? ' required' : '' ?>>
                    </label>
                    <label class="funeral-new-suffix">Suffix
                        <input type="text" name="new_suffix" maxlength="20" value="<?= e($newPerson['suffix']) ?>">
                    </label>
                    <label class="funeral-new-birth-date">Date of birth
                        <input type="date" name="new_birth_date" value="<?= e($newPerson['birth_date']) ?>" data-new-person-birth>
                    </label>
                    <label class="funeral-new-birth-place">Birthplace
                        <input type="text" name="new_birth_place" maxlength="150" value="<?= e($newPerson['birth_place']) ?>">
                    </label>
                    <label class="funeral-new-father">Father's name
                        <input type="text" name="new_father_name" maxlength="150" value="<?= e($newPerson['father_name']) ?>">
                    </label>
                    <label class="funeral-new-mother">Mother's maiden name
                        <input type="text" name="new_mother_maiden_name" maxlength="150" value="<?= e($newPerson['mother_maiden_name']) ?>">
                    </label>
                </div>
                <?php if ($newPersonMatches !== []): ?>
                    <div class="alert alert-warning">
                        <strong>Possible existing profiles</strong>
                        <ul>
                            <?php foreach ($newPersonMatches as $match): ?>
                                <li><?= e(person_identity_label($match)) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <label class="checkbox-label">
                            <input type="checkbox" name="confirm_new_person" value="1"<?= funeral_input_string($_POST['confirm_new_person'] ?? '') === '1' ? ' checked' : '' ?>>
                            I reviewed these matches and still need a separate person profile.
                        </label>
                    </div>
                <?php endif; ?>
            </fieldset>
        <?php endif; ?>
    </section>

    <section class="panel-card funeral-form-card">
        <h2>Death and Burial</h2>
        <div class="funeral-death-grid">
            <label class="funeral-date-death">Date of Death *
                <input type="date" name="date_of_death" value="<?= e($values['date_of_death']) ?>" required>
            </label>
            <label class="funeral-date-burial">Date of Burial
                <input type="date" name="date_of_burial" value="<?= e($values['date_of_burial']) ?>">
            </label>
            <label class="funeral-age">Age at Death
                <input type="number" name="age_at_death" min="0" max="130" step="1" value="<?= e($values['age_at_death']) ?>" data-age-at-death data-auto-age="<?= $ageIsAuto ? '1' : '0' ?>">
            </label>
            <label class="funeral-civil-status">Civil Status
                <select name="civil_status">
                    <option value="">Not recorded</option>
                    <?php foreach (['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $values['civil_status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="funeral-spouse">Name of Spouse
                <input type="text" name="spouse_name" maxlength="150" value="<?= e($values['spouse_name']) ?>">
            </label>
            <label class="funeral-parents">Name of Parents
                <input type="text" name="parents_name" maxlength="255" value="<?= e($values['parents_name']) ?>" data-parents-name>
            </label>
            <fieldset class="funeral-sacraments">
                <legend>Sacraments Received</legend>
                <div class="funeral-sacrament-options">
                    <?php foreach ([
                        'baptism' => 'Baptism',
                        'confirmation' => 'Confirmation',
                        'marriage' => 'Matrimony',
                        'anointing' => 'Anointing',
                    ] as $value => $label): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="sacraments_received[]" value="<?= e($value) ?>"<?= in_array($value, $values['sacraments_received'], true) ? ' checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="field-hint">Suggested from sacramental records when a person is selected. Review and adjust as needed.</p>
            </fieldset>
            <label class="funeral-cause">Cause of Death
                <textarea name="cause_of_death" maxlength="255" rows="2"><?= e($values['cause_of_death']) ?></textarea>
                <span class="field-hint">Staff-only. This information is not included in public notices or audit details.</span>
            </label>
            <label class="funeral-place-burial">Place of Burial
                <input type="text" name="place_of_burial" maxlength="150" value="<?= e($values['place_of_burial']) ?>">
            </label>
        </div>
    </section>

    <section class="panel-card funeral-form-card funeral-service-card" id="death-service-schedule">
        <h2>Service and Register</h2>
        <fieldset class="funeral-service-type">
            <legend>Blessing or Mass *</legend>
            <div class="funeral-service-options">
                <?php foreach (['mass' => 'Mass', 'blessing' => 'Blessing'] as $value => $label): ?>
                    <label class="radio-label">
                        <input type="radio" name="service_type" value="<?= e($value) ?>" required<?= $values['service_type'] === $value ? ' checked' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="funeral-service-fields">
            <div
                class="parish-schedule-picker funeral-schedule-picker"
                data-parish-schedule-picker
                data-require-slot-on-date="1"
                data-availability-url="<?= e($basePath . '/admin/api/schedule-availability.php') ?>"
                data-exclude-funeral-record-id="<?= $editId ?>"
                data-current-date="<?= e($existingServiceDate) ?>"
                data-current-start="<?= e($existingServiceTime) ?>"
            >
                <label>Service Date
                    <input type="date" name="service_date" value="<?= e($values['service_date']) ?>" data-schedule-date>
                </label>
                <label>Available 60-minute service time
                    <select data-schedule-slot disabled>
                        <option value="">Choose a date first</option>
                    </select>
                </label>
                <input type="hidden" name="service_time" value="<?= e($values['service_time']) ?>" data-schedule-start>
                <p class="field-hint" data-schedule-status aria-live="polite">Choose a date to view parish schedules and open times.</p>
                <div data-schedule-event-panel hidden>
                    <strong>Schedules already booked that day</strong>
                    <p class="field-hint" data-schedule-event-empty>No schedules are booked on this date.</p>
                    <ul class="parish-schedule-picker__events" data-schedule-events></ul>
                </div>
            </div>
            <label>Minister
                <input type="text" name="minister_name" maxlength="150" value="<?= e($values['minister_name']) ?>">
            </label>
            <div class="funeral-register-grid">
                <label>Book
                    <input type="text" name="book_no" maxlength="20" value="<?= e($values['book_no']) ?>">
                </label>
                <label>Page
                    <input type="text" name="page_no" maxlength="20" value="<?= e($values['page_no']) ?>">
                </label>
                <label>Line
                    <input type="text" name="line_no" maxlength="20" value="<?= e($values['line_no']) ?>">
                </label>
            </div>
            <div class="funeral-contact-grid">
                <label>Contact Number
                    <input type="tel" name="contact_number" maxlength="30" value="<?= e($values['contact_number']) ?>">
                </label>
                <label>Family Contact Email (optional)
                    <input type="email" name="contact_email" maxlength="254" value="<?= e($values['contact_email']) ?>">
                </label>
            </div>
        </div>
        <p class="field-hint">Choose an open 60-minute time between 8:00 AM and 5:00 PM. Existing parish, sacrament, and death schedules are shown for the selected date. If provided, the family email receives the confirmed schedule or any changes.</p>
    </section>

    <div class="funeral-form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Save Death Record' ?></button>
        <a class="btn btn-secondary" href="<?= e($basePath . '/admin/funerals/index.php') ?>">Cancel</a>
    </div>
</form>
</div>

<script>
(function () {
    var form = document.querySelector('[data-funeral-form]');
    if (!form) return;
    var finder = form.querySelector('[data-person-finder]');
    var results = finder.querySelector('[data-person-results]');
    var search = finder.querySelector('[data-person-search]');
    var personId = form.querySelector('[data-person-id]');
    var birthDate = form.querySelector('[data-person-birth]');
    var deathDate = form.querySelector('[name="date_of_death"]');
    var age = form.querySelector('[data-age-at-death]');
    var parentsName = form.querySelector('[data-parents-name]');
    var spouseName = form.querySelector('[name="spouse_name"]');
    var civilStatus = form.querySelector('[name="civil_status"]');
    var autofillStatus = form.querySelector('[data-person-autofill-status]');
    var sacramentInputs = Array.prototype.slice.call(
        form.querySelectorAll('[name="sacraments_received[]"]')
    );
    var createToggle = form.querySelector('[data-create-person-toggle]');
    var createPanel = form.querySelector('[data-create-person-panel]');
    var createFlag = form.querySelector('[data-create-person-flag]');
    var ageIsAuto = age.dataset.autoAge === '1';
    var detailsRequestId = 0;

    function calculateAge() {
        if (!ageIsAuto || !birthDate.value || !deathDate.value) return;
        var birth = new Date(birthDate.value + 'T00:00:00');
        var death = new Date(deathDate.value + 'T00:00:00');
        if (Number.isNaN(birth.getTime()) || Number.isNaN(death.getTime()) || birth > death) return;
        var years = death.getFullYear() - birth.getFullYear();
        if (death.getMonth() < birth.getMonth()
            || (death.getMonth() === birth.getMonth() && death.getDate() < birth.getDate())) years--;
        if (years >= 0 && years <= 130) age.value = String(years);
    }

    function setAutofillStatus(message, visible) {
        autofillStatus.textContent = message;
        autofillStatus.hidden = !visible;
    }

    function clearLinkedSuggestions() {
        spouseName.value = '';
        civilStatus.value = '';
        sacramentInputs.forEach(function (input) {
            input.checked = false;
        });
        setAutofillStatus('', false);
    }

    function clearSelectedProfile() {
        detailsRequestId++;
        personId.value = '';
        ['person_first_name', 'person_middle_name', 'person_last_name', 'person_suffix', 'birth_date',
            'father_first', 'father_middle', 'father_last', 'father_suffix',
            'mother_first', 'mother_middle', 'mother_last', 'mother_suffix'].forEach(function (name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (input) input.value = '';
        });
        parentsName.value = '';
        clearLinkedSuggestions();
        age.value = '';
        ageIsAuto = true;
        age.dataset.autoAge = '1';
    }

    function loadLinkedSuggestions() {
        var selectedId = personId.value;
        var requestId = ++detailsRequestId;
        var endpoint = finder.dataset.detailsEndpoint;
        if (!selectedId || !endpoint) return;

        setAutofillStatus('Loading linked marriage and sacramental history...', true);
        fetch(endpoint + '?person_id=' + encodeURIComponent(selectedId), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    throw new Error(payload.error || 'Linked parish details could not be loaded.');
                }
                return payload;
            });
        }).then(function (payload) {
            if (requestId !== detailsRequestId || personId.value !== selectedId) return;
            spouseName.value = typeof payload.spouse_name === 'string' ? payload.spouse_name : '';
            civilStatus.value = payload.civil_status === 'married' ? 'married' : '';
            var suggested = Array.isArray(payload.sacraments_received)
                ? payload.sacraments_received
                : [];
            sacramentInputs.forEach(function (input) {
                input.checked = suggested.indexOf(input.value) !== -1;
            });
            setAutofillStatus(
                'Linked parish history suggestions loaded. Review them; these fields remain editable.',
                true
            );
        }).catch(function (error) {
            if (requestId !== detailsRequestId || personId.value !== selectedId) return;
            setAutofillStatus(
                error && error.message
                    ? error.message
                    : 'Linked parish details could not be loaded. Complete the fields manually or try again.',
                true
            );
        });
    }

    function syncSelectedProfile() {
        if (!personId.value) return;
        var parentLabels = [];
        ['father', 'mother'].forEach(function (side) {
            var full = ['first', 'middle', 'last', 'suffix'].map(function (part) {
                var input = form.querySelector('[name="' + side + '_' + part + '"]');
                return input ? input.value.trim() : '';
            }).filter(Boolean).join(' ');
            if (full) parentLabels.push((side === 'father' ? 'Father: ' : 'Mother: ') + full);
        });
        parentsName.value = parentLabels.join('; ');
        ageIsAuto = true;
        age.dataset.autoAge = '1';
        age.value = '';
        calculateAge();
        loadLinkedSuggestions();
    }

    function syncNewPersonParents() {
        var parentLabels = [];
        [
            ['new_father_name', 'Father: '],
            ['new_mother_maiden_name', 'Mother: ']
        ].forEach(function (field) {
            var input = createPanel.querySelector('[name="' + field[0] + '"]');
            var value = input ? input.value.trim() : '';
            if (value) parentLabels.push(field[1] + value);
        });
        parentsName.value = parentLabels.join('; ');
    }

    function recalculateAgeFromDeathDate() {
        if (birthDate.value) {
            ageIsAuto = true;
            age.dataset.autoAge = '1';
        }
        calculateAge();
    }

    if (search) {
        search.addEventListener('input', clearSelectedProfile);
    }
    finder.querySelector('[data-person-selection]').addEventListener('click', function (event) {
        if (event.target.closest('.person-finder-clear')) {
            window.setTimeout(clearSelectedProfile, 0);
        }
    });
    results.addEventListener('click', function () {
        window.setTimeout(syncSelectedProfile, 0);
    });
    deathDate.addEventListener('input', recalculateAgeFromDeathDate);
    deathDate.addEventListener('change', recalculateAgeFromDeathDate);
    age.addEventListener('input', function () {
        ageIsAuto = age.value === '';
        age.dataset.autoAge = ageIsAuto ? '1' : '0';
        calculateAge();
    });

    var newBirthDate = form.querySelector('[data-new-person-birth]');
    if (newBirthDate) {
        function syncNewPersonBirthDate() {
            birthDate.value = newBirthDate.value;
            ageIsAuto = true;
            age.dataset.autoAge = '1';
            calculateAge();
        }
        newBirthDate.addEventListener('input', syncNewPersonBirthDate);
        newBirthDate.addEventListener('change', syncNewPersonBirthDate);
    }

    if (createToggle && createPanel) {
        createToggle.addEventListener('click', function () {
            var showCreate = createPanel.hidden;
            createPanel.hidden = !showCreate;
            finder.hidden = showCreate;
            createFlag.value = showCreate ? '1' : '0';
            createPanel.querySelector('[name="new_first_name"]').required = showCreate;
            createPanel.querySelector('[name="new_last_name"]').required = showCreate;
            search.value = '';
            results.textContent = '';
            createToggle.textContent = showCreate
                ? 'Search for an existing person instead'
                : 'No profile found? Create the person\'s profile';
            clearSelectedProfile();
            if (showCreate) {
                birthDate.value = newBirthDate ? newBirthDate.value : '';
                syncNewPersonParents();
                var firstName = createPanel.querySelector('[name="new_first_name"]');
                if (firstName) firstName.focus();
            }
        });
    }

    if (createPanel) {
        ['new_father_name', 'new_mother_maiden_name'].forEach(function (name) {
            var input = createPanel.querySelector('[name="' + name + '"]');
            if (input) input.addEventListener('input', syncNewPersonParents);
        });
    }

    if (newBirthDate && newBirthDate.value) {
        birthDate.value = newBirthDate.value;
    }
    if (createPanel && createFlag.value === '1' && parentsName.value === '') {
        syncNewPersonParents();
    }
    calculateAge();
}());
</script>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
