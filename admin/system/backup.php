<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/backup.php';
require_once __DIR__ . '/../../includes/auth.php';

require_role('secretary');

$pageTitle = 'Database Backup / Export';
$error = null;
$backupCounts = [];
$backupPassphrase = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    if (!csrf_verify()) {
        $error = 'The security token expired. Please refresh the page and try again.';
    } else {
        $user = current_user();
        $attemptKey = 'backup_reauth:' . (int) $user['id'] . ':' . client_ip();
        if (too_many_attempts($pdo, $attemptKey, 5, 15)) {
            $error = 'Too many failed re-authentication attempts. Please try again later.';
        } else {
            $password = (string) ($_POST['password'] ?? '');
            $backupPassphrase = (string) ($_POST['backup_passphrase'] ?? '');
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND role = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([(int) $user['id'], 'secretary']);
            $authRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$authRow || !password_verify($password, (string) $authRow['password_hash'])) {
                record_attempt($pdo, $attemptKey);
                $error = 'Your staff password is incorrect.';
            } elseif (mb_strlen($backupPassphrase) < 12) {
                $error = 'Use a backup passphrase of at least 12 characters.';
            } elseif (!function_exists('openssl_encrypt') || !function_exists('openssl_cipher_iv_length')) {
                $error = 'OpenSSL is required to encrypt backups.';
            } else {
                $backup = export_database_sql($pdo);
                $cipher = 'aes-256-gcm';
                $salt = random_bytes(16);
                $nonce = random_bytes(openssl_cipher_iv_length($cipher));
                $iterations = 210000;
                $key = hash_pbkdf2('sha256', $backupPassphrase, $salt, $iterations, 32, true);
                $tag = '';
                $ciphertext = openssl_encrypt($backup['sql'], $cipher, $key, OPENSSL_RAW_DATA, $nonce, $tag);
                if ($ciphertext === false || strlen($tag) !== 16) {
                    throw new RuntimeException('Could not encrypt the database backup.');
                }

                $header = json_encode([
                    'format' => 'srs-sppo-backup-v1',
                    'algorithm' => 'AES-256-GCM',
                    'kdf' => 'PBKDF2-SHA256',
                    'iterations' => $iterations,
                    'salt' => base64_encode($salt),
                    'nonce' => base64_encode($nonce),
                    'tag_length' => strlen($tag),
                ], JSON_THROW_ON_ERROR) . "\n";
                $payload = $header . $ciphertext . $tag;
                log_activity(
                    $pdo,
                    (int) $user['id'],
                    'database_backup_exported',
                    null,
                    null,
                    sprintf('tables=%d bytes=%d', $backup['table_count'], strlen($payload))
                );

                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="srs-sppo-backup-' . date('Y-m-d') . '.sql.enc"');
                header('Content-Length: ' . strlen($payload));
                echo $payload;
                exit;
            }
        }
    }
}

$sacramentTypes = ['baptism', 'first_communion', 'confirmation', 'marriage'];
foreach ($sacramentTypes as $sacramentType) {
    $backupCounts["sacrament_records ({$sacramentType})"] = 0;
}

$stmt = $pdo->query(
    'SELECT sacrament_type, COUNT(*) AS row_count
     FROM sacrament_records
     GROUP BY sacrament_type'
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $sacramentType = (string) $row['sacrament_type'];
    $backupCounts["sacrament_records ({$sacramentType})"] = (int) $row['row_count'];
}

$backupCounts['persons'] = (int) $pdo->query('SELECT COUNT(*) FROM persons')->fetchColumn();
$backupCounts['sacrament_participants'] = (int) $pdo->query('SELECT COUNT(*) FROM sacrament_participants')->fetchColumn();
$backupCounts['certificate_requests'] = (int) $pdo->query('SELECT COUNT(*) FROM certificate_requests')->fetchColumn();
$backupCounts['sacrament_applications'] = (int) $pdo->query('SELECT COUNT(*) FROM sacrament_applications')->fetchColumn();
$backupCounts['schedule_events'] = (int) $pdo->query('SELECT COUNT(*) FROM schedule_events')->fetchColumn();
$backupCounts['announcements'] = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
$backupCounts['audit_logs'] = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$backupCounts['users'] = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

$extraHead = '';
?>
<?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
        <h1>Database Backup / Export</h1>
        <p class="field-hint">The encrypted export excludes password/hash columns, password-reset tokens, submission rate limits, and email delivery logs.</p>
        <p class="field-hint"><strong>Backup note:</strong> Store backups on an external drive, keep at least two copies, and follow the parish's approved retention policy when disposing of old copies.</p>
        <?php if ($error !== null): ?>
            <p class="form-error"><?= e($error) ?></p>
        <?php endif; ?>

        <div class="table-scroll">
        <table class="admin-table" style="max-width:500px;">
            <thead><tr><th>Table</th><th>Rows</th></tr></thead>
            <tbody>
                <?php foreach ($backupCounts as $table => $count): ?>
                    <tr><td><?= e($table) ?></td><td><?= $count ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <form autocomplete="off" method="post" style="margin-top:1.5rem;">
            <?= csrf_field() ?>
            <label>Administrator password
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <label>Backup passphrase
                <input type="password" name="backup_passphrase" autocomplete="new-password" minlength="12" required>
            </label>
            <button type="submit" name="download" value="1" class="btn">Download Encrypted Backup (.sql.enc)</button>
        </form>
        <p class="field-hint">OpenSSL AES-256-GCM encryption is used. The passphrase is never stored or logged.</p>
<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
