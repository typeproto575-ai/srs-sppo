<?php
/**
 * =====================================================================
 * NOTIFICATIONS API (admin header bell)
 * =====================================================================
 * GET  → JSON snapshot for the bell:
 *        { unread_count, upcoming_events_count, items: [ {kind, id, type,
 *          title, message, link, is_read, created_at} ] }
 *        unread_count = unread notifications only.
 *        upcoming_events_count = informational today/tomorrow reminders only.
 *        items = upcoming events (live-queried, most urgent first)
 *                followed by the 10 newest unread notifications.
 *
 * POST → action=mark_read&id=N    mark one notification read
 *        action=mark_all_read     mark every notification read
 *        Either way the response is the same fresh snapshot, so the
 *        caller can just re-render from it.
 *
 * Both GET and POST require a logged-in secretary. POST additionally
 * requires the session CSRF token (sent by main.js from the bell's
 * data-csrf attribute).
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // starts the session

header('Content-Type: application/json; charset=utf-8');

// JSON answer instead of the HTML login redirect for expired sessions —
// a fetch() would otherwise follow the redirect and choke on login markup.
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'auth']);
    exit;
}
require_role('secretary');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?');
            $stmt->execute([$id]);
        }
    } elseif ($action === 'mark_all_read') {
        $pdo->exec('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0');
    }
}

/**
 * Build the notification snapshot shared by GET and POST responses.
 */
function notifications_snapshot(PDO $pdo): array
{
    // Unread count (stored notifications only).
    $unread = (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();

        // The header bell is an actionable inbox, so read notifications stay in
        // the archive but are omitted here. The archive page remains unchanged.
    $rows = $pdo->query(
        'SELECT id, type, title, message, link, is_read, created_at
         FROM notifications
            WHERE is_read = 0
            ORDER BY created_at DESC
         LIMIT 10'
    )->fetchAll();

    $prefix = base_path();
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'kind'       => 'notif',
            'id'         => (int) $r['id'],
            'type'       => $r['type'],
            'title'      => $r['title'],
            'message'    => $r['message'],
            'link'       => $prefix . $r['link'],
            'is_read'    => (int) $r['is_read'],
            'created_at' => str_replace(' ', 'T', (string) $r['created_at']),
        ];
    }

    // Upcoming events (today + tomorrow), queried live so they show
    // without needing a scheduler. These are informational reminders only
    // and have no read/unread state of their own; they must never keep the
    // bell badge lit when there are zero real notifications to review.
    $evStmt = $pdo->query(
        "SELECT id, event_type, title, event_date, start_time
         FROM schedule_events
         WHERE event_date IN (CURDATE(), CURDATE() + INTERVAL 1 DAY)
         ORDER BY event_date ASC, start_time ASC
         LIMIT 5"
    );
    $events = [];
    foreach ($evStmt->fetchAll() as $e) {
        $dateLabel = ($e['event_date'] === date('Y-m-d')) ? 'Today' : 'Tomorrow';
        $time = $e['start_time'] ? date('g:i A', strtotime((string) $e['start_time'])) : '';
        $events[] = [
            'kind'       => 'event',
            'id'         => 'event-' . (int) $e['id'],
            'type'       => 'event_reminder',
            'title'      => $dateLabel . ': ' . $e['title'],
            'message'    => ucfirst($e['event_type']) . ($time !== '' ? ' at ' . $time : ''),
            'link'       => $prefix . '/admin/schedule/calendar.php',
            'is_read'    => 0,
            'created_at' => $e['event_date'] . 'T' . ($e['start_time'] ?: '00:00:00'),
        ];
    }

    return [
        'unread_count'          => $unread,
        'upcoming_events_count' => count($events),
        'items'                 => array_merge($events, $items),
    ];
}

echo json_encode(notifications_snapshot($pdo), JSON_UNESCAPED_SLASHES);
exit;