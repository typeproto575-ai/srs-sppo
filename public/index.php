<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Subfolder-safe URL prefix for the CTA links below.
$basePath = $basePath ?? base_path();

$pageTitle = 'Home';

// Latest published announcements (newest events first, capped at 5)
$announcements = $pdo->query(
    "SELECT * FROM announcements WHERE is_published = 1 ORDER BY event_date DESC LIMIT 5"
)->fetchAll();

// Upcoming schedule events (today forward, capped at 10) for the calendar
$upcomingEvents = $pdo->query(
    "SELECT * FROM schedule_events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 10"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<section class="hero">
    <h1>Welcome to St. Patrick's Parish Office</h1>
    <p>Dingalan, Aurora</p>
</section>

<section class="announcements">
    <h2>Announcements</h2>
    <?php if (empty($announcements)): ?>
        <p>No announcements at this time.</p>
    <?php else: ?>
        <ul>
        <?php foreach ($announcements as $a): ?>
            <li>
                <strong><?= e($a['title']) ?></strong>
                <?php if ($a['event_date']): ?>
                    <span class="event-date"><?= e($a['event_date']) ?></span>
                <?php endif; ?>
                <p><?= e($a['content']) ?></p>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="calendar-preview">
    <h2>Parish Calendar</h2>
    <div id="parish-calendar" style="background:#fff; border:1px solid var(--color-rule); border-radius:6px; padding:1rem; max-width:900px;"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.11/index.global.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('parish-calendar');
        if (typeof FullCalendar === 'undefined') {
            el.innerHTML = '<p>Calendar could not be loaded. Check the list of upcoming events below.</p>';
            return;
        }
        var calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            height: 'auto',
            events: <?= json_encode(array_map(function ($e) {
                return [
                    'title' => $e['title'] . ' (' . ucfirst($e['event_type']) . ')',
                    'start' => $e['event_date'] . ($e['start_time'] ? 'T' . $e['start_time'] : ''),
                ];
            }, $upcomingEvents)) ?>
        });
        calendar.render();
    });
    </script>
    <?php if (!empty($upcomingEvents)): ?>
    <h3 style="margin-top:1.5rem;">Upcoming Events</h3>
    <ul>
    <?php foreach ($upcomingEvents as $ev): ?>
        <li><?= e(date('M j, Y', strtotime($ev['event_date']))) ?> — <?= e($ev['title']) ?> (<?= e(ucfirst($ev['event_type'])) ?>)</li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<!-- Closing calls-to-action: guide visitors to the two key flows -->
<section class="cta">
    <a href="<?= $basePath ?>/public/how-it-works.php" class="btn">See How It Works</a>
    <a href="<?= $basePath ?>/public/certificate-request.php" class="btn btn-secondary">Request a Certificate</a>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
