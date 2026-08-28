<?php
/**
 * =====================================================================
 * SPPO-IS PARISH CALENDAR (admin)
 * =====================================================================
 * Purpose: Month-view calendar of parish events (masses, weddings,
 *          baptisms, seminars) rendered with FullCalendar, plus a
 *          secretary-only add-event form so the schedule stays current.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Calendar';
$errors  = [];
$success = null;

// NOTE: the original stub described this page as read-only. In practice the
// calendar needs *some* way to get events into it, so a minimal add-event
// form is included here (secretary only) rather than building a separate
// page. Flag this back to the team if a dedicated events page is preferred.
// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form). Nothing is written when it fails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid - please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event']) && csrf_verify()) {
    require_role('secretary');

    $eventType = $_POST['event_type'] ?? '';
    $title     = trim($_POST['title'] ?? '');
    $eventDate = $_POST['event_date'] ?? '';
    $startTime = $_POST['start_time'] ?: null;
    $endTime   = $_POST['end_time'] ?: null;
    $notes     = trim($_POST['notes'] ?? '');

    if (!in_array($eventType, ['mass', 'wedding', 'baptism', 'other'], true)) $errors[] = 'Event type is required.';
    if ($title === '')     $errors[] = 'Title is required.';
    if ($eventDate === '') $errors[] = 'Event date is required.';

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            "INSERT INTO schedule_events (event_type, title, event_date, start_time, end_time, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$eventType, $title, $eventDate, $startTime, $endTime, $notes, current_user()['id']]);
        $eventId = (int) $pdo->lastInsertId();

        log_activity($pdo, current_user()['id'], 'schedule_event_added', 'schedule_events', $eventId, "Added: {$title}");

        redirect_to('/admin/schedule/calendar.php?added=1');
        exit;
    }
}

if (isset($_GET['added'])) $success = 'Event added to the calendar.';

$stmt = $pdo->query("SELECT * FROM schedule_events ORDER BY event_date ASC, start_time ASC");
$events = $stmt->fetchAll();

$calendarEvents = array_map(function ($e) {
    return [
        'title' => $e['title'] . ' (' . ucfirst($e['event_type']) . ')',
        'start' => $e['event_date'] . ($e['start_time'] ? 'T' . $e['start_time'] : ''),
    ];
}, $events);

$isSecretary = current_user()['role'] === 'secretary';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= e($pageTitle) ?> | SPPO-IS</title>
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/admin.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.11/index.global.min.js"></script>
</head>
<body>
<div class="admin-layout">
    <main class="admin-content">
        <p><a href="<?= $basePath ?>/admin/dashboard.php">&larr; Back to Dashboard</a></p>
        <h1>Calendar</h1>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <div id="parish-calendar" style="background:#fff; border:1px solid var(--color-rule); border-radius:6px; padding:1rem; max-width:900px;"></div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('parish-calendar');
            if (typeof FullCalendar === 'undefined') {
                el.innerHTML = '<p>Calendar library failed to load (no internet connection?). See the list below instead.</p>';
                return;
            }
            var calendar = new FullCalendar.Calendar(el, {
                initialView: 'dayGridMonth',
                height: 'auto',
                events: <?= json_encode($calendarEvents) ?>
            });
            calendar.render();
        });
        </script>

        <h2 style="margin-top:2rem;">All Scheduled Events</h2>
        <table class="admin-table">
            <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Title</th><th>Notes</th></tr></thead>
            <tbody>
                <?php foreach ($events as $e): ?>
                    <tr>
                        <td><?= e(date('M j, Y', strtotime($e['event_date']))) ?></td>
                        <td><?= $e['start_time'] ? e(date('g:i A', strtotime($e['start_time']))) : '—' ?></td>
                        <td><?= e(ucfirst($e['event_type'])) ?></td>
                        <td><?= e($e['title']) ?></td>
                        <td><?= e($e['notes']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($isSecretary): ?>
        <h2 style="margin-top:2.5rem;">Add Event</h2>
        <form method="post" action="">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <fieldset>
                <legend>New Event</legend>
                <label>Type *
                    <select name="event_type" required>
                        <option value="">-- Select --</option>
                        <option value="mass">Mass</option>
                        <option value="wedding">Wedding</option>
                        <option value="baptism">Baptism</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>Title * <input type="text" name="title" required></label>
                <div class="inline-fields">
                    <label>Date * <input type="date" name="event_date" required></label>
                    <label>Start Time <input type="time" name="start_time"></label>
                    <label>End Time <input type="time" name="end_time"></label>
                </div>
                <label>Notes <textarea name="notes"></textarea></label>
            </fieldset>
            <button type="submit" name="add_event" value="1" class="btn">Add Event</button>
        </form>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
