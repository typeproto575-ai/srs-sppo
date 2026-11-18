<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/funeral-data.php';

require_role('secretary');
header('Content-Type: application/json; charset=utf-8');

function funeral_person_details_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    funeral_person_details_response(405, ['error' => 'Use GET to load linked person details.']);
}

$personId = filter_var($_GET['person_id'] ?? null, FILTER_VALIDATE_INT);
if ($personId === false || $personId === null || $personId < 1) {
    funeral_person_details_response(400, ['error' => 'Select a valid person profile.']);
}

try {
    $personStmt = $pdo->prepare('SELECT id FROM persons WHERE id = ? LIMIT 1');
    $personStmt->execute([(int) $personId]);
    if ($personStmt->fetchColumn() === false) {
        funeral_person_details_response(404, ['error' => 'The selected person profile could not be found.']);
    }

    $marriage = funeral_person_marriage_suggestion($pdo, (int) $personId);
    funeral_person_details_response(200, [
        'person_id' => (int) $personId,
        'has_marriage_record' => $marriage['has_marriage_record'],
        'spouse_name' => $marriage['spouse_name'],
        'civil_status' => $marriage['has_marriage_record'] ? 'married' : '',
        'sacraments_received' => funeral_suggested_sacraments($pdo, (int) $personId),
    ]);
} catch (Throwable $e) {
    error_log('Funeral person details lookup failed: ' . $e->getMessage());
    funeral_person_details_response(500, [
        'error' => 'Linked parish details could not be loaded. Complete the fields manually or try again.',
    ]);
}
