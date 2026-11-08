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
$observandaValue = (string) ($person['observanda'] ?? '');
$updatedFlag = isset($_GET['updated']) && (string) $_GET['updated'] === '1';
$observandaUpdatedFlag = isset($_GET['observanda_updated']) && (string) $_GET['observanda_updated'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_observanda') {
        $observandaValue = trim((string) ($_POST['observanda'] ?? ''));
    }
    if (!csrf_verify()) {
        $notice = 'Your session token is invalid or expired. Refresh the page and try again.';
        $noticeType = 'error';
    } else {
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
        } elseif ($action === 'save_observanda') {
            if (strlen($observandaValue) > 4000) {
                $notice = 'Observanda must be 4,000 bytes or fewer.';
                $noticeType = 'error';
            } else {
                try {
                    $pdo->beginTransaction();
                    $before = sd_person_audit_snapshot($pdo, $personId);
                    sd_save_person($pdo, ['observanda' => $observandaValue], $personId);
                    $after = sd_person_audit_snapshot($pdo, $personId);
                    sd_log_person_edit(
                        $pdo,
                        $userId,
                        $personId,
                        $before,
                        $after,
                        'Updated profile Observanda'
                    );
                    $pdo->commit();
                    redirect_to('/admin/system/person-profile.php?id=' . $personId . '&observanda_updated=1');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('SRS-SPPO profile Observanda update failed: ' . $e->getMessage());
                    $notice = 'Could not save profile Observanda. Check the server error log.';
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
$personHistoryStmt = $pdo->prepare(
    'SELECT al.id, al.user_id, al.actor_name, al.action, al.description,
            al.reason, al.created_at, u.full_name AS user_name
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.target_table = ? AND al.target_id = ? AND al.action = ?
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT 20'
);
$personHistoryStmt->execute(['persons', $personId, 'person.updated']);
$personHistory = $personHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

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
$recordCount = count(array_filter([
    $recs['baptism'],
    $recs['first_communion'],
    $recs['confirmation'],
])) + count($recs['marriages']);

$pageTitle = 'Person Profile';
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="person-profile-page">
<?php if ($updatedFlag): ?>
    <p class="alert alert-success">Person details updated.</p>
<?php endif; ?>
<?php if ($observandaUpdatedFlag): ?>
    <p class="alert alert-success">Profile Observanda saved.</p>
<?php endif; ?>
<?php if ($verifiedFlag): ?>
    <p class="alert alert-success">Book entry verified.</p>
<?php endif; ?>
<?php if ($notice): ?>
    <p class="alert alert-<?= e($noticeType) ?>"><?= e($notice) ?></p>
<?php endif; ?>

<header class="person-profile-hero">
    <div class="person-profile-hero__main">
        <a class="person-profile-back" href="<?= e($basePath) ?>/admin/dashboard.php">
            <span aria-hidden="true">&larr;</span> Back to search
        </a>
        <p class="person-profile-kicker">Parishioner record <span>·</span> #<?= (int) $person['id'] ?></p>
        <h1><?= e($personLabel) ?></h1>
        <p class="person-profile-subtitle">A consolidated view of this person's parish identity and sacramental history.</p>
    </div>
    <div class="person-profile-summary" aria-label="Profile summary">
        <div class="person-profile-summary__item">
            <span class="person-profile-summary__value"><?= $recordCount ?></span>
            <span class="person-profile-summary__label">Sacramental <?= $recordCount === 1 ? 'record' : 'records' ?></span>
        </div>
    </div>
</header>

<section class="panel-card person-profile-section person-identity" aria-labelledby="identity-heading">
    <div class="person-profile-section__heading">
        <div>
            <p class="person-profile-section__eyebrow">Identity</p>
            <h2 id="identity-heading">Personal details</h2>
        </div>
        <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/system/person-edit.php?id=' . (int) $personId) ?>">Edit personal details</a>
    </div>
    <div class="person-profile-details">
        <div class="person-profile-detail">
            <span class="person-profile-detail__label">Date of birth</span>
            <span class="person-profile-detail__value"><?= e($person['birth_date'] ? date('M j, Y', strtotime($person['birth_date'])) : 'Not specified') ?></span>
        </div>
        <div class="person-profile-detail">
            <span class="person-profile-detail__label">Birthplace</span>
            <span class="person-profile-detail__value"><?= e($person['birth_place'] ?: 'Not specified') ?></span>
        </div>
        <div class="person-profile-detail">
            <span class="person-profile-detail__label">Sex</span>
            <span class="person-profile-detail__value"><?= e($person['sex'] ? ucfirst($person['sex']) : 'Not specified') ?></span>
        </div>
        <div class="person-profile-detail">
            <span class="person-profile-detail__label">Baptismal status</span>
            <span class="person-profile-detail__value"><?php
                $statusLabels = ['baptized_catholic' => 'Baptized Catholic', 'baptized_non_catholic' => 'Baptized (non-Catholic)', 'unbaptized' => 'Unbaptized'];
                echo e($statusLabels[$person['baptismal_status'] ?? ''] ?? 'Not specified');
            ?></span>
        </div>
        <div class="person-profile-detail">
            <span class="person-profile-detail__label">Father</span>
            <span class="person-profile-detail__value"><?= e($person['father_name'] ?: 'Not specified') ?></span>
        </div>
        <div class="person-profile-detail">
            <span class="person-profile-detail__label">Mother's maiden name</span>
            <span class="person-profile-detail__value"><?= e($person['mother_maiden_name'] ?: 'Not specified') ?></span>
        </div>
        <div class="person-profile-detail person-profile-detail--wide">
            <span class="person-profile-detail__label">Address</span>
            <span class="person-profile-detail__value"><?= e($person['address'] ?: 'Not specified') ?></span>
        </div>
        <div class="person-profile-detail">
            <span class="person-profile-detail__label">Contact number</span>
            <span class="person-profile-detail__value"><?= e($person['contact_number'] ?: 'Not specified') ?></span>
        </div>
    </div>
</section>

<section class="panel-card person-profile-section" aria-labelledby="profile-observanda-heading">
    <div class="person-profile-section__heading">
        <div>
            <p class="person-profile-section__eyebrow">Person-level note</p>
            <h2 id="profile-observanda-heading">Profile Observanda</h2>
        </div>
    </div>
    <p class="field-hint">Use this for a note about the person as a whole. Individual sacrament Observanda remain with their register entries.</p>
    <form method="post" class="person-profile-observanda-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_observanda">
        <label for="profile-observanda">Observanda</label>
        <textarea id="profile-observanda" name="observanda" maxlength="4000" rows="5"><?= e($observandaValue) ?></textarea>
        <div class="person-profile-observanda-actions">
            <span class="field-hint">Saving a change records the previous and new note in Correction History. Leave blank to clear it.</span>
            <button type="submit" class="btn btn-primary">Save Observanda</button>
        </div>
    </form>
</section>

<section class="panel-card person-profile-section" aria-labelledby="person-history-heading">
    <div class="person-profile-section__heading">
        <div>
            <p class="person-profile-section__eyebrow">Audit trail</p>
            <h2 id="person-history-heading">Correction history</h2>
        </div>
        <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/system/audit-log.php?' . http_build_query([
            'module' => 'persons',
            'record_id' => $personId,
        ])) ?>">View full audit log</a>
    </div>
    <?php if (!$personHistory): ?>
        <p class="field-hint">No profile corrections have been recorded.</p>
    <?php else: ?>
        <ul class="record-person-link-list record-history-list">
            <?php foreach ($personHistory as $entry): ?>
                <?php
                $correctionData = json_decode((string) ($entry['description'] ?? ''), true);
                if (!is_array($correctionData) || ($correctionData['format'] ?? '') !== 'person-correction-v1') {
                    $correctionData = [];
                }
                ?>
                <li class="record-person-link">
                    <div>
                        <span class="field-hint"><?= e(date('M j, Y g:i A', strtotime($entry['created_at']))) ?></span>
                        <strong><?= e($entry['user_name'] ?? $entry['actor_name'] ?? 'Unknown staff member') ?></strong>
                    </div>
                    <?php if (!empty($entry['reason'])): ?><small>Reason: <?= e($entry['reason']) ?></small><?php endif; ?>
                    <?php if (!empty($correctionData['changes']) && is_array($correctionData['changes'])): ?>
                        <details>
                            <summary>Before-and-after values (<?= count($correctionData['changes']) ?> fields)</summary>
                            <?php foreach ($correctionData['changes'] as $field => $change): ?>
                                <?php if (!is_array($change) || !array_key_exists('before', $change) || !array_key_exists('after', $change)) continue; ?>
                                <h3><?= e(ucwords(str_replace('_', ' ', (string) $field))) ?></h3>
                                <dl class="record-detail-list">
                                    <div><dt>Before</dt><dd><pre><?= e(json_encode($change['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Not recorded') ?></pre></dd></div>
                                    <div><dt>After</dt><dd><pre><?= e(json_encode($change['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Not recorded') ?></pre></dd></div>
                                </dl>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="panel-card person-profile-section sacrament-cards" aria-labelledby="sacraments-heading">
    <div class="person-profile-section__heading">
        <div>
            <p class="person-profile-section__eyebrow">Parish register</p>
            <h2 id="sacraments-heading">Sacramental history</h2>
        </div>
        <span class="person-profile-count"><?= $recordCount ?> <?= $recordCount === 1 ? 'record' : 'records' ?></span>
    </div>
    <?php
    $cardOrder = ['baptism', 'first_communion', 'confirmation'];
    $recordIndex = 0;
    foreach ($cardOrder as $type):
        $record = $recs[$type];
        $recordIndex++;
    ?>
        <article class="record-card person-profile-record<?= $record ? ' has-record' : ' is-empty' ?>">
            <div class="person-profile-record__top">
                <span class="person-profile-record__index"><?= sprintf('%02d', $recordIndex) ?></span>
                <h3><?= e(sd_type_label($type)) ?></h3>
            </div>
            <?php if ($record): ?>
                <dl class="person-profile-record__details">
                    <div><dt>Date</dt><dd><?= e(date('M j, Y', strtotime($record['event_date']))) ?></dd></div>
                    <div><dt>Place</dt><dd><?= e($record['place'] ?: 'Not specified') ?></dd></div>
                    <div><dt>Minister</dt><dd><?= e($record['minister_name'] ?: 'Not specified') ?></dd></div>
                    <div><dt>Book · Page · Line</dt><dd><?= e(trim((string) ($record['book_no'] ?? '') . ' / ' . (string) ($record['page_no'] ?? '') . ' / ' . (string) ($record['line_no'] ?? ''), ' /') ?: 'Not specified') ?></dd></div>
                </dl>
                <div class="person-profile-record__badges">
                    <span class="person-profile-badge"><?= (!empty($record['source']) && $record['source'] === 'other_parish') ? 'Other parish' : 'This parish' ?></span>
                    <span class="person-profile-badge<?= !empty($record['book_verified_at']) ? ' is-verified' : ' is-unverified' ?>"><?= !empty($record['book_verified_at']) ? 'Book entry verified' : 'Not yet verified' ?></span>
                    <?php if ($type === 'baptism' && !empty($record['parents_union_type'])): ?>
                        <span class="person-profile-badge person-profile-badge--neutral">Parents: <?= e($unionLabels[$record['parents_union_type']] ?? $record['parents_union_type']) ?></span>
                    <?php endif; ?>
                </div>
                <section class="person-profile-observanda" aria-label="<?= e(sd_type_label($type)) ?> Observanda">
                    <h4>Observanda</h4>
                    <?php if (!empty($record['remarks'])): ?>
                        <p class="record-view-remarks"><?= nl2br(e((string) $record['remarks'])) ?></p>
                    <?php else: ?>
                        <p class="person-profile-note">No Observanda recorded.</p>
                    <?php endif; ?>
                </section>
                <div class="person-profile-record__actions">
                    <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/view.php?' . http_build_query([
                        'type' => $type === 'baptism' ? 'baptismal' : $type,
                        'id' => (int) $record['id'],
                        'return' => '/admin/system/person-profile.php?id=' . (int) $personId,
                    ])) ?>">View / edit record</a>
                    <?php if (!empty($record['source']) && $record['source'] === 'other_parish'): ?>
                        <span class="person-profile-note">Recorded from origin certificate</span>
                    <?php elseif (sd_can_print_certificate($record)): ?>
                        <a class="btn btn-small" href="<?= e($basePath . '/public/certificate-request.php?record_id=' . (int) $record['id']) ?>">Start certificate request</a>
                    <?php elseif (empty($record['book_verified_at'])): ?>
                        <form method="post" data-confirm="Mark this book entry as verified against the physical register?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify">
                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                            <button type="submit" class="btn btn-small person-profile-verify">Verify register entry</button>
                            <span class="field-hint">Required before a certificate request can be started.</span>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="person-profile-empty">No <?= strtolower(e(sd_type_label($type))) ?> record is linked to this profile yet.</p>
                <?php if (!empty($warnings[$type])): ?>
                    <div class="notice notice-warning person-profile-warning">
                        <?php foreach ($warnings[$type] as $w): ?><p><?= e($w) ?></p><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="person-profile-record__actions">
                    <a class="btn btn-small" href="<?= e($basePath . '/admin/records/editor.php?' . http_build_query(['type' => $type, 'person_id' => (int) $personId])) ?>">Add record</a>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <?php if (!empty($recs['marriages'])): ?>
        <?php foreach ($recs['marriages'] as $record): ?>
            <?php $recordIndex++; ?>
            <article class="record-card person-profile-record has-record">
                <div class="person-profile-record__top">
                    <span class="person-profile-record__index"><?= sprintf('%02d', $recordIndex) ?></span>
                    <h3><?= e(sd_type_label('marriage')) ?></h3>
                </div>
                <dl class="person-profile-record__details">
                    <div><dt>Date</dt><dd><?= e(date('M j, Y', strtotime($record['event_date']))) ?></dd></div>
                    <div><dt>Place</dt><dd><?= e($record['place'] ?: 'Not specified') ?></dd></div>
                    <div><dt>Minister</dt><dd><?= e($record['minister_name'] ?: 'Not specified') ?></dd></div>
                    <div><dt>Book · Page · Line</dt><dd><?= e(trim((string) ($record['book_no'] ?? '') . ' / ' . (string) ($record['page_no'] ?? '') . ' / ' . (string) ($record['line_no'] ?? ''), ' /') ?: 'Not specified') ?></dd></div>
                    <?php if (!empty($record['partner_name']) && !empty($record['partner_id'])): ?>
                        <div><dt>Spouse</dt><dd><a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $record['partner_id']) ?>"><?= e($record['partner_name']) ?></a></dd></div>
                    <?php endif; ?>
                </dl>
                <div class="person-profile-record__badges">
                    <span class="person-profile-badge"><?= (!empty($record['source']) && $record['source'] === 'other_parish') ? 'Other parish' : 'This parish' ?></span>
                    <span class="person-profile-badge<?= !empty($record['book_verified_at']) ? ' is-verified' : ' is-unverified' ?>"><?= !empty($record['book_verified_at']) ? 'Book entry verified' : 'Not yet verified' ?></span>
                </div>
                <section class="person-profile-observanda" aria-label="Marriage Observanda">
                    <h4>Observanda</h4>
                    <?php if (!empty($record['remarks'])): ?>
                        <p class="record-view-remarks"><?= nl2br(e((string) $record['remarks'])) ?></p>
                    <?php else: ?>
                        <p class="person-profile-note">No Observanda recorded.</p>
                    <?php endif; ?>
                </section>
                <div class="person-profile-record__actions">
                    <a class="btn btn-secondary btn-small" href="<?= e($basePath . '/admin/records/view.php?' . http_build_query([
                        'type' => 'marriage',
                        'id' => (int) $record['id'],
                        'return' => '/admin/system/person-profile.php?id=' . (int) $personId,
                    ])) ?>">View / edit record</a>
                    <?php if (!empty($record['source']) && $record['source'] === 'other_parish'): ?>
                        <span class="person-profile-note">Recorded from origin certificate</span>
                    <?php elseif (sd_can_print_certificate($record)): ?>
                        <a class="btn btn-small" href="<?= e($basePath . '/public/certificate-request.php?record_id=' . (int) $record['id']) ?>">Start certificate request</a>
                    <?php elseif (empty($record['book_verified_at'])): ?>
                        <form method="post" data-confirm="Mark this book entry as verified against the physical register?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify">
                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                            <button type="submit" class="btn btn-small person-profile-verify">Verify against book</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    <?php else: ?>
        <?php $recordIndex++; ?>
        <article class="record-card person-profile-record is-empty">
            <div class="person-profile-record__top">
                <span class="person-profile-record__index"><?= sprintf('%02d', $recordIndex) ?></span>
                <h3><?= e(sd_type_label('marriage')) ?></h3>
            </div>
            <p class="person-profile-empty">No marriage record is linked to this profile yet.</p>
            <div class="person-profile-record__actions">
                <a class="btn btn-small" href="<?= e($basePath . '/admin/records/editor.php?' . http_build_query(['type' => 'marriage', 'person_id' => (int) $personId])) ?>">Add record</a>
            </div>
        </article>
    <?php endif; ?>
