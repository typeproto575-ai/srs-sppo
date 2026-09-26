<?php
/**
 * Admin-only JSON search for non-archived Baptismal register records.
 * Used by record-linking controls; all query values remain parameterized.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role('secretary');

header('Content-Type: application/json; charset=utf-8');

$name = trim((string) ($_GET['name'] ?? ''));
$birthDate = trim((string) ($_GET['birth_date'] ?? ''));
$fatherName = trim((string) ($_GET['father_name'] ?? ''));
$motherName = trim((string) ($_GET['mother_name'] ?? ''));
$personId = (int) ($_GET['person_id'] ?? 0);

if ($name === '' && $birthDate === '' && $fatherName === '' && $motherName === '' && $personId <= 0) {
    echo json_encode(['results' => []]);
    exit;
}

$where = ['br.is_archived = 0'];
$whereParams = [];
$scoreParams = [];
$scoreParts = [];

if ($name !== '') {
    $scoreParts[] = "(CASE WHEN br.child_full_name LIKE ? THEN 5 ELSE 0 END)";
    $scoreParams[] = '%' . $name . '%';
}
if ($birthDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    $scoreParts[] = '(CASE WHEN br.birth_date = ? THEN 4 ELSE 0 END)';
    $scoreParams[] = $birthDate;
}
if ($fatherName !== '') {
    $scoreParts[] = '(CASE WHEN br.father_name LIKE ? THEN 2 ELSE 0 END)';
    $scoreParams[] = '%' . $fatherName . '%';
}
if ($motherName !== '') {
    $scoreParts[] = '(CASE WHEN br.mother_name LIKE ? OR br.mother_maiden_name LIKE ? THEN 2 ELSE 0 END)';
    $scoreParams[] = '%' . $motherName . '%';
    $scoreParams[] = '%' . $motherName . '%';
}
if ($personId > 0) {
    $scoreParts[] = '(CASE WHEN br.person_id = ? THEN 10 ELSE 0 END)';
    $scoreParams[] = $personId;
}

$matchWhere = [];
$matchParams = [];
if ($name !== '') {
    $matchWhere[] = 'br.child_full_name LIKE ?';
    $matchParams[] = '%' . $name . '%';
}
if ($birthDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    $matchWhere[] = 'br.birth_date = ?';
    $matchParams[] = $birthDate;
}
if ($fatherName !== '') {
    $matchWhere[] = 'br.father_name LIKE ?';
    $matchParams[] = '%' . $fatherName . '%';
}
if ($motherName !== '') {
    $matchWhere[] = '(br.mother_name LIKE ? OR br.mother_maiden_name LIKE ?)';
    $matchParams[] = '%' . $motherName . '%';
    $matchParams[] = '%' . $motherName . '%';
}
if ($personId > 0) {
    $matchWhere[] = 'br.person_id = ?';
    $matchParams[] = $personId;
}
if ($matchWhere) {
    $where[] = '(' . implode(' OR ', $matchWhere) . ')';
    $whereParams = $matchParams;
}

$scoreSql = $scoreParts ? implode(' + ', $scoreParts) : '0';
$sql = "SELECT br.id, br.child_full_name, br.birth_date, br.baptism_date,
               br.father_name, br.mother_name, br.mother_maiden_name,
               br.book_no, br.page_no, br.line_no, br.person_id,
               ({$scoreSql}) AS match_score
        FROM baptismal_records br
        WHERE " . implode(' AND ', $where) . "
        ORDER BY match_score DESC, br.child_full_name ASC, br.id DESC
        LIMIT 25";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($scoreParams, $whereParams));
    echo json_encode(['results' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['results' => [], 'error' => 'Search failed.']);
}
