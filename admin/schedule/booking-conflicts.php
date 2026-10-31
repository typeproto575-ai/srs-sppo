<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Booking Conflict Review';

$selfUrl = $basePath . '/admin/schedule/booking-conflicts.php';
$rfConfig = ['date_column' => 'sa.preferred_date', 'search_columns' => ['sa.request_no', 'gp.first_name', 'gp.last_name', 'sp.first_name', 'sp.last_name', 'sa.applicant_name'], 'placeholder' => 'Search request no., groom, or bride...'];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

$baseWhere = "WHERE sa.sacrament_type = 'marriage' AND sa.status NOT IN ('completed', 'rejected')";
$combinedWhere = $baseWhere . $rf['where'];

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM sacrament_applications sa
     LEFT JOIN persons gp ON gp.id = sa.person_id
     LEFT JOIN persons sp ON sp.id = sa.spouse_person_id
     $combinedWhere"
);
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT sa.id AS application_id, sa.request_no, sa.status, sa.preferred_date, sa.preferred_time,
            sa.person_id, sa.spouse_person_id, sa.applicant_name, sa.details,
            gp.first_name AS groom_first, gp.middle_name AS groom_middle, gp.last_name AS groom_last,
            sp.first_name AS bride_first, sp.middle_name AS bride_middle, sp.last_name AS bride_last,
            (SELECT se.application_id FROM schedule_events se WHERE se.application_id = sa.id LIMIT 1) AS own_event_id
     FROM sacrament_applications sa
     LEFT JOIN persons gp ON gp.id = sa.person_id
     LEFT JOIN persons sp ON sp.id = sa.spouse_person_id
     $combinedWhere
     ORDER BY sa.preferred_date ASC
     LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$applications = $stmt->fetchAll();

foreach ($applications as &$app) {
    $groomName = trim(($app['groom_first'] ?? '') . ' ' . ($app['groom_middle'] ?? '') . ' ' . ($app['groom_last'] ?? ''));
    if ($groomName === '' && !empty($app['applicant_name'])) $groomName = $app['applicant_name'];
    if ($groomName === '') {
        $d = json_decode($app['details'] ?? '', true);
        $groomName = $d['groom']['full_name'] ?? '';
    }
    $brideName = trim(($app['bride_first'] ?? '') . ' ' . ($app['bride_middle'] ?? '') . ' ' . ($app['bride_last'] ?? ''));
    if ($brideName === '') {
        $d = json_decode($app['details'] ?? '', true);
        $brideName = $d['bride']['full_name'] ?? '';
    }
    $app['groom_full_name'] = $groomName;
    $app['bride_full_name'] = $brideName;
    $app['preferred_date'] = $app['preferred_date'];
    $app['preferred_time'] = $app['preferred_time'];
    $app['request_no'] = $app['request_no'];
}
unset($app);

$eventsByDate = [];
if (!empty($applications)) {
    $dateSet = [];
    foreach ($applications as $a) {
        if (!empty($a['preferred_date'])) $dateSet[$a['preferred_date']] = true;
    }
    $dates = array_keys($dateSet);
    if (!empty($dates)) {
        $places = implode(',', array_fill(0, count($dates), '?'));
        $confStmt = $pdo->prepare(
            "SELECT id, application_id, event_date, title, event_type, start_time, end_time FROM schedule_events
             WHERE event_date IN ({$places}) AND event_type IN ('wedding','mass')
             ORDER BY event_date, start_time"
        );
        $confStmt->execute($dates);
        foreach ($confStmt->fetchAll() as $c) {
            $d = $c['event_date'];
            $eventsByDate[$d] = $eventsByDate[$d] ?? [];
            $eventsByDate[$d][] = $c;
        }
    }
}

foreach ($applications as &$app) {
    $eventsOnDate = $eventsByDate[$app['preferred_date']] ?? [];
    $conflicts = [];
    foreach ($eventsOnDate as $c) {
        if ((int)($c['application_id'] ?? 0) === (int)$app['application_id']) continue;
        if (!event_times_overlap($app['preferred_time'], null, $c['start_time'], $c['end_time'])) {
            continue;
        }
        $conflicts[] = [
            'title' => $c['title'],
            'event_type' => $c['event_type'],
            'start_time' => $c['start_time'],
            'end_time' => $c['end_time'],
            'time_confirmed' => $app['preferred_time'] !== null && $c['start_time'] !== null,
        ];
    }
    $app['conflicts'] = $conflicts;
}
unset($app);

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="admin-list-page booking-conflicts-page">
        <header class="admin-queue-heading">
            <div>
                <p class="admin-queue-kicker">Schedule</p>
                <h1>Booking conflict review</h1>
                <p>Compare preferred marriage dates with events already on the parish calendar.</p>
            </div>
            <a href="<?= $basePath ?>/admin/schedule/calendar.php" class="btn btn-secondary">Open calendar</a>
        </header>
        <div class="schedule-review-notice" role="note">
            <strong>Review conflicts manually.</strong>
            The comparison assumes a <?= DEFAULT_EVENT_SLOT_MINUTES ?>-minute ceremony when no end time is set. Same-day events are only flagged when their times overlap; nothing is automatically resolved.
        </div>

                <?php rf_render_bar($selfUrl, $rfConfig, $rf); ?>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>

        <?php if (empty($applications)): ?>
            <p class="admin-queue-empty">No open marriage applications match these filters.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Request No.</th>
                    <th>Couple</th>
                    <th>Preferred Date</th>
                    <th>Preferred Time</th>
                    <th>Status</th>
                    <th>Calendar Conflict</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                    <tr class="<?= !empty($app['conflicts']) ? 'row-flagged' : '' ?>">
                        <td><?= e($app['request_no']) ?></td>
                        <td><?= e($app['groom_full_name']) ?> &amp; <?= e($app['bride_full_name']) ?></td>
                        <td><?= $app['preferred_date'] ? e(date('M j, Y', strtotime($app['preferred_date']))) : '<span class="field-hint">Not set</span>' ?></td>
                        <td><?= $app['preferred_time'] ? e(date('g:i A', strtotime($app['preferred_time']))) : '<span class="field-hint">Not set</span>' ?></td>
                        <td><span class="status-badge status-<?= e($app['status']) ?>"><?= e(humanize_label($app['status'])) ?></span></td>
                        <td>
                            <?php if (empty($app['conflicts'])): ?>
                                None
                            <?php else: ?>
                                <?php foreach ($app['conflicts'] as $c): ?>
                                    <span class="flag-badge">
                                        <?= e($c['title']) ?> (<?= e(ucfirst($c['event_type'])) ?>)
                                        <?php if ($c['start_time']): ?> &mdash; <?= e(format_event_time_range($c['start_time'], $c['end_time'])) ?><?php endif; ?>
                                        <?php if (!$c['time_confirmed']): ?><br><small class="field-hint">Time not yet confirmed on one side &mdash; verify manually</small><?php endif; ?>
                                    </span><br>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php rf_render_pagination($selfUrl, $totalRows, $rf); ?>
        <?php endif; ?>

        </div>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';


