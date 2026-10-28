<?php
/**
 * =====================================================================
 * SRS-SPPO RECORD FORM (admin, schema v2) — baptism, first communion, confirmation, marriage
 * =====================================================================
 * Purpose: Create or edit a sacrament record linked to a person (or two
 *          people for marriage). Identity is read-only for existing persons;
 *          baptism can create a new person. Other types require an existing person.
 *
 * Access: Secretary only (require_role('secretary')).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';
require_role('secretary');

$basePath = $basePath ?? base_path();

$type = trim((string) ($_GET['type'] ?? 'baptism'));
$personId = (int) ($_GET['person_id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);

if (!in_array($type, ['baptism', 'first_communion', 'confirmation', 'marriage'], true)) {
    $pageTitle = 'Record Form';
    require_once __DIR__ . '/../../includes/admin-header.php';
    ?>
    <h1>Not built yet</h1>
    <p class="field-hint">The <?= e(sd_type_label($type)) ?> form is not available in this version yet.</p>
    <a class="btn btn-small" href="<?= e($basePath) ?>/admin/dashboard.php">Back to dashboard</a>
    <?php
    require_once __DIR__ . '/../../includes/admin-footer.php';
    return;
}

$isBaptism = ($type === 'baptism');
$isMarriage = ($type === 'marriage');

// ---- Load existing record when editing ----
$existingRecord = null;
$existingParticipants = [];
if ($editId > 0) {
    $existingRecord = sd_get_record($pdo, $editId);
    if ($existingRecord && (string) ($existingRecord['sacrament_type'] ?? '') === $type) {
        $existingRecord = sd_decode_record($existingRecord);
        $existingParticipants = sd_get_participants($pdo, $editId);
        if (!$isMarriage && $personId < 1 && !empty($existingRecord['person_id'])) {
            $personId = (int) $existingRecord['person_id'];
        }
    } else {
        $existingRecord = null;
    }
}

// ---- Load person if known ----
$person = null;
if ($personId > 0) {
    $person = sd_get_person($pdo, $personId);
    if (!$person) {
        $personId = 0;
    }
}

$notice = null;
$noticeType = null;
$matchBox = null;

// ---- Marriage: GET-side person selection (two panels) ----
$groomId = 0;
$brideId = 0;
$groomNew = false;
$brideNew = false;
$groomPerson = null;
$bridePerson = null;

if ($isMarriage && $editId < 1) {
    // If person_id passed (from profile card), assign by sex
    if ($personId > 0 && $person) {
        if (($person['sex'] ?? '') === 'female') {
            $brideId = $personId;
            $bridePerson = $person;
        } else {
            $groomId = $personId;
            $groomPerson = $person;
        }
    }
    // URL params override
    if (isset($_GET['groom_id'])) {
        $groomId = (int) $_GET['groom_id'];
    }
    if (isset($_GET['bride_id'])) {
        $brideId = (int) $_GET['bride_id'];
    }
    if (isset($_GET['groom_new']) && $_GET['groom_new'] === '1') {
        $groomNew = true;
    }
    if (isset($_GET['bride_new']) && $_GET['bride_new'] === '1') {
        $brideNew = true;
    }
    if ($groomId > 0 && !$groomPerson) {
        $groomPerson = sd_get_person($pdo, $groomId);
        if (!$groomPerson) {
            $groomId = 0;
        }
    }
    if ($brideId > 0 && !$bridePerson) {
        $bridePerson = sd_get_person($pdo, $brideId);
        if (!$bridePerson) {
            $brideId = 0;
        }
    }
    // Edit mode: load both from record
    if ($editId > 0 && $existingRecord) {
        $groomId = (int) ($existingRecord['person_id'] ?? 0);
        $brideId = (int) ($existingRecord['spouse_person_id'] ?? 0);
        if ($groomId > 0) {
            $groomPerson = sd_get_person($pdo, $groomId);
        }
        if ($brideId > 0) {
            $bridePerson = sd_get_person($pdo, $brideId);
        }
    }
}

// ---- Person search when person_id missing for non-baptism non-marriage types ----
if (!$isBaptism && !$isMarriage && $personId < 1 && $editId < 1) {
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    $searchResults = [];
    if ($searchQuery !== '') {
        $searchResults = sd_search_persons($pdo, $searchQuery);
    }
    $pageTitle = sd_type_label($type) . ' Record';
    require_once __DIR__ . '/../../includes/admin-header.php';
    ?>
    <h1>New <?= e(sd_type_label($type)) ?> record</h1>
    <p class="field-hint">Choose an existing person first. This form does not create new persons.</p>
    <form method="get" action="<?= e($basePath) ?>/admin/records/record-form.php" class="search-form" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem;">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search by name or birth year" autofocus style="flex:1;padding:.5rem .75rem;">
        <button type="submit" class="btn btn-primary">Search</button>
    </form>
    <?php if ($searchQuery !== ''): ?>
        <?php if (empty($searchResults)): ?>
            <p class="field-hint">No match found.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th>Name</th><th>Birth date</th><th>Birth place</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($searchResults as $p): ?>
                            <tr>
                                <td><?= e($p['full_name']) ?></td>
                                <td><?= e($p['birth_date'] ? date('M j, Y', strtotime($p['birth_date'])) : '—') ?></td>
                                <td><?= e($p['birth_place'] ?: '—') ?></td>
                                <td><a class="btn btn-small" href="<?= e($basePath) ?>/admin/records/record-form.php?type=<?= urlencode($type) ?>&person_id=<?= (int) $p['id'] ?>">Select</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
    <?php return; ?>
<?php }

// ---- POST handling ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $notice = 'Your session token is invalid or expired. Refresh the page and try again.';
        $noticeType = 'error';
    } else {
        // Collect common record fields
        $eventDate = trim((string) ($_POST['event_date'] ?? ''));
        $place = trim((string) ($_POST['place'] ?? ''));
        $ministerName = trim((string) ($_POST['minister_name'] ?? ''));
        $source = trim((string) ($_POST['source'] ?? 'this_parish'));
        $entryNo = trim((string) ($_POST['entry_no'] ?? ''));
        $bookNo = trim((string) ($_POST['book_no'] ?? ''));
        $pageNo = trim((string) ($_POST['page_no'] ?? ''));
        $lineNo = trim((string) ($_POST['line_no'] ?? ''));
        $remarks = trim((string) ($_POST['remarks'] ?? ''));

        $confirmNew = (string) ($_POST['confirm_new'] ?? '') === '1';

        if ($isMarriage) {
            // Marriage POST
            $groomId = (int) ($_POST['groom_id'] ?? 0);
            $brideId = (int) ($_POST['bride_id'] ?? 0);
            $groomNew = (string) ($_POST['groom_new'] ?? '') === '1';
            $brideNew = (string) ($_POST['bride_new'] ?? '') === '1';
            $confirmNewGroom = (string) ($_POST['confirm_new_groom'] ?? '') === '1';
            $confirmNewBride = (string) ($_POST['confirm_new_bride'] ?? '') === '1';

            // Groom identity fields
            $groomFirst = trim((string) ($_POST['groom_first_name'] ?? ''));
            $groomMiddle = trim((string) ($_POST['groom_middle_name'] ?? ''));
            $groomLast = trim((string) ($_POST['groom_last_name'] ?? ''));
            $groomSuffix = trim((string) ($_POST['groom_suffix'] ?? ''));
            $groomBirth = trim((string) ($_POST['groom_birth_date'] ?? ''));
            $groomBirthPlace = trim((string) ($_POST['groom_birth_place'] ?? ''));
            $groomFather = trim((string) ($_POST['groom_father_name'] ?? ''));
            $groomMother = trim((string) ($_POST['groom_mother_maiden_name'] ?? ''));
            $groomBaptismal = trim((string) ($_POST['groom_baptismal_status'] ?? ''));

            // Bride identity fields
            $brideFirst = trim((string) ($_POST['bride_first_name'] ?? ''));
            $brideMiddle = trim((string) ($_POST['bride_middle_name'] ?? ''));
            $brideLast = trim((string) ($_POST['bride_last_name'] ?? ''));
            $brideSuffix = trim((string) ($_POST['bride_suffix'] ?? ''));
            $brideBirth = trim((string) ($_POST['bride_birth_date'] ?? ''));
            $brideBirthPlace = trim((string) ($_POST['bride_birth_place'] ?? ''));
            $brideFather = trim((string) ($_POST['bride_father_name'] ?? ''));
            $brideMother = trim((string) ($_POST['bride_mother_maiden_name'] ?? ''));
            $brideBaptismal = trim((string) ($_POST['bride_baptismal_status'] ?? ''));

            // Details JSON fields
            $groomCivilStatus = trim((string) ($_POST['groom_civil_status'] ?? ''));
            $groomAge = trim((string) ($_POST['groom_age'] ?? ''));
            $groomOrigin = trim((string) ($_POST['groom_origin'] ?? ''));
            $groomResidence = trim((string) ($_POST['groom_residence'] ?? ''));
            $brideCivilStatus = trim((string) ($_POST['bride_civil_status'] ?? ''));
            $brideAge = trim((string) ($_POST['bride_age'] ?? ''));
            $brideOrigin = trim((string) ($_POST['bride_origin'] ?? ''));
            $brideResidence = trim((string) ($_POST['bride_residence'] ?? ''));
            $licenseRef = trim((string) ($_POST['license_ref'] ?? ''));
            $civilMarriageDate = trim((string) ($_POST['civil_marriage_date'] ?? ''));
            $civilMarriagePlace = trim((string) ($_POST['civil_marriage_place'] ?? ''));
            $bannsDate1 = trim((string) ($_POST['banns_date_1'] ?? ''));
            $bannsDate2 = trim((string) ($_POST['banns_date_2'] ?? ''));
            $bannsDate3 = trim((string) ($_POST['banns_date_3'] ?? ''));
            $preCanaDate = trim((string) ($_POST['pre_cana_date'] ?? ''));
            $canonicalInterviewDate = trim((string) ($_POST['canonical_interview_date'] ?? ''));

            // Sponsors/witnesses: 6 rows
            $participants = [];
            for ($i = 0; $i < 6; $i++) {
                $role = trim((string) ($_POST['sp_role'][$i] ?? ''));
                $spName = trim((string) ($_POST['sp_name'][$i] ?? ''));
                $spResidence = trim((string) ($_POST['sp_residence'][$i] ?? ''));
                if ($role === '' && $spName === '' && $spResidence === '') {
                    continue;
                }
                if ($role !== '' || $spName !== '') {
                    $participants[] = [
                        'role' => $role !== '' ? $role : null,
                        'full_name' => $spName !== '' ? $spName : null,
                        'residence' => $spResidence !== '' ? $spResidence : null,
                    ];
                }
            }

            // Possible match check for new groom
            $matchBoxGroom = null;
            if ($groomNew && $groomId < 1 && $groomLast !== '' && !$confirmNewGroom) {
                $sq = $groomLast;
                if ($groomBirth !== '') $sq .= ' ' . $groomBirth;
                $m = sd_search_persons($pdo, $sq);
                if (!empty($m)) $matchBoxGroom = $m;
            }
            $matchBoxBride = null;
            if ($brideNew && $brideId < 1 && $brideLast !== '' && !$confirmNewBride) {
                $sq = $brideLast;
                if ($brideBirth !== '') $sq .= ' ' . $brideBirth;
                $m = sd_search_persons($pdo, $sq);
                if (!empty($m)) $matchBoxBride = $m;
            }

            if ($matchBoxGroom !== null || $matchBoxBride !== null) {
                if ($matchBoxGroom !== null) $matchBox = ['groom' => $matchBoxGroom];
                if ($matchBoxBride !== null) {
                    if ($matchBox === null) $matchBox = [];
                    $matchBox['bride'] = $matchBoxBride;
                }
            } else {
                // Save new persons
                $effectiveGroomId = $groomId;
                $effectiveBrideId = $brideId;
                try {
                    $pdo->beginTransaction();
                    if ($groomNew && $effectiveGroomId < 1) {
                        $effectiveGroomId = sd_save_person($pdo, [
                            'first_name' => $groomFirst,
                            'last_name' => $groomLast,
                            'middle_name' => $groomMiddle,
                            'suffix' => $groomSuffix,
                            'sex' => 'male',
                            'birth_date' => $groomBirth,
                            'birth_place' => $groomBirthPlace,
                            'father_name' => $groomFather,
                            'mother_maiden_name' => $groomMother,
                            'baptismal_status' => $groomBaptismal,
                        ]);
                    }
                    if ($brideNew && $effectiveBrideId < 1) {
                        $effectiveBrideId = sd_save_person($pdo, [
                            'first_name' => $brideFirst,
                            'last_name' => $brideLast,
                            'middle_name' => $brideMiddle,
                            'suffix' => $brideSuffix,
                            'sex' => 'female',
                            'birth_date' => $brideBirth,
                            'birth_place' => $brideBirthPlace,
                            'father_name' => $brideFather,
                            'mother_maiden_name' => $brideMother,
                            'baptismal_status' => $brideBaptismal,
                        ]);
                    }

                    if ($effectiveGroomId < 1 || $effectiveBrideId < 1) {
                        throw new InvalidArgumentException('Both groom and bride must be chosen or created.');
                    }

                    // Build details
                    $groomObj = [];
                    if ($groomCivilStatus !== '') $groomObj['civil_status'] = $groomCivilStatus;
                    if ($groomAge !== '') $groomObj['age'] = $groomAge;
                    if ($groomOrigin !== '') $groomObj['origin'] = $groomOrigin;
                    if ($groomResidence !== '') $groomObj['residence'] = $groomResidence;
                    $brideObj = [];
                    if ($brideCivilStatus !== '') $brideObj['civil_status'] = $brideCivilStatus;
                    if ($brideAge !== '') $brideObj['age'] = $brideAge;
                    if ($brideOrigin !== '') $brideObj['origin'] = $brideOrigin;
                    if ($brideResidence !== '') $brideObj['residence'] = $brideResidence;
                    $bannsDates = [];
                    foreach ([$bannsDate1, $bannsDate2, $bannsDate3] as $bd) {
                        if ($bd !== '') $bannsDates[] = $bd;
                    }
                    $details = [];
                    if (!empty($groomObj)) $details['groom'] = $groomObj;
                    if (!empty($brideObj)) $details['bride'] = $brideObj;
                    if ($licenseRef !== '') $details['license_ref'] = $licenseRef;
                    if ($civilMarriageDate !== '') $details['civil_marriage_date'] = $civilMarriageDate;
                    if ($civilMarriagePlace !== '') $details['civil_marriage_place'] = $civilMarriagePlace;
                    if (!empty($bannsDates)) $details['banns_dates'] = $bannsDates;
                    if ($preCanaDate !== '') $details['pre_cana_date'] = $preCanaDate;
                    if ($canonicalInterviewDate !== '') $details['canonical_interview_date'] = $canonicalInterviewDate;

                    $recordData = [
                        'sacrament_type' => 'marriage',
                        'person_id' => $effectiveGroomId,
                        'spouse_person_id' => $effectiveBrideId,
                        'source' => $source,
                        'event_date' => $eventDate,
                        'place' => $place,
                        'minister_name' => $ministerName,
                        'entry_no' => $entryNo,
                        'book_no' => $bookNo,
                        'page_no' => $pageNo,
                        'line_no' => $lineNo,
                        'details' => $details,
                        'remarks' => $remarks,
                    ];
                    if ($editId > 0) {
                        $recordData['id'] = $editId;
                    }

                    $recordId = sd_save_record($pdo, $recordData, $participants, (int) current_user()['id']);
                    $pdo->commit();

                    $actionLabel = $editId > 0 ? 'record.update' : 'record.create';
                    log_activity($pdo, (int) current_user()['id'], $actionLabel, 'sacrament_records', $recordId, 'Marriage record saved');
                    redirect_to('/admin/system/person-profile.php?id=' . $effectiveGroomId . '&saved=1');
                } catch (InvalidArgumentException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $notice = $e->getMessage();
                    $noticeType = 'error';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('SRS-SPPO record save failed: ' . $e->getMessage());
                    $notice = 'Could not save the record. Check the server error log.';
                    $noticeType = 'error';
                }
            }
        } else {
            // ---- Non-marriage POST (baptism, first_communion, confirmation) ----
            $confirmNew = (string) ($_POST['confirm_new'] ?? '') === '1';

            // Collect identity (baptism only)
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $middleName = trim((string) ($_POST['middle_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $suffix = trim((string) ($_POST['suffix'] ?? ''));
            $sex = trim((string) ($_POST['sex'] ?? ''));
            $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
            $birthPlace = trim((string) ($_POST['birth_place'] ?? ''));
            $fatherName = trim((string) ($_POST['father_name'] ?? ''));
            $motherMaiden = trim((string) ($_POST['mother_maiden_name'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));

            $parentsUnion = trim((string) ($_POST['parents_union_type'] ?? ''));
            $fatherBirthplace = trim((string) ($_POST['father_birthplace'] ?? ''));
            $motherBirthplace = trim((string) ($_POST['mother_birthplace'] ?? ''));
            $parentsOrigin = trim((string) ($_POST['parents_origin'] ?? ''));
            $parentsResidence = trim((string) ($_POST['parents_residence'] ?? ''));
            $proxyName = trim((string) ($_POST['proxy_name'] ?? ''));
            $domicile = trim((string) ($_POST['domicile'] ?? ''));

            // Godparents (baptism only): 4 rows
            $participants = [];
            if ($isBaptism) {
                for ($i = 0; $i < 4; $i++) {
                    $role = trim((string) ($_POST['gp_role'][$i] ?? ''));
                    $gpName = trim((string) ($_POST['gp_name'][$i] ?? ''));
                    $gpResidence = trim((string) ($_POST['gp_residence'][$i] ?? ''));
                    if ($role === '' && $gpName === '' && $gpResidence === '') {
                        continue;
                    }
                    if ($role !== '' || $gpName !== '') {
                        $participants[] = [
                            'role' => $role !== '' ? $role : null,
                            'full_name' => $gpName !== '' ? $gpName : null,
                            'residence' => $gpResidence !== '' ? $gpResidence : null,
                        ];
                    }
                }
            }
            // Sponsor (confirmation only): 1 row
            if ($type === 'confirmation') {
                $sponsorName = trim((string) ($_POST['sponsor_name'] ?? ''));
                $sponsorResidence = trim((string) ($_POST['sponsor_residence'] ?? ''));
                if ($sponsorName !== '' || $sponsorResidence !== '') {
                    $participants[] = [
                        'role' => 'sponsor',
                        'full_name' => $sponsorName !== '' ? $sponsorName : null,
                        'residence' => $sponsorResidence !== '' ? $sponsorResidence : null,
                    ];
                }
            }

            $effectivePersonId = $personId;
            $matchBox = null;
            // New person possible-match check (baptism only)
            if ($isBaptism && $effectivePersonId < 1 && $lastName !== '') {
                $searchQuery = $lastName;
                if ($birthDate !== '') {
                    $searchQuery .= ' ' . $birthDate;
                }
                $matches = sd_search_persons($pdo, $searchQuery);
                if (!empty($matches) && !$confirmNew) {
                    $matchBox = $matches;
                }
            }

            if ($matchBox === null) {
                // Create new person (baptism only)
                if ($isBaptism && $effectivePersonId < 1) {
                    $personData = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'middle_name' => $middleName,
                        'suffix' => $suffix,
                        'sex' => $sex,
                        'birth_date' => $birthDate,
                        'birth_place' => $birthPlace,
                        'father_name' => $fatherName,
                        'mother_maiden_name' => $motherMaiden,
                        'address' => $address,
                    ];
                    try {
                        $effectivePersonId = sd_save_person($pdo, $personData);
                    } catch (Throwable $e) {
                        $notice = 'Could not save person: ' . $e->getMessage();
                        $noticeType = 'error';
                        $matchBox = null;
                        $effectivePersonId = 0;
                    }
                }

                if ($effectivePersonId > 0 && $matchBox === null) {
                    $details = [];
                    if ($isBaptism) {
                        if ($fatherBirthplace !== '') $details['father_birthplace'] = $fatherBirthplace;
                        if ($motherBirthplace !== '') $details['mother_birthplace'] = $motherBirthplace;
                        if ($parentsOrigin !== '') $details['parents_origin'] = $parentsOrigin;
                        if ($parentsResidence !== '') $details['parents_residence'] = $parentsResidence;
                        if ($proxyName !== '') $details['proxy_name'] = $proxyName;
                    }
                    if ($type === 'first_communion' && $domicile !== '') {
                        $details['domicile'] = $domicile;
                    }

                    $recordData = [
                        'sacrament_type' => $type,
                        'person_id' => $effectivePersonId,
                        'source' => $source,
                        'event_date' => $eventDate,
                        'place' => $place,
                        'minister_name' => $ministerName,
                        'entry_no' => $entryNo,
                        'book_no' => $bookNo,
                        'page_no' => $pageNo,
                        'line_no' => $lineNo,
                        'details' => $details,
                        'remarks' => $remarks,
                    ];
                    if ($isBaptism) {
                        $recordData['parents_union_type'] = $parentsUnion;
                    }
                    if ($editId > 0) {
                        $recordData['id'] = $editId;
                    }

                    try {
                        $recordId = sd_save_record($pdo, $recordData, $participants, (int) current_user()['id']);
                        $actionLabel = $editId > 0 ? 'record.update' : 'record.create';
                        $typeLabel = sd_type_label($type);
                        log_activity($pdo, (int) current_user()['id'], $actionLabel, 'sacrament_records', $recordId, $typeLabel . ' record saved');
                        redirect_to('/admin/system/person-profile.php?id=' . $effectivePersonId . '&saved=1');
                    } catch (InvalidArgumentException $e) {
                        $notice = $e->getMessage();
                        $noticeType = 'error';
                    } catch (Throwable $e) {
                        error_log('SRS-SPPO record save failed: ' . $e->getMessage());
                        $notice = 'Could not save the record. Check the server error log.';
                        $noticeType = 'error';
                    }
                }
            }
        }
    }
}

// ---- Prefill for edit mode ----
$prefill = [];
$prefillDetails = [];
if ($existingRecord) {
    $prefill = $existingRecord;
    $prefillDetails = $existingRecord['details'] ?? [];
}

// ---- Prefill logbook numbers for new records ----
$logbookDefaults = ['entry_no' => '', 'book_no' => 'I', 'page_no' => '1', 'line_no' => '1'];
if ($editId < 1) {
    try {
        $logbookDefaults = sd_next_logbook_numbers($pdo, $type);
    } catch (Throwable $e) {
        // keep defaults
    }
}

// ---- Prerequisite warnings ----
$warnings = [];
if ($isMarriage) {
    if ($groomId > 0) {
        $warnings = array_merge($warnings, sd_prerequisite_warnings($pdo, $groomId, 'marriage'));
    }
    if ($brideId > 0) {
        $warnings = array_merge($warnings, sd_prerequisite_warnings($pdo, $brideId, 'marriage'));
    }
} elseif ($personId > 0) {
    $warnings = sd_prerequisite_warnings($pdo, $personId, $type);
}

// ---- Match box for marriage ----
$matchBoxGroom = (is_array($matchBox) && isset($matchBox['groom'])) ? $matchBox['groom'] : null;
$matchBoxBride = (is_array($matchBox) && isset($matchBox['bride'])) ? $matchBox['bride'] : null;

$pageTitle = sd_type_label($type) . ' Record';
require_once __DIR__ . '/../../includes/admin-header.php';
?>

<h1><?= e($editId > 0 ? 'Edit ' . sd_type_label($type) . ' record' : 'New ' . sd_type_label($type) . ' record') ?></h1>
<?php if ($notice): ?>
    <p class="alert alert-<?= e($noticeType) ?>"><?= e($notice) ?></p>
<?php endif; ?>
<?php if ($editId > 0): ?>
    <p class="alert alert-warning">Saving clears the book-entry verification. Verify the entry again afterwards.</p>
<?php endif; ?>
<?php if (!empty($warnings)): ?>
    <div class="notice notice-warning">
        <?php foreach ($warnings as $w): ?><p><?= e($w) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($isMarriage): ?>
    <?php // ---- Marriage: two-person panels ---- ?>
    <?php if (!$editId || $editId < 1): ?>
        <!-- Groom panel -->
        <section class="panel-card">
            <h2>Groom</h2>
            <?php if ($groomPerson): ?>
                <p class="field-hint"><strong><?= e(sd_full_name($groomPerson)) ?></strong> &middot; born <?= e($groomPerson['birth_date'] ? date('M j, Y', strtotime($groomPerson['birth_date'])) : 'not specified') ?></p>
                <p class="field-hint">Baptismal status: <?= e($groomPerson['baptismal_status'] ?? 'not specified') ?> &middot; Father: <?= e($groomPerson['father_name'] ?: 'not specified') ?> &middot; Mother: <?= e($groomPerson['mother_maiden_name'] ?: 'not specified') ?></p>
                <a class="btn btn-small" href="<?= e($basePath) ?>/admin/records/record-form.php?type=marriage&bride_id=<?= (int) $brideId ?><?= $brideNew ? '&bride_new=1' : '' ?>">Change groom</a>
            <?php elseif ($groomNew): ?>
                <?php if ($matchBoxGroom !== null): ?>
                    <div class="notice notice-warning" style="margin-bottom:1rem;">
                        <p><strong>Possible match:</strong></p>
                        <ul>
                            <?php foreach ($matchBoxGroom as $m): ?>
                                <li><a href="<?= e($basePath) ?>/admin/system/person-profile.php?id=<?= (int) $m['id'] ?>" target="_blank"><?= e($m['full_name']) ?></a> (<?= e($m['birth_date'] ?: 'no birth date') ?>)</li>
                            <?php endforeach; ?>
                        </ul>
                        <form method="post" style="margin-top:.5rem;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="groom_new" value="1">
                            <input type="hidden" name="bride_new" value="<?= $brideNew ? '1' : '0' ?>">
                            <input type="hidden" name="confirm_new_groom" value="1">
                            <input type="hidden" name="groom_id" value="<?= (int) $groomId ?>">
                            <input type="hidden" name="bride_id" value="<?= (int) $brideId ?>">
                            <?php foreach (['groom_first_name','groom_middle_name','groom_last_name','groom_suffix','groom_birth_date','groom_birth_place','groom_father_name','groom_mother_maiden_name','groom_baptismal_status','bride_first_name','bride_middle_name','bride_last_name','bride_suffix','bride_birth_date','bride_birth_place','bride_father_name','bride_mother_maiden_name','bride_baptismal_status','event_date','place','minister_name','source','entry_no','book_no','page_no','line_no','groom_civil_status','groom_age','groom_origin','groom_residence','bride_civil_status','bride_age','bride_origin','bride_residence','license_ref','civil_marriage_date','civil_marriage_place','banns_date_1','banns_date_2','banns_date_3','pre_cana_date','canonical_interview_date','remarks'] as $f): ?>
                                <input type="hidden" name="<?= e($f) ?>" value="<?= e((string) ($_POST[$f] ?? '')) ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-small">No, this is a different person</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="form-grid view-grid">
                        <div class="field"><label class="field-label">First name (required)</label><input type="text" name="groom_first_name" value="<?= e((string) ($_POST['groom_first_name'] ?? '')) ?>" form="marriageForm" required></div>
                        <div class="field"><label class="field-label">Middle name</label><input type="text" name="groom_middle_name" value="<?= e((string) ($_POST['groom_middle_name'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Last name (required)</label><input type="text" name="groom_last_name" value="<?= e((string) ($_POST['groom_last_name'] ?? '')) ?>" form="marriageForm" required></div>
                        <div class="field"><label class="field-label">Suffix</label><input type="text" name="groom_suffix" value="<?= e((string) ($_POST['groom_suffix'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Birth date</label><input type="date" name="groom_birth_date" value="<?= e((string) ($_POST['groom_birth_date'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Birth place</label><input type="text" name="groom_birth_place" value="<?= e((string) ($_POST['groom_birth_place'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Father's name</label><input type="text" name="groom_father_name" value="<?= e((string) ($_POST['groom_father_name'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Mother's maiden name</label><input type="text" name="groom_mother_maiden_name" value="<?= e((string) ($_POST['groom_mother_maiden_name'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Baptismal status</label>
                            <select name="groom_baptismal_status" form="marriageForm">
                                <option value=""<?= (($_POST['groom_baptismal_status'] ?? '') === '') ? ' selected' : '' ?>>— not specified —</option>
                                <option value="baptized_catholic"<?= (($_POST['groom_baptismal_status'] ?? '') === 'baptized_catholic') ? ' selected' : '' ?>>Baptized Catholic</option>
                                <option value="baptized_non_catholic"<?= (($_POST['groom_baptismal_status'] ?? '') === 'baptized_non_catholic') ? ' selected' : '' ?>>Baptized (non-Catholic)</option>
                                <option value="unbaptized"<?= (($_POST['groom_baptismal_status'] ?? '') === 'unbaptized') ? ' selected' : '' ?>>Unbaptized</option>
                            </select>
                        </div>
                    </div>
                    <?php if (in_array($_POST['groom_baptismal_status'] ?? '', ['unbaptized', 'baptized_non_catholic'], true)): ?>
                        <p class="notice notice-warning">Note: groom is not a baptized Catholic.</p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <p class="field-hint">No groom selected.</p>
                <form method="get" action="<?= e($basePath) ?>/admin/records/record-form.php" class="search-form" style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;">
                    <input type="hidden" name="type" value="marriage">
                    <input type="hidden" name="bride_id" value="<?= (int) $brideId ?>">
                    <?php if ($brideNew): ?><input type="hidden" name="bride_new" value="1"><?php endif; ?>
                    <input type="text" name="q_groom" value="<?= e(trim((string) ($_GET['q_groom'] ?? ''))) ?>" placeholder="Search groom by name" style="flex:1;padding:.5rem .75rem;">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <?php
                $qGroom = trim((string) ($_GET['q_groom'] ?? ''));
                if ($qGroom !== ''):
                    $groomResults = sd_search_persons($pdo, $qGroom);
                ?>
                    <?php if (empty($groomResults)): ?>
                        <p class="field-hint">No match.</p>
                    <?php else: ?>
                        <div class="table-scroll"><table class="admin-table"><thead><tr><th>Name</th><th>Birth date</th><th></th></tr></thead><tbody>
                            <?php foreach ($groomResults as $p): ?>
                                <tr><td><?= e($p['full_name']) ?></td><td><?= e($p['birth_date'] ? date('M j, Y', strtotime($p['birth_date'])) : '—') ?></td><td><a class="btn btn-small" href="<?= e($basePath) ?>/admin/records/record-form.php?type=marriage&groom_id=<?= (int) $p['id'] ?>&bride_id=<?= (int) $brideId ?><?= $brideNew ? '&bride_new=1' : '' ?>">Select</a></td></tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                <?php endif; ?>
                <p class="field-hint"><a href="<?= e($basePath) ?>/admin/records/record-form.php?type=marriage&groom_new=1&bride_id=<?= (int) $brideId ?><?= $brideNew ? '&bride_new=1' : '' ?>">Not in the system? Enter a new person</a></p>
            <?php endif; ?>
        </section>

        <!-- Bride panel -->
        <section class="panel-card">
            <h2>Bride</h2>
            <?php if ($bridePerson): ?>
                <p class="field-hint"><strong><?= e(sd_full_name($bridePerson)) ?></strong> &middot; born <?= e($bridePerson['birth_date'] ? date('M j, Y', strtotime($bridePerson['birth_date'])) : 'not specified') ?></p>
                <p class="field-hint">Baptismal status: <?= e($bridePerson['baptismal_status'] ?? 'not specified') ?> &middot; Father: <?= e($bridePerson['father_name'] ?: 'not specified') ?> &middot; Mother: <?= e($bridePerson['mother_maiden_name'] ?: 'not specified') ?></p>
                <a class="btn btn-small" href="<?= e($basePath) ?>/admin/records/record-form.php?type=marriage&groom_id=<?= (int) $groomId ?><?= $groomNew ? '&groom_new=1' : '' ?>&bride_id=0">Change bride</a>
            <?php elseif ($brideNew): ?>
                <?php if ($matchBoxBride !== null): ?>
                    <div class="notice notice-warning" style="margin-bottom:1rem;">
                        <p><strong>Possible match:</strong></p>
                        <ul>
                            <?php foreach ($matchBoxBride as $m): ?>
                                <li><a href="<?= e($basePath) ?>/admin/system/person-profile.php?id=<?= (int) $m['id'] ?>" target="_blank"><?= e($m['full_name']) ?></a> (<?= e($m['birth_date'] ?: 'no birth date') ?>)</li>
                            <?php endforeach; ?>
                        </ul>
                        <form method="post" style="margin-top:.5rem;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="groom_new" value="<?= $groomNew ? '1' : '0' ?>">
                            <input type="hidden" name="bride_new" value="1">
                            <input type="hidden" name="confirm_new_bride" value="1">
                            <input type="hidden" name="groom_id" value="<?= (int) $groomId ?>">
                            <input type="hidden" name="bride_id" value="<?= (int) $brideId ?>">
                            <?php foreach (['groom_first_name','groom_middle_name','groom_last_name','groom_suffix','groom_birth_date','groom_birth_place','groom_father_name','groom_mother_maiden_name','groom_baptismal_status','bride_first_name','bride_middle_name','bride_last_name','bride_suffix','bride_birth_date','bride_birth_place','bride_father_name','bride_mother_maiden_name','bride_baptismal_status','event_date','place','minister_name','source','entry_no','book_no','page_no','line_no','groom_civil_status','groom_age','groom_origin','groom_residence','bride_civil_status','bride_age','bride_origin','bride_residence','license_ref','civil_marriage_date','civil_marriage_place','banns_date_1','banns_date_2','banns_date_3','pre_cana_date','canonical_interview_date','remarks'] as $f): ?>
                                <input type="hidden" name="<?= e($f) ?>" value="<?= e((string) ($_POST[$f] ?? '')) ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-small">No, this is a different person</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="form-grid view-grid">
                        <div class="field"><label class="field-label">First name (required)</label><input type="text" name="bride_first_name" value="<?= e((string) ($_POST['bride_first_name'] ?? '')) ?>" form="marriageForm" required></div>
                        <div class="field"><label class="field-label">Middle name</label><input type="text" name="bride_middle_name" value="<?= e((string) ($_POST['bride_middle_name'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Last name (required)</label><input type="text" name="bride_last_name" value="<?= e((string) ($_POST['bride_last_name'] ?? '')) ?>" form="marriageForm" required></div>
                        <div class="field"><label class="field-label">Suffix</label><input type="text" name="bride_suffix" value="<?= e((string) ($_POST['bride_suffix'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Birth date</label><input type="date" name="bride_birth_date" value="<?= e((string) ($_POST['bride_birth_date'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Birth place</label><input type="text" name="bride_birth_place" value="<?= e((string) ($_POST['bride_birth_place'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Father's name</label><input type="text" name="bride_father_name" value="<?= e((string) ($_POST['bride_father_name'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Mother's maiden name</label><input type="text" name="bride_mother_maiden_name" value="<?= e((string) ($_POST['bride_mother_maiden_name'] ?? '')) ?>" form="marriageForm"></div>
                        <div class="field"><label class="field-label">Baptismal status</label>
                            <select name="bride_baptismal_status" form="marriageForm">
                                <option value=""<?= (($_POST['bride_baptismal_status'] ?? '') === '') ? ' selected' : '' ?>>— not specified —</option>
                                <option value="baptized_catholic"<?= (($_POST['bride_baptismal_status'] ?? '') === 'baptized_catholic') ? ' selected' : '' ?>>Baptized Catholic</option>
                                <option value="baptized_non_catholic"<?= (($_POST['bride_baptismal_status'] ?? '') === 'baptized_non_catholic') ? ' selected' : '' ?>>Baptized (non-Catholic)</option>
                                <option value="unbaptized"<?= (($_POST['bride_baptismal_status'] ?? '') === 'unbaptized') ? ' selected' : '' ?>>Unbaptized</option>
                            </select>
                        </div>
                    </div>
                    <?php if (in_array($_POST['bride_baptismal_status'] ?? '', ['unbaptized', 'baptized_non_catholic'], true)): ?>
                        <p class="notice notice-warning">Note: bride is not a baptized Catholic.</p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <p class="field-hint">No bride selected.</p>
                <form method="get" action="<?= e($basePath) ?>/admin/records/record-form.php" class="search-form" style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;">
                    <input type="hidden" name="type" value="marriage">
                    <input type="hidden" name="groom_id" value="<?= (int) $groomId ?>">
                    <?php if ($groomNew): ?><input type="hidden" name="groom_new" value="1"><?php endif; ?>
                    <input type="text" name="q_bride" value="<?= e(trim((string) ($_GET['q_bride'] ?? ''))) ?>" placeholder="Search bride by name" style="flex:1;padding:.5rem .75rem;">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <?php
                $qBride = trim((string) ($_GET['q_bride'] ?? ''));
                if ($qBride !== ''):
                    $brideResults = sd_search_persons($pdo, $qBride);
                ?>
                    <?php if (empty($brideResults)): ?>
                        <p class="field-hint">No match.</p>
                    <?php else: ?>
                        <div class="table-scroll"><table class="admin-table"><thead><tr><th>Name</th><th>Birth date</th><th></th></tr></thead><tbody>
                            <?php foreach ($brideResults as $p): ?>
                                <tr><td><?= e($p['full_name']) ?></td><td><?= e($p['birth_date'] ? date('M j, Y', strtotime($p['birth_date'])) : '—') ?></td><td><a class="btn btn-small" href="<?= e($basePath) ?>/admin/records/record-form.php?type=marriage&groom_id=<?= (int) $groomId ?>&bride_id=<?= (int) $p['id'] ?><?= $groomNew ? '&groom_new=1' : '' ?>">Select</a></td></tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                <?php endif; ?>
                <p class="field-hint"><a href="<?= e($basePath) ?>/admin/records/record-form.php?type=marriage&bride_new=1&groom_id=<?= (int) $groomId ?><?= $groomNew ? '&groom_new=1' : '' ?>">Not in the system? Enter a new person</a></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php // ---- Record fields (only when both sides chosen or new) ---- ?>
    <?php $bothReady = ($groomPerson !== null || $groomNew) && ($bridePerson !== null || $brideNew); ?>
    <?php if ($bothReady): ?>
        <form method="post" class="form-grid" id="marriageForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="type" value="marriage">
            <input type="hidden" name="groom_id" value="<?= (int) $groomId ?>">
            <input type="hidden" name="bride_id" value="<?= (int) $brideId ?>">
            <input type="hidden" name="groom_new" value="<?= $groomNew ? '1' : '0' ?>">
            <input type="hidden" name="bride_new" value="<?= $brideNew ? '1' : '0' ?>">
            <input type="hidden" name="confirm_new_groom" value="<?= $confirmNewGroom ? '1' : '0' ?>">
            <input type="hidden" name="confirm_new_bride" value="<?= $confirmNewBride ? '1' : '0' ?>">

            <section class="panel-card">
                <h2>Record</h2>
                <div class="form-grid view-grid">
                    <div class="field"><label class="field-label">Event date (required)</label><input type="date" name="event_date" value="<?= e((string) ($_POST['event_date'] ?? ($prefill['event_date'] ?? ''))) ?>" required></div>
                    <div class="field"><label class="field-label">Place</label><input type="text" name="place" value="<?= e((string) ($_POST['place'] ?? ($prefill['place'] ?? 'St. Patrick Parish'))) ?>"></div>
                    <div class="field"><label class="field-label">Minister name</label><input type="text" name="minister_name" value="<?= e((string) ($_POST['minister_name'] ?? ($prefill['minister_name'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Source</label>
                        <?php $src = (string) ($_POST['source'] ?? ($prefill['source'] ?? 'this_parish')); ?>
                        <label><input type="radio" name="source" value="this_parish"<?= $src === 'this_parish' ? ' checked' : '' ?>> This parish</label>
                        <label><input type="radio" name="source" value="other_parish"<?= $src === 'other_parish' ? ' checked' : '' ?>> Other parish</label>
                    </div>
                </div>
            </section>

            <section class="panel-card">
                <h2>Logbook</h2>
                <div class="form-grid view-grid">
                    <div class="field"><label class="field-label">Entry no.</label><input type="text" name="entry_no" value="<?= e((string) ($_POST['entry_no'] ?? ($prefill['entry_no'] ?? $logbookDefaults['entry_no']))) ?>"></div>
                    <div class="field"><label class="field-label">Book no.</label><input type="text" name="book_no" value="<?= e((string) ($_POST['book_no'] ?? ($prefill['book_no'] ?? $logbookDefaults['book_no']))) ?>"></div>
                    <div class="field"><label class="field-label">Page no.</label><input type="text" name="page_no" value="<?= e((string) ($_POST['page_no'] ?? ($prefill['page_no'] ?? $logbookDefaults['page_no']))) ?>"></div>
                    <div class="field"><label class="field-label">Line no.</label><input type="text" name="line_no" value="<?= e((string) ($_POST['line_no'] ?? ($prefill['line_no'] ?? $logbookDefaults['line_no']))) ?>"></div>
                </div>
            </section>

            <section class="panel-card">
                <h2>Details</h2>
                <h3>Groom</h3>
                <div class="form-grid view-grid">
                    <div class="field"><label class="field-label">Civil status</label><input type="text" name="groom_civil_status" value="<?= e((string) ($_POST['groom_civil_status'] ?? ($prefillDetails['groom']['civil_status'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Age</label><input type="text" name="groom_age" value="<?= e((string) ($_POST['groom_age'] ?? ($prefillDetails['groom']['age'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Origin</label><input type="text" name="groom_origin" value="<?= e((string) ($_POST['groom_origin'] ?? ($prefillDetails['groom']['origin'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Residence</label><input type="text" name="groom_residence" value="<?= e((string) ($_POST['groom_residence'] ?? ($prefillDetails['groom']['residence'] ?? ''))) ?>"></div>
                </div>
                <h3>Bride</h3>
                <div class="form-grid view-grid">
                    <div class="field"><label class="field-label">Civil status</label><input type="text" name="bride_civil_status" value="<?= e((string) ($_POST['bride_civil_status'] ?? ($prefillDetails['bride']['civil_status'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Age</label><input type="text" name="bride_age" value="<?= e((string) ($_POST['bride_age'] ?? ($prefillDetails['bride']['age'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Origin</label><input type="text" name="bride_origin" value="<?= e((string) ($_POST['bride_origin'] ?? ($prefillDetails['bride']['origin'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Residence</label><input type="text" name="bride_residence" value="<?= e((string) ($_POST['bride_residence'] ?? ($prefillDetails['bride']['residence'] ?? ''))) ?>"></div>
                </div>
                <div class="form-grid view-grid">
                    <div class="field"><label class="field-label">License ref</label><input type="text" name="license_ref" value="<?= e((string) ($_POST['license_ref'] ?? ($prefillDetails['license_ref'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Civil marriage date</label><input type="date" name="civil_marriage_date" value="<?= e((string) ($_POST['civil_marriage_date'] ?? ($prefillDetails['civil_marriage_date'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Civil marriage place</label><input type="text" name="civil_marriage_place" value="<?= e((string) ($_POST['civil_marriage_place'] ?? ($prefillDetails['civil_marriage_place'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Banns date 1</label><input type="date" name="banns_date_1" value="<?= e((string) ($_POST['banns_date_1'] ?? ($prefillDetails['banns_dates'][0] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Banns date 2</label><input type="date" name="banns_date_2" value="<?= e((string) ($_POST['banns_date_2'] ?? ($prefillDetails['banns_dates'][1] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Banns date 3</label><input type="date" name="banns_date_3" value="<?= e((string) ($_POST['banns_date_3'] ?? ($prefillDetails['banns_dates'][2] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Pre-Cana date</label><input type="date" name="pre_cana_date" value="<?= e((string) ($_POST['pre_cana_date'] ?? ($prefillDetails['pre_cana_date'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Canonical interview date</label><input type="date" name="canonical_interview_date" value="<?= e((string) ($_POST['canonical_interview_date'] ?? ($prefillDetails['canonical_interview_date'] ?? ''))) ?>"></div>
                </div>
            </section>

            <section class="panel-card">
                <h2>Sponsors / Witnesses (6 rows)</h2>
                <?php
                $spRows = [];
                if (!empty($existingParticipants)) {
                    foreach ($existingParticipants as $p) {
                        $spRows[] = $p;
                    }
                }
                while (count($spRows) < 6) {
                    $spRows[] = ['role' => '', 'full_name' => '', 'residence' => ''];
                }
                ?>
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <div class="form-grid view-grid" style="margin-bottom:.5rem;">
                        <div class="field"><label class="field-label">Role</label>
                            <select name="sp_role[<?= $i ?>]">
                                <option value=""<?= (($_POST['sp_role'][$i] ?? ($spRows[$i]['role'] ?? '')) === '') ? ' selected' : '' ?>>— select —</option>
                                <option value="witness"<?= (($_POST['sp_role'][$i] ?? ($spRows[$i]['role'] ?? '')) === 'witness') ? ' selected' : '' ?>>Witness</option>
                                <option value="principal_sponsor"<?= (($_POST['sp_role'][$i] ?? ($spRows[$i]['role'] ?? '')) === 'principal_sponsor') ? ' selected' : '' ?>>Principal sponsor</option>
                            </select>
                        </div>
                        <div class="field"><label class="field-label">Full name</label><input type="text" name="sp_name[<?= $i ?>]" value="<?= e((string) ($_POST['sp_name'][$i] ?? ($spRows[$i]['full_name'] ?? ''))) ?>"></div>
                        <div class="field"><label class="field-label">Residence</label><input type="text" name="sp_residence[<?= $i ?>]" value="<?= e((string) ($_POST['sp_residence'][$i] ?? ($spRows[$i]['residence'] ?? ''))) ?>"></div>
                    </div>
                <?php endfor; ?>
            </section>

            <section class="panel-card">
                <h2>Remarks</h2>
                <textarea name="remarks" rows="3" style="width:100%;"><?= e((string) ($_POST['remarks'] ?? ($prefill['remarks'] ?? ''))) ?></textarea>
            </section>

            <div style="margin-top:1rem;">
                <button type="submit" class="btn btn-primary">Save <?= e(sd_type_label($type)) ?></button>
                <a href="<?= e($basePath) ?>/admin/dashboard.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    <?php endif; ?>

<?php else: ?>
    <?php // ---- Non-marriage: person identity (read-only or editable) ---- ?>
    <?php if ($person || $personId > 0): ?>
        <?php if ($person): ?>
            <p class="field-hint"><strong><?= e(sd_full_name($person)) ?></strong> &middot; born <?= e($person['birth_date'] ? date('M j, Y', strtotime($person['birth_date'])) : 'not specified') ?> &middot; <?= e($person['birth_place'] ?: 'no birth place') ?></p>
            <p class="field-hint">Father: <?= e($person['father_name'] ?: 'not specified') ?> &middot; Mother: <?= e($person['mother_maiden_name'] ?: 'not specified') ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ---- Possible match box (baptism only) ---- ?>
    <?php if ($matchBox !== null): ?>
        <div class="notice notice-warning" style="margin-bottom:1rem;">
            <p><strong>Possible match:</strong></p>
            <ul>
                <?php foreach ($matchBox as $m): ?>
                    <li><a href="<?= e($basePath) ?>/admin/system/person-profile.php?id=<?= (int) $m['id'] ?>" target="_blank"><?= e($m['full_name']) ?></a> (<?= e($m['birth_date'] ?: 'no birth date') ?>)</li>
                <?php endforeach; ?>
            </ul>
            <form method="post" style="margin-top:.5rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="confirm_new" value="1">
                <?php foreach (['first_name','middle_name','last_name','suffix','sex','birth_date','birth_place','father_name','mother_maiden_name','address','event_date','place','minister_name','source','parents_union_type','entry_no','book_no','page_no','line_no','father_birthplace','mother_birthplace','parents_origin','parents_residence','proxy_name','remarks'] as $f): ?>
                    <input type="hidden" name="<?= e($f) ?>" value="<?= e((string) ($_POST[$f] ?? '')) ?>">
                <?php endforeach; ?>
                <button type="submit" class="btn btn-small">No, this is a different person</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($isBaptism && !$person && $personId < 1): ?>
        <!-- Person identity form (baptism new person) -->
    <?php endif; ?>

    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($personId > 0): ?>
            <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
        <?php endif; ?>

        <!-- A. Person (identity) — baptism only, when no person exists yet -->
        <?php if ($isBaptism && !$person && $personId < 1): ?>
            <section class="panel-card">
                <h2>Person (identity)</h2>
                <div class="form-grid view-grid">
                    <div class="field"><label class="field-label">First name (required)</label><input type="text" name="first_name" value="<?= e((string) ($_POST['first_name'] ?? '')) ?>" required></div>
                    <div class="field"><label class="field-label">Middle name</label><input type="text" name="middle_name" value="<?= e((string) ($_POST['middle_name'] ?? '')) ?>"></div>
                    <div class="field"><label class="field-label">Last name (required)</label><input type="text" name="last_name" value="<?= e((string) ($_POST['last_name'] ?? '')) ?>" required></div>
                    <div class="field"><label class="field-label">Suffix</label><input type="text" name="suffix" value="<?= e((string) ($_POST['suffix'] ?? '')) ?>"></div>
                    <div class="field"><label class="field-label">Sex</label>
                        <select name="sex">
                            <option value=""<?= (($_POST['sex'] ?? '') === '') ? ' selected' : '' ?>>Not specified</option>
                            <option value="male"<?= (($_POST['sex'] ?? '') === 'male') ? ' selected' : '' ?>>Male</option>
                            <option value="female"<?= (($_POST['sex'] ?? '') === 'female') ? ' selected' : '' ?>>Female</option>
                        </select>
                    </div>
                    <div class="field"><label class="field-label">Birth date</label><input type="date" name="birth_date" value="<?= e((string) ($_POST['birth_date'] ?? '')) ?>"></div>
                    <div class="field"><label class="field-label">Birth place</label><input type="text" name="birth_place" value="<?= e((string) ($_POST['birth_place'] ?? '')) ?>"></div>
                    <div class="field"><label class="field-label">Father's name</label><input type="text" name="father_name" value="<?= e((string) ($_POST['father_name'] ?? '')) ?>"></div>
                    <div class="field"><label class="field-label">Mother's maiden name</label><input type="text" name="mother_maiden_name" value="<?= e((string) ($_POST['mother_maiden_name'] ?? '')) ?>"></div>
                    <div class="field"><label class="field-label">Address</label><input type="text" name="address" value="<?= e((string) ($_POST['address'] ?? '')) ?>"></div>
                </div>
            </section>
        <?php endif; ?>

        <!-- B. Record -->
        <section class="panel-card">
            <h2>Record</h2>
            <div class="form-grid view-grid">
                <div class="field"><label class="field-label">Event date (required)</label><input type="date" name="event_date" value="<?= e((string) ($_POST['event_date'] ?? ($prefill['event_date'] ?? ''))) ?>" required></div>
                <div class="field"><label class="field-label">Place</label><input type="text" name="place" value="<?= e((string) ($_POST['place'] ?? ($prefill['place'] ?? 'St. Patrick Parish'))) ?>"></div>
                <div class="field"><label class="field-label">Minister name</label><input type="text" name="minister_name" value="<?= e((string) ($_POST['minister_name'] ?? ($prefill['minister_name'] ?? ''))) ?>"></div>
                <div class="field"><label class="field-label">Source</label>
                    <?php $src = (string) ($_POST['source'] ?? ($prefill['source'] ?? 'this_parish')); ?>
                    <label><input type="radio" name="source" value="this_parish"<?= $src === 'this_parish' ? ' checked' : '' ?>> This parish</label>
                    <label><input type="radio" name="source" value="other_parish"<?= $src === 'other_parish' ? ' checked' : '' ?>> Other parish</label>
                </div>
                <?php if ($isBaptism): ?>
                    <div class="field"><label class="field-label">Parents' marital status</label>
                        <select name="parents_union_type">
                            <option value=""<?= (($_POST['parents_union_type'] ?? ($prefill['parents_union_type'] ?? '')) === '') ? ' selected' : '' ?>>— not specified —</option>
                            <option value="legitimate"<?= (($_POST['parents_union_type'] ?? ($prefill['parents_union_type'] ?? '')) === 'legitimate') ? ' selected' : '' ?>>Church married</option>
                            <option value="civil"<?= (($_POST['parents_union_type'] ?? ($prefill['parents_union_type'] ?? '')) === 'civil') ? ' selected' : '' ?>>Civilly married</option>
                            <option value="natural"<?= (($_POST['parents_union_type'] ?? ($prefill['parents_union_type'] ?? '')) === 'natural') ? ' selected' : '' ?>>Unmarried</option>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if ($type === 'first_communion'): ?>
                    <div class="field"><label class="field-label">Domicile</label><input type="text" name="domicile" value="<?= e((string) ($_POST['domicile'] ?? ($prefillDetails['domicile'] ?? ''))) ?>"></div>
                <?php endif; ?>
                <?php if ($type === 'confirmation'): ?>
                    <div class="field"><label class="field-label">Sponsor name</label><input type="text" name="sponsor_name" value="<?= e((string) ($_POST['sponsor_name'] ?? '')) ?>"></div>
                    <div class="field"><label class="field-label">Sponsor residence</label><input type="text" name="sponsor_residence" value="<?= e((string) ($_POST['sponsor_residence'] ?? '')) ?>"></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- C. Logbook -->
        <section class="panel-card">
            <h2>Logbook</h2>
            <div class="form-grid view-grid">
                <div class="field"><label class="field-label">Entry no.</label><input type="text" name="entry_no" value="<?= e((string) ($_POST['entry_no'] ?? ($prefill['entry_no'] ?? $logbookDefaults['entry_no']))) ?>"></div>
                <div class="field"><label class="field-label">Book no.</label><input type="text" name="book_no" value="<?= e((string) ($_POST['book_no'] ?? ($prefill['book_no'] ?? $logbookDefaults['book_no']))) ?>"></div>
                <div class="field"><label class="field-label">Page no.</label><input type="text" name="page_no" value="<?= e((string) ($_POST['page_no'] ?? ($prefill['page_no'] ?? $logbookDefaults['page_no']))) ?>"></div>
                <div class="field"><label class="field-label">Line no.</label><input type="text" name="line_no" value="<?= e((string) ($_POST['line_no'] ?? ($prefill['line_no'] ?? $logbookDefaults['line_no']))) ?>"></div>
            </div>
        </section>

        <!-- D. Details (JSON keys) — baptism only -->
        <?php if ($isBaptism): ?>
            <section class="panel-card">
                <h2>Details</h2>
                <div class="form-grid view-grid">
                    <div class="field"><label class="field-label">Father's birthplace</label><input type="text" name="father_birthplace" value="<?= e((string) ($_POST['father_birthplace'] ?? ($prefillDetails['father_birthplace'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Mother's birthplace</label><input type="text" name="mother_birthplace" value="<?= e((string) ($_POST['mother_birthplace'] ?? ($prefillDetails['mother_birthplace'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Parents' origin</label><input type="text" name="parents_origin" value="<?= e((string) ($_POST['parents_origin'] ?? ($prefillDetails['parents_origin'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Parents' residence</label><input type="text" name="parents_residence" value="<?= e((string) ($_POST['parents_residence'] ?? ($prefillDetails['parents_residence'] ?? ''))) ?>"></div>
                    <div class="field"><label class="field-label">Proxy name</label><input type="text" name="proxy_name" value="<?= e((string) ($_POST['proxy_name'] ?? ($prefillDetails['proxy_name'] ?? ''))) ?>"></div>
                </div>
            </section>

            <!-- E. Godparents -->
            <section class="panel-card">
                <h2>Godparents</h2>
                <?php
                $gpRows = [];
                if (!empty($existingParticipants)) {
                    foreach ($existingParticipants as $p) {
                        $gpRows[] = $p;
                    }
                }
                while (count($gpRows) < 4) {
                    $gpRows[] = ['role' => '', 'full_name' => '', 'residence' => ''];
                }
                ?>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="form-grid view-grid" style="margin-bottom:.5rem;">
                        <div class="field"><label class="field-label">Role</label>
                            <select name="gp_role[<?= $i ?>]">
                                <option value=""<?= (($_POST['gp_role'][$i] ?? ($gpRows[$i]['role'] ?? '')) === '') ? ' selected' : '' ?>>— select —</option>
                                <option value="godfather"<?= (($_POST['gp_role'][$i] ?? ($gpRows[$i]['role'] ?? '')) === 'godfather') ? ' selected' : '' ?>>Godfather</option>
                                <option value="godmother"<?= (($_POST['gp_role'][$i] ?? ($gpRows[$i]['role'] ?? '')) === 'godmother') ? ' selected' : '' ?>>Godmother</option>
                            </select>
                        </div>
                        <div class="field"><label class="field-label">Full name</label><input type="text" name="gp_name[<?= $i ?>]" value="<?= e((string) ($_POST['gp_name'][$i] ?? ($gpRows[$i]['full_name'] ?? ''))) ?>"></div>
                        <div class="field"><label class="field-label">Residence</label><input type="text" name="gp_residence[<?= $i ?>]" value="<?= e((string) ($_POST['gp_residence'][$i] ?? ($gpRows[$i]['residence'] ?? ''))) ?>"></div>
                    </div>
                <?php endfor; ?>
            </section>
        <?php endif; ?>

        <!-- F. Remarks -->
        <section class="panel-card">
            <h2>Remarks</h2>
            <textarea name="remarks" rows="3" style="width:100%;"><?= e((string) ($_POST['remarks'] ?? ($prefill['remarks'] ?? ''))) ?></textarea>
        </section>

        <div style="margin-top:1rem;">
            <button type="submit" class="btn btn-primary">Save <?= e(sd_type_label($type)) ?></button>
            <a href="<?= e($basePath) ?>/admin/dashboard.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>