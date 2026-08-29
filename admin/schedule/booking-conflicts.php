<?php
/**
 * =====================================================================
 * SPPO-IS BOOKING CONFLICT REVIEW (admin)
 * =====================================================================
 * Purpose: Surfaces marriage applications whose preferred wedding date
 *          collides with an existing calendar event, so the secretary
 *          can resolve the clash manually (reschedule or confirm).
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
$stmt = $pdo->query(
    "SELECT sa.id AS application_id, sa.tracking_number, sa.status,
            ma.groom_full_name, ma.bride_full_name, ma.preferred_wedding_date
     FROM sacrament_applications sa
     JOIN marriage_applications ma ON ma.application_id = sa.id
     WHERE sa.status NOT IN ('completed', 'rejected')
     ORDER BY ma.preferred_wedding_date ASC"
);
$applications = $stmt->fetchAll();

foreach ($applications as &$app) {
    $s = $pdo->prepare(
        "SELECT title, event_type FROM schedule_events WHERE event_date = ? AND event_type IN ('wedding','mass')"
    );
    $s->execute([$app['preferred_wedding_date']]);
    $app['conflicts'] = $s->fetchAll();
}
unset($app);

function humanize_label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <h1>Booking Conflict Review</h1>
        <p class="field-hint">Compares each pending marriage application's preferred wedding date against the calendar. Nothing here is auto-resolved — the secretary or priest confirms manually and adjusts the calendar as needed.</p>

        <?php if (empty($applications)): ?>
            <p>No open marriage applications to review.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tracking No.</th>
                    <th>Couple</th>
                    <th>Preferred Date</th>
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
                        <td><span class="status-badge status-<?= e($app['status']) ?>"><?= e(humanize_label($app['status'])) ?></span></td>
                        <td>
                            <?php if (empty($app['conflicts'])): ?>
                                None
                            <?php else: ?>
                                <?php foreach ($app['conflicts'] as $c): ?>
                                    <span class="flag-badge"><?= e($c['title']) ?> (<?= e(ucfirst($c['event_type'])) ?>)</span><br>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <p style="margin-top:1.5rem;"><a href="<?= $basePath ?>/admin/schedule/calendar.php" class="btn btn-secondary btn-small">Go to Calendar</a></p>
    <?php require_once __DIR__ . '/../../includes/admin-footer.php';