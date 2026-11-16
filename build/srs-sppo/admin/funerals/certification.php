<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/funeral-data.php';
require_once __DIR__ . '/../certificates/baptismal-certificate.php';
require_role('secretary');

$rawId = $_GET['id'] ?? null;
$id = is_scalar($rawId) ? filter_var((string) $rawId, FILTER_VALIDATE_INT) : false;
if ($id === false || $id === null || $id < 1) {
    redirect_to('/admin/funerals/index.php');
    exit;
}

$recordStmt = $pdo->prepare(
    'SELECT fr.id, fr.person_id, fr.date_of_death, fr.date_of_burial, fr.age_at_death,
            fr.civil_status, fr.spouse_name, fr.parents_name, fr.cause_of_death,
            fr.place_of_burial, fr.service_type, fr.service_date, fr.service_time,
            fr.minister_name, fr.book_no, fr.page_no, fr.line_no,
            p.first_name, p.middle_name, p.last_name, p.suffix, p.sex
     FROM funeral_records fr
     JOIN persons p ON p.id = fr.person_id
     WHERE fr.id = ?
     LIMIT 1'
);
$recordStmt->execute([(int) $id]);
$record = $recordStmt->fetch(PDO::FETCH_ASSOC);
if (!$record) {
    redirect_to('/admin/funerals/index.php');
    exit;
}

$person = [
    'first_name' => (string) ($record['first_name'] ?? ''),
    'middle_name' => (string) ($record['middle_name'] ?? ''),
    'last_name' => (string) ($record['last_name'] ?? ''),
    'suffix' => (string) ($record['suffix'] ?? ''),
    'sex' => (string) ($record['sex'] ?? ''),
];
$purposeOptions = [
    'sss' => 'SSS claim',
    'gsis' => 'GSIS claim',
    'legal' => 'Legal claim',
    'insurance' => 'Insurance',
    'other' => 'Other',
];
$purposeKey = is_scalar($_GET['purpose'] ?? null) ? trim((string) $_GET['purpose']) : '';
$otherPurpose = is_scalar($_GET['other_purpose'] ?? null) ? trim((string) $_GET['other_purpose']) : '';
$requesterName = is_scalar($_GET['requester_name'] ?? null) ? trim((string) $_GET['requester_name']) : '';
$printRequested = (string) ($_GET['print'] ?? '') === '1';
$errors = [];
$purpose = '';
$certificationLines = [];

if ($printRequested) {
    if ($purposeKey === 'other') {
        $purpose = $otherPurpose;
        if ($purpose === '') {
            $errors[] = 'Enter the purpose when Other is selected.';
        }
    } elseif (isset($purposeOptions[$purposeKey])) {
        $purpose = $purposeOptions[$purposeKey];
    } else {
        $errors[] = 'Choose a purpose for the certification.';
    }

    if (strlen($purpose) > 120) {
        $errors[] = 'Purpose must be 120 characters or fewer.';
    }
    if (strlen($requesterName) > 150) {
        $errors[] = 'Requester name must be 150 characters or fewer.';
    }

    if ($errors === []) {
        log_activity(
            $pdo,
            (int) current_user()['id'],
            'funeral certification printed',
            'funeral_records',
            (int) $id,
            'Funeral certification rendered for printing.'
        );
        $certificationLines = funeral_certification_lines(
            $record,
            $person,
            $purpose,
            $requesterName,
            date('Y-m-d')
        );
    }
}

