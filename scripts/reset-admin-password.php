<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php'; // for client_ip()

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    return trim((string) fgets(STDIN));
}

// -----------------------------------------------------------------
// 1️⃣ Determine the username (defaults to 'admin')
// -----------------------------------------------------------------
$username = $argv[1] ?? 'admin';

// -----------------------------------------------------------------
// 2️⃣ Determine the new password
// -----------------------------------------------------------------
if (isset($argv[2])) {
    $password = $argv[2];
    $confirm  = $argv[3] ?? $argv[2];
} else {
    $password = prompt('Enter your new password (minimum 12 characters): ');
    $confirm  = prompt('Confirm new password: ');
}

if (strlen($password) < 12) {
    fwrite(STDERR, "The new password must be at least 12 characters.\n");
    exit(1);
}
if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

// -----------------------------------------------------------------
// 3️⃣ Verify the admin account exists and is active
// -----------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT id, username, is_active FROM users WHERE username = ? AND role = "parish_admin" LIMIT 1'
);
$stmt->execute([$username]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    fwrite(STDERR, "Admin (parish_admin) account not found or is inactive.\n");
    exit(1);
}

echo "Found active admin account '{$admin['username']}' (ID {$admin['id']}).\n";

// -----------------------------------------------------------------
// 4️⃣ Clear any recent rate‑limit lock‑out for this user
// -----------------------------------------------------------------
// The login routine counts failures in the audit_logs table
// (action = 'rate_limit_attempt', description = 'user:admin').
// Removing those rows within the last 15 minutes will lift the lock‑out.
$clearStmt = $pdo->prepare(
    "DELETE FROM audit_logs
     WHERE action = 'rate_limit_attempt'
       AND description = 'user:{$username}'
       AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
);
$clearStmt->execute();

// Also clear IP‑based lock‑out for the current client (if detected)
$ip = client_ip();
if ($ip && $ip !== 'unknown') {
    $clearIpStmt = $pdo->prepare(
        "DELETE FROM audit_logs
         WHERE action = 'rate_limit_attempt'
           AND description = 'ip:{$ip}'
           AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $clearIpStmt->execute();
}

echo "Cleared any recent login lock‑out attempts for '{$username}'.\n";

// -----------------------------------------------------------------
// 5️⃣ Update the password hash
// -----------------------------------------------------------------
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    'UPDATE users
     SET password_hash = ?, updated_at = NOW()
     WHERE username = ? AND role = "parish_admin"'
);
$stmt->execute([$hash, $username]);

echo "Password updated successfully.\n";
echo "You can now log in at http://localhost/srs-sppo/admin/login.php\n";
