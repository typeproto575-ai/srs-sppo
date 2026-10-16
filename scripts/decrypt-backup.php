<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/decrypt-backup.php <backup.sql.enc> <passphrase>\n");
    exit(1);
}

$inputPath = $argv[1];
$passphrase = $argv[2];
if (!is_file($inputPath) || !is_readable($inputPath)) {
    fwrite(STDERR, "Backup file is not readable.\n");
    exit(1);
}

$contents = file_get_contents($inputPath);
$headerEnd = strpos((string) $contents, "\n");
if ($contents === false || $headerEnd === false) {
    fwrite(STDERR, "Invalid backup header.\n");
    exit(1);
}

$header = json_decode(substr($contents, 0, $headerEnd), true);
if (!is_array($header)
    || ($header['format'] ?? '') !== 'srs-sppo-backup-v1'
    || ($header['algorithm'] ?? '') !== 'AES-256-GCM'
    || ($header['kdf'] ?? '') !== 'PBKDF2-SHA256'
    || !isset($header['iterations'], $header['salt'], $header['nonce'], $header['tag_length'])) {
    fwrite(STDERR, "Unsupported backup format.\n");
    exit(1);
}

$salt = base64_decode((string) $header['salt'], true);
$nonce = base64_decode((string) $header['nonce'], true);
$tagLength = (int) $header['tag_length'];
$encrypted = substr($contents, $headerEnd + 1);
if ($salt === false || $nonce === false || $tagLength < 12 || $tagLength > 16 || strlen($encrypted) <= $tagLength) {
    fwrite(STDERR, "Invalid backup encryption metadata.\n");
    exit(1);
}

$tag = substr($encrypted, -$tagLength);
$ciphertext = substr($encrypted, 0, -$tagLength);
$key = hash_pbkdf2('sha256', $passphrase, $salt, (int) $header['iterations'], 32, true);
$sql = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
if ($sql === false) {
    fwrite(STDERR, "Decryption failed; check the file and passphrase.\n");
    exit(1);
}

$outputPath = preg_replace('/\.enc$/i', '', $inputPath);
if ($outputPath === null || $outputPath === $inputPath || file_put_contents($outputPath, $sql) === false) {
    fwrite(STDERR, "Could not write the decrypted SQL file.\n");
    exit(1);
}

fwrite(STDOUT, "Decrypted backup written to {$outputPath}\n");