$logo = baptismal_logo_paths();
$basePath = base_path();
$pageTitle = 'Parish Certification';
$extraHead = <<<'HTML'
<style>
.funeral-certification-page { max-width: 980px; margin: 0 auto; }
.funeral-certification-controls { margin-bottom: 1.25rem; padding: 1rem 1.2rem; }
.funeral-certification-controls__fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .8rem; align-items: end; }
.funeral-certification-controls__fields label { display: grid; gap: .3rem; }
.funeral-certification-actions { display: flex; justify-content: center; gap: .6rem; margin: 1rem 0; }
.funeral-certification-sheet { box-sizing: border-box; position: relative; width: 100%; max-width: 8.5in; min-height: 11in; margin: 0 auto; padding: .7in .72in; overflow: hidden; color: #242a24; background: #fff; border: 1px solid #d5d0c3; box-shadow: 0 18px 48px rgba(32, 38, 31, .12); font-family: Georgia, 'Times New Roman', serif; }
.funeral-certification-sheet::before { position: absolute; inset: .28in; border: 1px solid #c8d0c4; content: ''; pointer-events: none; }
.funeral-certification-letterhead { position: relative; display: flex; align-items: center; justify-content: center; gap: 1rem; padding-bottom: .28in; border-bottom: 2px solid #49634f; text-align: center; }
.funeral-certification-letterhead svg { width: .72in; height: .72in; flex: 0 0 auto; color: #49634f; }
.funeral-certification-letterhead p, .funeral-certification-letterhead h2 { margin: .12rem 0; }
.funeral-certification-letterhead__prelature { color: #49634f; font-size: .88rem; letter-spacing: .16em; text-transform: uppercase; }
.funeral-certification-letterhead h2 { color: #294536; font-size: 1.32rem; letter-spacing: .07em; font-weight: 600; }
.funeral-certification-letterhead__location { color: #5f665e; font-size: .88rem; letter-spacing: .12em; text-transform: uppercase; }
.funeral-certification-title { margin: .55in 0 .4in; color: #263d2e; font-size: 1.45rem; letter-spacing: .2em; text-align: center; }
.funeral-certification-lines { position: relative; font-size: 12.5pt; line-height: 1.85; }
.funeral-certification-lines p { margin: 0 0 .27in; text-align: justify; text-indent: .34in; }
.funeral-certification-signature { position: relative; width: 2.8in; margin: .9in 0 0 auto; text-align: center; }
.funeral-certification-signature__rule { height: .28in; border-bottom: 1px solid #313a32; }
.funeral-certification-signature strong { display: block; margin-top: .12in; font-size: .82rem; letter-spacing: .12em; }
.funeral-certification-validity { position: relative; margin: .45in 0 0; color: #4b514a; font-size: .88rem; text-align: center; }
@media (max-width: 720px) {
    .funeral-certification-controls__fields { grid-template-columns: 1fr; }
    .funeral-certification-sheet { min-height: 0; padding: 1.2rem; }
    .funeral-certification-sheet::before { inset: .35rem; }
    .funeral-certification-title { margin: 2rem 0 1.5rem; }
}
@page { size: Letter; margin: .55in; }
@media print {
    .site-master-header, .admin-sidebar, .site-footer, .page-heading,
    .funeral-certification-controls, .funeral-certification-actions, .alert { display: none !important; }
    .admin-layout { display: block !important; min-height: 0 !important; margin: 0 !important; }
    .admin-content { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }
    .funeral-certification-page { max-width: none !important; margin: 0 !important; }
    .funeral-certification-sheet { width: 100% !important; max-width: none !important; min-height: 0 !important; margin: 0 !important; padding: .15in !important; border: 0 !important; box-shadow: none !important; break-inside: avoid; }
    .funeral-certification-sheet::before { inset: 0; }
}
</style>
HTML;
require_once __DIR__ . '/../../includes/admin-header.php';
?>
<div class="funeral-certification-page">
    <div class="page-heading">
        <div>
            <p class="hero-eyebrow">Liber Defunctorum · Record #<?= (int) $record['id'] ?></p>
            <h1>Parish Certification</h1>
        </div>
        <a class="btn btn-secondary" href="<?= e($basePath . '/admin/funerals/form.php?id=' . (int) $record['id']) ?>">Back to Funeral Record</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="get" class="panel-card funeral-certification-controls">
        <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
        <input type="hidden" name="print" value="1">
        <div class="funeral-certification-controls__fields">
            <label for="certification-purpose">Purpose
                <select id="certification-purpose" name="purpose" required>
                    <option value="">Choose purpose</option>
                    <?php foreach ($purposeOptions as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $purposeKey === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="certification-other-purpose">Other purpose
                <input id="certification-other-purpose" type="text" name="other_purpose" maxlength="120" value="<?= e($otherPurpose) ?>" placeholder="Specify if Other is selected">
            </label>
            <label for="certification-requester">Requester name (optional)
                <input id="certification-requester" type="text" name="requester_name" maxlength="150" value="<?= e($requesterName) ?>">
            </label>
        </div>
        <p class="field-hint">The selected purpose and optional requester name will appear on the certification.</p>
        <button type="submit" class="btn btn-primary">Prepare certification</button>
    </form>

    <?php if ($certificationLines !== []): ?>
        <div class="funeral-certification-actions">
            <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
        </div>
        <article class="funeral-certification-sheet">
            <header class="funeral-certification-letterhead">
                <svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                    <g transform="translate(50 50) scale(88)" fill="#49634f">
                        <path d="<?= e($logo['ring']) ?>" fill-rule="evenodd"></path>
                        <path d="<?= e($logo['mark']) ?>"></path>
                    </g>
                </svg>
                <div>
                    <p class="funeral-certification-letterhead__prelature">PRELATURE OF INFANTA</p>
                    <h2>ST. PATRICK PARISH</h2>
                    <p class="funeral-certification-letterhead__location">DINGALAN, AURORA</p>
                </div>
            </header>
            <h2 class="funeral-certification-title">PARISH CERTIFICATION</h2>
            <div class="funeral-certification-lines">
                <?php foreach ($certificationLines as $line): ?>
                    <p><?= e($line) ?></p>
                <?php endforeach; ?>
            </div>
            <div class="funeral-certification-signature">
                <div class="funeral-certification-signature__rule"></div>
                <strong>PARISH PRIEST</strong>
            </div>
            <p class="funeral-certification-validity">Valid only with the signature of the Parish Priest and the parish seal.</p>
        </article>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
