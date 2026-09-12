<?php
/**
 * =====================================================================
 * SRS-SPPO BAPTISMAL RECORDS (admin register)
 * =====================================================================
 * Purpose: Digital mirror of the physical baptismal register. The
 *          secretary encodes entries from the physical logbook (never
 *          replacing it), lists/searches them, and this is the source
 *          the certificate generator validates requests against.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Baptismal Records';
$errors  = [];
$success = null;

// --- Handle new record submission (secretary/priest only encode from the physical logbook) ---
// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form). Nothing is written when it fails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record']) && csrf_verify()) {
    require_role('secretary'); // encoding records is a secretary task; priest is read-only here

    $entryNo          = trim($_POST['entry_no'] ?? '');
    $baptismDate      = $_POST['baptism_date'] ?? '';
    $baptismPlace     = trim($_POST['baptism_place'] ?? '');
    $childName        = trim($_POST['child_full_name'] ?? '');
    $birthDate        = $_POST['birth_date'] ?: null;
    $legitimacy       = $_POST['legitimacy'] ?: null;
    $fatherName       = trim($_POST['father_name'] ?? '');
    $motherName       = trim($_POST['mother_name'] ?? '');
    $parentsOrigin    = trim($_POST['parents_origin'] ?? '');
    $parentsResidence = trim($_POST['parents_residence'] ?? '');
    $ministerName     = trim($_POST['minister_name'] ?? '');
    $remarks          = trim($_POST['remarks'] ?? '');
    $bookNo           = trim($_POST['book_no'] ?? '');
    $pageNo           = trim($_POST['page_no'] ?? '');
    $lineNo           = trim($_POST['line_no'] ?? '');

    // Sponsors (Godfathers and Godmothers) - separate arrays for each type
    $godfatherNames      = $_POST['godfather_name'] ?? [];
    $godfatherResidences = $_POST['godfather_residence'] ?? [];
    $godmotherNames      = $_POST['godmother_name'] ?? [];
    $godmotherResidences = $_POST['godmother_residence'] ?? [];
    
    // Keep old field names for backward compatibility with database
    $sponsorNames      = array_merge($godfatherNames, $godmotherNames);
    $sponsorResidences = array_merge($godfatherResidences, $godmotherResidences);

    if ($baptismDate === '') $errors[] = 'Baptism date is required.';
    if ($childName === '')   $errors[] = "Child's full name is required.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Make room for a mid-register insert when requested.
            $shifted = 0;
            if ($entryNo !== '' && ($_POST['entry_conflict'] ?? 'shift') === 'shift') {
                $shifted = register_shift_entry_numbers($pdo, 'baptismal_records', $entryNo);
            }

            // Collect new parish form fields
            $unionType       = $_POST['union_type'] ?: null;
            $fatherBirthplace = trim($_POST['father_birthplace'] ?? '');
            $motherMaidenName = trim($_POST['mother_maiden_name'] ?? '');
            $motherBirthplace = trim($_POST['mother_birthplace'] ?? '');

            $stmt = $pdo->prepare(
                "INSERT INTO baptismal_records
                    (entry_no, baptism_date, baptism_place, child_full_name, birth_date, legitimacy, union_type,
                     father_name, father_birthplace, mother_name, mother_maiden_name, mother_birthplace,
                     parents_origin, parents_residence, minister_name, remarks,
                     book_no, page_no, line_no, encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $baptismDate, $baptismPlace, $childName, $birthDate, $legitimacy, $unionType,
                $fatherName, $fatherBirthplace, $motherName, $motherMaidenName, $motherBirthplace,
                $parentsOrigin, $parentsResidence, $ministerName, $remarks,
                $bookNo, $pageNo, $lineNo, current_user()['id'],
            ]);
            $recordId = (int) $pdo->lastInsertId();

            // Insert godfathers (Ninong)
            $sponsorStmt = $pdo->prepare(
                "INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type) VALUES (?, ?, ?, 'godfather')"
            );
            foreach ($godfatherNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $sponsorStmt->execute([$recordId, $name, trim($godfatherResidences[$i] ?? '')]);
            }
            
            // Insert godmothers (Ninang)
            $sponsorStmt2 = $pdo->prepare(
                "INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type) VALUES (?, ?, ?, 'godmother')"
            );
            foreach ($godmotherNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $sponsorStmt2->execute([$recordId, $name, trim($godmotherResidences[$i] ?? '')]);
            }

            log_activity($pdo, current_user()['id'], 'baptismal_record_encoded', 'baptismal_records', $recordId,
                "Baptismal record encoded for {$childName}" . ($shifted > 0 ? " — {$shifted} later entries renumbered" : ''));

            $pdo->commit();
            redirect_to('/admin/records/baptismal.php?added=1' . ($shifted > 0 ? '&shifted=' . $shifted : ''));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}


// --- Update an existing record (Edit button on a table row) -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record']) && csrf_verify()) {
    require_role('secretary');

    $editId           = (int) ($_POST['edit_id'] ?? 0);
    $unionType        = $_POST['union_type'] ?: null;
    $fatherBirthplace = trim($_POST['father_birthplace'] ?? '');
    $motherMaidenName = trim($_POST['mother_maiden_name'] ?? '');
    $motherBirthplace = trim($_POST['mother_birthplace'] ?? '');
    $godfatherNames      = $_POST['godfather_name'] ?? [];
    $godfatherResidences = $_POST['godfather_residence'] ?? [];
    $godmotherNames      = $_POST['godmother_name'] ?? [];
    $godmotherResidences = $_POST['godmother_residence'] ?? [];
    $entryNo          = trim($_POST['entry_no'] ?? '');
    $baptismDate      = $_POST['baptism_date'] ?? '';
    $baptismPlace     = trim($_POST['baptism_place'] ?? '');
    $childName        = trim($_POST['child_full_name'] ?? '');
    $birthDate        = $_POST['birth_date'] ?: null;
    $legitimacy       = $_POST['legitimacy'] ?: null;
    $fatherName       = trim($_POST['father_name'] ?? '');
    $motherName       = trim($_POST['mother_name'] ?? '');
    $parentsOrigin    = trim($_POST['parents_origin'] ?? '');
    $parentsResidence = trim($_POST['parents_residence'] ?? '');
    $ministerName     = trim($_POST['minister_name'] ?? '');
    $remarks          = trim($_POST['remarks'] ?? '');
    $bookNo           = trim($_POST['book_no'] ?? '');
    $pageNo           = trim($_POST['page_no'] ?? '');
    $lineNo           = trim($_POST['line_no'] ?? '');
    $sponsorNames      = $_POST['sponsor_name'] ?? [];
    $sponsorResidences = $_POST['sponsor_residence'] ?? [];

    if ($baptismDate === '') $errors[] = 'Baptism date is required.';
    if ($childName === '')   $errors[] = "Child's full name is required.";
    if ($editId <= 0)        $errors[] = 'Invalid record selected for update.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "UPDATE baptismal_records
                    SET entry_no = ?, baptism_date = ?, baptism_place = ?, child_full_name = ?, birth_date = ?,
                        legitimacy = ?, union_type = ?, father_name = ?, father_birthplace = ?,
                        mother_name = ?, mother_maiden_name = ?, mother_birthplace = ?, parents_origin = ?,
                        parents_residence = ?, minister_name = ?, remarks = ?, book_no = ?,
                        page_no = ?, line_no = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $entryNo, $baptismDate, $baptismPlace, $childName, $birthDate, $legitimacy, $unionType,
                $fatherName, $fatherBirthplace, $motherName, $motherMaidenName, $motherBirthplace, $parentsOrigin,
                $parentsResidence, $ministerName, $remarks,
                $bookNo, $pageNo, $lineNo, $editId,
            ]);

            // Replace the sponsor set wholesale with what was submitted.
            $pdo->prepare("DELETE FROM baptismal_sponsors WHERE baptismal_record_id = ?")->execute([$editId]);
            $gfStmt = $pdo->prepare(
                "INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type) VALUES (?, ?, ?, 'godfather')"
            );
            foreach ($godfatherNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $gfStmt->execute([$editId, $name, trim($godfatherResidences[$i] ?? '')]);
            }
            $gmStmt = $pdo->prepare(
                "INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type) VALUES (?, ?, ?, 'godmother')"
            );
            foreach ($godmotherNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $gmStmt->execute([$editId, $name, trim($godmotherResidences[$i] ?? '')]);
            }

            log_activity($pdo, current_user()['id'], 'baptismal_record_updated', 'baptismal_records', $editId,
                "Baptismal record updated for {$childName}");

            $pdo->commit();
            redirect_to('/admin/records/baptismal.php?updated=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while updating this record. Please try again.';
        }
    }
}

// --- Delete a record (Delete button on a table row; linked refs unlink) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record']) && csrf_verify()) {
    require_role('secretary');

    $delId = (int) ($_POST['delete_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT child_full_name FROM baptismal_records WHERE id = ?");
        $stmt->execute([$delId]);
        $deletedName = (string) ($stmt->fetchColumn() ?: 'Unknown');

        $stmt = $pdo->prepare("DELETE FROM baptismal_records WHERE id = ?");
        $stmt->execute([$delId]);

        log_activity($pdo, current_user()['id'], 'baptismal_record_deleted', 'baptismal_records', $delId,
            "Baptismal record deleted ({$deletedName})");

        redirect_to('/admin/records/baptismal.php?deleted=1');
        exit;
    } catch (Exception $e) {
        $errors[] = 'Something went wrong while deleting this record. Please try again.';
    }
}

// --- Edit mode: load the requested record into the encode form -----------
$editRecord   = null;
$editSponsors = [];
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM baptismal_records WHERE id = ?");
    $stmt->execute([$editId]);
    $editRecord = $stmt->fetch();
    if (!$editRecord) {
        redirect_to('/admin/records/baptismal.php');
        exit;
    }
    $stmt = $pdo->prepare("SELECT sponsor_name, sponsor_residence, sponsor_type FROM baptismal_sponsors WHERE baptismal_record_id = ? ORDER BY id");
    $stmt->execute([(int) $editRecord['id']]);
    $editSponsors = $stmt->fetchAll();
    foreach ($editSponsors as $sp) {
        if (($sp['sponsor_type'] ?? 'godfather') === 'godmother') { $editGodmothers[] = $sp; }
        else { $editGodfathers[] = $sp; }
    }
}
$isEdit = $editRecord !== null;

// Suggested next logbook numbers for the encode dialog (create mode only).
$suggest = $isEdit
    ? ['entry_no' => '', 'book_no' => '', 'page_no' => '', 'line_no' => '']
    : register_next_logbook_numbers($pdo, 'baptismal_records');

if (isset($_GET['added'])) $success = 'Baptismal record saved.' . (isset($_GET['shifted']) ? ' Following entries were renumbered to make room.' : '');
if (isset($_GET['updated'])) $success = 'Baptismal record updated.';
if (isset($_GET['deleted'])) $success = 'Baptismal record deleted.';

// --- Filter / paginate (shared component) --------------------------------
$rfConfig = [
    'date_column'    => 'baptism_date',
    'search_columns' => ['child_full_name', 'father_name', 'mother_name', 'entry_no', 'minister_name'],
    'placeholder'    => 'Search child, father, mother, entry no., minister&hellip;',
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM baptismal_records WHERE 1=1 {$rf['where']}");
$stmt->execute($rf['params']);
$rfTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM baptismal_records WHERE 1=1 {$rf['where']}
     ORDER BY baptism_date DESC LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$records = $stmt->fetchAll();

$isSecretary = current_user()['role'] === 'secretary';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="page-heading">
            <h1>Baptismal Records</h1>
            <button type="button" class="btn btn-success btn-small" data-open-dialog="#encode-dialog">+ Add New Entry</button>
        </div>
        <p class="field-hint">This is a digital index of the physical baptismal logbook, used for fast retrieval when matching certificate requests. The physical logbook remains the canonical record.</p>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>

                <?php rf_render_bar($basePath . '/admin/records/baptismal.php', $rfConfig, $rf); ?>
        <?php rf_render_pagination($basePath . '/admin/records/baptismal.php', $rfTotal, $rf); ?>

        <?php if (empty($records)): ?>
            <p>No baptismal records found<?= $rf['q'] !== '' ? ' for "' . e($rf['q']) . '"' : '' ?>.</p>
        <?php else: ?>
        <table id="record-table" class="admin-table">
            <thead>
                <tr>
                    <th>Child's Name</th>
                    <th>Baptism Date</th>
                    <th>Place of Baptism</th>
                    <th>Parents</th>
                    <th>Minister</th>
                    <th>Book/Page/Line</th>
                    <th>Certificate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                    <tr>
                        <td><?= e($rec['child_full_name']) ?></td>
                        <td><?= e(date('M j, Y', strtotime($rec['baptism_date']))) ?></td>
                        <td><?= e($rec['baptism_place'] ?? '') ?></td>
                        <td><?= e($rec['father_name']) ?> &amp; <?= e($rec['mother_name']) ?></td>
                        <td><?= e($rec['minister_name']) ?></td>
                        <td><?= e($rec['book_no']) ?> / <?= e($rec['page_no']) ?> / <?= e($rec['line_no']) ?></td>
                                                <td class="row-actions">
                            <a href="<?= $basePath ?>/admin/certificates/generate.php?record_type=baptismal&record_id=<?= (int) $rec['id'] ?>" class="btn btn-success btn-small">Generate</a>
                            <a href="?edit=<?= (int) $rec['id'] ?>" class="btn btn-warning btn-small">Edit</a>
                            <form method="post" action="" class="inline-form" data-confirm="Delete this baptismal record permanently? Linked entries will be unlinked.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_record" value="1">
                                <input type="hidden" name="delete_id" value="<?= (int) $rec['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-small">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php rf_render_pagination($basePath . '/admin/records/baptismal.php', $rfTotal, $rf); ?>

        <dialog id="encode-dialog" class="encode-dialog"<?= ($isEdit || !empty($errors)) ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $isEdit ? 'Edit Entry #' . (int) $editRecord['id'] . ' - ' . e($editRecord['child_full_name']) : 'Encode New Entry' ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>
            <div class="dialog-body">
        <?php if ($isEdit): ?>
            <p class="field-hint"><a href="<?= $basePath ?>/admin/records/baptismal.php">Cancel edit - back to adding a new entry.</a></p>
        <?php else: ?>
            <p class="field-hint">Use this to digitize an entry from the physical logbook. This does not replace the physical record.</p>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" action="">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editRecord['id'] ?>"><?php endif; ?>
            <fieldset>
                <legend>Logbook Reference</legend>
                <div class="inline-fields">
                    <label>Entry No. <input type="text" name="entry_no" value="<?= e($_POST['entry_no'] ?? $editRecord['entry_no'] ?? $suggest['entry_no']) ?>"></label>
                    <label>Book No. <input type="text" name="book_no" value="<?= e($_POST['book_no'] ?? $editRecord['book_no'] ?? $suggest['book_no']) ?>"></label>
                    <label>Page <input type="text" name="page_no" value="<?= e($_POST['page_no'] ?? $editRecord['page_no'] ?? $suggest['page_no']) ?>"></label>
                    <label>Line <input type="text" name="line_no" value="<?= e($_POST['line_no'] ?? $editRecord['line_no'] ?? $suggest['line_no']) ?>"></label>
                </div>
                <?php if (!$isEdit): ?>
                <label>If the Entry No. already exists
                    <select name="entry_conflict">
                        <option value="shift" selected>Shift later entries to make room</option>
                        <option value="keep">Save as entered (duplicate allowed)</option>
                    </select>
                </label>
                <p class="field-hint">Suggestions are pre-filled from the last entry — edit them freely. "Shift" bumps every later entry with the same numbering up by one, for inserting into the middle of the register.</p>
                <?php endif; ?>
            </fieldset>

            <fieldset>
                <legend>Child</legend>
                <label>Full Name * <input type="text" name="child_full_name" value="<?= e($_POST['child_full_name'] ?? $editRecord['child_full_name'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Birth Date <input type="date" name="birth_date" value="<?= e($_POST['birth_date'] ?? $editRecord['birth_date'] ?? '') ?>"></label>
                    <label>Baptism Date * <input type="date" name="baptism_date" value="<?= e($_POST['baptism_date'] ?? $editRecord['baptism_date'] ?? '') ?>" required></label>
                    <label>Place of Baptism <input type="text" name="baptism_place" value="<?= e($_POST['baptism_place'] ?? $editRecord['baptism_place'] ?? '') ?>"></label>
                </div>
                <label>Legitimacy (internal only — never shown on certificates or public output)
                    <select name="legitimacy">
                        <option value="" <?= ($_POST['legitimacy'] ?? $editRecord['legitimacy'] ?? '') === '' ? 'selected' : '' ?>>-- Not specified --</option>
                        <option value="legitimate" <?= ($_POST['legitimacy'] ?? $editRecord['legitimacy'] ?? '') === 'legitimate' ? 'selected' : '' ?>>Legitimate</option>
                        <option value="illegitimate" <?= ($_POST['legitimacy'] ?? $editRecord['legitimacy'] ?? '') === 'illegitimate' ? 'selected' : '' ?>>Illegitimate</option>
                    </select>
                </label>
            </fieldset>

            <fieldset>
                <legend>Parents</legend>
                <div class="inline-fields">
                    <label>Father's Name <input type="text" name="father_name" value="<?= e($_POST['father_name'] ?? $editRecord['father_name'] ?? '') ?>"></label>
                    <label>Father's Birthplace <input type="text" name="father_birthplace" value="<?= e($_POST['father_birthplace'] ?? $editRecord['father_birthplace'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Mother's Maiden Name <input type="text" name="mother_name" value="<?= e($_POST['mother_name'] ?? $editRecord['mother_name'] ?? '') ?>"></label>
                    <label>Mother's Birthplace <input type="text" name="mother_birthplace" value="<?= e($_POST['mother_birthplace'] ?? $editRecord['mother_birthplace'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Parents' Origin (Hometown) <input type="text" name="parents_origin" value="<?= e($_POST['parents_origin'] ?? $editRecord['parents_origin'] ?? '') ?>"></label>
                    <label>Parents' Residence <input type="text" name="parents_residence" value="<?= e($_POST['parents_residence'] ?? $editRecord['parents_residence'] ?? '') ?>"></label>
                </div>
                <!-- Union type: Legitimate (married in church), Civil (married civilly), Natural (not married) -->
                <label>Union Type
                    <select name="union_type">
                        <option value="" <?= ($_POST['union_type'] ?? $editRecord['union_type'] ?? '') === '' ? 'selected' : '' ?>>-- Not specified --</option>
                        <option value="legitimate" <?= ($_POST['union_type'] ?? $editRecord['union_type'] ?? '') === 'legitimate' ? 'selected' : '' ?>>Legitimate (Church Marriage)</option>
                        <option value="civil" <?= ($_POST['union_type'] ?? $editRecord['union_type'] ?? '') === 'civil' ? 'selected' : '' ?>>Civil (Civil Marriage)</option>
                        <option value="natural" <?= ($_POST['union_type'] ?? $editRecord['union_type'] ?? '') === 'natural' ? 'selected' : '' ?>>Natural (Not Married)</option>
                    </select>
                </label>
            </fieldset>

            <!-- Godfathers (Ninong) - up to 8 -->
            <fieldset>
                <legend>Godfathers (Ninong) - Up to 8</legend>
                <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="inline-fields">
                        <label>Godfather Name <input type="text" name="godfather_name[]" value="<?= e($_POST['godfather_name'][$i] ?? $editGodfathers[$i]['sponsor_name'] ?? '') ?>"></label>
                        <label>Residence <input type="text" name="godfather_residence[]" value="<?= e($_POST['godfather_residence'][$i] ?? $editGodfathers[$i]['sponsor_residence'] ?? '') ?>"></label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <!-- Godmothers (Ninang) - up to 8 -->
            <fieldset>
                <legend>Godmothers (Ninang) - Up to 8</legend>
                <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="inline-fields">
                        <label>Godmother Name <input type="text" name="godmother_name[]" value="<?= e($_POST['godmother_name'][$i] ?? $editGodmothers[$i]['sponsor_name'] ?? '') ?>"></label>
                        <label>Residence <input type="text" name="godmother_residence[]" value="<?= e($_POST['godmother_residence'][$i] ?? $editGodmothers[$i]['sponsor_residence'] ?? '') ?>"></label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
                <label>Officiating Minister <input type="text" name="minister_name" value="<?= e($_POST['minister_name'] ?? $editRecord['minister_name'] ?? '') ?>"></label>
                <label>Remarks / Observanda <textarea name="remarks"><?= e($_POST['remarks'] ?? $editRecord['remarks'] ?? '') ?></textarea></label>
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

    <?php require_once __DIR__ . '/../../includes/admin-footer.php';