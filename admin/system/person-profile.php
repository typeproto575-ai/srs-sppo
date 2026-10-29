<?php
/**
 * SRS-SPPO PERSON PROFILE (admin, schema v2)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';
require_role('secretary');

$basePath = $basePath ?? base_path();

$personId = (int) ($_GET['id'] ?? 0);
if ($personId < 1) {
    redirect_to('/admin/dashboard.php');
}

$person = sd_get_person($pdo, $personId);
if (!$person) {
    redirect_to('/admin/dashboard.php');
}

$personLabel = sd_full_name($person);
$notice = null;
$noticeType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $notice = 'Your session token is invalid or expired. Refresh the page and try again.';
        $noticeType = 'error';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        $recordId = filter_input(INPUT_POST, 'record_id', FILTER_VALIDATE_INT);
        $userId = (int) current_user()['id'];
        if ($action === 'verify' && $recordId) {
            $record = sd_get_record($pdo, (int) $recordId);
            if (!$record || ((int) ($record['person_id'] ?? 0) !== $personId && (int) ($record['spouse_person_id'] ?? 0) !== $personId)) {
                $notice = 'That record does not belong to this person.';
                $noticeType = 'error';
            } else {
                try {
                    sd_verify_book_entry($pdo, (int) $recordId, $userId);
                    log_activity($pdo, $userId, 'verify_book_entry', 'sacrament_records', (int) $recordId, 'Verified book entry');
                    redirect_to('/admin/system/person-profile.php?id=' . $personId . '&verified=1');
                } catch (Throwable $e) {
                    error_log('SRS-SPPO verify book entry failed: ' . $e->getMessage());
                    $notice = 'Could not verify the book entry. Check the server error log.';
                    $noticeType = 'error';
                }
            }
        } else {
            $notice = 'Invalid action.';
            $noticeType = 'error';
        }
    }
}

$recs = sd_get_person_records($pdo, $personId);
$family = sd_get_family($pdo, $personId);

$unionLabels = [
    'legitimate' => 'Church married',
    'civil' => 'Civilly married',
    'natural' => 'Unmarried',
];

$warnings = [];
if ($recs['first_communion'] === null) {
    $warnings['first_communion'] = sd_prerequisite_warnings($pdo, $personId, 'first_communion');
}
if ($recs['confirmation'] === null) {
    $warnings['confirmation'] = sd_prerequisite_warnings($pdo, $personId, 'confirmation');
}

$verifiedFlag = isset($_GET['verified']) && (string) $_GET['verified'] === '1';

$pageTitle = 'Person Profile';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<h1><?= e($personLabel) ?></h1>
<?php if ($verifiedFlag): ?>
    <p class="alert alert-success">Book entry verified.</p>
<?php endif; ?>
<?php if ($notice): ?>
    <p class="alert alert-<?= e($noticeType) ?>"><?= e($notice) ?></p>
<?php endif; ?>
<p class="field-hint">Person #<?= (int) $person['id'] ?> &middot; Read-only identity &middot; <a href="<?= e($basePath) ?>/admin/dashboard.php">Back to search</a></p>

<section class="panel-card person-identity">
    <h2>Person details</h2>
    <div class="form-grid view-grid">
        <div class="field"><label class="field-label">Full name</label><p class="field-value"><?= e($personLabel) ?></p></div>
        <div class="field"><label class="field-label">Sex</label><p class="field-value"><?= e($person['sex'] ?: 'Not specified') ?></p></div>
        <div class="field"><label class="field-label">Birth date</label><p class="field-value"><?= e($person['birth_date'] ? date('F j, Y', strtotime($person['birth_date'])) : 'Not specified') ?></p></div>
        <div class="field"><label class="field-label">Birth place</label><p class="field-value"><?= e($person['birth_place'] ?: 'Not specified') ?></p></div>
        <div class="field"><label class="field-label">Father's name</label><p class="field-value"><?= e($person['father_name'] ?: 'Not specified') ?></p></div>
        <div class="field"><label class="field-label">Mother's maiden name</label><p class="field-value"><?= e($person['mother_maiden_name'] ?: 'Not specified') ?></p></div>
        <div class="field"><label class="field-label">Address</label><p class="field-value"><?= e($person['address'] ?: 'Not specified') ?></p></div>
        <div class="field"><label class="field-label">Contact number</label><p class="field-value"><?= e($person['contact_number'] ?: 'Not specified') ?></p></div>
        <div class="field"><label class="field-label">Baptismal status</label><p class="field-value"><?php
            $statusLabels = ['baptized_catholic' => 'Baptized Catholic', 'baptized_non_catholic' => 'Baptized (non-Catholic)', 'unbaptized' => 'Unbaptized'];
            echo e($statusLabels[$person['baptismal_status'] ?? ''] ?? 'Not specified');
        ?></p></div>
        <div class="field"><label class="field-label">Deceased</label><p class="field-value"><?= !empty($person['is_deceased']) ? 'Yes' : 'No' ?></p></div>
    </div>
</section>

<section class="panel-card sacrament-cards">
    <h2>Sacrament records</h2>
    <?php
    $cardOrder = ['baptism', 'first_communion', 'confirmation'];
    foreach ($cardOrder as $type):
        $record = $recs[$type];
    ?>
        <div class="record-card">
            <h3><?= e(sd_type_label($type)) ?></h3>
            <?php if ($record): ?>
                <div class="record-meta">
                    <span><strong>Date:</strong> <?= e(date('M j, Y', strtotime($record['event_date']))) ?></span>
                    <span><strong>Place:</strong> <?= e($record['place'] ?: 'Not specified') ?></span>
                    <span><strong>Minister:</strong> <?= e($record['minister_name'] ?: 'Not specified') ?></span>
                    <span><strong>Book/Page/Line:</strong> <?= e(trim((string) ($record['book_no'] ?? '') . ' / ' . (string) ($record['page_no'] ?? '') . ' / ' . (string) ($record['line_no'] ?? ''), ' /') ?: 'Not specified') ?></span>
                </div>
                <span class="source-badge"><?= (!empty($record['source']) && $record['source'] === 'other_parish') ? 'Other parish' : 'This parish' ?></span>
                <span class="verify-label"><?= !empty($record['book_verified_at']) ? 'Book entry verified' : 'Not yet verified' ?></span>
                <?php if ($type === 'baptism' && !empty($record['parents_union_type'])): ?>
                    <span class="union-label">Parents' marital status: <?= e($unionLabels[$record['parents_union_type']] ?? $record['parents_union_type']) ?></span>
                <?php endif; ?>
                <div class="card-actions">
                    <?php if (!empty($record['source']) && $record['source'] === 'other_parish'): ?>
                        <span class="note">Recorded from origin certificate</span>
                    <?php elseif (sd_can_print_certificate($record)): ?>
                        <span class="note">Print from a certificate request</span>
                    <?php elseif (empty($record['book_verified_at'])): ?>
                        <form method="post" style="display:inline;" data-confirm="Mark this book entry as verified against the physical register?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify">
                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                            <button type="submit" class="btn btn-small">Verify book entry</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="field-hint">No record yet.</p>
                <?php if (!empty($warnings[$type])): ?>
                    <div class="notice notice-warning">
                        <?php foreach ($warnings[$type] as $w): ?><p><?= e($w) ?></p><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <a class="btn btn-small" href="<?= e($basePath . '/admin/records/record-form.php?type=' . urlencode($type) . '&person_id=' . (int) $personId) ?>">Add record</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($recs['marriages'])): ?>
        <?php foreach ($recs['marriages'] as $record): ?>
            <div class="record-card">
                <h3><?= e(sd_type_label('marriage')) ?></h3>
                <div class="record-meta">
                    <span><strong>Date:</strong> <?= e(date('M j, Y', strtotime($record['event_date']))) ?></span>
                    <span><strong>Place:</strong> <?= e($record['place'] ?: 'Not specified') ?></span>
                    <span><strong>Minister:</strong> <?= e($record['minister_name'] ?: 'Not specified') ?></span>
                    <span><strong>Book/Page/Line:</strong> <?= e(trim((string) ($record['book_no'] ?? '') . ' / ' . (string) ($record['page_no'] ?? '') . ' / ' . (string) ($record['line_no'] ?? ''), ' /') ?: 'Not specified') ?></span>
                    <?php if (!empty($record['partner_name']) && !empty($record['partner_id'])): ?>
                        <span><strong>Partner:</strong> <a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $record['partner_id']) ?>"><?= e($record['partner_name']) ?></a></span>
                    <?php endif; ?>
                </div>
                <span class="source-badge"><?= (!empty($record['source']) && $record['source'] === 'other_parish') ? 'Other parish' : 'This parish' ?></span>
                <span class="verify-label"><?= !empty($record['book_verified_at']) ? 'Book entry verified' : 'Not yet verified' ?></span>
                <div class="card-actions">
                    <?php if (!empty($record['source']) && $record['source'] === 'other_parish'): ?>
                        <span class="note">Recorded from origin certificate</span>
                    <?php elseif (sd_can_print_certificate($record)): ?>
                        <span class="note">Print from a certificate request</span>
                    <?php elseif (empty($record['book_verified_at'])): ?>
                        <form method="post" style="display:inline;" data-confirm="Mark this book entry as verified against the physical register?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify">
                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                            <button type="submit" class="btn btn-small">Verify book entry</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="record-card">
            <h3><?= e(sd_type_label('marriage')) ?></h3>
            <p class="field-hint">No record yet.</p>
            <a class="btn btn-small" href="<?= e($basePath . '/admin/records/record-form.php?type=' . urlencode('marriage') . '&person_id=' . (int) $personId) ?>">Add record</a>
        </div>
    <?php endif; ?>
</section>

<?php
$hasFamily = $family['father'] || $family['mother'] || !empty($family['children']);
if ($hasFamily): ?>
<section class="panel-card family-block">
    <h2>Family</h2>
    <div class="form-grid view-grid">
        <?php if ($family['father']): ?>
            <div class="field"><label class="field-label">Father</label><p class="field-value"><a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $family['father']['id']) ?>"><?= e(sd_full_name($family['father'])) ?></a></p></div>
        <?php endif; ?>
        <?php if ($family['mother']): ?>
            <div class="field"><label class="field-label">Mother</label><p class="field-value"><a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $family['mother']['id']) ?>"><?= e(sd_full_name($family['mother'])) ?></a></p></div>
        <?php endif; ?>
        <?php if (!empty($family['children'])): ?>
            <div class="field"><label class="field-label">Children</label><p class="field-value">
                <?php foreach ($family['children'] as $child): ?>
                    <a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $child['id']) ?>"><?= e(sd_full_name($child)) ?></a><br>
                <?php endforeach; ?>
            </p></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>