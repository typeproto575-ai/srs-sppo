<?php
/**
 * =====================================================================
 * SRS-SPPO MARRIAGE APPLICATION DETAIL (admin)
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
            $approvableStatuses = ['submitted', 'documents_pending', 'ready_for_approval'];
            if (!in_array($app['status'], $approvableStatuses, true)) {
                $errors[] = 'This application is not in an approvable workflow state.';
            }

            // Payment gate: an online application still awaiting payment cannot
            // be advanced until the secretary records the payment by hand.
            if ($decision === 'approved' && !sacrament_application_payment_gate($app, 'approved')['ok']) {
                $errors[] = sacrament_application_payment_gate($app, 'approved')['reason'];
            }

            if ($decision === 'approved') {
                $gate = can_approve_sacrament_application($pdo, $applicationId, 'marriage');
                if (!$gate['ok']) {
                    $errors[] = 'Cannot approve until these requirements are complete: ' . implode('; ', $gate['missing']) . '.';
                    log_activity($pdo, current_user()['id'], 'sacrament_application_approval_blocked', 'sacrament_applications', $applicationId, 'Approval blocked; missing: ' . implode(' ', $gate['missing']));
                }
            }

            if (empty($errors)) {
                $stmt = $pdo->prepare(
                    "UPDATE sacrament_applications SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?"
                );
                $stmt->execute([$decision, current_user()['id'], $applicationId]);
                log_activity($pdo, current_user()['id'], 'marriage_application_' . $decision, 'sacrament_applications', $applicationId,
                    "Marriage application {$decision} by secretary");
                notify_sacrament_application_status($pdo, $decision, $row['sacrament_type'], $row['tracking_number']);
                $notice = 'Application marked as ' . $decision . '.';
                // Reload
                $stmt = $pdo->prepare("SELECT sa.*, ma.* FROM sacrament_applications sa JOIN marriage_applications ma ON ma.application_id = sa.id WHERE sa.id = ?");
                $stmt->execute([$applicationId]);
                $app = $stmt->fetch();
            }
        }
    }

    // Mark an online application's payment as received (by hand). Records only
    // WHO and WHEN -- never an amount. Only valid while awaiting payment and
    // not yet recorded. This unblocks the payment gate in the decision handler.
    if (isset($_POST['mark_paid'])) {
        if (($app['status'] ?? '') !== 'awaiting_payment') {
            $errors[] = "Only an application that is awaiting payment can be marked paid.";
        } elseif (!empty($app['paid_at'])) {
            $errors[] = 'Payment has already been recorded for this application.';
        } else {
            $up = $pdo->prepare("UPDATE sacrament_applications SET paid_at = NOW(), paid_by = ? WHERE id = ?");
            $up->execute([current_user()['id'], $applicationId]);
            log_activity($pdo, current_user()['id'], 'sacrament_application_payment_recorded', 'sacrament_applications', $applicationId, 'Payment recorded by hand (no amount stored).');
            $notice = 'Payment recorded. The application can now move forward.';
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
$workflowEntityType = 'sacrament_application';
$workflowEntityId = $applicationId;
$workflowSacramentType = 'marriage';
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
require __DIR__ . '/../../includes/workflow-checklist-panel.php';
?>
        <p class="field-hint"><strong>Submission channel:</strong> <?= e(ucfirst($app['submission_channel'] ?? 'onsite')) ?><?= ($app['submission_channel'] ?? 'onsite') === 'online' ? ' — public pre-application; not yet an official parish record' : '' ?></p>
    <h1>Marriage Application — <?= e($app['groom_full_name']) ?> &amp; <?= e($app['bride_full_name']) ?></h1>
        <p>Tracking No.: <?= e($app['tracking_number']) ?> &middot; Status: <span class="status-badge status-<?= e($app['status']) ?>"><?= e(humanize_label($app['status'])) ?></span></p>

        <?php if ($notice): ?><div class="alert alert-success"><p><?= e($notice) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <h2>Groom's Information</h2>
        <div class="table-scroll">
        <table class="admin-table" style="max-width:600px;">
            <tbody>
                <tr><th>Full Name</th><td><?= e($app['groom_full_name']) ?></td></tr>
                <tr><th>Birthdate</th><td><?= e($app['groom_birthdate']) ?></td></tr>
                <tr><th>Birthplace</th><td><?= e($app['groom_birthplace']) ?></td></tr>
                <tr><th>Address / Length of stay</th><td><?= e(($app['groom_current_address'] ?? '—') . ' / ' . ($app['groom_address_length'] ?? '—')) ?></td></tr>
                <tr><th>Contact</th><td><?= e($app['groom_contact_number']) ?></td></tr>
                <tr><th>Email</th><td><?= e($app['groom_email']) ?></td></tr>
                <tr><th>Religion / Occupation</th><td><?= e(($app['groom_religion'] ?? '—') . ' / ' . ($app['groom_occupation'] ?? '—')) ?></td></tr>
                <tr><th>Parents</th><td><?= e(($app['groom_father_name'] ?? '—') . ' / ' . ($app['groom_mother_maiden_name'] ?? '—')) ?></td></tr>
                <tr><th>Marital Status</th><td><?= e(humanize_label($app['groom_marital_status'])) ?></td></tr>
                <tr><th>Baptized Here</th><td><?= $app['groom_baptized_here'] ? 'Yes' : 'No' ?></td></tr>
                <tr><th>Confirmed / Previous marriage</th><td><?= !empty($app['groom_confirmed']) ? 'Yes' : 'No' ?> / <?= e(humanize_label($app['groom_previous_marriage'] ?? 'no')) ?></td></tr>
                <tr><th>Baptismal Cert</th><td><?= e(humanize_label($app['groom_baptismal_cert_status'])) ?></td></tr>
                <tr><th>Confirmation Cert</th><td><?= e(humanize_label($app['groom_confirmation_cert_status'])) ?></td></tr>
            </tbody>
        </table>
        </div>

        <h2>Bride's Information</h2>
        <div class="table-scroll">
        <table class="admin-table" style="max-width:600px;">
            <tbody>
                <tr><th>Full Name</th><td><?= e($app['bride_full_name']) ?></td></tr>
                <tr><th>Birthdate</th><td><?= e($app['bride_birthdate']) ?></td></tr>
                <tr><th>Birthplace</th><td><?= e($app['bride_birthplace']) ?></td></tr>
                <tr><th>Address / Length of stay</th><td><?= e(($app['bride_current_address'] ?? '—') . ' / ' . ($app['bride_address_length'] ?? '—')) ?></td></tr>
                <tr><th>Contact</th><td><?= e($app['bride_contact_number']) ?></td></tr>
                <tr><th>Email</th><td><?= e($app['bride_email']) ?></td></tr>
                <tr><th>Religion / Occupation</th><td><?= e(($app['bride_religion'] ?? '—') . ' / ' . ($app['bride_occupation'] ?? '—')) ?></td></tr>
                <tr><th>Parents</th><td><?= e(($app['bride_father_name'] ?? '—') . ' / ' . ($app['bride_mother_maiden_name'] ?? '—')) ?></td></tr>
                <tr><th>Marital Status</th><td><?= e(humanize_label($app['bride_marital_status'])) ?></td></tr>
                <tr><th>Baptized Here</th><td><?= $app['bride_baptized_here'] ? 'Yes' : 'No' ?></td></tr>
                <tr><th>Confirmed / Previous marriage</th><td><?= !empty($app['bride_confirmed']) ? 'Yes' : 'No' ?> / <?= e(humanize_label($app['bride_previous_marriage'] ?? 'no')) ?></td></tr>
                <tr><th>Baptismal Cert</th><td><?= e(humanize_label($app['bride_baptismal_cert_status'])) ?></td></tr>
                <tr><th>Confirmation Cert</th><td><?= e(humanize_label($app['bride_confirmation_cert_status'])) ?></td></tr>
            </tbody>
        </table>
        </div>

        <h2>Wedding Details</h2>
        <div class="table-scroll">
        <table class="admin-table" style="max-width:600px;">
            <tbody>
                <tr><th>Preferred Date</th><td><?= e(date('F j, Y', strtotime($app['preferred_wedding_date']))) ?></td></tr>
                <tr><th>Time / Officiating Priest</th><td><?= e(($app['preferred_wedding_time'] ?? '—') . ' / ' . ($app['assigned_priest_name'] ?? '—')) ?></td></tr>
                <tr><th>Civil marriage</th><td><?= !empty($app['civilly_married_to_each_other']) ? 'Yes — ' . e(($app['civil_marriage_date'] ?? 'date not recorded') . ' / ' . ($app['civil_marriage_place'] ?? 'place not recorded')) : 'No' ?></td></tr>
                <tr><th>Documentation</th><td>Groom CENOMAR: <?= e(humanize_label($app['groom_cenomar_status'] ?? 'pending')) ?>; Bride CENOMAR: <?= e(humanize_label($app['bride_cenomar_status'] ?? 'pending')) ?>; License: <?= e(humanize_label($app['marriage_license_status'] ?? 'pending')) ?></td></tr>
                <tr><th>Date Submitted</th><td><?= e(date('F j, Y g:i A', strtotime($app['date_submitted']))) ?></td></tr>
                <?php if ($app['notes']): ?><tr><th>Notes</th><td><?= e($app['notes']) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <h2>Canonical Clearance (recorded by secretary after priest confirmation)</h2>
        <form autocomplete="off" method="post" action="">
            <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
            <fieldset>
                <legend>Marriage Clearance</legend>
                <label class="checkbox-label">
                    <input autocomplete="off" type="checkbox" name="pre_cana_seminar_completed" value="1" <?= $app['pre_cana_seminar_completed'] ? 'checked' : '' ?>>
                    Pre-Cana / Marriage Seminar completed
                </label>
                <label>Pre-Cana Completion Date
                    <input autocomplete="off" type="date" name="pre_cana_completion_date" value="<?= e($app['pre_cana_completion_date'] ?? '') ?>">
                </label>
                <label class="checkbox-label">
                    <input autocomplete="off" type="checkbox" name="canonical_interview_completed" value="1" <?= $app['canonical_interview_completed'] ? 'checked' : '' ?>>
                    Canonical (pastoral) interview completed
                </label>
                <label>Canonical Interview Date
                    <input autocomplete="off" type="date" name="canonical_interview_date" value="<?= e($app['canonical_interview_date'] ?? '') ?>">
                </label>
                <label>Banns Date 1
                    <input autocomplete="off" type="date" name="banns_date_1" value="<?= e($app['banns_date_1'] ?? '') ?>">
                </label>
                <label>Banns Date 2
                    <input autocomplete="off" type="date" name="banns_date_2" value="<?= e($app['banns_date_2'] ?? '') ?>">
                </label>
                <label>Banns Date 3
                    <input autocomplete="off" type="date" name="banns_date_3" value="<?= e($app['banns_date_3'] ?? '') ?>">
                </label>
                <label>Priest Who Confirmed Clearance (name, for records)
                    <input autocomplete="off" type="text" name="assigned_priest_name" value="<?= e($app['assigned_priest_name'] ?? '') ?>">
                </label>
            </fieldset>
            <button type="submit" name="save_clearance" value="1" class="btn">Save Clearance Data</button>
        </form>

        <h2>Approval</h2>
        <?php if (($app['status'] ?? '') === 'awaiting_payment' && empty($app['paid_at'])): ?>
        <form autocomplete="off" method="post" class="inline-fields" style="margin-top:0.75rem;" data-confirm="Record that payment for this application has been received (by hand)? No amount is stored.">
            <?= csrf_field() ?>
            <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
            <button type="submit" name="mark_paid" value="1" class="btn btn-small" data-confirm="Record that payment for this application has been received (by hand)? No amount is stored.">Mark as Paid</button>
            <span class="field-hint">Payment is collected outside the system. This records only that it was received, and by whom.</span>
        </form>
        <?php elseif (!empty($app['paid_at'])): ?>
        <p class="field-hint" style="margin-top:0.75rem;">Payment recorded on <?= e(date('F j, Y g:i A', strtotime($app['paid_at']))) ?> (no amount stored).</p>
        <?php endif; ?>
        <?php if ($app['status'] === 'ready_for_approval' || $app['status'] === 'submitted' || $app['status'] === 'documents_pending'): ?>
        <form autocomplete="off" method="post" class="inline-fields" style="margin-top:0.75rem;" data-confirm="Update this application's status?">
            <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
            <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
            <button type="submit" name="decision" value="approved" class="btn btn-small" data-confirm="Approve this marriage application?">Approve</button>
            <button type="submit" name="decision" value="rejected" class="btn btn-secondary btn-small" data-confirm="Reject this marriage application?">Reject</button>
        </form>
        <?php else: ?>
        <p class="field-hint">This application is currently <?= e(humanize_label($app['status'])) ?>. Only applications with status "Submitted", "Documents Pending", or "Ready for Approval" can be approved/rejected from here.</p>
        <?php endif; ?>
<!-- After the wedding happens: encode it into the register (pre-filled
     from this application; saving also marks the application completed). -->
<?php if (in_array($app['status'], ['approved', 'scheduled'], true)): ?>
<p style="margin-top:0.75rem;">
    <a class="btn btn-small" href="<?= $basePath ?>/admin/records/marriage.php?from_application=<?= (int) $app['id'] ?>">
        Encode into marriage register (pre-filled)
    </a>
    <span class="field-hint">Opens the encode form with the couple's data; saving completes the application. Verify the wife's maiden name.</span>
</p>
<?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
