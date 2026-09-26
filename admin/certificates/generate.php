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
require_once __DIR__ . '/print-coordinates.php';
require_once __DIR__ . '/baptismal-certificate.php';
require_once __DIR__ . '/sacramental-certificate.php';
require_role('secretary');
// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();
$pageTitle = 'Generate / Preview Certificate';
$notice = null;

$printMode = $_GET['print_mode'] ?? 'standard';
$printOnly = ($_GET['print_only'] ?? '') === '1';
$allowedPrintModes = ['standard', 'blank_stock'];
if (!in_array($printMode, $allowedPrintModes, true)) {
    $printMode = 'standard';
}
// Maps each sacrament type to its extension table (for request details) and
// the canonical records table (for the authoritative register entry).
// Used for certificate preview and record matching. The *_name columns let us
// auto-suggest and validate record matches without hardcoding 4 queries.
// (death_burial is intentionally absent: certificate_requests.sacrament_type
// is an ENUM without death_burial, so no such request can exist.)
const CERT_EXT = [
    'baptismal'      => ['ext' => 'baptismal_certificate_requests',       'record' => 'baptismal_records',        'ext_name' => 'child_full_name',      'record_name' => 'child_full_name'],
    'confirmation'   => ['ext' => 'confirmation_certificate_requests',    'record' => 'confirmation_records',     'ext_name' => 'confirmand_full_name',  'record_name' => 'confirmand_full_name'],
    'first_communion'=> ['ext' => 'first_communion_certificate_requests', 'record' => 'first_communion_records',  'ext_name' => 'communicant_full_name', 'record_name' => 'communicant_full_name'],
    'marriage'       => ['ext' => 'marriage_certificate_requests',        'record' => 'marriage_records',         'ext_name' => 'party_a_full_name',     'record_name' => 'husband_full_name'],
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
            $recordStmt = $pdo->prepare("SELECT * FROM {$config['record']} WHERE id = ?");
            $recordStmt->execute([$recordId]);
            $recordRow = $recordStmt->fetch();
            // Defensive only (not a live leak): this record-matching branch never
            // reaches the certificate template — the printable data is built from
            // the stripped copy further below. Strip here as well so a future edit
            // that renders from THIS variable cannot expose the internal-only field.
            if ($recordRow) unset($recordRow['legitimacy']);
            $extStmt = $pdo->prepare("SELECT * FROM {$config['ext']} WHERE request_id = ?");
            $extStmt->execute([$requestId]);
            $extRow = $extStmt->fetch();
            if (!$recordRow || !$extRow) {
                $notice = 'The selected record or request details could not be found.';
            } else {
                // Optional sanity check: the requester's stated subject name vs the
                // register record's primary name. Only warns — never blocks — because
                // the secretary may know the record is correct despite a typo.
                // Case-insensitive compare via the engine's ucwords() helper.
                $subjectName = trim((string) ($extRow[$config['ext_name']] ?? ''));
                $recordName  = trim((string) ($recordRow[$config['record_name']] ?? ''));
                $nameMismatch = ($subjectName !== '' && $recordName !== ''
                    && strcmp(ucwords($subjectName), ucwords($recordName)) !== 0);
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
                    // Reverse link: let the register record know which approved
                    // certificate request cited it. Guarded (record tables may
                    // not yet have the column on a pre-migration install).
                    try {
                        $rlink = $pdo->prepare("UPDATE {$config['record']} SET certificate_request_id = ? WHERE id = ?");
                        $rlink->execute([$requestId, $recordId]);
                    } catch (\Throwable $e) {
                        // Reverse link is best-effort; forward match already done.
                    }
                    log_activity($pdo, current_user()['id'], 'certificate_request_matched', 'certificate_requests', $requestId,
                        "Matched to {$sacramentType} record #{$recordId}" . ($nameMismatch ? " (name mismatch: '{$subjectName}' vs '{$recordName}')" : ''));
                    $notice = $nameMismatch
                        ? "Request matched to record #{$recordId}, but the subject name DOES NOT match — request says '{$subjectName}', record says '{$recordName}'. Please confirm before printing."
                        : 'Request matched to record.';
                }
            }
        }
    }
}
// --- Verify a matched record against the physical register book --------
// A record is verified once (per register entry, not per request) and that
// verification then covers every future certificate request citing the same
// entry. See migrations/031_add_book_record_verification.sql. Blocks
// log_generated below until this is done for a matched request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_book_record']) && csrf_verify()) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $sacramentType = $_POST['sacrament_type'] ?? '';
    if (($_POST['confirm_book_verified'] ?? '') !== '1') {
        $notice = 'Please check the confirmation box to verify this entry against the physical register book.';
    } elseif ($requestId && isset(CERT_EXT[$sacramentType])) {
        $config = CERT_EXT[$sacramentType];
        $requestStmt = $pdo->prepare('SELECT sacrament_type, status FROM certificate_requests WHERE id = ?');
        $requestStmt->execute([$requestId]);
        $requestRow = $requestStmt->fetch();
        $extStmt = $pdo->prepare("SELECT matched_record_id FROM {$config['ext']} WHERE request_id = ?");
        $extStmt->execute([$requestId]);
        $extRow = $extStmt->fetch();
        if (!$requestRow || $requestRow['sacrament_type'] !== $sacramentType) {
            $notice = 'The request type does not match the selected sacrament.';
        } elseif (!$extRow || !$extRow['matched_record_id']) {
            $notice = 'This request is not yet matched to a digital record; match it before verifying.';
        } else {
            $recordId = (int) $extRow['matched_record_id'];
            $stmt = $pdo->prepare("UPDATE {$config['record']} SET book_verified_by = ?, book_verified_at = NOW() WHERE id = ?");
            $stmt->execute([current_user()['id'], $recordId]);
            log_activity($pdo, current_user()['id'], 'book_record_verified', $config['record'], $recordId,
                "Confirmed {$sacramentType} record #{$recordId} against the physical register book (request #{$requestId}).");
            $notice = "Record #{$recordId} confirmed against the physical register book.";
        }
    }
}
// --- Log a generated certificate ----------------------------------------
// Per the workflow, "generate" = digital draft created on approval. After the
// secretary generates it, the request is considered physically ready to be
// printed/signed at the counter, so we flip the status to ready_for_pickup
// (unless it's already there) and raise the admin bell. "Print" stays a
// separate, in-person step — never merged with this.
//
// Duplicate-log prevention: before inserting, check whether an active
// (non-released, non-superseded) generation log already exists for this
// request. If one does, the secretary must explicitly choose "Regenerate"
// (which supersedes the prior row) — we never silently create a second one.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_generated']) && csrf_verify()) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    if ($requestId) {
        $stmt = $pdo->prepare('SELECT status, tracking_number, sacrament_type, requester_name FROM certificate_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $requestRow = $stmt->fetch();
        // Book-verification gate: a request matched to a digital record may
        // only be generated once the record has been checked against the
        // physical register. A request with no matched record (manual-search
        // path) is not gated here -- there is no digital entry to check yet.
        $bookGateBlocked = false;
        if ($requestRow && isset(CERT_EXT[$requestRow['sacrament_type']])) {
            $config = CERT_EXT[$requestRow['sacrament_type']];
            $extStmt = $pdo->prepare("SELECT matched_record_id FROM {$config['ext']} WHERE request_id = ?");
            $extStmt->execute([$requestId]);
            $extRow = $extStmt->fetch();
            if ($extRow && $extRow['matched_record_id']) {
                $recStmt = $pdo->prepare("SELECT book_verified_at FROM {$config['record']} WHERE id = ?");
                $recStmt->execute([(int) $extRow['matched_record_id']]);
                $bookGateBlocked = !$recStmt->fetchColumn();
            }
        }
        if (!$requestRow || !in_array($requestRow['status'], ['approved', 'ready_for_pickup'], true)) {
            $notice = 'Only approved requests can be logged as generated.';
        } elseif ($bookGateBlocked) {
            $notice = 'This record has not yet been confirmed against the physical register book. Verify it below before generating the certificate.';
        } else {
            // --- Duplicate-log guard ------------------------------------
            $cur = cert_current_generation_log($pdo, $requestId);
            if ($cur && $cur['print_status'] !== 'released') {
                // An active log already exists — require explicit regeneration.
                $existingLogId = (int) $cur['id'];
                $notice = "An active generation log already exists for this request (log #{$existingLogId}, status: {$cur['print_status']}). To create a new one, use the Regenerate button (it will mark the prior log as superseded).";
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO certificate_generation_log (certificate_request_id, generated_by, print_status) VALUES (?, ?, 'generated')"
                );
                $stmt->execute([$requestId, current_user()['id']]);
                // Request ready for pickup (unless already).
                if ($requestRow['status'] !== 'ready_for_pickup') {
                    $up = $pdo->prepare('UPDATE certificate_requests SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?');
                    $up->execute(['ready_for_pickup', current_user()['id'], $requestId]);
                }
                $kind = ucwords(str_replace('_', ' ', (string) ($requestRow['sacrament_type'] ?? ''))) . ' Certificate';
                notify($pdo, 'request_ready', 'Certificate ready for pickup',
                    $kind . ' for ' . $requestRow['requester_name'] . ' (' . $requestRow['tracking_number'] . ')',
                    '/admin/requests/queue.php?status=ready_for_pickup');
                notify_certificate_requester_status($pdo, $requestId, 'ready_for_pickup');
                log_activity($pdo, current_user()['id'], 'certificate_generated', 'certificate_requests', $requestId, 'Certificate generated/printed; marked ready for pickup');
                $notice = 'Logged as generated and marked ready for pickup. Remember to print and have it signed before pickup.';
            }
        }
    }
}
// --- Cancel: preserve the lifecycle history and require a reason -----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_certificate']) && csrf_verify()) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $reason = trim((string) ($_POST['cancellation_reason'] ?? ''));
    if (!$requestId || $reason === '') {
        $notice = 'A cancellation reason is required.';
    } else {
        $requestStmt = $pdo->prepare('SELECT status FROM certificate_requests WHERE id = ?');
        $requestStmt->execute([$requestId]);
        $requestStatus = (string) $requestStmt->fetchColumn();
        if ($requestStatus === '' || !cert_request_transition_valid($requestStatus, 'cancelled')) {
            $notice = $requestStatus === ''
                ? 'Request not found.'
                : "Cannot cancel a request with status '{$requestStatus}'.";
        } else {
            $cur = cert_current_generation_log($pdo, $requestId);
            $pdo->beginTransaction();
            $cancelStmt = $pdo->prepare("UPDATE certificate_requests SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ? WHERE id = ? AND status = ?");
            $cancelStmt->execute([current_user()['id'], $reason, $requestId, $requestStatus]);
            if ($cancelStmt->rowCount() !== 1) {
                $pdo->rollBack();
                $notice = 'The request changed before cancellation could be recorded. Refresh and try again.';
            } else {
                if ($cur) $pdo->prepare('UPDATE certificate_generation_log SET cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ? WHERE id = ?')->execute([current_user()['id'], $reason, (int) $cur['id']]);
                log_activity($pdo, current_user()['id'], 'certificate_cancelled', 'certificate_requests', $requestId, 'Certificate request cancelled.', $reason);
                $pdo->commit();
                $notice = 'Certificate request cancelled and preserved in history.';
            }
        }
    }
}
// --- Regenerate: refresh the current generation log in place --------------
// The user must confirm via the checkbox before replacing the current
// generated/printed state. Keeping the same row prevents duplicate active
// history entries for one certificate request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate']) && $_POST['confirm_regenerate'] === '1' && csrf_verify()) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    if ($requestId) {
        $cur = cert_current_generation_log($pdo, $requestId);
        if (!$cur) {
            $notice = 'No current generation log to regenerate.';
        } else {
            $currentLogId  = (int) $cur['id'];
            $currentStatus = $cur['print_status'];
            $stmt = $pdo->prepare(
                "UPDATE certificate_generation_log
                    SET generated_by = ?, generated_at = NOW(), print_status = 'generated',
                        printed_by = NULL, printed_at = NULL
                  WHERE id = ? AND superseded_at IS NULL"
            );
            $stmt->execute([current_user()['id'], $currentLogId]);
            log_activity($pdo, current_user()['id'], 'certificate_regenerated',
                'certificate_requests', $requestId,
                "Regenerated certificate using generation log #{$currentLogId} (was {$currentStatus}); existing log updated.");
            $notice = "Regenerated certificate using the existing generation log #{$currentLogId}.";
        }
    }
}
// --- Helper: render the Regenerate confirmation UI when an active log exists ---
function render_regenerate_if_active(PDO $pdo, int $requestId): void
{
    $cur = cert_current_generation_log($pdo, $requestId);
    if (!$cur || $cur['print_status'] === 'released') {
        return;
    }
    $logId = (int) $cur['id'];
    echo '<div class="alert alert-info" style="margin-top:1rem;">';
    echo '<p><strong>Active generation log exists</strong> (log #' . $logId . ', status: ' . e($cur['print_status']) . '). ';
    echo 'To create a replacement, confirm below — the prior log will be marked superseded:</p>';
        echo '<form autocomplete="off" method="post" style="margin-top:0.5rem;">';
    echo csrf_field();
    echo '<input type="hidden" name="request_id" value="' . $requestId . '">';
        echo '<input type="hidden" name="confirm_regenerate" value="1">';
    echo '<button type="submit" name="regenerate" value="1" class="btn btn-small">Confirm Regeneration</button>';
    echo '</form></div>';
}
// --- List requests ready to generate (approved or ready_for_pickup) -----
$selfUrl = $basePath . '/admin/certificates/generate.php';
$rfConfig = ['date_column' => 'date_submitted', 'search_columns' => ['tracking_number', 'sacrament_type', 'requester_name'], 'placeholder' => 'Search tracking no., sacrament, requester...', 'ranges' => ['today', 'week', 'month', 'year']];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$baseWhere = "WHERE status IN ('approved','ready_for_pickup')";
// $rf['where'] is ' AND ...' when filters are active, '' otherwise —
// append it as-is (ltrim with a charlist would corrupt it, e.g. it
// previously produced "AND AND (...)" → SQL 1064).
$combinedWhere = $baseWhere . $rf['where'];
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM certificate_requests $combinedWhere");
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();
$stmt = $pdo->prepare("SELECT * FROM certificate_requests $combinedWhere ORDER BY date_submitted ASC LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$stmt->execute($rf['params']);
$requests = $stmt->fetchAll();
foreach ($requests as &$req) {
    $conf = CERT_EXT[$req['sacrament_type']] ?? null;
    $req['matched_record_id'] = null;
    $req['book_verified'] = null; // null = n/a (unmatched); 0/1 once matched
    if ($conf) {
        $s = $pdo->prepare("SELECT matched_record_id FROM {$conf['ext']} WHERE request_id = ?");
        $s->execute([$req['id']]);
        $row = $s->fetch();
        $req['matched_record_id'] = $row['matched_record_id'] ?? null;
        if ($req['matched_record_id']) {
            $vs = $pdo->prepare("SELECT book_verified_at FROM {$conf['record']} WHERE id = ?");
            $vs->execute([(int) $req['matched_record_id']]);
            $req['book_verified'] = $vs->fetchColumn() ? 1 : 0;
        }
    }
}
unset($req);
// --- Preview a specific request's certificate content --------------------
$previewData = null;
$previewSacrament = null;
$currentLog = null;
$suggestedRecords = [];
$suggestNoResults = false;
$directRecordId = 0;
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
    if (!$reqRow) {
        $recordTable = CERT_EXT[$recordType]['record'];
        $stmt = $pdo->prepare(
            "SELECT cr.*
             FROM certificate_requests cr
             INNER JOIN {$recordTable} record ON record.certificate_request_id = cr.id
             WHERE record.id = ? AND cr.status IN ('approved', 'ready_for_pickup')
             ORDER BY cr.date_submitted DESC
             LIMIT 1"
        );
        $stmt->execute([$recordId]);
        $reqRow = $stmt->fetch();
    }
    if ($reqRow) {
        $directRecordId = $recordId;
        $_GET['preview'] = (int) $reqRow['id'];
    } else {
        $notice = 'No approved certificate request is linked to this record yet.';
    }
}
if (!empty($_GET['preview'])) {
    $reqId = (int) $_GET['preview'];
    $stmt = $pdo->prepare("SELECT * FROM certificate_requests WHERE id = ? AND status IN ('approved', 'ready_for_pickup')");
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
            $r = $pdo->prepare("SELECT * FROM {$conf['record']} WHERE id = ?");
            $r->execute([$extRow['matched_record_id']]);
            $recordRow = $r->fetch();
        } elseif ($directRecordId > 0) {
            $r = $pdo->prepare("SELECT * FROM {$conf['record']} WHERE id = ? AND certificate_request_id = ?");
            $r->execute([$directRecordId, $reqId]);
            $recordRow = $r->fetch();
        }
        if ($recordRow) unset($recordRow['legitimacy']);
        $previewData = ['request' => $reqRow, 'ext' => $extRow, 'record' => $recordRow];

        // Fetch the current generation log — this drives the Regenerate UI below.
        // (Mark Printed is owned by admin/certificates/history.php.)
        $currentLog = cert_current_generation_log($pdo, $reqId);
        // ---- Auto-suggest record matches ------------------------------------
        // When a request has no matched record yet, pre-fetch candidate records
        // whose primary name loosely matches the request's stated subject name,
        // so the secretary can pick one instead of typing a record ID.
        $suggestedRecords = [];
        $suggestNoResults = false;
        if ($extRow && !$recordRow && isset(CERT_EXT[$previewSacrament])) {
            $sc = CERT_EXT[$previewSacrament];
            $kw = trim((string) ($extRow[$sc['ext_name']] ?? ''));
            if ($kw !== '') {
                $like = '%' . $kw . '%';
                $sq = $pdo->prepare(
                    "SELECT id, {$sc['record_name']} AS subject_name,
                            book_no, page_no, line_no, date_encoded
                     FROM {$sc['record']}
                     WHERE {$sc['record_name']} LIKE ?
                     ORDER BY id ASC LIMIT 5"
                );
                $sq->execute([$like]);
                $suggestedRecords = $sq->fetchAll();
                $suggestNoResults = count($suggestedRecords) === 0;
            } else {
                $suggestNoResults = true;
            }
        }
    }
}
function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <?php $bcLayout = baptismal_blank_stock_layout(); ?>
        <style>
            @page {
                size: <?= (float) $bcLayout['page']['width_in'] ?>in <?= (float) $bcLayout['page']['height_in'] ?>in;
                margin: 0;
            }
            @media print {
                body {
                    margin: 0 !important;
                    background: #fff;
                }
                body:has(.blank-stock-print-wrapper) .site-master-header,
                body:has(.blank-stock-print-wrapper) .admin-sidebar,
                body:has(.blank-stock-print-wrapper) .site-footer,
                body:has(.blank-stock-print-wrapper) .admin-content > :not(.cert-preview),
                body:has(.blank-stock-print-wrapper) .cert-preview > :not(.blank-stock-print-wrapper) {
                    display: none !important;
                }
                body:has(.blank-stock-print-wrapper) .admin-layout {
                    display: block;
                    min-height: 0;
                    background: #fff;
                }
                body:has(.blank-stock-print-wrapper) .admin-content {
                    padding: 0;
                    margin: 0;
                }
                .blank-stock-print-wrapper {
                    margin: 0 !important;
                    padding: 0 !important;
                    box-shadow: none !important;
                    max-width: none !important;
                    width: auto !important;
                }
                /* True physical size; calibration offsets nudge the printed values. */
                .blank-stock-print-wrapper .bc-svg {
                    width: <?= (float) $bcLayout['page']['width_in'] ?>in !important;
                    height: <?= (float) $bcLayout['page']['height_in'] ?>in !important;
                    transform: translate(<?= (float) $bcLayout['calibration']['offset_x_in'] ?>in, <?= (float) $bcLayout['calibration']['offset_y_in'] ?>in);
                }
                .blank-stock-print-wrapper .sc-svg {
                    width: <?= (float) $bcLayout['page']['width_in'] ?>in !important;
                    height: <?= (float) $bcLayout['page']['height_in'] ?>in !important;
                }
                /* Default print = values only, for the pre-printed stock. */
                .bc-template { display: none; }
                body[data-cert-print="full"] .bc-template { display: initial; }
            }
            <?php if ($printOnly): ?>
            .print-only-hidden { display: none !important; }
            <?php endif; ?>
            .blank-stock-print-wrapper {
                width: 100%;
                max-width: <?= (float) $bcLayout['page']['width_in'] ?>in;
                margin: 0 auto;
                background: #fff;
                box-shadow: 0 0 0 1px rgba(0,0,0,0.12), 0 4px 18px rgba(0,0,0,0.08);
            }
            .blank-stock-print-wrapper .bc-svg {
                display: block;
                width: 100%;
                height: auto;
            }
            .blank-stock-print-wrapper .sc-svg {
                display: block;
                width: 100%;
                height: auto;
            }
            .admin-content .inline-fields > button,
            .admin-content .inline-fields > .btn {
                flex: 0 0 auto;
                width: auto;
                align-self: flex-end;
            }
            .cert-action-toolbar {
                display: flex;
                align-items: center;
                gap: 0.6rem;
                flex-wrap: wrap;
                margin-top: 0.9rem;
            }
            .cert-action-toolbar form,
            .cert-action-toolbar .cert-action-form {
                margin: 0;
            }
            .cert-action-toolbar .btn,
            .cert-action-toolbar button {
                width: auto;
                white-space: nowrap;
            }
            .cert-cancel-form {
                margin-top: 0.7rem;
            }
            .cert-cancel-form input {
                min-width: 16rem;
            }
            @media (max-width: 700px) {
                .cert-action-toolbar {
                    align-items: stretch;
                    flex-direction: column;
                }
                .cert-action-toolbar form,
                .cert-action-toolbar .btn,
                .cert-action-toolbar button {
                    width: 100%;
                }
            }
        </style>
        <?php if (!$printOnly): ?>
        <h1>Generate / Preview Certificate</h1>
        <p class="field-hint">Certificates are printed and signed in person — this page prepares the content and logs that it was generated. It does not produce a downloadable file for the requester.</p>
        <?php endif; ?>
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

                // ---- Certificate name casing (display only) -----------------------
                // The person(s) being certified print in ALL CAPS; everyone else
                // (parents, minister, sponsors) prints in Title Case, matching the
                // parish's existing baptismal-certificate convention. This only
                // touches $certSubject/$certRows for rendering below -- $source and
                // the database are never modified.
                $certSubject = cert_format_upper_case($certSubject);
                $certSubjectRows = ['Husband', 'Wife (maiden name)']; // marriage: both spouses are "the certified"
                $certPersonRows  = ['Father', 'Mother', 'Minister', 'Bishop / Minister', 'Sponsor', 'Sponsors', 'Officiating Minister'];
                foreach ($certRows as $label => &$rowValue) {
                    if (in_array($label, $certSubjectRows, true)) {
                        $rowValue = cert_format_upper_case((string) $rowValue);
                    } elseif (in_array($label, $certPersonRows, true)) {
                        $rowValue = cert_format_title_case((string) $rowValue);
                    }
                }
                unset($rowValue);
                // Request metadata — always taken from the shared request row.
                $certRows['Requested by'] = $req['requester_name'] ?? '—';
                $certRows['Purpose'] = (!empty($req['purpose_other']) && ($req['purpose'] ?? '') === 'other')
                    ? $req['purpose_other']
                    : humanize_label($req['purpose'] ?? '');
            ?>
            <div class="cert-preview">
                <?php if (!$printOnly): ?><h2>Certificate Preview — <?= e(humanize_label($previewSacrament)) ?></h2><?php endif; ?>
                <?php if (!$rec): ?>
                    <div class="alert alert-warning"><p>No matched digital record yet — this preview uses the requester's submitted details only. Match to a record below for an authoritative preview.</p></div>
                <?php endif; ?>
                <?php if ($previewSacrament === 'baptismal'): ?>
                    <?php
                    $bcSponsors = [];
                    if ($rec) { // sponsors belong to the register record, never to the request row
                        $sponsorQuery = $pdo->prepare('SELECT sponsor_name FROM baptismal_sponsors WHERE baptismal_record_id = ? ORDER BY id ASC');
                        $sponsorQuery->execute([(int) $rec['id']]);
                        foreach ($sponsorQuery->fetchAll() as $sponsorRow) {
                            $sponsorName = trim((string) ($sponsorRow['sponsor_name'] ?? ''));
                            if ($sponsorName !== '') $bcSponsors[] = $sponsorName;
                        }
                    }
                    $bcSource = $source;
                    $bcValues = baptismal_certificate_values($bcSource, $req, $bcSponsors);
                    ?>
                    <?php if (!$printOnly): ?><div class="alert alert-warning" style="margin-bottom:1rem;">
                        <p><strong>Test print first:</strong> field positions were measured from a photo of the blank stock. Print one on plain paper with "Print Full Certificate", hold it against the stock to the light, and adjust <code>calibration</code> / <code>page</code> in print-coordinates.php if needed.</p>
                    </div><?php endif; ?>
                    <div class="blank-stock-print-wrapper" aria-label="Baptismal certificate preview">
                        <?= baptismal_certificate_svg($bcLayout, $bcValues) ?>
                    </div>
                    <?php if ($printOnly): ?>
                        <div class="cert-action-toolbar">
                            <button type="button" class="btn btn-primary" onclick="bcPrint('overlay')">Print on Blank Stock</button>
                            <button type="button" class="btn btn-secondary" onclick="bcPrint('full')">Print Full Certificate</button>
                        </div>
                    <?php else: ?>
                        <p class="field-hint">This page is for preview and generation. Printing and pickup handling are completed from <a href="<?= $basePath ?>/admin/certificates/history.php?request_id=<?= (int) $req['id'] ?>">Print / Pickup History</a>.</p>
                    <?php endif; ?>
                    <?= baptismal_certificate_fit_script() ?>
                <?php else: ?>
                <?php if (in_array($previewSacrament, ['confirmation', 'first_communion', 'marriage'], true)): ?>
                    <?= sacramental_certificate_html($previewSacrament, $source, $req, $basePath) ?>
                    <?= sacramental_certificate_fit_script() ?>
                <?php else: ?>
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
                        <?php if (!empty($req['verification_code'])): ?>
                            <div class="cert-verification" style="margin-top: 1rem; font-size: 0.8rem; color: #555; border-top: 1px solid #ccc; padding-top: 0.5rem; display: flex; align-items: center; gap: 1rem;">
                                <div class="cert-qr-code">
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=60x60&data=<?= urlencode($basePath . '/public/verify.php?token=' . $req['verification_code']) ?>" alt="Verification QR Code" style="width: 60px; height: 60px;">
                                </div>
                                <div class="cert-token-text">
                                    <strong>Verify this certificate:</strong><br>
                                    Visit: <?= e($basePath . '/public/verify.php') ?><br>
                                    Token: <code><?= e($req['verification_code']) ?></code>
                                </div>
                            </div>
                        <?php endif; ?>
                        <p class="cert-issued">Issued this <?= date('j F Y') ?> at St. Patrick Parish Office, Dingalan, Aurora. This certificate is not valid without the signature of the Parish Secretary and the parish seal.</p>
                        <div class="cert-signature">
                            <span class="sign-line">Parish Secretary</span>
                        </div>
                    </div>
                    <?php if ($printOnly): ?>
                        <div class="cert-action-toolbar"><button type="button" class="btn btn-primary" onclick="window.print()">Print Certificate</button></div>
                    <?php else: ?>
                        <p class="field-hint">Printing and release handling are done from the <a href="<?= $basePath ?>/admin/certificates/history.php">Print / Pickup History</a> page.</p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!$printOnly): ?><div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center; margin-top:1rem;">
                    <?php if ($currentLog && ($currentLog['print_status'] === 'generated' || ($currentLog['print_status'] === 'printed' && empty($currentLog['finalized_at'])))): ?>
                        <a href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= (int) $req['id'] ?>&print_only=1" class="btn btn-primary">Open Print View</a>
                    <?php endif; ?>
                    <?php if ($previewSacrament !== 'baptismal' && $printMode !== 'blank_stock'): ?>
                        <a href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= (int) $req['id'] ?>&print_mode=blank_stock" class="btn btn-secondary">Open Certificate Template</a>
                    <?php elseif ($previewSacrament !== 'baptismal'): ?>
                        <a href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= (int) $req['id'] ?>" class="btn btn-secondary">Back to Full Preview</a>
                    <?php endif; ?>
                </div>
                <?php
                    // Book-verification status for a matched record. $rec, when set,
                    // is the matched register row loaded above (legitimacy already
                    // stripped) — its book_verified_at flag drives this section.
                    $bookVerified = $rec ? !empty($rec['book_verified_at']) : true; // unmatched: nothing to verify here
                ?>
                <?php if ($rec && !$bookVerified): ?>
                    <div class="alert alert-warning">
                        <p><strong>Not yet confirmed against the physical register book.</strong> Check the corresponding logbook entry, then confirm below before this certificate can be marked as generated.</p>
                        <form autocomplete="off" method="post" class="cert-action-form">
                            <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                            <input type="hidden" name="sacrament_type" value="<?= e($previewSacrament) ?>">
                            <label class="checkbox-label"><input type="checkbox" name="confirm_book_verified" value="1" required> I checked record #<?= (int) $rec['id'] ?> against the physical register book and it matches.</label>
                            <button type="submit" name="verify_book_record" value="1" class="btn btn-secondary">Confirm Verification</button>
                        </form>
                    </div>
                <?php elseif ($rec): ?>
                    <?php
                        $verifierName = '';
                        if (!empty($rec['book_verified_by'])) {
                            $vs = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
                            $vs->execute([(int) $rec['book_verified_by']]);
                            $verifierName = (string) $vs->fetchColumn();
                        }
                    ?>
                    <p class="field-hint">Register book verified<?= $verifierName !== '' ? ' by ' . e($verifierName) : '' ?><?= !empty($rec['book_verified_at']) ? ' on ' . e(date('F j, Y', strtotime($rec['book_verified_at']))) : '' ?>.</p>
                <?php endif; ?>
                <div class="cert-action-toolbar">
                <form autocomplete="off" method="post" class="cert-action-form">
                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                    <button type="submit" name="log_generated" value="1" class="btn" <?= $bookVerified ? '' : 'disabled title="Confirm the register book verification above first."' ?>>Mark as Generated</button>
                </form>
                </div>
                <?php if (!$currentLog || empty($currentLog['cancelled_at'])): ?>
                <form autocomplete="off" method="post" class="inline-fields cert-cancel-form" style="gap:0.5rem;" data-confirm="Cancel this certificate request? The reason will be preserved in the audit history.">
                    <?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                    <input type="text" name="cancellation_reason" maxlength="500" required placeholder="Reason for cancellation">
                    <button type="submit" name="cancel_certificate" value="1" class="btn btn-danger btn-small">Cancel Request</button>
                </form>
                <?php endif; ?>
                <?php render_regenerate_if_active($pdo, $req['id']); ?></div><?php endif; ?>
            <?php if (!$rec): ?>
            <h3 style="margin-top:2rem;">Match to a Digital Record</h3>
            <?php if (count($suggestedRecords) > 0): ?>
                <p class="field-hint">Suggested records that match "<?= e((string)($ext[$conf['ext_name']] ?? '')) ?>". Click one to match, or enter a record ID below.</p>
                <form autocomplete="off" method="post" class="inline-fields" style="gap:0.75rem; flex-wrap:wrap;">
                    <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                    <input type="hidden" name="sacrament_type" value="<?= e($previewSacrament) ?>">
                    <input type="hidden" name="match_record" value="1">
                    <?php foreach ($suggestedRecords as $sug): ?>
                        <button type="submit" name="record_id" value="<?= (int) $sug['id'] ?>" class="btn btn-secondary btn-small">
                            #<?= (int) $sug['id'] ?> — <?= e((string)($sug['subject_name'] ?? '')) ?>
                        </button>
                    <?php endforeach; ?>
                </form>
            <?php elseif ($suggestNoResults): ?>
                <p class="field-hint">No existing record matched "<?= e((string)($ext[$conf['ext_name']] ?? '')) ?>". You can still enter a record ID below, or encode the record on the Records page first.</p>
            <?php endif; ?>
            <form autocomplete="off" method="post" class="inline-fields" style="margin-top:0.75rem;">
                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                <input type="hidden" name="sacrament_type" value="<?= e($previewSacrament) ?>">
                <label>Or enter Record ID (look up on the corresponding Records page)
                    <input autocomplete="off" type="number" name="record_id" min="1">
                </label>
                <button type="submit" name="match_record" value="1" class="btn btn-small">Match</button>
            </form>
            <?php endif; ?>
            <p style="margin-top:1.5rem;"><a href="<?= $basePath ?>/admin/certificates/generate.php">&larr; Back to list</a></p>
        <?php else: ?>
                <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php if (empty($requests)): ?>
            <p>No requests are currently approved or ready for pickup.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Sacrament</th>
                    <th>Requester</th>
                    <th>Status</th>
                    <th>Matched Record?</th>
                    <th>Book Verified?</th>
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
                        <td>
                            <?php if ($req['book_verified'] === null): ?>
                                <span class="field-hint">&ndash;</span>
                            <?php elseif ($req['book_verified']): ?>
                                <span class="flag-badge flag-badge-ok">Verified</span>
                            <?php else: ?>
                                <span class="flag-badge">Not yet</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= (int) $req['id'] ?>" class="btn btn-secondary btn-small">Preview / Generate</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';
