<?php
/**
 * Admin-only JSON search for Baptismal register records.
 * Used by record-linking controls; all query values remain parameterized.
 */
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/functions.php";
require_once __DIR__ . "/../../includes/auth.php";

header("Content-Type: application/json; charset=utf-8");

if (!current_user()) {
    http_response_code(401);
    echo json_encode(["results" => []]);
    exit;
}
if ((string) (current_user()["role"] ?? "") !== "secretary") {
    http_response_code(403);
    echo json_encode(["results" => []]);
    exit;
}

function baptismal_like_term(string $value): string
{
    $escaped = str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value);
    return "%" . $escaped . "%";
}

$name = trim((string) ($_GET["name"] ?? ""));
$birthDate = trim((string) ($_GET["birth_date"] ?? ""));
$fatherName = trim((string) ($_GET["father_name"] ?? ""));
$motherName = trim((string) ($_GET["mother_name"] ?? ""));
$personId = (int) ($_GET["person_id"] ?? 0);

if ($name !== "" && mb_strlen($name) < 2) {
    $name = "";
}
if ($fatherName !== "" && mb_strlen($fatherName) < 2) {
    $fatherName = "";
}
if ($motherName !== "" && mb_strlen($motherName) < 2) {
    $motherName = "";
}

if ($name === "" && $birthDate === "" && $fatherName === "" && $motherName === "" && $personId <= 0) {
    echo json_encode(["results" => []]);
    exit;
}

$where = ["sr.sacrament_type = 'baptism'"];
$whereParams = [];
$scoreParams = [];
$scoreParts = [];

if ($name !== "") {
    $scoreParts[] = "(CASE WHEN CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ? THEN 5 ELSE 0 END)";
    $scoreParams[] = baptismal_like_term($name);
}
if ($birthDate !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    $scoreParts[] = "(CASE WHEN p.birth_date = ? THEN 4 ELSE 0 END)";
    $scoreParams[] = $birthDate;
}
if ($fatherName !== "") {
    $scoreParts[] = "(CASE WHEN p.father_name LIKE ? THEN 2 ELSE 0 END)";
    $scoreParams[] = baptismal_like_term($fatherName);
}
if ($motherName !== "") {
    $scoreParts[] = "(CASE WHEN p.mother_maiden_name LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(sr.details, '$.mother_name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(sr.details, '$.mother_full_name')) LIKE ? THEN 2 ELSE 0 END)";
    $scoreParams[] = baptismal_like_term($motherName);
    $scoreParams[] = baptismal_like_term($motherName);
    $scoreParams[] = baptismal_like_term($motherName);
}
if ($personId > 0) {
    $scoreParts[] = "(CASE WHEN sr.person_id = ? THEN 10 ELSE 0 END)";
    $scoreParams[] = $personId;
}

$matchWhere = [];
$matchParams = [];
if ($name !== "") {
    $matchWhere[] = "CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ?";
    $matchParams[] = baptismal_like_term($name);
}
if ($birthDate !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    $matchWhere[] = "p.birth_date = ?";
    $matchParams[] = $birthDate;
}
if ($fatherName !== "") {
    $matchWhere[] = "p.father_name LIKE ?";
    $matchParams[] = baptismal_like_term($fatherName);
}
if ($motherName !== "") {
    $matchWhere[] = "(p.mother_maiden_name LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(sr.details, '$.mother_name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(sr.details, '$.mother_full_name')) LIKE ?)";
    $matchParams[] = baptismal_like_term($motherName);
    $matchParams[] = baptismal_like_term($motherName);
    $matchParams[] = baptismal_like_term($motherName);
}
if ($personId > 0) {
    $matchWhere[] = "sr.person_id = ?";
    $matchParams[] = $personId;
}
if ($matchWhere) {
    $where[] = "(" . implode(" OR ", $matchWhere) . ")";
    $whereParams = $matchParams;
}

$scoreSql = $scoreParts ? implode(" + ", $scoreParts) : "0";
$sql = "SELECT sr.id,
               CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) AS child_full_name,
               p.birth_date AS birth_date,
               sr.event_date AS baptism_date,
               p.father_name AS father_name,
               COALESCE(JSON_UNQUOTE(JSON_EXTRACT(sr.details, '$.mother_name')),
                        JSON_UNQUOTE(JSON_EXTRACT(sr.details, '$.mother_full_name')),
                        p.mother_maiden_name) AS mother_name,
               p.mother_maiden_name AS mother_maiden_name,
               sr.book_no, sr.page_no, sr.line_no, sr.person_id,
               ({$scoreSql}) AS match_score
        FROM sacrament_records sr
        INNER JOIN persons p ON p.id = sr.person_id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY match_score DESC, child_full_name ASC, sr.id DESC
        LIMIT 25";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($scoreParams, $whereParams));
    echo json_encode(["results" => $stmt->fetchAll()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["results" => [], "error" => "Search failed."]);
}
