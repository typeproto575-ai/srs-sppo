<?php
/**
 * SRS-SPPO PUBLIC HOME PAGE
 * Public information hub for online intake, parish requirements, and tracking.
 * Parishioners do not create accounts; online submissions are requests for secretary review only.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$basePath = $basePath ?? base_path();

$settings = [
    'hero_heading_text'    => "Parish records, prepared with care.",
    'hero_eyebrow_text'    => "St. Patrick Parish Office · Dingalan, Aurora",
    'hero_subtitle_text'   => "Submit a request online or visit the parish office. Every online submission is reviewed by the secretary before processing, and certificates are released only in person.",
    'office_hours_text'    => "Monday - Saturday: 8:00 AM - 12:00 NN\nMonday - Saturday: 2:00 PM - 5:00 PM\nSunday: Closed",
    'office_location_text' => "St. Patrick Parish Office\nDingalan, Aurora, Philippines",
];

$pageTitle = 'Home';
$typeColorMap = [ 'mass' => '#2a7fe0', 'wedding' => '#d14b8b', 'baptism' => '#2a9d8f', 'other' => '#7a8797' ];
$announcements = $pdo->query("SELECT * FROM announcements WHERE is_published = 1 ORDER BY event_date DESC LIMIT 5")->fetchAll();
$upcomingEvents = $pdo->query("SELECT * FROM schedule_events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 10")->fetchAll();
function public_calendar_title(string $title): string
{
    return trim((string) preg_replace('/(?:\s*[-–—|:]\s*|\s+)\(?\b(?:AM|PM)\b\)?\s*$/i', '', $title));
}

$calendarEvents = array_map(function ($event) use ($typeColorMap) {
    $color = $typeColorMap[$event['event_type']] ?? '#7a8797';
    $timeLabel = '';
    if (!empty($event['start_time'])) {
        $timeLabel = date('g:i A', strtotime($event['start_time']));
        $timeLabel .= ' - ';
    }
    return [
        'title' => $timeLabel . public_calendar_title((string) $event['title']),
        'start' => $event['event_date'] . ($event['start_time'] ? 'T' . $event['start_time'] : ''),
        'end' => $event['end_time'] ? $event['event_date'] . 'T' . $event['end_time'] : null,
        'color' => $color,
    ];
}, $upcomingEvents);

require_once __DIR__ . '/../includes/header.php';
?>

<section class="portal-hero">
    <div class="portal-hero-copy">
        <p class="hero-eyebrow"><?= e($settings['hero_eyebrow_text']) ?></p>
        <h1><?= e($settings['hero_heading_text']) ?></h1>
        <p class="hero-sub"><?= e($settings['hero_subtitle_text']) ?></p>
        <div class="hero-actions">
            <a href="<?= $basePath ?>/public/requirements.php" class="btn">Get Started</a>
        </div>
    </div>
    <aside class="portal-hero-note" aria-label="Important parish office note">
        <span class="portal-note-label">Before you begin</span>
        <h2>Online intake, secretary review</h2>
        <p>There are no parishioner accounts or online payments. An online submission is only a pre-application; the secretary verifies requirements and identity before processing.</p>
        <a href="<?= $basePath ?>/public/how-it-works.php">See how the office process works &rarr;</a>
    </aside>
</section>

<section class="portal-section portal-services" aria-labelledby="services-heading">
    <div class="section-heading-row">
        <div>
            <p class="section-kicker">Parish office services</p>
            <h2 id="services-heading">What do you need?</h2>
        </div>
        <p class="section-intro">Choose a service, send your information for parish review, and keep your tracking number. The parish office makes the final decision.</p>
    </div>
    <div class="service-grid">
        <a class="service-card" href="<?= $basePath ?>/public/requirements.php">
            <span class="service-number">01</span>
            <h3>Request a certificate</h3>
            <p>Send an online request for a Baptismal, Confirmation, First Communion, or Marriage certificate.</p>
            <span class="service-link">Request a certificate &rarr;</span>
        </a>
        <a class="service-card service-card-accent" href="<?= $basePath ?>/public/sacrament-application.php">
            <span class="service-number">02</span>
            <h3>Apply for a Sacrament</h3>
            <p>Send your information online for parish review, then follow the secretary's instructions for requirements and preparation.</p>
            <span class="service-link">View applications &rarr;</span>
        </a>
        <a class="service-card" href="<?= $basePath ?>/public/track-request.php">
            <span class="service-number">03</span>
            <h3>Track an online submission</h3>
            <p>Already submitted online or received a tracking number from the secretary? Check its current processing stage with your email address.</p>
            <span class="service-link">Track a request &rarr;</span>
        </a>
        <a class="service-card service-card-accent" href="<?= $basePath ?>/public/correction-request.php">
            <span class="service-number">04</span>
            <h3>Request a certificate correction</h3>
            <p>If an issued certificate has an error, submit a correction request with your tracking number and explain the needed change.</p>
            <span class="service-link">Submit a correction request &rarr;</span>
        </a>
    </div>
</section>

<section class="portal-section process-strip" aria-labelledby="process-heading">
    <div class="section-heading-row">
        <div>
            <p class="section-kicker">Simple office process</p>
            <h2 id="process-heading">From requirements to pickup</h2>
        </div>
    </div>
    <div class="process-grid">
        <div class="process-step"><span>01</span><h3>Prepare</h3><p>Review the requirements before coming to the parish office.</p></div>
        <div class="process-step"><span>02</span><h3>Visit</h3><p>The secretary reviews the online intake or encodes the request onsite.</p></div>
        <div class="process-step"><span>03</span><h3>Bring if needed</h3><p>If documents are needed, show them in person at the Parish Office. The system never receives or stores documents — please do not email or message them.</p></div>
        <div class="process-step"><span>04</span><h3>Pick up</h3><p>When finalized, the parish office notifies you so the certificate can be claimed in person.</p></div>
    </div>
</section>

<section id="office-contact" class="office-hours portal-section" aria-labelledby="office-heading">
    <div class="section-heading-row">
        <div>
            <p class="section-kicker">Plan your visit</p>
            <h2 id="office-heading">Parish office information</h2>
        </div>
    </div>
    <div class="office-hours-grid">
        <div class="office-card"><h3>Office Hours</h3><ul class="office-hours-list"><?php foreach (explode("\n", $settings['office_hours_text']) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul></div>
        <div class="office-card"><h3>Location</h3><p><?= nl2br(e($settings['office_location_text'])) ?></p><a class="text-link" href="<?= $basePath ?>/public/requirements.php">Review requirements before visiting &rarr;</a><br><a class="text-link" href="https://www.facebook.com/profile.php?id=61575322479373" target="_blank" rel="noopener noreferrer">Visit our Facebook Page</a></div>
    </div>
</section>

<?php if (!empty($announcements)): ?>
<section class="announcements portal-section" aria-labelledby="announcements-heading">
    <div class="section-heading-row"><div><p class="section-kicker">From the parish office</p><h2 id="announcements-heading">Announcements</h2></div></div>
    <div class="carousel" data-carousel aria-roledescription="carousel" aria-label="Announcements">
        <div class="carousel-viewport"><div class="carousel-track">
            <?php foreach (array_chunk($announcements, 2) as $batch): ?>
                <article class="slide announcement-slide">
                    <?php foreach ($batch as $announcement): ?>
                        <div class="card"><h3><?= e($announcement['title']) ?></h3><?php if ($announcement['event_date']): ?><span class="event-date"><?= e(date('M j, Y', strtotime($announcement['event_date']))) ?></span><?php endif; ?><p><?= e($announcement['content']) ?></p></div>
                    <?php endforeach; ?>
                </article>
            <?php endforeach; ?>
        </div></div>
        <div class="carousel-controls"><button type="button" class="carousel-btn carousel-prev" data-carousel-prev aria-label="Previous announcement">&#8249;</button><div class="carousel-dots" data-carousel-dots></div><button type="button" class="carousel-btn carousel-next" data-carousel-next aria-label="Next announcement">&#8250;</button></div>
    </div>
</section>
<?php endif; ?>

<section class="calendar-preview portal-section" aria-labelledby="calendar-heading">
    <div class="section-heading-row"><div><p class="section-kicker">Stay informed</p><h2 id="calendar-heading">Parish calendar</h2></div><p class="section-intro">Upcoming parish activities and office events.</p></div>
    <div id="parish-calendar" class="calendar-shell"></div>
    <div class="calendar-legend" aria-label="Calendar event colors"><?php foreach ($typeColorMap as $type => $color): ?><span class="legend-item" title="<?= e(ucfirst($type)) ?>" aria-label="<?= e(ucfirst($type)) ?>"><span class="legend-swatch" style="background:<?= e($color) ?>"></span><?= e(ucfirst($type)) ?></span><?php endforeach; ?></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.11/index.global.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('parish-calendar');
        if (typeof FullCalendar === 'undefined') { el.innerHTML = '<p class="empty-state">Calendar could not be loaded. See the parish office for event details.</p>'; return; }
        var calendar = new FullCalendar.Calendar(el, { initialView: 'dayGridMonth', headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' }, buttonText: { month: 'Month', list: 'List' }, height: 'auto', displayEventTime: false, dayMaxEventRows: true, eventDisplay: 'block', fixedWeekCount: false, events: <?= json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> });
        calendar.render();
    });
    </script>
    <?php if (!empty($upcomingEvents)): ?><h3 class="events-heading">Upcoming events</h3><div class="carousel" data-carousel aria-roledescription="carousel" aria-label="Upcoming events"><div class="carousel-viewport"><div class="carousel-track"><?php foreach (array_chunk($upcomingEvents, 2) as $batch): ?><article class="slide event-slide"><?php foreach ($batch as $event): ?><?php $eventColor = $typeColorMap[$event['event_type']] ?? '#7a8797'; ?><div class="card"><span class="event-date"><?= e(date('M j', strtotime($event['event_date']))) ?></span><div class="event-details"><strong><?= e($event['title']) ?></strong><?php if ($event['start_time']): ?><p><?= e(date('D, M j g:i A', strtotime($event['event_date'] . ' ' . $event['start_time']))) ?></p><?php endif; ?></div><span class="event-badge" style="background:<?= e($eventColor) ?>20; color:<?= e($eventColor) ?>"><?= e(ucfirst($event['event_type'])) ?></span></div><?php endforeach; ?></article><?php endforeach; ?></div></div><div class="carousel-controls"><button type="button" class="carousel-btn carousel-prev" data-carousel-prev aria-label="Previous event">&#8249;</button><div class="carousel-dots" data-carousel-dots></div><button type="button" class="carousel-btn carousel-next" data-carousel-next aria-label="Next event">&#8250;</button></div></div><?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
