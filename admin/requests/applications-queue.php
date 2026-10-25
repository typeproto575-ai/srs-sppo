<?php
/**
 * =====================================================================
 * SRS-SPPO SACRAMENT APPLICATIONS QUEUE (admin)
 * =====================================================================
 * Purpose: The secretary's worklist of sacrament applications (public
 *          walk-in and online submissions). Each row can be moved
 *          through its sacrament's workflow: Marriage alone uses the
 *          full pipeline (submitted → documents → interview → banns →
 *          seminar → approval → scheduled → completed / rejected);
 *          Baptismal, Confirmation and First Communion skip the
 *          interview and banns stages.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Sacrament Applications Queue';

// Every status that exists on sacrament_applications (used when no
// sacrament filter is chosen).
$allStatuses = [
    'submitted', 'awaiting_payment', 'documents_pending', 'interview_scheduled', 'banns_in_progress',
    'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected',
];

// Per-sacrament workflows: the status dropdown for each application only
// offers the stages that actually apply to its sacrament. Marriage alone
// uses the interview + banns (Tawag sa Kasal) stages; Baptismal,
// Confirmation and First Communion go through their seminar then approval.
$statusFlow = [
    'baptismal'       => ['submitted', 'awaiting_payment', 'documents_pending', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'],
    'confirmation'    => ['submitted', 'awaiting_payment', 'documents_pending', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'],
    'first_communion' => ['submitted', 'awaiting_payment', 'documents_pending', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'],
    'marriage'        => $allStatuses,
];

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

// --- Handle status update ------------------------------------------------
// CSRF: forged or stale POSTs are dropped silently (fail closed) - the
// queue simply re-renders with no status change applied.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    redirect_to('/admin/requests/applications-queue.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && csrf_verify()) {
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $newStatus     = $_POST['new_status'] ?? '';
    $appType       = '';

    // Validate the requested status against the workflow of THIS
    // application's sacrament type (never another type's stages).
    $allowedStatus = false;
    if ($applicationId > 0) {
        $typeStmt = $pdo->prepare('SELECT sacrament_type FROM sacrament_applications WHERE id = ?');
        $typeStmt->execute([$applicationId]);
        $appType = (string) $typeStmt->fetchColumn();
        $allowedStatus = in_array($newStatus, $statusFlow[$appType] ?? [], true);
    }

    if ($applicationId && $allowedStatus) {
        // Re-fetch the CURRENT status: never trust the value rendered earlier
        // (it may have changed since page load).
        $curStmt = $pdo->prepare('SELECT status FROM sacrament_applications WHERE id = ?');
        $curStmt->execute([$applicationId]);
        $curStatus = (string) $curStmt->fetchColumn();
        $proceed = true;
        if ($curStatus === '') {
            log_activity($pdo, current_user()['id'], 'sacrament_application_status_rejected', 'sacrament_applications', $applicationId, 'Status change refused: application not found.');
            $proceed = false;
        } elseif (!sacrament_application_transition_valid($curStatus, $newStatus, $appType)) {
            log_activity($pdo, current_user()['id'], 'sacrament_application_status_rejected', 'sacrament_applications', $applicationId, "Status change refused: {$curStatus} -> {$newStatus} for {$appType}.");
            $proceed = false;
        } else {
            // Payment gate: an online application still awaiting payment cannot
            // advance until the secretary records the payment by hand.
            $gateRowStmt = $pdo->prepare('SELECT submission_channel, paid_at FROM sacrament_applications WHERE id = ?');
            $gateRowStmt->execute([$applicationId]);
            $gateRow = $gateRowStmt->fetch() ?: [];
            $payGate = sacrament_application_payment_gate(
                ['status' => $curStatus, 'submission_channel' => $gateRow['submission_channel'] ?? 'onsite', 'paid_at' => $gateRow['paid_at'] ?? null],
                $newStatus
            );
            if (!$payGate['ok']) {
                log_activity($pdo, current_user()['id'], 'sacrament_application_status_rejected', 'sacrament_applications', $applicationId, "Status change blocked by payment gate: {$curStatus} -> {$newStatus}.");
                $proceed = false;
            }
        }
        if ($proceed && $newStatus === 'approved') {
            // Approval is the hard gate: every prerequisite must be met.
            $gate = can_approve_sacrament_application($pdo, $applicationId, $appType);
            if (!$gate['ok']) {
                log_activity($pdo, current_user()['id'], 'sacrament_application_approval_blocked', 'sacrament_applications', $applicationId, 'Approval blocked; missing: ' . implode(' ', $gate['missing']));
                $proceed = false;
            }
        }
        if ($proceed) {
            $stmt = $pdo->prepare(
                "UPDATE sacrament_applications SET status = ?, date_processed = NOW(), processed_by = ? WHERE id = ?"
            );
            $stmt->execute([$newStatus, current_user()['id'], $applicationId]);

            log_activity($pdo, current_user()['id'], 'sacrament_application_status_updated', 'sacrament_applications', $applicationId,
                "Status changed to {$newStatus}");
        }
    }
    redirect_to('/admin/requests/applications-queue.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}

// --- Mark payment received (by hand) for an awaiting-payment application ----
// Records only WHO and WHEN -- never an amount. Valid only while the online
// application is awaiting payment and not yet recorded. This unblocks the
// payment gate in the status-update flow above.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid']) && ctype_digit((string) ($_POST['application_id'] ?? '')) && csrf_verify()) {
    $applicationId = (int) $_POST['application_id'];
    $rowStmt = $pdo->prepare('SELECT status, paid_at FROM sacrament_applications WHERE id = ?');
    $rowStmt->execute([$applicationId]);
    $row = $rowStmt->fetch();
    if ($row && $row['status'] === 'awaiting_payment' && empty($row['paid_at'])) {
        $up = $pdo->prepare('UPDATE sacrament_applications SET paid_at = NOW(), paid_by = ? WHERE id = ?');
        $up->execute([current_user()['id'], $applicationId]);
        log_activity($pdo, current_user()['id'], 'sacrament_application_payment_recorded', 'sacrament_applications', $applicationId, 'Payment recorded by hand (no amount stored).');
    }
    redirect_to('/admin/requests/applications-queue.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}

// --- Filters ---------------------------------------------------------------
$filterStatus    = $_GET['status'] ?? '';
$filterSacrament = $_GET['sacrament'] ?? '';
$filterChannel = $_GET['channel'] ?? '';
$sacramentTypes  = ['baptismal', 'confirmation', 'first_communion', 'marriage'];

$where  = [];
$params = [];
if ($filterStatus !== '' && in_array($filterStatus, $allStatuses, true)) {
    $where[] = 'sa.status = ?';
    $params[] = $filterStatus;
}
if ($filterSacrament !== '' && in_array($filterSacrament, $sacramentTypes, true)) {
    $where[] = 'sa.sacrament_type = ?';
    $params[] = $filterSacrament;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$selfUrl = $basePath . '/admin/requests/applications-queue.php';
$rfConfig = [
    'date_column'    => 'sa.date_submitted',
    'search_columns' => ['sa.tracking_number', 'sa.sacrament_type', 'ba.child_full_name', 'ca.applicant_full_name', 'fc.applicant_full_name', 'ma.groom_full_name', 'ma.bride_full_name'],
    'placeholder'    => 'Search tracking no., sacrament, or applicant name...',
    'ranges'         => ['today', 'week', 'month', 'year'],
    'filters'        => [
        'status'    => ['label' => 'Status',
            'options' => [
                'submitted'          => 'Submitted',
                'awaiting_payment'   => 'Awaiting payment',
                'requirements_pending' => 'Requirements pending',
                'requirements_incomplete' => 'Requirements incomplete',
                'awaiting_onsite_verification' => 'Awaiting onsite verification',
                'documents_pending' => 'Documents pending',
                'interview_scheduled' => 'Interview scheduled',
                'banns_in_progress'  => 'Banns in progress',
                'seminar_pending'    => 'Seminar pending',
                'ready_for_approval' => 'Ready for approval',
                'approved'           => 'Approved',
                'scheduled'          => 'Scheduled',
                'completed'          => 'Completed',
                'rejected'           => 'Rejected',
                'cancelled'          => 'Cancelled',
            ]],
        'channel'   => ['label' => 'Channel', 'options' => ['online' => 'Online', 'onsite' => 'Onsite']],
        'sacrament' => ['label' => 'Sacrament',
            'options' => [
                'baptismal'      => 'Baptismal',
                'confirmation'   => 'Confirmation',
                'first_communion'=> 'First Communion',
                'marriage'       => 'Marriage',
            ]],
    ],
];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);
$sort = $_GET['sort'] ?? 'date_desc';
$allowedSorts = ['tracking', 'tracking_desc', 'sacrament', 'sacrament_desc', 'channel', 'channel_desc', 'date_desc', 'date_asc', 'status', 'status_desc'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'date_desc';
$orderClauses = [
    'tracking' => 'sa.tracking_number ASC, sa.id ASC', 'tracking_desc' => 'sa.tracking_number DESC, sa.id DESC',
    'sacrament' => 'sa.sacrament_type ASC, sa.id ASC', 'sacrament_desc' => 'sa.sacrament_type DESC, sa.id DESC',
    'channel' => 'sa.submission_channel ASC, sa.id ASC', 'channel_desc' => 'sa.submission_channel DESC, sa.id DESC',
    'date_desc' => 'sa.date_submitted DESC, sa.id DESC', 'date_asc' => 'sa.date_submitted ASC, sa.id ASC',
    'status' => 'sa.status ASC, sa.id ASC', 'status_desc' => 'sa.status DESC, sa.id DESC',
];
$orderClause = $orderClauses[$sort];

$allClauses = [];
$allParams = [];

if ($rf['where'] !== '') {
    $allClauses[] = ltrim($rf['where'], ' AND');
    $allParams = array_merge($allParams, $rf['params']);
}

if ($where !== []) {
    $allClauses[] = '(' . implode(' AND ', $where) . ')';
    $allParams = array_merge($allParams, $params);
}

$combinedWhere = $allClauses === [] ? '' : ' WHERE ' . implode(' AND ', $allClauses);

// Applicant names live in per-sacrament child tables (fetched per-row
// below), so search JOINs all four: each child has UNIQUE(application_id),
// i.e. at most one row per parent — the JOINs cannot duplicate rows or
// inflate the count. SELECT sa.* (never *) so child `id` columns can't
// collide with $app['id'] in the fetched assoc array.
$queueJoins = ' FROM sacrament_applications sa'
    . ' LEFT JOIN baptismal_applications ba ON ba.application_id = sa.id'
    . ' LEFT JOIN confirmation_applications ca ON ca.application_id = sa.id'
    . ' LEFT JOIN first_communion_applications fc ON fc.application_id = sa.id'
    . ' LEFT JOIN marriage_applications ma ON ma.application_id = sa.id';
$countStmt = $pdo->prepare("SELECT COUNT(*) {$queueJoins}{$combinedWhere}");
$countStmt->execute($allParams);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT sa.* {$queueJoins}{$combinedWhere} ORDER BY {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
$stmt->execute($allParams);
$applications = $stmt->fetchAll();

// Fetch sacrament-specific applicant name + flags for each row.
foreach ($applications as &$app) {
    $app['subject_name']  = null;
    $app['flagged']       = false;
    $app['flag_reason']   = [];
    $app['id_view_urls']  = []; // [label => url]
    $app['age_status']    = null;
    $app['record_status'] = null;

    if ($app['sacrament_type'] === 'baptismal') {
        $s = $pdo->prepare("SELECT child_full_name FROM baptismal_applications WHERE application_id = ?");
        $s->execute([$app['id']]);
        $row = $s->fetch();
        if ($row) {
            $app['subject_name'] = $row['child_full_name'];
        }
    } elseif ($app['sacrament_type'] === 'confirmation') {
        $s = $pdo->prepare("SELECT applicant_full_name, applicant_birthdate, age_eligibility_flag, baptismal_record_id, baptismal_cert_status FROM confirmation_applications WHERE application_id = ?");
        $s->execute([$app['id']]);
        $row = $s->fetch();
        if ($row) {
            $app['subject_name'] = $row['applicant_full_name'];
            $app['age_status'] = $row['applicant_birthdate'] ? sacrament_age_status($pdo, 'confirmation', calculate_age($row['applicant_birthdate'])) : 'Birthdate not recorded';
            if ($app['age_status'] !== 'Age-eligible') { $app['flagged'] = true; $app['flag_reason'][] = strtolower($app['age_status']); }
            $app['record_status'] = $row['baptismal_record_id'] ? 'Baptismal record linked' : 'No baptismal record linked yet';
            if ($row['baptismal_cert_status'] === 'pending') { $app['flag_reason'][] = 'baptismal cert not yet submitted'; }
        }
    } elseif ($app['sacrament_type'] === 'first_communion') {
        $s = $pdo->prepare("SELECT applicant_full_name, applicant_birthdate, age_eligibility_flag, baptismal_record_id, baptismal_cert_status FROM first_communion_applications WHERE application_id = ?");
        $s->execute([$app['id']]);
        $row = $s->fetch();
        if ($row) {
            $app['subject_name'] = $row['applicant_full_name'];
            $app['age_status'] = $row['applicant_birthdate'] ? sacrament_age_status($pdo, 'first_communion', calculate_age($row['applicant_birthdate'])) : 'Birthdate not recorded';
            if ($app['age_status'] !== 'Age-eligible') { $app['flagged'] = true; $app['flag_reason'][] = strtolower($app['age_status']); }
            $app['record_status'] = $row['baptismal_record_id'] ? 'Baptismal record linked' : 'No baptismal record linked yet';
            if ($row['baptismal_cert_status'] === 'pending') { $app['flag_reason'][] = 'baptismal cert not yet submitted'; }
        }
    } elseif ($app['sacrament_type'] === 'marriage') {
        $s = $pdo->prepare(
            "SELECT groom_full_name, bride_full_name, groom_baptismal_cert_status, bride_baptismal_cert_status,
                    groom_confirmation_cert_status, bride_confirmation_cert_status, preferred_wedding_date
             FROM marriage_applications WHERE application_id = ?"
        );
        $s->execute([$app['id']]);
        $row = $s->fetch();
        if ($row) {
            $app['subject_name'] = $row['groom_full_name'] . ' & ' . $row['bride_full_name'];
            $app['wedding_date'] = $row['preferred_wedding_date'];
            foreach (['groom_baptismal_cert_status', 'bride_baptismal_cert_status', 'groom_confirmation_cert_status', 'bride_confirmation_cert_status'] as $col) {
                if ($row[$col] === 'pending') {
                    $app['flag_reason'][] = str_replace('_', ' ', $col) . ' pending';
                }
            }
            if (!empty($app['flag_reason'])) $app['flagged'] = true;
        }
    }
}
unset($app);
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <header class="admin-queue-heading">
            <div>
                <p class="admin-queue-kicker">Requests</p>
                <h1>Sacrament applications</h1>
                <p>Review applications, check flagged details, and update their preparation status.</p>
            </div>
            <div class="admin-queue-total" aria-label="<?= (int) $totalRows ?> applications match the current filters">
                <strong><?= (int) $totalRows ?></strong>
                <span><?= $totalRows === 1 ? 'application' : 'applications' ?></span>
            </div>
        </header>

                <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <?php if (empty($applications)): ?>
            <p class="admin-queue-empty">No applications match your search or filters. Try broadening your search.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'tracking') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'tracking', $sort)) ?>">Tracking No.<?= admin_sort_indicator('tracking', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'sacrament') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'sacrament', $sort)) ?>">Sacrament<?= admin_sort_indicator('sacrament', $sort) ?></a></th>
                    <th>Applicant(s)</th>
                    <th>Channel</th>
                    <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'date', $sort)) ?>">Submitted<?= admin_sort_indicator('date', $sort) ?></a></th>
                    <th>Record link</th>
                    <th>Flags</th>
                    <th>Actions</th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'status') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'status', $sort)) ?>">Status<?= admin_sort_indicator('status', $sort) ?></a></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                    <tr class="<?= $app['flagged'] ? 'row-flagged' : '' ?>">
                        <td><?= e($app['tracking_number']) ?></td>
                        <td><?= e(humanize_label($app['sacrament_type'])) ?></td>
                        <td><?= e($app['subject_name'] ?? '—') ?></td>
                        <td><?= e(ucfirst($app['submission_channel'] ?? 'onsite')) ?></td>
                        <td><?= e(date('M j, Y', strtotime($app['date_submitted']))) ?></td>
                        <td>
                            <?php if (($app['record_status'] ?? '') === 'Baptismal record linked'): ?>
                                <span title="Baptismal record linked to this application">Linked</span>
                            <?php elseif (!empty($app['record_status'])): ?>
                                <span title="Baptismal record not yet linked to this application">Not linked</span>
                            <?php else: ?>
                                Not applicable
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($app['flagged']): ?>
                                <span class="flag-badge" title="<?= e(implode('; ', $app['flag_reason'])) ?>">&#9873; For review</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            
                            <?php
                            $detailPages = [
                                'baptismal'       => '/admin/requests/baptismal-detail.php',
                                'confirmation'    => '/admin/requests/confirmation-detail.php',
                                'first_communion' => '/admin/requests/first-communion-detail.php',
                                'marriage'        => '/admin/requests/marriage-detail.php',
                            ];
                            if (isset($detailPages[$app['sacrament_type']])): ?>
                                <a href="<?= $basePath ?><?= $detailPages[$app['sacrament_type']] ?>?id=<?= (int) $app['id'] ?>" class="btn btn-secondary btn-small">Detail</a>
                            <?php endif; ?>
                            <?php if (($app['status'] ?? '') === 'awaiting_payment' && empty($app['paid_at'])): ?>
                                <form autocomplete="off" method="post" class="inline-fields" data-confirm="Record that payment for this application has been received (by hand)? No amount is stored.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                    <button type="submit" name="mark_paid" value="1" class="btn btn-small" data-confirm="Record that payment for this application has been received (by hand)? No amount is stored.">Mark as Paid</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            <form autocomplete="off" method="post" class="inline-fields">
                                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <select name="new_status" class="status-select status-<?= e($app['status']) ?>">
                                    <?php
                                    // Only this sacrament's stages are offered; the current
                                    // status is always kept as a choice (legacy rows may hold
                                    // a stage outside the modern workflow).
                                    $rowStatuses = $statusFlow[$app['sacrament_type']] ?? $allStatuses;
                                    if (!in_array($app['status'], $rowStatuses, true)) {
                                        $rowStatuses[] = $app['status'];
                                    }
                                    ?>
                                    <?php foreach ($rowStatuses as $s): ?>
                                        <option value="<?= e($s) ?>" <?= $app['status'] === $s ? 'selected' : '' ?>><?= e(humanize_label($s)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_status" value="1" class="btn btn-small">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="field-hint">
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        Flagged rows (age outside configured range, prerequisite documents pending, etc.) are shown for review — they are never auto-rejected. <?php if ($filterSacrament === 'marriage' || $filterSacrament === ''): ?>Marriage applications use the <a href="<?= $basePath ?>/admin/requests/marriage-detail.php">Marriage Detail</a> page for clearance recording and approval.<?php endif; ?></p>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';