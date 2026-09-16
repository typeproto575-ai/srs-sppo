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
        $cur = cert_current_generation_log($pdo, (int) $_POST['certificate_request_id'] ?? 0);
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
$rfConfig = ['date_column' => 'gl.generated_at', 'search_columns' => ['cr.tracking_number', 'cr.sacrament_type', 'cr.requester_name'], 'placeholder' => 'Search tracking no., sacrament, requester&hellip;', 'ranges' => ['today', 'week', 'month', 'year']];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM certificate_generation_log gl
     JOIN certificate_requests cr ON cr.id = gl.certificate_request_id
     {$rf['where']}"
);
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT gl.*, cr.tracking_number, cr.sacrament_type, cr.requester_name
     FROM certificate_generation_log gl
     JOIN certificate_requests cr ON cr.id = gl.certificate_request_id
     {$rf['where']}
     ORDER BY gl.generated_at DESC
     LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
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
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Sacrament</th>
                    <th>Requester</th>
                    <th>Generated</th>
                    <th>Status</th>
                    <th>Pickup Date</th>
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
                            <?php if ($log['print_status'] !== 'released'): ?>
                            <form method="post" style="min-width:240px;">
                                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                <input type="hidden" name="log_id" value="<?= (int) $log['id'] ?>">
                                <input type="hidden" name="certificate_request_id" value="<?= (int) $log['certificate_request_id'] ?>">
                                <label>Claimant Name
                                    <input type="text" name="claimant_name" required placeholder="Full name of person picking up">
                                </label>
                                <label>Relationship to Subject
                                    <input type="text" name="claimant_relationship" required placeholder="e.g. Parent, Self, Guardian">
                                </label>
                                <label>Notes (optional)
                                    <textarea name="claimant_notes" rows="2" placeholder="Any pickup notes..."></textarea>
                                </label>
                                <label style="display:flex;align-items:center;gap:0.5rem;font-weight:normal;">
                                    <input type="checkbox" name="id_verified" value="1" required>
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
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';