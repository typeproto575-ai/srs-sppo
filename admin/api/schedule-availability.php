<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('secretary');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function schedule_availability_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    schedule_availability_response(405, ['error' => 'Use GET to view schedule availability.']);
}

$date = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : '';
$excludeEventId = filter_var($_GET['exclude_event_id'] ?? 0, FILTER_VALIDATE_INT);
$eventType = is_string($_GET['event_type'] ?? null) ? trim($_GET['event_type']) : '';
$dateError = application_schedule_input_error($date, '', '');
if ($dateError !== null || $excludeEventId === false || $excludeEventId < 0) {
    schedule_availability_response(422, ['error' => $dateError ?? 'Choose a valid date from today onward.']);
}
if (!in_array($eventType, ['baptism', 'wedding', 'mass', 'other'], true)) {
    schedule_availability_response(422, ['error' => 'Choose a valid event type.']);
}

try {
    $eventsStmt = $pdo->prepare(
        'SELECT id, event_type, title, public_details, start_time, end_time
         FROM schedule_events
         WHERE event_date = ? AND id <> ?
         ORDER BY start_time, id'
    );
    $eventsStmt->execute([$date, (int) $excludeEventId]);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
    $displayEvents = array_map(static function (array $event): array {
        $start = isset($event['start_time']) && $event['start_time'] !== ''
            ? substr((string) $event['start_time'], 0, 5)
            : null;
        $end = isset($event['end_time']) && $event['end_time'] !== ''
            ? substr((string) $event['end_time'], 0, 5)
            : null;

        return [
            'label' => (string) $event['title'],
            'time' => $start === null ? 'Time not confirmed' : format_event_time_range($start, $end),
        ];
    }, $events);

    schedule_availability_response(200, [
        'date' => $date,
        'slot_minutes' => 60,
        'events' => $displayEvents,
        'slots' => application_schedule_available_slots($eventType, $events),
    ]);
} catch (PDOException $exception) {
    error_log('Schedule availability lookup failed: ' . $exception->getMessage());
    schedule_availability_response(500, ['error' => 'Schedule availability could not be loaded. Please try again.']);
}
