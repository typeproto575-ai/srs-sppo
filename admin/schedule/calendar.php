<?php
/**
 * =====================================================================
 * SRS-SPPO PARISH CALENDAR (admin)
 * =====================================================================
 * Purpose: Month-view calendar of parish events (masses, weddings,
 *          baptisms, seminars) rendered with FullCalendar, plus a
 *          secretary-only event manager (add / edit / delete) with
 *          conflict flagging, a color legend, and month/list views.
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

// --- Edit (?edit=ID opens the shared dialog pre-filled) ---------------------
$editEvent = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM schedule_events WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editEvent = $stmt->fetch();
    if (!$editEvent) {
        redirect_to('/admin/schedule/calendar.php');
        exit;
    }
}
$isEdit = $editEvent !== null;

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

    $postedEventType = $_POST['event_type'] ?? '';
    $title     = trim($_POST['title'] ?? '');
    $eventDate = $_POST['event_date'] ?? '';
    $startTime = $_POST['start_time'] ?: null;
    $endTime   = $_POST['end_time'] ?: null;
    $notes     = trim($_POST['notes'] ?? '');

    if (!in_array($postedEventType, ['mass', 'wedding', 'baptism', 'other'], true)) $errors[] = 'Event type is required.';
    if ($title === '')     $errors[] = 'Title is required.';
    if ($eventDate === '') $errors[] = 'Event date is required.';
    if ($startTime && $endTime && $endTime <= $startTime) $errors[] = 'End time must be after the start time.';

    if (empty($errors)) {
        // Flag (never block): count same-date events BEFORE the insert so the
        // success message can warn about a booking conflict that the
        // secretary must confirm manually.
        $occupyStmt = $pdo->prepare("SELECT COUNT(*) FROM schedule_events WHERE event_date = ?");
        $occupyStmt->execute([$eventDate]);
        $hadConflict = (int) $occupyStmt->fetchColumn() > 0;

        $stmt = $pdo->prepare(
            "INSERT INTO schedule_events (event_type, title, event_date, start_time, end_time, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$postedEventType, $title, $eventDate, $startTime, $endTime, $notes, current_user()['id']]);
        $eventId = (int) $pdo->lastInsertId();

        log_activity($pdo, current_user()['id'], 'schedule_event_added', 'schedule_events', $eventId, "Added event: {$title} on {$eventDate}");

        redirect_to('/admin/schedule/calendar.php?added=1' . ($hadConflict ? '&conflict=1' : ''));
        exit;
    }
}

if (isset($_GET['added'])) {
    $success = 'Event added to the calendar.'
        . (isset($_GET['conflict']) ? ' Note: that date already has an event — please confirm there is no booking conflict.' : '');
}
if (isset($_GET['updated'])) $success = 'Event updated.';
if (isset($_GET['deleted'])) $success = 'Event deleted.';

// --- Update / delete handlers (placed after the edit loader so the shared
// edit dialog can re-render with validation errors and sticky values) -------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event']) && csrf_verify()) {
    require_role('secretary');

    $editId   = (int) ($_POST['edit_id'] ?? 0);
    $updType  = $_POST['event_type'] ?? '';
    $updTitle = trim($_POST['title'] ?? '');
    $updDate  = $_POST['event_date'] ?? '';
    $updStart = $_POST['start_time'] ?: null;
    $updEnd   = $_POST['end_time'] ?: null;
    $updNotes = trim($_POST['notes'] ?? '');

    $editEvent = [
        'id' => $editId, 'event_type' => $updType, 'title' => $updTitle,
        'event_date' => $updDate, 'start_time' => $updStart,
        'end_time' => $updEnd, 'notes' => $updNotes,
    ];
    $isEdit = $editId > 0;

    if (!in_array($updType, ['mass', 'wedding', 'baptism', 'other'], true)) $errors[] = 'Event type is required.';
    if ($updTitle === '')     $errors[] = 'Title is required.';
    if ($updDate === '')      $errors[] = 'Event date is required.';
    if ($updStart && $updEnd && $updEnd <= $updStart) $errors[] = 'End time must be after the start time.';

    // Verify the row still exists (the GET loader was skipped on POST).
    if ($editId && empty($errors)) {
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schedule_events WHERE id = ?");
        $checkStmt->execute([$editId]);
        if ((int) $checkStmt->fetchColumn() === 0) $errors[] = 'That event no longer exists.';
    }

    if ($editId && empty($errors)) {
        $stmt = $pdo->prepare(
            "UPDATE schedule_events SET event_type = ?, title = ?, event_date = ?, start_time = ?, end_time = ?, notes = ? WHERE id = ?"
        );
        $stmt->execute([$updType, $updTitle, $updDate, $updStart, $updEnd, $updNotes, $editId]);
        log_activity($pdo, current_user()['id'], 'schedule_event_updated', 'schedule_events', $editId, "Updated: {$updTitle}");
        redirect_to('/admin/schedule/calendar.php?updated=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event']) && csrf_verify()) {
    require_role('secretary');
    $id = (int) ($_POST['event_id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM schedule_events WHERE id = ?");
        $stmt->execute([$id]);
        log_activity($pdo, current_user()['id'], 'schedule_event_deleted', 'schedule_events', $id, null);
    }
    redirect_to('/admin/schedule/calendar.php?deleted=1');
    exit;
}

// Events load below (shared metadata + data query used by the toolbar,
// FullCalendar feed, and the events table).


$stmt = $pdo->query("SELECT * FROM schedule_events ORDER BY event_date ASC, start_time ASC");
$events = $stmt->fetchAll();

// Per-type calendar colors (also used by the legend and the list badges).
$typeColors = ['mass' => '#1d6f42', 'wedding' => '#b03060', 'baptism' => '#1f6feb', 'other' => '#6b7280'];
$typeLabels = ['mass' => 'Mass', 'wedding' => 'Wedding', 'baptism' => 'Baptism', 'other' => 'Other'];

// Any date carrying 2+ events flags a booking conflict (shown "— CONFLICT" in
// red on the calendar and with a warning badge in the list below). Resolution
// stays manual per the project convention — the secretary confirms.
$dateCounts = array_count_values(array_column($events, 'event_date'));

$calendarEvents = array_map(function ($e) use ($typeColors, $typeLabels, $dateCounts, $basePath) {
    $conflict = ($dateCounts[$e['event_date']] ?? 0) > 1;
    return [
        'title' => $e['title'] . ' (' . ($typeLabels[$e['event_type']] ?? ucfirst($e['event_type'])) . ')' . ($conflict ? ' — CONFLICT' : ''),
        'start' => $e['event_date'] . ($e['start_time'] ? 'T' . $e['start_time'] : ''),
        'color' => $conflict ? '#b91c1c' : ($typeColors[$e['event_type']] ?? '#6b7280'),
        'url'   => $basePath . '/admin/schedule/calendar.php?edit=' . (int) $e['id'],
    ];
}, $events);

$isSecretary = current_user()['role'] === 'secretary';
// FullCalendar must load in <head>; pass it to the shared shell via $extraHead.
$extraHead = '<script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.11/index.global.min.js"></script>';

// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <section class="admin-hero">
            <div>
                <p class="hero-eyebrow">Schedule</p>
                <h1>Parish Calendar</h1>
                <p class="hero-copy">Masses, weddings, baptisms, and parish events — click any event to edit it.</p>
            </div>
            <?php if ($isSecretary): ?>
            <button type="button" class="btn" data-open-dialog="#event-dialog">+ Add Event</button>
            <?php endif; ?>
        </section>

        <?php if ($success): ?><div class="alert alert-success"><p><?= e($success) ?></p></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <div class="calendar-toolbar">
            <div class="calendar-legend">
                <?php foreach ($typeLabels as $type => $label): ?>
                    <span class="legend-item"><span class="legend-dot" style="background:<?= e($typeColors[$type]) ?>;"></span><?= e($label) ?></span>
                <?php endforeach; ?>
                <span class="legend-item"><span class="legend-dot" style="background:#b91c1c;"></span>Conflict</span>
            </div>
            <div class="calendar-view-toggle">
                <button type="button" class="btn btn-small" data-cal-view="dayGridMonth">Month</button>
                <button type="button" class="btn btn-small btn-secondary" data-cal-view="listMonth">List</button>
            </div>
        </div>

        <div id="parish-calendar" class="calendar-shell"></div>

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
                dayMaxEventRows: 6,
                fixedWeekCount: false,
                eventDisplay: 'block',
                displayEventTime: true,
                eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
                events: <?= json_encode($calendarEvents) ?>
            });
            calendar.render();
            document.querySelectorAll('[data-cal-view]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    calendar.changeView(btn.getAttribute('data-cal-view'));
                    document.querySelectorAll('[data-cal-view]').forEach(function (b) { b.classList.add('btn-secondary'); });
                    btn.classList.remove('btn-secondary');
                });
            });
        });
        </script>

        <?php if (empty($events)): ?>
            <p>No events scheduled yet.</p>
        <?php else: ?>
        <h2 style="margin-top:2rem;">All Scheduled Events (<?= count($events) ?>)</h2>
        <table class="admin-table">
            <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Title</th><th>Notes</th><?php if ($isSecretary): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($events as $e): ?>
                    <?php $conflict = ($dateCounts[$e['event_date']] ?? 0) > 1; ?>
                    <tr>
                        <td><?= e(date('M j, Y', strtotime($e['event_date']))) ?></td>
                        <td><?= $e['start_time'] ? e(date('g:i A', strtotime($e['start_time']))) : '—' ?></td>
                        <td><span class="status-badge" style="background:<?= e($typeColors[$e['event_type']] ?? '#6b7280') ?>;color:#fff;"><?= e($typeLabels[$e['event_type']] ?? ucfirst($e['event_type'])) ?></span></td>
                        <td><?= e($e['title']) ?><?php if ($conflict): ?> <span class="status-badge status-rejected">Conflict</span><?php endif; ?></td>
                        <td><?= e($e['notes']) ?></td>
                        <?php if ($isSecretary): ?>
                        <td class="row-actions">
                            <a class="btn btn-small" href="<?= $basePath ?>/admin/schedule/calendar.php?edit=<?= (int) $e['id'] ?>">Edit</a>
                            <form method="post" class="inline-form" data-confirm="Delete this event?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="event_id" value="<?= (int) $e['id'] ?>">
                                <button type="submit" name="delete_event" value="1" class="btn btn-small btn-danger">Delete</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ($isSecretary): ?>
        <!-- Add/Edit event dialog (same records encode-dialog style).
             Pre-filled when ?edit=ID was opened; sticky POST values survive
             validation errors so nothing typed is lost. -->
        <dialog id="event-dialog" class="encode-dialog"<?= $isEdit ? ' data-autoshow' : '' ?>>
            <div class="dialog-head">
                <h2><?= $isEdit ? 'Edit event — ' . e($editEvent['title']) : 'Add new event' ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>
            <div class="dialog-body">
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= (int) $editEvent['id'] ?>"><?php endif; ?>
                    <label>Type *
                        <select name="event_type" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($typeLabels as $type => $label): ?>
                                <option value="<?= e($type) ?>" <?= (($_POST['event_type'] ?? ($editEvent['event_type'] ?? '')) === $type) ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Title * <input type="text" name="title" value="<?= e($_POST['title'] ?? ($editEvent['title'] ?? '')) ?>" required></label>
                    <div class="inline-fields">
                        <label>Date * <input type="date" name="event_date" value="<?= e($_POST['event_date'] ?? ($editEvent['event_date'] ?? '')) ?>" required></label>
                        <label>Start Time <input type="time" name="start_time" value="<?= e($_POST['start_time'] ?? ($editEvent['start_time'] ?? '')) ?>"></label>
                        <label>End Time <input type="time" name="end_time" value="<?= e($_POST['end_time'] ?? ($editEvent['end_time'] ?? '')) ?>"></label>
                    </div>
                    <label>Notes <textarea name="notes"><?= e($_POST['notes'] ?? ($editEvent['notes'] ?? '')) ?></textarea></label>
                    <div class="form-actions">
                        <button type="submit" name="<?= $isEdit ? 'update_event' : 'add_event' ?>" value="1" class="btn"><?= $isEdit ? 'Save changes' : 'Add Event' ?></button>
                        <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
                    </div>
                </form>
            </div>
        </dialog>
        <?php endif; ?>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
