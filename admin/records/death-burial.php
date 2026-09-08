<?php
/**
 * ================================================================
 * SPPO-IS DEATH / BURIAL RECORDS (admin register)
 * ================================================================
 * PURPOSE: Digital mirror of the Liber Defunctorum (Book of the Dead).
 *          The secretary encodes entries from the physical logbook.
 * 
 * FIELDS (per parish Liber Defunctorum):
 * - Deceased: Name, Date of Death, Date of Burial, Age at Death
 * - Personal: Civil Status, Spouse Name, Parents
 * - Sacraments: Baptism (B), Confirmation (C), Marriage (M), Anointing (An)
 * - Death Details: Cause of Death, Place of Burial
 * - Blessing/Mass: Date, Time, Minister
 * - Reference: Book/Page/Line, Contact Number
 * ================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Death / Burial Records';
$errors  = [];
$success = null;

// --- Handle new record submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid — please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record']) && csrf_verify()) {
    require_role('secretary');

    // Collect form fields
    $entryNo        = trim($_POST['entry_no'] ?? '');
    $deceasedName   = trim($_POST['deceased_full_name'] ?? '');
    $dateOfDeath    = $_POST['date_of_death'] ?? '';
    $dateOfBurial   = $_POST['date_of_burial'] ?? '';
    $ageAtDeath     = $_POST['age_at_death'] ?: null;
    $civilStatus    = $_POST['civil_status'] ?? '';
    $spouseName     = trim($_POST['spouse_name'] ?? '');
    $fatherName     = trim($_POST['father_name'] ?? '');
    $motherName     = trim($_POST['mother_name'] ?? '');
    $causeOfDeath   = trim($_POST['cause_of_death'] ?? '');
    $placeOfBurial  = trim($_POST['place_of_burial'] ?? '');
    $blessingDate   = $_POST['blessing_date'] ?? '';
    $blessingTime   = $_POST['blessing_time'] ?? '';
    $ministerName   = trim($_POST['minister_name'] ?? '');
    $bookNo         = trim($_POST['book_no'] ?? '');
    $pageNo         = trim($_POST['page_no'] ?? '');
    $lineNo         = trim($_POST['line_no'] ?? '');
    $contactNumber  = trim($_POST['contact_number'] ?? '');
    $remarks        = trim($_POST['remarks'] ?? '');

    // Sacraments received (checkboxes)
    $sacramentBaptism = isset($_POST['sacrament_baptism']) ? 1 : 0;
    $sacramentConfirmation = isset($_POST['sacrament_confirmation']) ? 1 : 0;
    $sacramentMarriage = isset($_POST['sacrament_marriage']) ? 1 : 0;
    $sacramentAnointing = isset($_POST['sacrament_anointing']) ? 1 : 0;

    // Validation
    if ($deceasedName === '') $errors[] = "Deceased's full name is required.";
    if ($dateOfDeath === '') $errors[] = 'Date of death is required.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO death_burial_records
                    (entry_no, deceased_full_name, date_of_death, date_of_burial, age_at_death,
                     civil_status, spouse_name, father_name, mother_name, cause_of_death,
                     place_of_burial, blessing_date, blessing_time, minister_name,
                     book_no, page_no, line_no, contact_number, remarks,
                     sacrament_baptism, sacrament_confirmation, sacrament_marriage, sacrament_anointing,
                     encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $deceasedName, $dateOfDeath, $dateOfBurial, $ageAtDeath,
                $civilStatus, $spouseName, $fatherName, $motherName, $causeOfDeath,
                $placeOfBurial, $blessingDate, $blessingTime, $ministerName,
                $bookNo, $pageNo, $lineNo, $contactNumber, $remarks,
                $sacramentBaptism, $sacramentConfirmation, $sacramentMarriage, $sacramentAnointing,
                current_user()['id'],
            ]);
            $recordId = (int) $pdo->lastInsertId();

            log_activity($pdo, current_user()['id'], 'death_burial_record_encoded', 'death_burial_records', $recordId,
                "Death/Burial record encoded for {$deceasedName}");

            $pdo->commit();
            redirect_to('/admin/records/death-burial.php?added=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

if (isset($_GET['added'])) $success = 'Death/Burial record saved.';
// --- Search / list ---
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM death_burial_records WHERE deceased_full_name LIKE ? ORDER BY date_of_death DESC LIMIT 100");
    $stmt->execute(['%' . $q . '%']);
} else {
    $stmt = $pdo->query("SELECT * FROM death_burial_records ORDER BY date_of_death DESC LIMIT 100");
}
$records = $stmt->fetchAll();

$isSecretary = current_user()['role'] === 'secretary';
?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
        <div class="panel-card">
            <p><a href="<?= base_path() ?>/admin/dashboard.php">&larr; Back to Dashboard</a></p>
            <h1>Death / Burial Records (Liber Defunctorum)</h1>
            <p class="field-hint">Digital index of the physical Death/Burial logbook, used for fast retrieval when matching certificate requests.</p>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="get" class="inline-fields filter-form">
            <label>Search by Name
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="e.g. Juan Dela Cruz">
            </label>
            <button type="submit" class="btn btn-small">Search</button>
            <?php if ($q !== ''): ?><a href="<?= base_path() ?>/admin/records/death-burial.php" class="btn btn-secondary btn-small">Clear</a><?php endif; ?>
        </form>

        <?php if (empty($records)): ?>
            <p>No death/burial records found<?= $q !== '' ? ' for "' . e($q) . '"' : '' ?>.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Date of Death</th>
                    <th>Date of Burial</th>
                    <th>Age</th>
                    <th>Civil Status</th>
                    <th>Minister</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                    <tr>
                        <td><?= e($rec['deceased_full_name']) ?></td>
                        <td><?= e(date('M j, Y', strtotime($rec['date_of_death']))) ?></td>
                        <td><?= e($rec['date_of_burial'] ? date('M j, Y', strtotime($rec['date_of_burial'])) : '—') ?></td>
                        <td><?= e($rec['age_at_death'] ?? '—') ?></td>
                        <td><?= e($rec['civil_status'] ?? '—') ?></td>
                        <td><?= e($rec['minister_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

<?php if ($isSecretary): ?>
        <h2 style="margin-top:2.5rem;">Encode New Entry (Liber Defunctorum)</h2>
        <p class="field-hint">Use this to digitize an entry from the physical Death/Burial logbook.</p>
        <form method="post" action="">
            <?= csrf_field() ?>
            <fieldset>
                <legend>Logbook Reference</legend>
                <div class="inline-fields">
                    <label>Entry No. <input type="text" name="entry_no" value="<?= e($_POST['entry_no'] ?? '') ?>"></label>
                    <label>Book No. <input type="text" name="book_no" value="<?= e($_POST['book_no'] ?? '') ?>"></label>
                    <label>Page <input type="text" name="page_no" value="<?= e($_POST['page_no'] ?? '') ?>"></label>
                    <label>Line <input type="text" name="line_no" value="<?= e($_POST['line_no'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Deceased Information</legend>
                <label>Full Name * <input type="text" name="deceased_full_name" value="<?= e($_POST['deceased_full_name'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Date of Death * <input type="date" name="date_of_death" value="<?= e($_POST['date_of_death'] ?? '') ?>" required></label>
                    <label>Date of Burial <input type="date" name="date_of_burial" value="<?= e($_POST['date_of_burial'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Age at Death <input type="number" min="0" name="age_at_death" value="<?= e($_POST['age_at_death'] ?? '') ?>"></label>
                    <label>Civil Status
                        <select name="civil_status">
                            <option value="">-- Select --</option>
                            <option value="single">Single</option>
                            <option value="married">Married</option>
                            <option value="widowed">Widowed</option>
                            <option value="separated">Separated</option>
                        </select>
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Family</legend>
                <label>Spouse Name <input type="text" name="spouse_name" value="<?= e($_POST['spouse_name'] ?? '') ?>"></label>
                <div class="inline-fields">
                    <label>Father's Name <input type="text" name="father_name" value="<?= e($_POST['father_name'] ?? '') ?>"></label>
                    <label>Mother's Name <input type="text" name="mother_name" value="<?= e($_POST['mother_name'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Sacraments Received</legend>
                <p class="field-hint">Check all sacraments the deceased received:</p>
                <div class="inline-fields">
                    <label><input type="checkbox" name="sacrament_baptism" value="1"> Baptism (B)</label>
                    <label><input type="checkbox" name="sacrament_confirmation" value="1"> Confirmation (C)</label>
                    <label><input type="checkbox" name="sacrament_marriage" value="1"> Marriage (M)</label>
                    <label><input type="checkbox" name="sacrament_anointing" value="1"> Anointing of the Sick (An)</label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Death & Burial Details</legend>
                <label>Cause of Death <input type="text" name="cause_of_death" value="<?= e($_POST['cause_of_death'] ?? '') ?>"></label>
                <label>Place of Burial <input type="text" name="place_of_burial" value="<?= e($_POST['place_of_burial'] ?? '') ?>"></label>
            </fieldset>

            <fieldset>
                <legend>Blessing / Mass</legend>
                <div class="inline-fields">
                    <label>Date <input type="date" name="blessing_date" value="<?= e($_POST['blessing_date'] ?? '') ?>"></label>
                    <label>Time <input type="time" name="blessing_time" value="<?= e($_POST['blessing_time'] ?? '') ?>"></label>
                </div>
                <label>Officiating Minister <input type="text" name="minister_name" value="<?= e($_POST['minister_name'] ?? '') ?>"></label>
            </fieldset>

            <fieldset>
                <legend>Contact & Notes</legend>
                <label>Contact Number <input type="text" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>"></label>
                <label>Remarks / Observanda <textarea name="remarks"><?= e($_POST['remarks'] ?? '') ?></textarea></label>
            </fieldset>

            <button type="submit" name="add_record" value="1" class="btn">Save Record</button>
        </form>
        <?php else: ?>
        <p class="field-hint" style="margin-top:2rem;">Encoding new entries is available to the Secretary. Your account (Priest) has read/view access.</p>
        <?php endif; ?>
        </div>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>