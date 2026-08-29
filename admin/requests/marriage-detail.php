<?php
/**
 * =====================================================================
 * SPPO-IS MARRIAGE APPLICATION DETAIL (admin)
 * =====================================================================
 * Purpose: Drill-down view for ONE marriage sacrament application:
 *          groom/bride details, documents checklist, status updates,
 *          and banns/seminar tracking. Reached from the applications
 *          queue (?id=<application id>).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

// Require a valid application id in the query string, else back to the queue.
$applicationId = (int) ($_GET['id'] ?? 0);
if (!$applicationId) {
    redirect_to('/admin/requests/applications-queue.php'); // subfolder-safe redirect
}

$notice = null;
$errors = [];

// --- Load the marriage application ---
$stmt = $pdo->prepare(
    "SELECT sa.*, ma.*
     FROM sacrament_applications sa
     JOIN marriage_applications ma ON ma.application_id = sa.id
     WHERE sa.id = ? AND sa.sacrament_type = 'marriage'"
);
$stmt->execute([$applicationId]);
$app = $stmt->fetch();

if (!$app) {
    redirect_to('/admin/requests/applications-queue.php');
    exit;
}

// --- Handle clearance recording + approval ---
// CSRF: forged or stale POSTs are bounced back to this page - nothing
// is saved or approved.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    redirect_to('/admin/requests/marriage-detail.php?id=' . $applicationId);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (isset($_POST['save_clearance'])) {
        $preCanaCompleted     = isset($_POST['pre_cana_seminar_completed']) ? 1 : 0;
        $preCanaDate          = $_POST['pre_cana_completion_date'] ?: null;
        $interviewCompleted   = isset($_POST['canonical_interview_completed']) ? 1 : 0;
        $interviewDate        = $_POST['canonical_interview_date'] ?: null;
        $bannsDate1           = $_POST['banns_date_1'] ?: null;
        $bannsDate2           = $_POST['banns_date_2'] ?: null;
        $bannsDate3           = $_POST['banns_date_3'] ?: null;
        $priestName           = trim($_POST['assigned_priest_name'] ?? '');

        $stmt = $pdo->prepare(
            "UPDATE marriage_applications SET
                pre_cana_seminar_completed = ?,
                pre_cana_completion_date = ?,
                canonical_interview_completed = ?,
                canonical_interview_date = ?,
                banns_date_1 = ?,
                banns_date_2 = ?,
                banns_date_3 = ?,
                assigned_priest_name = ?
             WHERE application_id = ?"
        );
        $stmt->execute([
            $preCanaCompleted, $preCanaDate,
            $interviewCompleted, $interviewDate,
            $bannsDate1, $bannsDate2, $bannsDate3,
            $priestName, $applicationId,
        ]);

        log_activity($pdo, current_user()['id'], 'marriage_clearance_updated', 'marriage_applications', $applicationId,
            "Marriage clearance data updated for {$app['groom_full_name']} & {$app['bride_full_name']}");
        $notice = 'Clearance data saved.';
        // Reload app data
        $stmt = $pdo->prepare("SELECT sa.*, ma.* FROM sacrament_applications sa JOIN marriage_applications ma ON ma.application_id = sa.id WHERE sa.id = ?");
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
    } elseif (isset($_POST['decision'])) {
        $decision = $_POST['decision'];
        if (in_array($decision, ['approved', 'rejected'], true)) {
            $stmt = $pdo->prepare(
                "UPDATE sacrament_applications SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?"
            );
            $stmt->execute([$decision, current_user()['id'], $applicationId]);
            log_activity($pdo, current_user()['id'], 'marriage_application_' . $decision, 'sacrament_applications', $applicationId,
                "Marriage application {$decision} by secretary");
            $notice = 'Application marked as ' . $decision . '.';
            // Reload
            $stmt = $pdo->prepare("SELECT sa.*, ma.* FROM sacrament_applications sa JOIN marriage_applications ma ON ma.application_id = sa.id WHERE sa.id = ?");
            $stmt->execute([$applicationId]);
            $app = $stmt->fetch();
        }
    }
}

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}
// Page title for the browser tab (shared shell renders it).
$pageTitle = 'Marriage Application Detail';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Marriage Application — <?= e($app['groom_full_name']) ?> &amp; <?= e($app['bride_full_name']) ?></h1>
        <p>Tracking No.: <?= e($app['tracking_number']) ?> &middot; Status: <span class="status-badge status-<?= e($app['status']) ?>"><?= e(humanize_label($app['status'])) ?></span></p>

        <?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <h2>Groom's Information</h2>
        <table class="admin-table" style="max-width:600px;">
            <tbody>
                <tr><th>Full Name</th><td><?= e($app['groom_full_name']) ?></td></tr>
                <tr><th>Birthdate</th><td><?= e($app['groom_birthdate']) ?></td></tr>
                <tr><th>Birthplace</th><td><?= e($app['groom_birthplace']) ?></td></tr>
                <tr><th>Contact</th><td><?= e($app['groom_contact_number']) ?></td></tr>
                <tr><th>Email</th><td><?= e($app['groom_email']) ?></td></tr>
                <tr><th>Marital Status</th><td><?= e(humanize_label($app['groom_marital_status'])) ?></td></tr>
                <tr><th>Baptized Here</th><td><?= $app['groom_baptized_here'] ? 'Yes' : 'No' ?></td></tr>
                <tr><th>Baptismal Cert</th><td><?= e(humanize_label($app['groom_baptismal_cert_status'])) ?></td></tr>
                <tr><th>Confirmation Cert</th><td><?= e(humanize_label($app['groom_confirmation_cert_status'])) ?></td></tr>
            </tbody>
        </table>

        <h2>Bride's Information</h2>
        <table class="admin-table" style="max-width:600px;">
            <tbody>
                <tr><th>Full Name</th><td><?= e($app['bride_full_name']) ?></td></tr>
                <tr><th>Birthdate</th><td><?= e($app['bride_birthdate']) ?></td></tr>
                <tr><th>Birthplace</th><td><?= e($app['bride_birthplace']) ?></td></tr>
                <tr><th>Contact</th><td><?= e($app['bride_contact_number']) ?></td></tr>
                <tr><th>Email</th><td><?= e($app['bride_email']) ?></td></tr>
                <tr><th>Marital Status</th><td><?= e(humanize_label($app['bride_marital_status'])) ?></td></tr>
                <tr><th>Baptized Here</th><td><?= $app['bride_baptized_here'] ? 'Yes' : 'No' ?></td></tr>
                <tr><th>Baptismal Cert</th><td><?= e(humanize_label($app['bride_baptismal_cert_status'])) ?></td></tr>
                <tr><th>Confirmation Cert</th><td><?= e(humanize_label($app['bride_confirmation_cert_status'])) ?></td></tr>
            </tbody>
        </table>

        <h2>Wedding Details</h2>
        <table class="admin-table" style="max-width:600px;">
            <tbody>
                <tr><th>Preferred Date</th><td><?= e(date('F j, Y', strtotime($app['preferred_wedding_date']))) ?></td></tr>
                <tr><th>Date Submitted</th><td><?= e(date('F j, Y g:i A', strtotime($app['date_submitted']))) ?></td></tr>
                <?php if ($app['notes']): ?><tr><th>Notes</th><td><?= e($app['notes']) ?></td></tr><?php endif; ?>
            </tbody>
        </table>

        <h2>Canonical Clearance (recorded by secretary after priest confirmation)</h2>
        <form method="post" action="">
            <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
            <fieldset>
                <legend>Marriage Clearance</legend>
                <label class="checkbox-label">
                    <input type="checkbox" name="pre_cana_seminar_completed" value="1" <?= $app['pre_cana_seminar_completed'] ? 'checked' : '' ?>>
                    Pre-Cana / Marriage Seminar completed
                </label>
                <label>Pre-Cana Completion Date
                    <input type="date" name="pre_cana_completion_date" value="<?= e($app['pre_cana_completion_date'] ?? '') ?>">
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="canonical_interview_completed" value="1" <?= $app['canonical_interview_completed'] ? 'checked' : '' ?>>
                    Canonical (pastoral) interview completed
                </label>
                <label>Canonical Interview Date
                    <input type="date" name="canonical_interview_date" value="<?= e($app['canonical_interview_date'] ?? '') ?>">
                </label>
                <label>Banns Date 1
                    <input type="date" name="banns_date_1" value="<?= e($app['banns_date_1'] ?? '') ?>">
                </label>
                <label>Banns Date 2
                    <input type="date" name="banns_date_2" value="<?= e($app['banns_date_2'] ?? '') ?>">
                </label>
                <label>Banns Date 3
                    <input type="date" name="banns_date_3" value="<?= e($app['banns_date_3'] ?? '') ?>">
                </label>
                <label>Priest Who Confirmed Clearance (name, for records)
                    <input type="text" name="assigned_priest_name" value="<?= e($app['assigned_priest_name'] ?? '') ?>">
                </label>
            </fieldset>
            <button type="submit" name="save_clearance" value="1" class="btn">Save Clearance Data</button>
        </form>

        <h2>Approval</h2>
        <?php if ($app['status'] === 'ready_for_approval' || $app['status'] === 'submitted' || $app['status'] === 'documents_pending'): ?>
        <form method="post" class="inline-fields" style="margin-top:0.75rem;">
            <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
            <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
            <button type="submit" name="decision" value="approved" class="btn btn-small">Approve</button>
            <button type="submit" name="decision" value="rejected" class="btn btn-secondary btn-small">Reject</button>
        </form>
        <?php else: ?>
        <p class="field-hint">This application is currently <?= e(humanize_label($app['status'])) ?>. Only applications with status "Submitted", "Documents Pending", or "Ready for Approval" can be approved/rejected from here.</p>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';