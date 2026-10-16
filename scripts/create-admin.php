<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    return trim((string) fgets(STDIN));
}

$fullName = prompt('Full name: ');
$username = prompt('Username: ');
$email = prompt('Email: ');
$password = prompt('Password (minimum 12 characters): ');
$confirmation = prompt('Confirm password: ');

if ($fullName === '' || $username === '' || $email === '') {
    fwrite(STDERR, "Full name, username, and email are required.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "The administrator password must be at least 12 characters.\n");
    exit(1);
}
if ($password !== $confirmation) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Enter a valid email address.\n");
    exit(1);
}

$check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
$check->execute([$username, $email]);
if ($check->fetch()) {
    fwrite(STDERR, "That username or email is already in use.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    'INSERT INTO users (full_name, username, email, password_hash, role, is_active)
     VALUES (?, ?, ?, ?, ?, 1)'
);
$stmt->execute([
    $fullName,
    $username,
    $email,
    password_hash($password, PASSWORD_DEFAULT),
    'parish_admin',
]);

fwrite(STDOUT, "Parish administrator account created.\n");
