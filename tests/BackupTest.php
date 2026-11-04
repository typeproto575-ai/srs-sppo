<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/google-drive-backup.php';
require_once __DIR__ . '/../includes/scheduled-backup-config.php';

final class BackupTest extends TestCase
{
    public function testScheduledBackupCompressesAndEncryptsSqlForRoundTrip(): void
    {
        $sql = str_repeat("-- fictional parish demo row\nINSERT INTO persons VALUES ('Demo');\n", 100);
        $passphrase = str_repeat('scheduled-backup-test-secret-', 2);

        $encrypted = encrypt_scheduled_backup($sql, $passphrase);
        $headerLine = strtok($encrypted['payload'], "\n");
        self::assertIsString($headerLine);
        $header = json_decode($headerLine, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('srs-sppo-backup-v2', $header['format']);
        self::assertSame('gzip', $header['compression']);
        self::assertSame(strlen($sql), $encrypted['sql_bytes']);
        self::assertLessThan($encrypted['sql_bytes'], $encrypted['compressed_bytes']);
        self::assertSame($sql, decrypt_backup_payload($encrypted['payload'], $passphrase));
    }

    public function testScheduledBackupRequiresLongPassphrase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        encrypt_scheduled_backup('SELECT 1;', 'too-short');
    }

    public function testScheduledBackupRejectsWrongPassphraseAndTamperedPayload(): void
    {
        $encrypted = encrypt_scheduled_backup('SELECT 1;', str_repeat('backup-test-secret-', 2));

        try {
            decrypt_backup_payload($encrypted['payload'], str_repeat('wrong-secret-', 3));
            self::fail('A wrong passphrase must not decrypt the backup.');
        } catch (RuntimeException $exception) {
            self::assertSame('Decryption failed; check the file and passphrase.', $exception->getMessage());
        }

        $tampered = $encrypted['payload'];
        $tampered[strlen($tampered) - 1] = $tampered[strlen($tampered) - 1] === 'x' ? 'y' : 'x';
        try {
            decrypt_backup_payload($tampered, str_repeat('backup-test-secret-', 2));
            self::fail('A modified authentication tag must not decrypt.');
        } catch (RuntimeException $exception) {
            self::assertSame('Decryption failed; check the file and passphrase.', $exception->getMessage());
        }
    }

    public function testDecryptionStillSupportsVersionOneExports(): void
    {
        $sql = "-- legacy export\nSELECT 1;\n";
        $passphrase = 'legacy-backup-passphrase';
        $salt = random_bytes(16);
        $nonce = random_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $iterations = 210000;
        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
        $tag = '';
        $ciphertext = openssl_encrypt($sql, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        self::assertNotFalse($ciphertext);

        $header = json_encode([
            'format' => 'srs-sppo-backup-v1',
            'algorithm' => 'AES-256-GCM',
            'kdf' => 'PBKDF2-SHA256',
            'iterations' => $iterations,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'tag_length' => strlen($tag),
        ], JSON_THROW_ON_ERROR);

        self::assertSame($sql, decrypt_backup_payload($header . "\n" . $ciphertext . $tag, $passphrase));
    }

    public function testRetentionDeletesOnlyBackupsOlderThanTheNewestSix(): void
    {
        $files = [];
        for ($month = 1; $month <= 8; $month++) {
            $files[] = [
                'id' => 'backup-' . $month,
                'name' => sprintf('srs-sppo-monthly-2026-%02d.sql.gz.enc', $month),
            ];
        }
        $files[] = [
            'id' => 'unrelated',
            'name' => 'parish-photo.jpg',
        ];
        $files[] = [
            'id' => 'invalid-month',
            'name' => 'srs-sppo-monthly-2026-13.sql.gz.enc',
        ];

        self::assertSame(
            ['backup-2', 'backup-1'],
            array_column(srs_monthly_backups_to_delete($files), 'id')
        );
    }

    public function testScheduledBackupsAreOptInAndCanBeDisabled(): void
    {
        self::assertFalse(srs_scheduled_backups_enabled(''));
        self::assertFalse(srs_scheduled_backups_enabled('false'));
        self::assertFalse(srs_scheduled_backups_enabled('0'));
        self::assertTrue(srs_scheduled_backups_enabled('true'));
        self::assertTrue(srs_scheduled_backups_enabled('1'));
    }

    public function testScheduledBackupSettingRejectsAmbiguousValues(): void
    {
        $this->expectException(RuntimeException::class);
        srs_scheduled_backups_enabled('sometimes');
    }
}
