<?php
/**
 * =====================================================================
 * SRS-SPPO CERTIFICATE CORRECTION PROCESSING (admin)
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Process Correction Request';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('Invalid request ID.');
}

// Fetch request
$stmt = $pdo->prepare('SELECT * FROM correction_requests WHERE id = ?');
$stmt->execute([$id]);
$req = $stmt->fetch();

if (!$req) {
    die('Request not found.');
}

// Handle status updates
$csrfError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $csrfError = true;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfError) {
    $action = $_POST['action'] ?? '';
    $status = '';
    $note = $_POST['admin_note'] ?? '';

    if ($action === 'approve') {
        $status = 'completed';
    } elseif ($action === 'reject') {
        $status = 'rejected';
    } elseif ($action === 'review') {
        $status = 'processing';
    }

    if ($status) {
        $stmt = $pdo->prepare('UPDATE correction_requests SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $note, $id]);
        header("Location: correction-list.php");
        exit;
    }
}

function humanize_label(string $s): string {
    return ucwords(str_replace('_', ' ', $s));
}

require_once __DIR__ . '/../../includes/admin-header.php';
?>

<div class="admin-page-header">
    <a href="<?= $basePath ?>/admin/corrections/correction-list.php" class="btn btn-small btn-outline">&larr; Back to Queue</a>
    <h1>Process Correction Request</h1>
</div>

<div class="detail-grid">
    <div class="detail-card">
        <h3>Request Details</h3>
        <table class="detail-table">
            <tr>
                <th>Tracking No:</th>
                <td><strong><?= e($req['tracking_number']) ?></strong></td>
            </tr>
            <tr>
                <th>Certificate Type:</th>
                <td>Certificate #<?= (int) $req['certificate_id'] ?></td>
            </tr>
            <tr>
                <th>Requester:</th>
                <td><?= e($req['requester_name']) ?> (<?= e($req['requester_email']) ?>)</td>
            </tr>
            <tr>
                <th>Date Submitted:</th>
                <td><?= e(date('M j, Y', strtotime($req['created_at']))) ?></td>
            </tr>
            <tr>
                <th>Current Status:</th>
                <td><span class="status-badge status-<?= e($req['status']) ?>"><?= e(humanize_label($req['status'])) ?></span></td>
            </tr>
        </table>
    </div>

    <div class="detail-card">
        <h3>Correction Details</h3>
        <div class="correction-box">
            <p><strong>Requested Changes:</strong></p>
            <p><?= nl2br(e($req['requested_changes'])) ?></p>
        </div>
        <div class="correction-box">
            <p><strong>Supporting Evidence/Notes:</strong></p>
            <p><?= e($req['document_path'] ?? 'No supporting document provided.') ?></p>
        </div>
    </div>
</div>

<div class="detail-card" style="margin-top: 20px;">
    <h3>Admin Action</h3>
    <?php if ($csrfError): ?><div class="alert alert-error">Security token expired or invalid - please reload the page and try again.</div><?php endif; ?>
    <form method="POST" data-confirm="Save this update?">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Admin Review Note:</label>
            <textarea name="admin_note" class="form-control" rows="4" placeholder="Enter outcome, record update confirmation, or reason for rejection..."><?= e($_POST['admin_note'] ?? $req['admin_note'] ?? '') ?></textarea>
        </div>
        <div class="action-buttons">
            <button type="submit" name="action" value="review" class="btn btn-small" data-confirm="Mark this correction request as Processing?">Mark as Processing</button>
            <button type="submit" name="action" value="approve" class="btn btn-success btn-small" data-confirm="Mark this correction request as Completed? Make sure the record has already been corrected.">Mark as Completed</button>
            <button type="submit" name="action" value="reject" class="btn btn-danger btn-small" data-confirm="Reject this correction request?">Reject Request</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
