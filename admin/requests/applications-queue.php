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
    'submitted', 'documents_pending', 'interview_scheduled', 'banns_in_progress',
    'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected',
];

// Per-sacrament workflows: the status dropdown for each application only
// offers the stages that actually apply to its sacrament. Marriage alone
// uses the interview + banns (Tawag sa Kasal) stages; Baptismal,
// Confirmation and First Communion go through their seminar then approval.
$statusFlow = [
    'baptismal'       => ['submitted', 'documents_pending', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'],
    'confirmation'    => ['submitted', 'documents_pending', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'],
    'first_communion' => ['submitted', 'documents_pending', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'],
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
        } elseif ($newStatus === 'approved') {
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

// --- Filters ---------------------------------------------------------------
$filterStatus    = $_GET['status'] ?? '';
$filterSacrament = $_GET['sacrament'] ?? '';
$filterChannel = $_GET['channel'] ?? '';
$sacramentTypes  = ['baptismal', 'confirmation', 'first_communion', 'marriage'];

$where  = [];
$params = [];
if ($filterStatus !== '' && in_array($filterStatus, $allStatuses, true)) {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}
if ($filterSacrament !== '' && in_array($filterSacrament, $sacramentTypes, true)) {
    $where[] = 'sacrament_type = ?';
    $params[] = $filterSacrament;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$selfUrl = $basePath . '/admin/requests/applications-queue.php';
$rfConfig = [
    'date_column'    => 'date_submitted',
    'search_columns' => ['tracking_number', 'sacrament_type'],
    'placeholder'    => 'Search tracking no. or sacrament...',
    'ranges'         => ['today', 'week', 'month', 'year'],
    'filters'        => [
        'status'    => ['label' => 'Status',
            'options' => [
                'submitted'          => 'Submitted',
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
    'tracking' => 'tracking_number ASC, id ASC', 'tracking_desc' => 'tracking_number DESC, id DESC',
    'sacrament' => 'sacrament_type ASC, id ASC', 'sacrament_desc' => 'sacrament_type DESC, id DESC',
    'channel' => 'submission_channel ASC, id ASC', 'channel_desc' => 'submission_channel DESC, id DESC',
    'date_desc' => 'date_submitted DESC, id DESC', 'date_asc' => 'date_submitted ASC, id ASC',
    'status' => 'status ASC, id ASC', 'status_desc' => 'status DESC, id DESC',
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

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM sacrament_applications{$combinedWhere}");
$countStmt->execute($allParams);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM sacrament_applications{$combinedWhere} ORDER BY {$orderClause} LIMIT {$rf['per_page']} OFFSET {$rf['offset']}");
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
        <h1>Sacrament Applications Queue</h1>

                <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <?php if (empty($applications)): ?>
            <p>No sacrament applications match this filter.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'tracking') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'tracking', $sort)) ?>">Tracking No.<?= admin_sort_indicator('tracking', $sort) ?></a></th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'sacrament') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'sacrament', $sort)) ?>">Sacrament<?= admin_sort_indicator('sacrament', $sort) ?></a></th>
                    <th>Applicant(s)</th>
                    <th><a class="table-sort-link<?= in_array($sort, ['date_desc', 'date_asc'], true) ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'date', $sort)) ?>">Submitted<?= admin_sort_indicator('date', $sort) ?></a></th>
                    <th>Record link</th>
                    <th>Flags</th>
                    <th>Actions</th>
                    <th><a class="table-sort-link<?= str_starts_with($sort, 'status') ? ' is-active' : '' ?>" href="<?= e(admin_sort_url($selfUrl, 'status', $sort)) ?>">Status<?= admin_sort_indicator('status', $sort) ?></a></th>
                    <th>Update</th>
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
                        <td><?= e($app['record_status'] ?? 'Not applicable') ?></td>
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
                        </td>
                        <td><span class="status-badge status-<?= e($app['status']) ?>"><?= e(humanize_label($app['status'])) ?></span></td>
                        <td class="row-actions">
                            <form autocomplete="off" method="post" class="inline-fields">
                                <?= csrf_field() ?><!-- CSRF: pairs with the guard above -->
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <select name="new_status">
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