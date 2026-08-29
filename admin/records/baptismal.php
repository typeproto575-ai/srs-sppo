<?php
/**
 * =====================================================================
 * SPPO-IS BAPTISMAL RECORDS (admin register)
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

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO baptismal_records
                    (entry_no, baptism_date, child_full_name, birth_date, legitimacy, father_name,
                     mother_name, parents_origin, parents_residence, minister_name, remarks,
                     book_no, page_no, line_no, encoded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entryNo, $baptismDate, $childName, $birthDate, $legitimacy, $fatherName,
                $motherName, $parentsOrigin, $parentsResidence, $ministerName, $remarks,
                $bookNo, $pageNo, $lineNo, current_user()['id'],
            ]);
            $recordId = (int) $pdo->lastInsertId();

            $sponsorStmt = $pdo->prepare(
                "INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence) VALUES (?, ?, ?)"
            );
            foreach ($sponsorNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $sponsorStmt->execute([$recordId, $name, trim($sponsorResidences[$i] ?? '')]);
            }

            log_activity($pdo, current_user()['id'], 'baptismal_record_encoded', 'baptismal_records', $recordId,
                "Baptismal record encoded for {$childName}");

            $pdo->commit();
            redirect_to('/admin/records/baptismal.php?added=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Something went wrong while saving this record. Please try again.';
        }
    }
}

if (isset($_GET['added'])) $success = 'Baptismal record saved.';

// --- Search / list ------------------------------------------------------
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM baptismal_records WHERE child_full_name LIKE ? ORDER BY baptism_date DESC LIMIT 100");
    $stmt->execute(['%' . $q . '%']);
} else {
    $stmt = $pdo->query("SELECT * FROM baptismal_records ORDER BY baptism_date DESC LIMIT 100");
}
$records = $stmt->fetchAll();

$isSecretary = current_user()['role'] === 'secretary';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Baptismal Records</h1>
        <p class="field-hint">This is a digital index of the physical baptismal logbook, used for fast retrieval when matching certificate requests. The physical logbook remains the canonical record.</p>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="get" class="inline-fields filter-form">
            <label>Search by Child's Name
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="e.g. Maria Santos">
            </label>
            <button type="submit" class="btn btn-small">Search</button>
            <?php if ($q !== ''): ?><a href="<?= $basePath ?>/admin/records/baptismal.php" class="btn btn-secondary btn-small">Clear</a><?php endif; ?>
        </form>

        <?php if (empty($records)): ?>
            <p>No baptismal records found<?= $q !== '' ? ' for "' . e($q) . '"' : '' ?>.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Child's Name</th>
                    <th>Baptism Date</th>
                    <th>Parents</th>
                    <th>Minister</th>
                    <th>Book/Page/Line</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                    <tr>
                        <td><?= e($rec['child_full_name']) ?></td>
                        <td><?= e(date('M j, Y', strtotime($rec['baptism_date']))) ?></td>
                        <td><?= e($rec['father_name']) ?> &amp; <?= e($rec['mother_name']) ?></td>
                        <td><?= e($rec['minister_name']) ?></td>
                        <td><?= e($rec['book_no']) ?> / <?= e($rec['page_no']) ?> / <?= e($rec['line_no']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2 style="margin-top:2.5rem;">Encode New Entry</h2>
        <p class="field-hint">Use this to digitize an entry from the physical logbook. This does not replace the physical record.</p>
        <form method="post" action="">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
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
                <legend>Child</legend>
                <label>Full Name * <input type="text" name="child_full_name" value="<?= e($_POST['child_full_name'] ?? '') ?>" required></label>
                <div class="inline-fields">
                    <label>Birth Date <input type="date" name="birth_date" value="<?= e($_POST['birth_date'] ?? '') ?>"></label>
                    <label>Baptism Date * <input type="date" name="baptism_date" value="<?= e($_POST['baptism_date'] ?? '') ?>" required></label>
                </div>
                <label>Legitimacy (internal only — never shown on certificates or public output)
                    <select name="legitimacy">
                        <option value="">-- Not specified --</option>
                        <option value="legitimate">Legitimate</option>
                        <option value="illegitimate">Illegitimate</option>
                    </select>
                </label>
            </fieldset>

            <fieldset>
                <legend>Parents</legend>
                <div class="inline-fields">
                    <label>Father's Name <input type="text" name="father_name" value="<?= e($_POST['father_name'] ?? '') ?>"></label>
                    <label>Mother's Name <input type="text" name="mother_name" value="<?= e($_POST['mother_name'] ?? '') ?>"></label>
                </div>
                <div class="inline-fields">
                    <label>Origin <input type="text" name="parents_origin" value="<?= e($_POST['parents_origin'] ?? '') ?>"></label>
                    <label>Residence <input type="text" name="parents_residence" value="<?= e($_POST['parents_residence'] ?? '') ?>"></label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Sponsors (Ninong/Ninang)</legend>
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <div class="inline-fields">
                        <label>Sponsor Name <input type="text" name="sponsor_name[]"></label>
                        <label>Residence <input type="text" name="sponsor_residence[]"></label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset>
                <legend>Other</legend>
                <label>Officiating Minister <input type="text" name="minister_name" value="<?= e($_POST['minister_name'] ?? '') ?>"></label>
                <label>Remarks / Observanda <textarea name="remarks"><?= e($_POST['remarks'] ?? '') ?></textarea></label>
            </fieldset>

            <button type="submit" name="add_record" value="1" class="btn">Save Record</button>
        </form>

    <?php require_once __DIR__ . '/../../includes/admin-footer.php';