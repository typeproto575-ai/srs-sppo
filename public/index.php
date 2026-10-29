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
$pageClass = 'public-home';
$typeColorMap = [ 'mass' => '#245b8f', 'wedding' => '#923b67', 'baptism' => '#176b5a', 'other' => '#526171' ];
$typeLabels = [ 'mass' => 'Mass', 'wedding' => 'Wedding', 'baptism' => 'Baptism', 'other' => 'Other' ];
$announcements = $pdo->query("SELECT * FROM announcements WHERE is_published = 1 ORDER BY event_date DESC LIMIT 5")->fetchAll();
$calendarEventRows = $pdo->query("SELECT * FROM schedule_events WHERE event_date >= CURDATE() ORDER BY event_date ASC")->fetchAll();
function public_calendar_title(string $title): string
{
    return trim((string) preg_replace('/(?:\s*[-–—|:]\s*|\s+)\(?\b(?:AM|PM)\b\)?\s*$/i', '', $title));
}

$calendarEvents = array_map(function ($event) use ($typeColorMap, $typeLabels) {
    $color = $typeColorMap[$event['event_type']] ?? $typeColorMap['other'];
    $displayTitle = public_calendar_title((string) $event['title']);
    $publicDetails = trim((string) ($event['public_details'] ?? ''));
    $calendarTitle = $displayTitle;
    if (!empty($event['start_time'])) {
        $timeLabel = date('g:i A', strtotime($event['start_time']));
        $calendarTitle = $timeLabel . ' - ' . $displayTitle;
    }
    return [
        'title' => $calendarTitle,
        'start' => $event['event_date'] . ($event['start_time'] ? 'T' . $event['start_time'] : ''),
        'end' => $event['end_time'] ? $event['event_date'] . 'T' . $event['end_time'] : null,
        'color' => $color,
        'classNames' => $publicDetails === '' ? [] : ['has-public-details'],
        'displayTitle' => $displayTitle,
        'publicDetails' => $publicDetails,
        'eventType' => $typeLabels[$event['event_type']] ?? $typeLabels['other'],
        'displayDate' => date('l, F j, Y', strtotime($event['event_date'])),
    ];
}, $calendarEventRows);

require_once __DIR__ . '/../includes/header.php';
?>

<section class="portal-hero">
    <div class="portal-hero-copy">
        <p class="hero-eyebrow"><?= e($settings['hero_eyebrow_text']) ?></p>
        <h1><?= e($settings['hero_heading_text']) ?></h1>
        <p class="hero-sub"><?= e($settings['hero_subtitle_text']) ?></p>
        <div class="hero-actions">
            <a href="<?= $basePath ?>/public/requirements.php" class="btn">View Requirements &amp; Get Started</a>
            <a href="<?= $basePath ?>/public/track-request.php" class="btn btn-secondary">Track a Request</a>
        </div>
    </div>
    <aside class="portal-hero-note" aria-label="Important parish office note">
        <span class="portal-note-label">Before you begin</span>
        <h2>Online forms. Personal parish care.</h2>
        <p>No account or online payment is needed. The parish office reviews each submission and confirms the next steps.</p>
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
        <a class="service-card" href="<?= $basePath ?>/public/requirements.php#application-preparation-heading">
            <span class="service-number">01</span>
            <h3>Apply for a sacrament</h3>
            <p>Start a Baptism, First Communion, Confirmation, or Church Wedding application for parish review.</p>
            <span class="service-link">Explore sacrament applications &rarr;</span>
        </a>
        <a class="service-card" href="<?= $basePath ?>/public/certificate-request.php">
            <span class="service-number">02</span>
            <h3>Request a certificate copy</h3>
            <p>Request a certified copy of a baptism, confirmation, first communion, or marriage record.</p>
            <span class="service-link">Request a certificate copy &rarr;</span>
        </a>
        <a class="service-card" href="<?= $basePath ?>/public/track-request.php">
            <span class="service-number">03</span>
            <h3>Track an online submission</h3>
            <p>Already submitted online or received a tracking number from the secretary? Check its current processing stage with your email address.</p>
            <span class="service-link">Track a request &rarr;</span>
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
        <div class="process-step"><span>03</span><h3>Bring if needed</h3><p>If documents are needed, show them in person at the Parish Office. Do not send documents by email or Messenger. You may still contact the Parish Office with questions.</p></div>
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
    <div class="section-heading-row"><div><p class="section-kicker">Stay informed</p><h2 id="calendar-heading">Parish calendar</h2></div><p class="section-intro">Upcoming parish activities and office events. Select an event with a details indicator to read more.</p></div>
    <div id="parish-calendar" class="calendar-shell"></div>
    <div class="calendar-legend" aria-label="Calendar event colors"><?php foreach ($typeLabels as $type => $label): ?><span class="legend-item" title="<?= e($label) ?>"><span class="legend-swatch" style="background:<?= e($typeColorMap[$type]) ?>" aria-hidden="true"></span><?= e($label) ?></span><?php endforeach; ?></div>
    <dialog id="calendar-event-dialog" class="calendar-event-dialog" aria-labelledby="calendar-event-title">
        <div class="calendar-event-head">
            <p class="calendar-event-type" data-calendar-event-type></p>
            <h2 id="calendar-event-title" data-calendar-event-title></h2>
        </div>
        <section class="calendar-event-description" data-calendar-event-description-section hidden>
            <h3>More information</h3>
            <p data-calendar-event-description></p>
        </section>
        <div class="calendar-event-actions">
            <button type="button" class="btn" data-calendar-event-close>Close</button>
        </div>
    </dialog>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.11/index.global.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('parish-calendar');
        if (typeof FullCalendar === 'undefined') { el.innerHTML = '<p class="empty-state">Calendar could not be loaded. See the parish office for event details.</p>'; return; }
        var dialog = document.getElementById('calendar-event-dialog');
        var closeButton = dialog.querySelector('[data-calendar-event-close]');
        closeButton.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) dialog.close();
        });
        var calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            buttonText: { prev: 'Previous', next: 'Next', today: 'Today', month: 'Month', list: 'List' },
            height: 'auto',
            displayEventTime: false,
            dayMaxEventRows: true,
            eventDisplay: 'block',
            fixedWeekCount: false,
            events: <?= json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            eventDidMount: function (info) {
                var details = info.event.extendedProps;
                var hasPublicDetails = details.publicDetails !== '';
                info.el.setAttribute('aria-label', details.displayTitle + ', ' + details.eventType + ', ' + details.displayDate + (hasPublicDetails ? '. Select to read more.' : '. No additional details posted.'));
                if (hasPublicDetails) {
                    var indicator = document.createElement('span');
                    indicator.className = 'calendar-event-has-details';
                    indicator.textContent = ' · Details';
                    indicator.setAttribute('aria-hidden', 'true');
                    info.el.appendChild(indicator);
                } else {
                    info.el.setAttribute('tabindex', '-1');
                }
            },
            eventClick: function (info) {
                var details = info.event.extendedProps;
                if (details.publicDetails === '') return;
                info.jsEvent.preventDefault();
                dialog.querySelector('[data-calendar-event-type]').textContent = details.eventType;
                dialog.querySelector('[data-calendar-event-title]').textContent = details.displayTitle;
                dialog.querySelector('[data-calendar-event-description]').textContent = details.publicDetails;
                dialog.querySelector('[data-calendar-event-description-section]').hidden = false;
                dialog.showModal();
            }
        });
        calendar.render();
    });
    </script>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
