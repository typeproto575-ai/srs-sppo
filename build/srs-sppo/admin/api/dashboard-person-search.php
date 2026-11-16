<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';
require_role('secretary');

header('Content-Type: application/json; charset=utf-8');

$query = trim((string) ($_GET['q'] ?? ''));
if (strlen($query) > 120) {
    http_response_code(400);
    echo json_encode(['error' => 'Search text must be 120 characters or fewer.']);
    exit;
}

try {
    $people = sd_search_persons($pdo, $query);
    $results = array_map(static function (array $person): array {
        return [
            'id' => (int) $person['id'],
            'full_name' => (string) $person['full_name'],
            'birth_date' => $person['birth_date'] ?? null,
            'birth_place' => $person['birth_place'] ?? null,
            'has_baptism' => !empty($person['has_baptism']),
            'has_first_communion' => !empty($person['has_first_communion']),
            'has_confirmation' => !empty($person['has_confirmation']),
            'has_marriage' => !empty($person['has_marriage']),
        ];
    }, $people);

    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    error_log('Dashboard person search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search is temporarily unavailable. Please try again.']);
}