</section>

<?php
$hasFamily = $family['father'] || $family['mother'] || !empty($family['children']);
if ($hasFamily): ?>
<section class="panel-card person-profile-section family-block" aria-labelledby="family-heading">
    <div class="person-profile-section__heading">
        <div>
            <p class="person-profile-section__eyebrow">Connected profiles</p>
            <h2 id="family-heading">Family</h2>
        </div>
    </div>
    <div class="person-profile-family">
        <?php if ($family['father']): ?>
            <div class="person-profile-relative"><span class="person-profile-detail__label">Father</span><a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $family['father']['id']) ?>"><?= e(sd_full_name($family['father'])) ?></a></div>
        <?php endif; ?>
        <?php if ($family['mother']): ?>
            <div class="person-profile-relative"><span class="person-profile-detail__label">Mother</span><a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $family['mother']['id']) ?>"><?= e(sd_full_name($family['mother'])) ?></a></div>
        <?php endif; ?>
        <?php if (!empty($family['children'])): ?>
            <div class="person-profile-relative person-profile-relative--children"><span class="person-profile-detail__label">Children</span><ul>
                <?php foreach ($family['children'] as $child): ?>
                    <li><a href="<?= e($basePath . '/admin/system/person-profile.php?id=' . (int) $child['id']) ?>"><?= e(sd_full_name($child)) ?></a></li>
                <?php endforeach; ?>
            </ul></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>