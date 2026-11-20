<?php
/**
 * Read-only dashboard data for the Angular staff workspace.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'auth']);
    exit;
}
require_role('secretary');

$pendingStatuses = ['new', 'matched', 'for_signature'];
$pendingPlaceholders = implode(',', array_fill(0, count($pendingStatuses), '?'));
$pendingStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM certificate_requests WHERE status IN ({$pendingPlaceholders})"
);
$pendingStmt->execute($pendingStatuses);

$counts = [
    'pending_certificates' => (int) $pendingStmt->fetchColumn(),
    'new_applications' => (int) $pdo->query(
        "SELECT COUNT(*) FROM sacrament_applications WHERE status = 'new'"
    )->fetchColumn(),
    'ready_for_pickup' => (int) $pdo->query(
        "SELECT COUNT(*) FROM certificate_requests WHERE status = 'ready'"
    )->fetchColumn(),
    'applications_in_progress' => (int) $pdo->query(
        "SELECT COUNT(*) FROM sacrament_applications
         WHERE status IN ('reviewing','requirements_pending','approved','scheduled')"
    )->fetchColumn(),
];

$query = trim((string) ($_GET['q'] ?? ''));
$searchResults = [];
if ($query !== '') {
    $matches = sd_search_persons($pdo, $query, 20);
    foreach ($matches as $person) {
        $searchResults[] = [
            'id' => (int) $person['id'],
            'full_name' => (string) $person['full_name'],
            'birth_date' => $person['birth_date'] ?: null,
            'birth_place' => $person['birth_place'] ?: null,
            'has_baptism' => (bool) $person['has_baptism'],
            'has_first_communion' => (bool) $person['has_first_communion'],
            'has_confirmation' => (bool) $person['has_confirmation'],
            'has_marriage' => (bool) $person['has_marriage'],
        ];
    }
}

echo json_encode([
    'counts' => $counts,
    'registry_counts' => sd_count_records_by_type($pdo),
    'search_results' => $searchResults,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
