<?php
/**
 * =====================================================================
 * SRS-SPPO RECORD FORM (admin, schema v2) — BAPTISM ONLY in this task
 * =====================================================================
 * Purpose: Create or edit a baptismal sacrament record linked to a person.
 *          Identity is read-only for an existing person; for a new person
 *          the form collects identity plus the record in one submit.
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

if ($type !== 'baptism') {
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

// ---- Load existing record when editing ----
$existingRecord = null;
$existingParticipants = [];
if ($editId > 0) {
    $existingRecord = sd_get_record($pdo, $editId);
    if ($existingRecord && (string) ($existingRecord['sacrament_type'] ?? '') === 'baptism') {
        $existingRecord = sd_decode_record($existingRecord);
        $existingParticipants = sd_get_participants($pdo, $editId);
        if ($personId < 1 && !empty($existingRecord['person_id'])) {
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
$matchBox = null; // possible-match list for new person

// ---- POST handling ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $notice = 'Your session token is invalid or expired. Refresh the page and try again.';
        $noticeType = 'error';
    } else {
        $action = trim((string) ($_POST['action'] ?? 'save'));
        $confirmNew = (string) ($_POST['confirm_new'] ?? '') === '1';

        // Collect identity (only used when no person_id exists yet)
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

        $eventDate = trim((string) ($_POST['event_date'] ?? ''));
        $place = trim((string) ($_POST['place'] ?? ''));
        $ministerName = trim((string) ($_POST['minister_name'] ?? ''));
        $source = trim((string) ($_POST['source'] ?? 'this_parish'));
        $parentsUnion = trim((string) ($_POST['parents_union_type'] ?? ''));

        $entryNo = trim((string) ($_POST['entry_no'] ?? ''));
        $bookNo = trim((string) ($_POST['book_no'] ?? ''));
        $pageNo = trim((string) ($_POST['page_no'] ?? ''));
        $lineNo = trim((string) ($_POST['line_no'] ?? ''));

        $fatherBirthplace = trim((string) ($_POST['father_birthplace'] ?? ''));
        $motherBirthplace = trim((string) ($_POST['mother_birthplace'] ?? ''));
        $parentsOrigin = trim((string) ($_POST['parents_origin'] ?? ''));
        $parentsResidence = trim((string) ($_POST['parents_residence'] ?? ''));
        $proxyName = trim((string) ($_POST['proxy_name'] ?? ''));

        $remarks = trim((string) ($_POST['remarks'] ?? ''));

        // Godparents: 4 rows
        $participants = [];
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

        // New person: possible-match check
        $effectivePersonId = $personId;
        if ($effectivePersonId < 1 && $lastName !== '') {
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
            // Create new person if needed
            if ($effectivePersonId < 1) {
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
                // Build details array (omit blanks)
                $details = [];
                if ($fatherBirthplace !== '') $details['father_birthplace'] = $fatherBirthplace;
                if ($motherBirthplace !== '') $details['mother_birthplace'] = $motherBirthplace;
                if ($parentsOrigin !== '') $details['parents_origin'] = $parentsOrigin;
                if ($parentsResidence !== '') $details['parents_residence'] = $parentsResidence;
                if ($proxyName !== '') $details['proxy_name'] = $proxyName;

                $recordData = [
                    'sacrament_type' => 'baptism',
                    'person_id' => $effectivePersonId,
                    'source' => $source,
                    'event_date' => $eventDate,
                    'place' => $place,
                    'minister_name' => $ministerName,
                    'entry_no' => $entryNo,
                    'book_no' => $bookNo,
                    'page_no' => $pageNo,
                    'line_no' => $lineNo,
                    'parents_union_type' => $parentsUnion,
                    'details' => $details,
                    'remarks' => $remarks,
                ];
                if ($editId > 0) {
                    $recordData['id'] = $editId;
                }

                try {
                    $recordId = sd_save_record($pdo, $recordData, $participants, (int) current_user()['id']);
                    $action = $editId > 0 ? 'record.update' : 'record.create';
                    log_activity($pdo, (int) current_user()['id'], $action, 'sacrament_records', $recordId, 'Baptism record saved');
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

// ---- Prefill for edit mode ----
$prefill = [];
if ($existingRecord) {
    $prefill = $existingRecord;
    $prefillDetails = $existingRecord['details'] ?? [];
} else {
    $prefillDetails = [];
}

// ---- Prefill logbook numbers for new records ----
$logbookDefaults = ['entry_no' => '', 'book_no' => 'I', 'page_no' => '1', 'line_no' => '1'];
if ($editId < 1) {
    try {
        $logbookDefaults = sd_next_logbook_numbers($pdo, 'baptism');
    } catch (Throwable $e) {
        // keep defaults
    }
}

// ---- Prerequisite warnings ----
$warnings = [];
if ($personId > 0) {
    $warnings = sd_prerequisite_warnings($pdo, $personId, 'baptism');
}

$pageTitle = 'Baptism Record';
require_once __DIR__ . '/../../includes/admin-header.php';
?>

<h1><?= e($editId > 0 ? 'Edit baptism record' : 'New baptism record') ?></h1>
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
            <?php for ($i = 0; $i < 4; $i++): ?>
                <input type="hidden" name="gp_role[<?= $i ?>]" value="<?= e((string) ($_POST['gp_role'][$i] ?? '')) ?>">
                <input type="hidden" name="gp_name[<?= $i ?>]" value="<?= e((string) ($_POST['gp_name'][$i] ?? '')) ?>">
                <input type="hidden" name="gp_residence[<?= $i ?>]" value="<?= e((string) ($_POST['gp_residence'][$i] ?? '')) ?>">
            <?php endfor; ?>
            <button type="submit" class="btn btn-small">No, this is a different person</button>
        </form>
    </div>
<?php endif; ?>

<form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <!-- A. Person (identity) -->
    <section class="panel-card">
        <h2>Person (identity)</h2>
        <?php if ($person || $personId > 0): ?>
            <?php if ($person): ?>
                <p class="field-hint"><strong><?= e(sd_full_name($person)) ?></strong> &middot; born <?= e($person['birth_date'] ? date('M j, Y', strtotime($person['birth_date'])) : 'not specified') ?> &middot; <?= e($person['birth_place'] ?: 'no birth place') ?></p>
                <p class="field-hint">Father: <?= e($person['father_name'] ?: 'not specified') ?> &middot; Mother: <?= e($person['mother_maiden_name'] ?: 'not specified') ?></p>
            <?php endif; ?>
            <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
        <?php else: ?>
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
        <?php endif; ?>
    </section>

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
            <div class="field"><label class="field-label">Parents' marital status</label>
                <select name="parents_union_type">
                    <option value=""<?= (($_POST['parents_union_type'] ?? ($prefill['parents_union_type'] ?? '')) === '') ? ' selected' : '' ?>>— not specified —</option>
                    <option value="legitimate"<?= (($_POST['parents_union_type'] ?? ($prefill['parents_union_type'] ?? '')) === 'legitimate') ? ' selected' : '' ?>>Church married</option>
                    <option value="civil"<?= (($_POST['parents_union_type'] ?? ($prefill['parents_union_type'] ?? '')) === 'civil') ? ' selected' : '' ?>>Civilly married</option>
                    <option value="natural"<?= (($_POST['parents_union_type'] ?? ($prefill['parents_union_type'] ?? '')) === 'natural') ? ' selected' : '' ?>>Unmarried</option>
                </select>
            </div>
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

    <!-- D. Details (JSON keys) -->
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
        // Pad to 4 rows
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

    <!-- F. Remarks -->
    <section class="panel-card">
        <h2>Remarks</h2>
        <textarea name="remarks" rows="3" style="width:100%;"><?= e((string) ($_POST['remarks'] ?? ($prefill['remarks'] ?? ''))) ?></textarea>
    </section>

    <div style="margin-top:1rem;">
        <button type="submit" class="btn btn-primary">Save baptism</button>
        <a href="<?= e($basePath) ?>/admin/dashboard.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>