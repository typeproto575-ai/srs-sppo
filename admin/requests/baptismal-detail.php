<?php
/**
 * =====================================================================
 * SRS-SPPO BAPTISMAL APPLICATION DETAIL (admin drill-down)
 * =====================================================================
 * Purpose: Shows full details of one baptismal sacrament application,
 *          including child info, parents, godparents, and status.
 *          Secretary can update the application status here.
 *
 * Features:
 *   - Displays child's full name, birthdate, birthplace
 *   - Shows father's name, mother's name, contact info
 *   - Lists godparents with type, name, and residence
 *   - Status update form with CSRF protection
 *   - Audit logging on status change
 *
 * Access: Secretary only (require_role('secretary'))
 * =====================================================================
 */

// Include required files: database, helpers, and auth guard
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page
$basePath = $basePath ?? base_path();

// Get the application ID from the query string
$applicationId = (int) ($_GET['id'] ?? 0);
if (!$applicationId) { redirect_to('/admin/requests/applications-queue.php'); }

$notice = null;

// Fetch the baptismal application with its base application data
$stmt = $pdo->prepare("SELECT sa.*, ba.* FROM sacrament_applications sa JOIN baptismal_applications ba ON ba.application_id = sa.id WHERE sa.id = ? AND sa.sacrament_type = 'baptismal'");
$stmt->execute([$applicationId]);
$app = $stmt->fetch();
if (!$app) { redirect_to('/admin/requests/applications-queue.php'); exit; }

// Fetch godparents for this application, ordered by type
$gpStmt = $pdo->prepare("SELECT godparent_name, godparent_residence, godparent_type FROM baptismal_application_godparents WHERE baptismal_application_id = ? ORDER BY godparent_type, id");
$gpStmt->execute([$app['id']]);
$godparents = $gpStmt->fetchAll();

// Handle status update form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && isset($_POST['update_status'])) {
    $newStatus = $_POST['new_status'] ?? '';
    $validStatuses = ['submitted', 'documents_pending', 'interview_scheduled', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'];
    if (in_array($newStatus, $validStatuses, true)) {
        $stmt = $pdo->prepare("UPDATE sacrament_applications SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?");
        $stmt->execute([$newStatus, current_user()['id'], $applicationId]);
        log_activity($pdo, current_user()['id'], 'sacrament_application_status_updated', 'sacrament_applications', $applicationId, "Status changed to {$newStatus}");
        $notice = 'Status updated.';
        // Refresh the application data after update
        $stmt = $pdo->prepare("SELECT sa.*, ba.* FROM sacrament_applications sa JOIN baptismal_applications ba ON ba.application_id = sa.id WHERE sa.id = ?");
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
    }
}

// Helper function to convert enum values to human-readable labels
function humanize_label(string $s): string { return ucwords(str_replace('_', ' ', $s)); }

// All valid status options for the dropdown
$statusOptions = ['submitted', 'documents_pending', 'interview_scheduled', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'];

// Include the shared admin header (nav, page title, etc.)
require_once __DIR__ . '/../../includes/admin-header.php';
?><p><a href="<?= $basePath ?>/admin/requests/applications-queue.php">&larr; Back to Queue</a></p>
<h1>Baptismal Application Detail</h1>
<?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>

<!-- Child Information Section -->
<h2>Child</h2>
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Full Name</th><td><?= e($app['child_full_name']) ?></td></tr>
<tr><th>Birthdate</th><td><?= e($app['child_birth_date'] ? date('F j, Y', strtotime($app['child_birth_date'])) : '—') ?></td></tr>
<tr><th>Birthplace</th><td><?= e($app['child_birthplace']) ?></td></tr>
</tbody></table>

<!-- Parents/Guardian Information Section -->
<h2>Parents</h2>
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Father's Name</th><td><?= e($app['father_full_name']) ?></td></tr>
<tr><th>Mother's Name</th><td><?= e($app['mother_guardian_name']) ?></td></tr>
<tr><th>Contact Number</th><td><?= e($app['contact_number']) ?></td></tr>
<tr><th>Email</th><td><?= e($app['email']) ?></td></tr>
</tbody></table>

<!-- Godparents Section (only shown if godparents exist) -->
<?php if (!empty($godparents)): ?>
<h2>Godparents</h2>
<table class="admin-table" style="max-width:600px;"><thead><tr><th>Type</th><th>Name</th><th>Residence</th></tr></thead><tbody>
<?php foreach ($godparents as $gp): ?>
<tr><td><?= e(ucfirst($gp['godparent_type'])) ?></td><td><?= e($gp['godparent_name']) ?></td><td><?= e($gp['godparent_residence'] ?: '—') ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<!-- Application Details Section -->
<h2>Application Details</h2>
<table class="admin-table" style="max-width:600px;"><tbody>
<tr><th>Tracking Number</th><td><?= e($app['tracking_number']) ?></td></tr>
<tr><th>Status</th><td><span class="status-badge status-<?= e($app['status']) ?>"><?= e(humanize_label($app['status'])) ?></span></td></tr>
<tr><th>Preferred Date</th><td><?= e($app['preferred_date'] ? date('F j, Y', strtotime($app['preferred_date'])) : '—') ?></td></tr>
<tr><th>Date Submitted</th><td><?= e(date('F j, Y g:i A', strtotime($app['date_submitted']))) ?></td></tr>
<?php if ($app['notes']): ?><tr><th>Notes</th><td><?= e($app['notes']) ?></td></tr><?php endif; ?>
</tbody></table>

<!-- Status Update Form (CSRF-protected) -->
<h2>Update Status</h2>
<form method="post" class="inline-fields">
<?= csrf_field() ?>
<input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
<select name="new_status">
<?php foreach ($statusOptions as $s): ?>
<option value="<?= e($s) ?>" <?= $app['status'] === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
<?php endforeach; ?>
</select>
<button type="submit" name="update_status" value="1" class="btn btn-small">Update</button>
</form>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>