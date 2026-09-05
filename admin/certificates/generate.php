<?php
/**
 * =====================================================================
 * SPPO-IS CERTIFICATE GENERATE / PREVIEW (admin)
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

const CERT_EXT = [
    'baptismal'      => ['ext' => 'baptismal_certificate_requests',       'record' => 'baptismal_records'],
    'confirmation'   => ['ext' => 'confirmation_certificate_requests',    'record' => 'confirmation_records'],
    'first_communion'=> ['ext' => 'first_communion_certificate_requests', 'record' => 'first_communion_records'],
    'marriage'       => ['ext' => 'marriage_certificate_requests',        'record' => 'marriage_records'],
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
        $extTable = CERT_EXT[$sacramentType]['ext'];
        $stmt = $pdo->prepare("UPDATE {$extTable} SET matched_record_id = ? WHERE request_id = ?");
        $stmt->execute([$recordId, $requestId]);
        log_activity($pdo, current_user()['id'], 'certificate_request_matched', 'certificate_requests', $requestId,
            "Matched to {$sacramentType} record #{$recordId}");
        $notice = 'Request matched to record.';
    }
}

// --- Log a generated certificate ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_generated']) && csrf_verify()) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    if ($requestId) {
        $stmt = $pdo->prepare(
            "INSERT INTO certificate_generation_log (certificate_request_id, generated_by, print_status) VALUES (?, ?, 'generated')"
        );
        $stmt->execute([$requestId, current_user()['id']]);
        log_activity($pdo, current_user()['id'], 'certificate_generated', 'certificate_requests', $requestId, 'Certificate generated/printed');
        $notice = 'Logged as generated. Remember to print and have it signed before pickup.';
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
            ?>
            <div class="cert-preview">
                <h2>Certificate Preview — <?= e(humanize_label($previewSacrament)) ?></h2>
                <?php if (!$rec): ?>
                    <div class="alert alert-warning"><p>No matched digital record yet — this preview uses the requester's submitted details only. Match to a record below for an authoritative preview.</p></div>
                <?php endif; ?>

                <div class="cert-paper">
                    <p class="cert-parish">Prelature of Infanta — St. Patrick Parish, Dingalan, Aurora</p>
                    <h3>Certificate of <?= e(humanize_label($previewSacrament)) ?></h3>
                    <table class="cert-fields">
                        <?php foreach ($source as $key => $value): ?>
                            <?php if (in_array($key, ['id','request_id','matched_record_id','encoded_by','date_encoded'])) continue; ?>
                            <tr><th><?= e(humanize_label($key)) ?></th><td><?= e((string) $value) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                    <p class="cert-purpose">Purpose: <?= e(humanize_label($req['purpose'])) ?><?= $req['purpose'] === 'other' ? ' — ' . e($req['purpose_other']) : '' ?></p>
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