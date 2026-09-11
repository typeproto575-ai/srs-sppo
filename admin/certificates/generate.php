<?php
/**
 * =====================================================================
 * SRS-SPPO CERTIFICATE GENERATE / PREVIEW (admin)
 * =====================================================================
 * Purpose: Produces the printable certificate for an approved request.
 *          The request's details are cross-checked against the matching
 *          register record (CERT_EXT map below), then rendered in a
 *          print-ready layout for the secretary to print and sign.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Generate / Preview Certificate';
$notice = null;

// Maps each sacrament type to its extension table (for request details) and
// the canonical records table (for the authoritative register entry).
// Used for certificate preview and record matching.
const CERT_EXT = [
    'baptismal'      => ['ext' => 'baptismal_certificate_requests',       'record' => 'baptismal_records'],
    'confirmation'   => ['ext' => 'confirmation_certificate_requests',    'record' => 'confirmation_records'],
    'first_communion'=> ['ext' => 'first_communion_certificate_requests', 'record' => 'first_communion_records'],
    'marriage'       => ['ext' => 'marriage_certificate_requests',        'record' => 'marriage_records'],
    'death_burial'   => ['ext' => 'death_burial_certificate_requests',    'record' => 'death_burial_records'],
];

// --- Match a request to a digital record index --------------------------
// CSRF: forged or stale POSTs are dropped silently - no match is written.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $notice = null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_record']) && csrf_verify()) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $sacramentType = $_POST['sacrament_type'] ?? '';
    $recordId = (int) ($_POST['record_id'] ?? 0);

    if ($requestId && $recordId && isset(CERT_EXT[$sacramentType])) {
        $requestStmt = $pdo->prepare('SELECT sacrament_type, status FROM certificate_requests WHERE id = ?');
        $requestStmt->execute([$requestId]);
        $requestRow = $requestStmt->fetch();
        $config = CERT_EXT[$sacramentType];

        if (!$requestRow || $requestRow['sacrament_type'] !== $sacramentType) {
            $notice = 'The request type does not match the selected sacrament.';
        } elseif (!in_array($requestRow['status'], ['approved', 'ready_for_pickup'], true)) {
            $notice = 'Only approved requests can be matched to a certificate record.';
        } else {
            $recordStmt = $pdo->prepare("SELECT id FROM {$config['record']} WHERE id = ?");
            $recordStmt->execute([$recordId]);
            $recordExists = $recordStmt->fetchColumn();
            $extStmt = $pdo->prepare("SELECT id FROM {$config['ext']} WHERE request_id = ?");
            $extStmt->execute([$requestId]);
            $extensionExists = $extStmt->fetchColumn();

            if (!$recordExists || !$extensionExists) {
                $notice = 'The selected record or request details could not be found.';
            } else {
                $stmt = $pdo->prepare("UPDATE {$config['ext']} SET matched_record_id = ? WHERE request_id = ?");
                $stmt->execute([$recordId, $requestId]);

                // Verify the UPDATE actually affected a row. If rowCount() is 0,
                // the extension row didn't exist (orphaned request — original
                // submission didn't save correctly) and we must NOT show a false success.
                if ($stmt->rowCount() === 0) {
                    $notice = 'Could not match — no request record found. This usually means the original submission didn\'t save correctly; check with technical support.';
                    log_activity($pdo, current_user()['id'], 'certificate_request_match_failed', 'certificate_requests', $requestId,
                        "Failed match attempt for {$sacramentType} record #{$recordId}");
                } else {
                    log_activity($pdo, current_user()['id'], 'certificate_request_matched', 'certificate_requests', $requestId,
                        "Matched to {$sacramentType} record #{$recordId}");
                    $notice = 'Request matched to record.';
                }
            }
        }
    }
}

// --- Log a generated certificate ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_generated']) && csrf_verify()) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    if ($requestId) {
                $stmt = $pdo->prepare('SELECT status FROM certificate_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $requestRow = $stmt->fetch();
        if (!$requestRow || !in_array($requestRow['status'], ['approved', 'ready_for_pickup'], true)) {
            $notice = 'Only approved requests can be logged as generated.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO certificate_generation_log (certificate_request_id, generated_by, print_status) VALUES (?, ?, 'generated')"
            );
            $stmt->execute([$requestId, current_user()['id']]);
            log_activity($pdo, current_user()['id'], 'certificate_generated', 'certificate_requests', $requestId, 'Certificate generated/printed');
            $notice = 'Logged as generated. Remember to print and have it signed before pickup.';
        }
    }
}

// --- List requests ready to generate (approved or ready_for_pickup) -----
$stmt = $pdo->query(
    "SELECT * FROM certificate_requests WHERE status IN ('approved','ready_for_pickup') ORDER BY date_submitted ASC"
);
$requests = $stmt->fetchAll();

foreach ($requests as &$req) {
    $conf = CERT_EXT[$req['sacrament_type']] ?? null;
    $req['matched_record_id'] = null;
    if ($conf) {
        $s = $pdo->prepare("SELECT matched_record_id FROM {$conf['ext']} WHERE request_id = ?");
        $s->execute([$req['id']]);
        $row = $s->fetch();
        $req['matched_record_id'] = $row['matched_record_id'] ?? null;
    }
}
unset($req);

// --- Preview a specific request's certificate content --------------------
$previewData = null;
$previewSacrament = null;

if (!empty($_GET['record_type']) && !empty($_GET['record_id']) && isset(CERT_EXT[$_GET['record_type']])) {
    $recordType = $_GET['record_type'];
    $recordId = (int) $_GET['record_id'];
    $extTable = CERT_EXT[$recordType]['ext'];

    $stmt = $pdo->prepare(
        "SELECT cr.*
         FROM certificate_requests cr
         INNER JOIN {$extTable} ext ON ext.request_id = cr.id
         WHERE ext.matched_record_id = ? AND cr.status IN ('approved', 'ready_for_pickup')
         ORDER BY cr.date_submitted DESC
         LIMIT 1"
    );
    $stmt->execute([$recordId]);
    $reqRow = $stmt->fetch();

    if ($reqRow) {
        $_GET['preview'] = (int) $reqRow['id'];
    } else {
        $notice = 'No approved certificate request is linked to this record yet.';
    }
}

if (!empty($_GET['preview'])) {
    $reqId = (int) $_GET['preview'];
    $stmt = $pdo->prepare("SELECT * FROM certificate_requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $reqRow = $stmt->fetch();

    if ($reqRow) {
        $previewSacrament = $reqRow['sacrament_type'];
        $conf = CERT_EXT[$previewSacrament];
        $s = $pdo->prepare("SELECT * FROM {$conf['ext']} WHERE request_id = ?");
        $s->execute([$reqId]);
        $extRow = $s->fetch();

        $recordRow = null;
        if ($extRow && $extRow['matched_record_id']) {
            // Explicitly exclude legitimacy -- internal-only, never surfaced on certificate output.
            $r = $pdo->prepare("SELECT * FROM {$conf['record']} WHERE id = ?");
            $r->execute([$extRow['matched_record_id']]);
            $recordRow = $r->fetch();
            if ($recordRow) unset($recordRow['legitimacy']);
        }

        $previewData = ['request' => $reqRow, 'ext' => $extRow, 'record' => $recordRow];
    }
}

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Generate / Preview Certificate</h1>
        <p class="field-hint">Certificates are printed and signed in person — this page prepares the content and logs that it was generated. It does not produce a downloadable file for the requester.</p>

        <?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>

        <?php if ($previewData): ?>
            <?php
                $req = $previewData['request'];
                $ext = $previewData['ext'];
                $rec = $previewData['record'];
                $source = $rec ?: $ext; // prefer matched canonical record, fall back to what was submitted

                // ---- Compose a proper certificate presentation -------------------
                // Instead of dumping database columns, we build a curated set of
                // label/value rows per sacrament. Values are kept raw here; the
                // render loop below escapes every one of them.
                $certTitle   = humanize_label($previewSacrament);
                $certSubject = '—';
                $certPhrase  = '';
                $certRows    = [];

                switch ($previewSacrament) {
                    case 'baptismal':
                        $certTitle   = 'Baptism';
                        $certSubject = (string) ($source['child_full_name'] ?? '—');
                        $certPhrase  = 'received the Sacrament of Holy Baptism';
                        $date = $source['baptism_date'] ?? $source['date_of_baptism'] ?? null;
                        if ($date) $certRows['Date of Baptism'] = date('F j, Y', strtotime($date));
                        $certRows['Father']   = $source['father_full_name'] ?? '—';
                        $certRows['Mother']   = $source['mother_full_name'] ?? '—';
                        if (!empty($source['sponsors_text'])) {
                            $certRows['Sponsors'] = $source['sponsors_text'];
                        }
                        $certRows['Minister'] = $source['minister_name'] ?? $source['officiating_minister'] ?? '—';
                        break;

                    case 'confirmation':
                        $certTitle   = 'Confirmation';
                        $certSubject = (string) ($source['confirmand_full_name'] ?? '—');
                        $certPhrase  = 'received the Sacrament of Confirmation';
                        $date = $source['confirmation_date'] ?? $source['date_of_confirmation'] ?? null;
                        if ($date) $certRows['Date of Confirmation'] = date('F j, Y', strtotime($date));
                        $certRows['Bishop / Minister'] = $source['minister_name'] ?? $source['officiating_minister'] ?? '—';
                        $certRows['Sponsor'] = $source['sponsor_name'] ?? '—';
                        $certRows['Father']  = $source['father_full_name'] ?? '—';
                        $certRows['Mother']  = $source['mother_full_name'] ?? '—';
                        break;

                    case 'first_communion':
                        $certTitle   = 'First Holy Communion';
                        $certSubject = (string) ($source['communicant_full_name'] ?? '—');
                        $certPhrase  = 'received the Sacrament of the Holy Eucharist (First Holy Communion)';
                        $date = $source['communion_date'] ?? $source['date_of_first_communion'] ?? null;
                        if ($date) $certRows['Date of First Communion'] = date('F j, Y', strtotime($date));
                        $certRows['Minister'] = $source['minister_name'] ?? $source['officiating_minister'] ?? '—';
                        $certRows['Father']   = $source['father_full_name'] ?? '—';
                        $certRows['Mother']   = $source['mother_full_name'] ?? '—';
                        break;

                    case 'marriage':
                        $certTitle   = 'Marriage';
                        $husband = (string) ($source['husband_full_name'] ?? '');
                        $wife    = (string) ($source['wife_maiden_name'] ?? '');
                        $certSubject = ($husband !== '' || $wife !== '') ? trim($husband . '  &  ' . $wife) : '—';
                        $certPhrase  = 'were united in the Sacrament of Holy Matrimony';
                        $date = $source['marriage_date'] ?? $source['date_of_marriage'] ?? null;
                        if ($date) $certRows['Date of Marriage'] = date('F j, Y', strtotime($date));
                        $certRows['Husband']   = $husband !== '' ? $husband : '—';
                        $certRows['Wife (maiden name)'] = $wife !== '' ? $wife : '—';
                        $certRows['Officiating Minister'] = $source['minister_name'] ?? $source['officiating_minister'] ?? '—';
                        break;
                }

                // Register entry reference (book / page / line).
                $refParts = array_filter([$source['book_no'] ?? '', $source['page_no'] ?? '', $source['line_no'] ?? '']);
                $certRows['Register Entry (Book / Page / Line)'] = $refParts
                    ? implode(' / ', $refParts)
                    : 'Not stated';

                // Request metadata — always taken from the shared request row.
                $certRows['Requested by'] = $req['requester_name'] ?? '—';
                $certRows['Purpose'] = (!empty($req['purpose_other']) && ($req['purpose'] ?? '') === 'other')
                    ? $req['purpose_other']
                    : humanize_label($req['purpose'] ?? '');
                $certRows['Copies'] = (string) (int) ($req['num_copies'] ?? 1);
            ?>
            <div class="cert-preview">
                <h2>Certificate Preview — <?= e(humanize_label($previewSacrament)) ?></h2>
                <?php if (!$rec): ?>
                    <div class="alert alert-warning"><p>No matched digital record yet — this preview uses the requester's submitted details only. Match to a record below for an authoritative preview.</p></div>
                <?php endif; ?>

                <div class="cert-paper">
                    <p class="cert-parish">Prelature of Infanta — St. Patrick Parish, Dingalan, Aurora</p>
                    <h3>Certificate of <?= e($certTitle) ?></h3>
                    <p class="cert-intro">This is to certify that, according to the records of this parish,</p>
                    <p class="cert-subject"><?= e($certSubject) ?></p>
                    <p class="cert-body"><?= e($certPhrase) ?>, as recorded in the parish register.</p>

                    <table class="cert-fields">
                        <?php foreach ($certRows as $label => $value): ?>
                            <tr><th><?= e($label) ?></th><td><?= e((string) $value) ?></td></tr>
                        <?php endforeach; ?>
                    </table>

                    <p class="cert-issued">Issued this <?= date('j F Y') ?> at St. Patrick Parish Office, Dingalan, Aurora. This certificate is not valid without the signature of the Parish Secretary and the parish seal.</p>
                    <div class="cert-signature">
                        <span class="sign-line">Parish Secretary</span>
                    </div>
                </div>

                <button type="button" class="btn btn-primary" onclick="window.print()">Print Certificate</button>
                <form method="post" style="margin-top:1rem;">
                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                    <button type="submit" name="log_generated" value="1" class="btn">Mark as Generated</button>
                </form>
            </div>

            <?php if (!$rec): ?>
            <h3 style="margin-top:2rem;">Match to a Digital Record</h3>
            <form method="post" class="inline-fields">
                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                <input type="hidden" name="sacrament_type" value="<?= e($previewSacrament) ?>">
                <label>Record ID (look up on the corresponding Records page)
                    <input type="number" name="record_id" min="1" required>
                </label>
                <button type="submit" name="match_record" value="1" class="btn btn-small">Match</button>
            </form>
            <?php endif; ?>

            <p style="margin-top:1.5rem;"><a href="<?= $basePath ?>/admin/certificates/generate.php">&larr; Back to list</a></p>
        <?php else: ?>

        <?php if (empty($requests)): ?>
            <p>No requests are currently approved or ready for pickup.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Sacrament</th>
                    <th>Requester</th>
                    <th>Status</th>
                    <th>Matched Record?</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req): ?>
                    <tr>
                        <td><?= e($req['tracking_number']) ?></td>
                        <td><?= e(humanize_label($req['sacrament_type'])) ?></td>
                        <td><?= e($req['requester_name']) ?></td>
                        <td><span class="status-badge status-<?= e($req['status']) ?>"><?= e(humanize_label($req['status'])) ?></span></td>
                        <td><?= $req['matched_record_id'] ? '#' . (int) $req['matched_record_id'] : '<span class="flag-badge">Not matched</span>' ?></td>
                        <td><a href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= (int) $req['id'] ?>" class="btn btn-secondary btn-small">Preview / Generate</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';