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
$excludeFuneralRecordId = filter_var($_GET['exclude_funeral_record_id'] ?? 0, FILTER_VALIDATE_INT);
if (!parish_schedule_date_is_valid($date)
    || $date < date('Y-m-d')
    || $excludeEventId === false
    || $excludeEventId < 0
    || $excludeFuneralRecordId === false
    || $excludeFuneralRecordId < 0) {
    schedule_availability_response(422, ['error' => 'Choose a valid date from today onward.']);
}

try {
    $events = parish_schedule_day_events(
        $pdo,
        $date,
        (int) $excludeEventId,
        (int) $excludeFuneralRecordId
    );
    $displayEvents = array_map(static function (array $event): array {
        $start = isset($event['start_time']) && $event['start_time'] !== ''
            ? substr((string) $event['start_time'], 0, 5)
            : null;
        $end = isset($event['end_time']) && $event['end_time'] !== ''
            ? substr((string) $event['end_time'], 0, 5)
            : null;

        return [
            'label' => (string) $event['label'],
            'time' => $start === null ? 'Time not confirmed; day unavailable' : format_event_time_range($start, $end),
            'source' => (string) $event['source'],
        ];
    }, $events);

    schedule_availability_response(200, [
        'date' => $date,
        'slot_minutes' => 60,
        'events' => $displayEvents,
        'slots' => parish_schedule_available_slots($events),
    ]);
} catch (PDOException $exception) {
    error_log('Schedule availability lookup failed: ' . $exception->getMessage());
    schedule_availability_response(500, ['error' => 'Schedule availability could not be loaded. Please try again.']);
}
