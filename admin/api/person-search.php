<?php
/**
 * Admin-only JSON search for internal person identities.
 * Returns bounded result sets for mapper and record-linking controls.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

header('Content-Type: application/json; charset=utf-8');

$firstName = trim((string) ($_GET['first_name'] ?? ''));
$middleName = trim((string) ($_GET['middle_name'] ?? ''));
$lastName = trim((string) ($_GET['last_name'] ?? ''));
$suffix = trim((string) ($_GET['suffix'] ?? ''));
$birthDate = trim((string) ($_GET['birth_date'] ?? ''));
$query = trim((string) ($_GET['q'] ?? ''));

if ($query !== '') {
    $like = '%' . $query . '%';
    $stmt = $pdo->prepare(
        'SELECT p.id, p.first_name, p.middle_name, p.last_name, p.suffix, p.maiden_name, p.birth_date
         FROM persons p
         WHERE CONCAT_WS(" ", p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ?
            OR p.maiden_name LIKE ? OR p.birth_date LIKE ?
         ORDER BY p.last_name, p.first_name, p.middle_name, p.id
         LIMIT 25'
    );
    $stmt->execute([$like, $like, $like]);
    echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($firstName === '' && $middleName === '' && $lastName === '' && $suffix === '' && $birthDate === '') {
    echo json_encode(['results' => []]);
    exit;
}

$where = [];
$params = [];
foreach ([
    'first_name' => $firstName,
    'middle_name' => $middleName,
    'last_name' => $lastName,
    'suffix' => $suffix,
] as $column => $value) {
    if ($value !== '') {
        $where[] = "p.{$column} LIKE ?";
        $params[] = '%' . $value . '%';
    }
}

if ($birthDate !== '') {
    $date = DateTime::createFromFormat('!Y-m-d', $birthDate);
    if (!$date || $date->format('Y-m-d') !== $birthDate) {
        http_response_code(400);
        echo json_encode(['results' => [], 'error' => 'Invalid birth date.']);
        exit;
    }
    $where[] = 'p.birth_date = ?';
    $params[] = $birthDate;
}

$sql = 'SELECT p.id, p.first_name, p.middle_name, p.last_name, p.suffix, p.maiden_name, p.birth_date
        FROM persons p
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY p.last_name, p.first_name, p.middle_name, p.id
        LIMIT 25';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('Person finder failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['results' => [], 'error' => 'Search failed.']);
}
