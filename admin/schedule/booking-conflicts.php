<?php
/**
 * =====================================================================
 * SRS-SPPO BOOKING CONFLICT REVIEW (admin)
 * =====================================================================
 * Purpose: Surfaces marriage applications whose preferred wedding date AND
 *          TIME overlap with an existing calendar event, so the secretary
 *          can resolve the clash manually (reschedule or confirm). Same-day
 *          bookings at different times are not flagged.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Booking Conflict Review';

// Marriage is currently the only sacrament with a preferred date field on
// its application. Compare each pending/in-progress marriage application's
// preferred_wedding_date against schedule_events for the same date.

// --- Search / filter / pagination (must run BEFORE the queries below) ---
// rf_parse() returns ' AND ...' when filters are active and '' otherwise,
// so the fragment is appended as-is to the base WHERE (an ltrim "prefix
// strip" here previously produced "AND AND (...)" → SQL 1064).
$selfUrl = $basePath . '/admin/schedule/booking-conflicts.php';
$rfConfig = ['date_column' => 'ma.preferred_wedding_date', 'search_columns' => ['sa.tracking_number', 'ma.groom_full_name', 'ma.bride_full_name'], 'placeholder' => 'Search tracking no., groom, or bride...'];
require_once __DIR__ . '/../../includes/record-filters.php';
$rf = rf_parse($rfConfig);

// Main query with filter pattern
$baseWhere = "WHERE sa.status NOT IN ('completed', 'rejected')";
$combinedWhere = $baseWhere . $rf['where'];

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM sacrament_applications sa
     JOIN marriage_applications ma ON ma.application_id = sa.id
     $combinedWhere"
);
$countStmt->execute($rf['params']);
$totalRows = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT sa.id AS application_id, sa.tracking_number, sa.status,
            ma.groom_full_name, ma.bride_full_name, ma.preferred_wedding_date,
            ma.preferred_wedding_time
     FROM sacrament_applications sa
     JOIN marriage_applications ma ON ma.application_id = sa.id
     $combinedWhere
     ORDER BY ma.preferred_wedding_date ASC
     LIMIT {$rf['per_page']} OFFSET {$rf['offset']}"
);
$stmt->execute($rf['params']);
$applications = $stmt->fetchAll();

// A wedding application only stores a single preferred TIME, not a
// duration, so we assume a fixed ceremony length for overlap checking
// (see DEFAULT_EVENT_SLOT_MINUTES / event_times_overlap() in functions.php,
// shared with the calendar's own conflict flagging).
$eventsByDate = [];
if (!empty($applications)) {
    $dateSet = [];
    foreach ($applications as $a) $dateSet[$a['preferred_wedding_date']] = true;
    $dates = array_keys($dateSet);

    $places = [];
    foreach ($dates as $i) $places[] = '?';
    $place = implode(',', $places);

    $confStmt = $pdo->prepare(
        "SELECT event_date, title, event_type, start_time, end_time FROM schedule_events
         WHERE event_date IN ({$place}) AND event_type IN ('wedding','mass')
         ORDER BY event_date, start_time"
    );
    $confStmt->execute($dates);
    foreach ($confStmt->fetchAll() as $c) {
        $d = $c['event_date'];
        $eventsByDate[$d] = $eventsByDate[$d] ?? [];
        $eventsByDate[$d][] = $c;
    }
}

foreach ($applications as &$app) {
    $eventsOnDate = $eventsByDate[$app['preferred_wedding_date']] ?? [];
    $conflicts = [];
    foreach ($eventsOnDate as $c) {
        if (!event_times_overlap($app['preferred_wedding_time'], null, $c['start_time'], $c['end_time'])) {
            continue; // same day, but times don't actually clash
        }
        $conflicts[] = [
            'title' => $c['title'],
            'event_type' => $c['event_type'],
            'start_time' => $c['start_time'],
            'end_time' => $c['end_time'],
            'time_confirmed' => $app['preferred_wedding_time'] !== null && $c['start_time'] !== null,
        ];
    }
    $app['conflicts'] = $conflicts;
}
unset($app);

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

// format_event_time_range() lives in includes/functions.php (shared with calendar.php).
// Search/filter/pagination setup moved above the queries (rf_parse must run
// before the COUNT/list SQL is assembled). $selfUrl / $rfConfig / $rf are
// already defined.

// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
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
                    <th>Tracking No.</th>
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
                        <td><?= e($app['tracking_number']) ?></td>
                        <td><?= e($app['groom_full_name']) ?> &amp; <?= e($app['bride_full_name']) ?></td>
                        <td><?= e(date('M j, Y', strtotime($app['preferred_wedding_date']))) ?></td>
                        <td><?= $app['preferred_wedding_time'] ? e(date('g:i A', strtotime($app['preferred_wedding_time']))) : '<span class="field-hint">Not set</span>' ?></td>
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