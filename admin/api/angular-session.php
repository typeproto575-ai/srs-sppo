<?php
/**
 * Session snapshot used by the Angular staff workspace.
 * The existing PHP session remains the source of authentication.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['authenticated' => false]);
    exit;
}

require_role('secretary');

echo json_encode([
    'authenticated' => true,
    'csrf_token' => csrf_token(),
    'user' => [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'full_name' => (string) $user['full_name'],
        'role' => (string) $user['role'],
    ],
], JSON_UNESCAPED_SLASHES);
