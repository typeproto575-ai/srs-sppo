<?php
/**
 * =====================================================================
 * SRS-SPPO PRINT / PICKUP HISTORY (admin)
 * =====================================================================
 * Purpose: Records when certificates were printed and RELEASED, and to
 *          whom - the pickup log. "Mark released" captures the pickup
 *          date and closes the loop on a request's lifecycle. Identity
 *          of the claimant is verified in person at the counter.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Print / Pickup History';
$notice = null;

// --- Mark printed: physical printing is owned by this page -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_printed']) && csrf_verify()) {
    $requestId = (int) ($_POST['certificate_request_id'] ?? 0);
    if ($requestId) {
        $result = log_certificate_printed($pdo, $requestId, current_user()['id']);
        if ($result['ok']) {
            $verificationToken = generate_uuidv4();
            $stmt = $pdo->prepare('UPDATE certificate_requests SET verification_code = ? WHERE id = ?');
            $stmt->execute([$verificationToken, $requestId]);
            log_activity($pdo, current_user()['id'], 'certificate_printed',
                'certificate_requests', $requestId,
                'Certificate marked as printed (physical copy confirmed) and verification token generated.');
            $notice = 'Marked as printed. The certificate has been physically printed and confirmed.';
        } else {
            $notice = $result['reason'];
        }
    }
}

// --- Finalize: confirm the printed certificate is signed and sealed --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_certificate']) && csrf_verify()) {
    $requestId = (int) ($_POST['certificate_request_id'] ?? 0);
    $cur = $requestId ? cert_current_generation_log($pdo, $requestId) : null;
    if (!$cur || $cur['print_status'] !== 'printed') {
        $notice = 'Only a currently printed certificate can be finalized.';
    } else {
        $stmt = $pdo->prepare('UPDATE certificate_generation_log SET finalized_by = ?, finalized_at = NOW() WHERE id = ? AND finalized_at IS NULL');
        $stmt->execute([current_user()['id'], (int) $cur['id']]);
        log_activity($pdo, current_user()['id'], 'certificate_finalized', 'certificate_requests', $requestId, 'Certificate finalized after signature and seal review.');
        $notice = 'Certificate finalized. It may now proceed to pickup readiness.';
    }
}

// --- Mark released + pickup date ------------------------------------------
// CSRF: forged or stale POSTs are dropped silently - nothing is marked.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $notice = null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_released']) && csrf_verify()) {
    require_role('secretary');
    $logId              = (int) ($_POST['log_id'] ?? 0);
    $claimantName       = trim((string) ($_POST['claimant_name'] ?? ''));
    $claimantRelation   = trim((string) ($_POST['claimant_relationship'] ?? ''));
    $claimantNotes      = trim((string) ($_POST['claimant_notes'] ?? ''));
    $idVerified         = isset($_POST['id_verified']) && $_POST['id_verified'] === '1';

    if (!$idVerified) {
        $notice = 'You must verify the claimant\'s valid ID before releasing the certificate.';
    } elseif ($claimantName === '') {
        $notice = 'Claimant name is required.';
    } elseif ($claimantRelation === '') {
        $notice = 'Claimant relationship to the subject is required.';
    } elseif (!$logId) {
        $notice = 'Invalid generation log.';
    } else {
        // Re-check inside the handler: the log must still be the current,
        // non-superseded one and the request must still be ready_for_pickup.
        $requestId = (int) ($_POST['certificate_request_id'] ?? 0);
        $cur = cert_current_generation_log($pdo, $requestId);
        if (!$cur || (int) $cur['id'] !== $logId) {
            $notice = 'This generation log is no longer the active one (it may have been superseded). Please refresh and try again.';
        } elseif ($cur['print_status'] === 'released') {
            $notice = 'This certificate has already been released.';
        } else {
            // Verify the request is still in the expected state.
            $stmt = $pdo->prepare('SELECT status FROM certificate_requests WHERE id = ?');
            $stmt->execute([$cur['certificate_request_id']]);
            $reqStatus = $stmt->fetchColumn();
            if ($reqStatus !== 'ready_for_pickup') {
                $notice = 'The request is no longer ready for pickup (current status: ' . e((string) $reqStatus) . ').';
            } else {
                $result = log_certificate_released(
                    $pdo,
                    $logId,
                    (int) $cur['certificate_request_id'],
                    current_user()['id'],
                    $claimantName,
                    $claimantRelation,
                    $claimantNotes,
                    $idVerified
                );
                if ($result['ok']) {
                    log_activity($pdo, current_user()['id'], 'certificate_released',
                        'certificate_generation_log', $logId,
                        "Certificate released to {$claimantName} ({$claimantRelation})" . ($claimantNotes ? " — notes: {$claimantNotes}" : ''));
                    $notice = 'Certificate marked as released. Claimant: ' . e($claimantName) . ' (' . e($claimantRelation) . ').';
                } else {
                    $notice = $result['reason'];
                }
            }
        }
    }
}

// --- List the most recent generations (newest first, capped) --------------
$selfUrl = $basePath . '/admin/certificates/history.php';
$rfConfig = ['date_column' => 'gl.generated_at', 'search_columns' => ['cr.tracking_number', 'cr.sacrament_type', 'cr.requester_name'], 'placeholder' => 'Search tracking no., sacrament, requester...', 'ranges' => ['today', 'week', 'month', 'year']];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$sort = $_GET['sort'] ?? 'date_desc';
$allowedSorts = ['tracking', 'tracking_desc', 'sacrament', 'sacrament_desc', 'requester', 'requester_desc', 'date_desc', 'date_asc', 'status', 'status_desc', 'pickup', 'pickup_desc'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'date_desc';
$orderClauses = [
    'tracking' => 'cr.tracking_number ASC, gl.id ASC', 'tracking_desc' => 'cr.tracking_number DESC, gl.id DESC',
    'sacrament' => 'cr.sacrament_type ASC, gl.id ASC', 'sacrament_desc' => 'cr.sacrament_type DESC, gl.id DESC',
    'requester' => 'cr.requester_name ASC, gl.id ASC', 'requester_desc' => 'cr.requester_name DESC, gl.id DESC',
    'date_desc' => 'gl.generated_at DESC, gl.id DESC', 'date_asc' => 'gl.generated_at ASC, gl.id ASC',
    'status' => 'gl.print_status ASC, gl.id ASC', 'status_desc' => 'gl.print_status DESC, gl.id DESC',
    'pickup' => 'gl.pickup_date ASC, gl.id ASC', 'pickup_desc' => 'gl.pickup_date DESC, gl.id DESC',
];
$orderClause = $orderClauses[$sort];
$requestIdFilter = max(0, (int) ($_GET['request_id'] ?? 0));
$requestWhere = $requestIdFilter > 0 ? ' AND gl.certificate_request_id = ?' : '';
$requestParams = $requestIdFilter > 0 ? [$requestIdFilter] : [];

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM certificate_generation_log gl
     JOIN certificate_requests cr ON cr.id = gl.certificate_request_id
    WHERE 1=1 {$rf['where']}{$requestWhere}"
);
$countStmt->execute(array_merge($rf['params'], $requestParams));
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT gl.*, cr.tracking_number, cr.sacrament_type, cr.requester_name
     FROM certificate_generation_log gl
     JOIN certificate_requests cr ON cr.id = gl.certificate_request_id
    WHERE 1=1 {$rf['where']}{$requestWhere}
    ORDER BY {$orderClause}
     LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute(array_merge($rf['params'], $requestParams));
$logs = $stmt->fetchAll();

// Local helper: "ready_for_pickup" -> "Ready For Pickup"
function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Print / Pickup History</h1>

        <?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>

                <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <?php if (empty($logs)): ?>
            <p>No certificates have been generated yet.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'tracking') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'tracking', $sort)) ?>">Tracking No.<?= admin_sort_indicator('tracking', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'sacrament') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'sacrament', $sort)) ?>">Sacrament<?= admin_sort_indicator('sacrament', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'requester') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'requester', $sort)) ?>">Requester<?= admin_sort_indicator('requester', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'date', $sort)) ?>">Generated<?= admin_sort_indicator('date', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'status') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'status', $sort)) ?>">Status<?= admin_sort_indicator('status', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'pickup') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'pickup', $sort)) ?>">Pickup Date<?= admin_sort_indicator('pickup', $sort) ?></a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= e($log['tracking_number']) ?></td>
                        <td><?= e(humanize_label($log['sacrament_type'])) ?></td>
                        <td><?= e($log['requester_name']) ?></td>
                        <td><?= e(date('M j, Y g:i A', strtotime($log['generated_at']))) ?></td>
                        <td><span class="status-badge status-<?= e($log['print_status']) ?>"><?= e(humanize_label($log['print_status'])) ?></span></td>
                        <td><?= $log['pickup_date'] ? e(date('M j, Y', strtotime($log['pickup_date']))) : '&mdash;' ?></td>
                        <td>
                            <?php if ($log['print_status'] === 'generated'): ?>
                            <div class="cert-history-actions">
                                <a class="btn btn-small" href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= (int) $log['certificate_request_id'] ?>&print_only=1">Print Certificate</a>
                                <form autocomplete="off" method="post" data-confirm="Mark this certificate as printed?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="certificate_request_id" value="<?= (int) $log['certificate_request_id'] ?>">
                                    <button type="submit" name="mark_printed" value="1" class="btn btn-small">Mark as Printed</button>
                                </form>
                            </div>
                            <?php elseif ($log['print_status'] === 'printed' && empty($log['finalized_at'])): ?>
                            <div class="cert-history-actions">
                                <a class="btn btn-small" href="<?= $basePath ?>/admin/certificates/generate.php?preview=<?= (int) $log['certificate_request_id'] ?>&print_only=1">Print Certificate</a>
                                <form autocomplete="off" method="post" data-confirm="Finalize this certificate as signed and sealed? This closes out its processing.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="certificate_request_id" value="<?= (int) $log['certificate_request_id'] ?>">
                                    <button type="submit" name="finalize_certificate" value="1" class="btn btn-small">Finalize Signed and Sealed</button>
                                </form>
                            </div>
                            <?php elseif ($log['print_status'] !== 'released'): ?>
                            <form autocomplete="off" method="post" style="min-width:240px;">
                                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                <input type="hidden" name="log_id" value="<?= (int) $log['id'] ?>">
                                <input type="hidden" name="certificate_request_id" value="<?= (int) $log['certificate_request_id'] ?>">
                                <label>Claimant Name
                                    <input autocomplete="off" type="text" name="claimant_name" required placeholder="Full name of person picking up">
                                </label>
                                <label>Relationship to Subject
                                    <input autocomplete="off" type="text" name="claimant_relationship" required placeholder="e.g. Parent, Self, Guardian">
                                </label>
                                <label>Notes (optional)
                                    <textarea autocomplete="off" name="claimant_notes" rows="2" placeholder="Any pickup notes..."></textarea>
                                </label>
                                <label style="display:flex;align-items:center;gap:0.5rem;font-weight:normal;">
                                    <input autocomplete="off" type="checkbox" name="id_verified" value="1" required>
                                    I verified valid ID at the counter
                                </label>
                                <button type="submit" name="mark_released" value="1" class="btn btn-small">Confirm Release</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';