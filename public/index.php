<?php
/**
 * SRS-SPPO PUBLIC HOME PAGE
 * Landing page: announcements, color-coded calendar, upcoming events.
 * Access: Public (no login required)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$basePath = $basePath ?? base_path();

$settings = [];
try {
    $tableExists = (bool) $pdo->query(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'site_settings'"
    )->fetch();
    if ($tableExists) {
        foreach ($pdo->query("SELECT setting_key, setting_value FROM site_settings ORDER BY setting_id")->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (\Throwable $e) { $settings = []; }

$defaultSettings = [
    'hero_heading_text'    => 'Welcome to St. Patrick\'s Parish Office',
    'hero_eyebrow_text'    => 'Parish Office Online',
    'hero_subtitle_text'   => 'Dingalan, Aurora - the parish\'s sacrament records and certificates, kept faithfully for generations, now easier to reach.',
    'office_hours_text'    => "Tuesday - Friday: 8:00 AM - 5:00 PM\nSaturday: 8:00 AM - 12:00 NN\nSunday & Monday: Closed",
    'office_location_text' => "St. Patrick's Parish Office\nDingalan, Aurora, Philippines",
];
foreach ($defaultSettings as $key => $value) { if (!isset($settings[$key])) $settings[$key] = $value; }

$pageTitle = 'Home';
$typeColorMap = [ 'mass' => '#2a7fe0', 'wedding' => '#d14b8b', 'baptism' => '#2a9d8f', 'other' => '#7a8797' ];

$announcements = $pdo->query("SELECT * FROM announcements WHERE is_published = 1 ORDER BY event_date DESC LIMIT 5")->fetchAll();
$upcomingEvents = $pdo->query("SELECT * FROM schedule_events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 10")->fetchAll();

$calendarEvents = array_map(function ($e) use ($typeColorMap) {
    $color = $typeColorMap[$e['event_type']] ?? '#7a8797';
    return [ 'title' => $e['title'], 'start' => $e['event_date'] . ($e['start_time'] ? 'T' . $e['start_time'] : ''), 'color' => $color ];
}, $upcomingEvents);

require_once __DIR__ . '/../includes/header.php';
?>

<section class="hero">
    <p class="hero-eyebrow"><?= e($settings['hero_eyebrow_text']) ?></p>
    <h1><?= e($settings['hero_heading_text']) ?></h1>
    <p class="hero-sub"><?= e($settings['hero_subtitle_text']) ?></p>
    <div class="hero-actions">
        <a href="<?= $basePath ?>/public/certificate-request.php" class="btn">Request a Certificate</a>
        <a href="<?= $basePath ?>/public/how-it-works.php" class="btn btn-secondary">See How It Works</a>
    </div>
</section>

<section class="office-hours">
    <h2>Visit or Contact the Office</h2>
    <div class="office-hours-grid">
        <div class="office-card">
            <h3>Office Hours</h3>
            <ul class="office-hours-list">
                <?php foreach (explode("\n", $settings['office_hours_text']) as $line): ?>
                    <li><?= e($line) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="office-card">
            <h3>Location</h3>
            <p><?= nl2br(e($settings['office_location_text'])) ?></p>
        </div>
    </div>
</section>

<section class="announcements">
    <h2>Announcements</h2>
    <?php if (empty($announcements)): ?>
        <div class="empty-state"><p>No announcements at this time.</p></div>
    <?php else: ?>
        <div class="carousel" data-carousel data-autoplay="6000" aria-roledescription="carousel" aria-label="Announcements">
            <div class="carousel-viewport">
                <div class="carousel-track">
                <?php foreach ($announcements as $a): ?>
                    <article class="carousel-slide announcement-card">
                        <h3><?= e($a['title']) ?></h3>
                        <?php if ($a['event_date']): ?><span class="event-date"><?= e(date('M j, Y', strtotime($a['event_date']))) ?></span><?php endif; ?>
                        <p><?= e($a['content']) ?></p>
                    </article>
                <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="carousel-btn carousel-prev" data-carousel-prev aria-label="Previous announcement">&#8249;</button>
            <button type="button" class="carousel-btn carousel-next" data-carousel-next aria-label="Next announcement">&#8250;</button>
            <div class="carousel-dots" data-carousel-dots></div>
        </div>
    <?php endif; ?>
</section>

<section class="calendar-preview">
    <h2>Parish Calendar</h2>
    <div id="parish-calendar" class="calendar-shell"></div>

    <!-- Color-coded legend so visitors can read the calendar at a glance -->
    <div class="calendar-legend">
        <?php foreach ($typeColorMap as $type => $color): ?>
            <span class="legend-item">
                <span class="legend-swatch" style="background:<?= e($color) ?>"></span>
                <?= e(ucfirst($type)) ?>
            </span>
        <?php endforeach; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.11/index.global.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('parish-calendar');
        if (typeof FullCalendar === 'undefined') {
            el.innerHTML = '<p class="empty-state">Calendar could not be loaded. See the upcoming events list below.</p>';
            return;
        }
        var calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,listMonth'
            },
            buttonText: { month: 'Month', list: 'List' },
            height: 'auto',
            displayEventTime: true,
            dayMaxEventRows: true,
            eventDisplay: 'block',
            fixedWeekCount: false,
            events: <?= json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
        });
        calendar.render();
    });
    </script>

    <?php if (!empty($upcomingEvents)): ?>
    <h3 class="events-heading">Upcoming Events</h3>
    <div class="carousel" data-carousel data-autoplay="6000" aria-roledescription="carousel" aria-label="Upcoming events">
        <div class="carousel-viewport">
            <div class="carousel-track">
            <?php foreach ($upcomingEvents as $ev): ?>
                <?php $evColor = $typeColorMap[$ev['event_type']] ?? '#7a8797'; ?>
                <div class="carousel-slide event-card">
                    <span class="event-date"><?= e(date('M j', strtotime($ev['event_date']))) ?></span>
                    <div class="event-details">
                        <strong><?= e($ev['title']) ?></strong>
                        <?php if ($ev['start_time']): ?><p><?= e(date('l, F j, Y — g:i A', strtotime($ev['event_date'] . ' ' . $ev['start_time']))) ?></p><?php endif; ?>
                    </div>
                    <span class="event-badge" style="background:<?= e($evColor) ?>20; color:<?= e($evColor) ?>"><?= e(ucfirst($ev['event_type'])) ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <button type="button" class="carousel-btn carousel-prev" data-carousel-prev aria-label="Previous event">&#8249;</button>
        <button type="button" class="carousel-btn carousel-next" data-carousel-next aria-label="Next event">&#8250;</button>
        <div class="carousel-dots" data-carousel-dots></div>
    </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/footer.php';
