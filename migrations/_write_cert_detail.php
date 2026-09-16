<?php
// Writes the complete certificate-detail.php file.
// Run: php migrations/_write_cert_detail.php && php -l admin/requests/certificate-detail.php
$dest = __DIR__ . '/../admin/requests/certificate-detail.php';
$tmp  = __DIR__ . '/../admin/requests/certificate-detail.tmp';

// Build in parts to avoid escaping issues.
$parts = [];

$parts[] = '<?php
/**
 * =====================================================================
 * SRS-SPPO CERTIFICATE REQUEST DETAIL (admin)
 * =====================================================================
 * Purpose: Single-request drill-down page for certificate_requests.
 *
 * Access: Secretary only.
 * =====================================================================
 */

require_once __DIR__ . \'/../../config/database.php\';
require_once __DIR__ . \'/../../includes/functions.php\';
require_once __DIR__ . \'/../../includes/auth.php\';
require_role(\'secretary\');

$basePath  = $basePath ?? base_path();
$pageTitle = \'Certificate Request\';
$notice    = null;

const CERT_EXT = [
    \'baptismal\'       => [\'table\' => \'baptismal_certificate_requests\',       \'name_col\' => \'child_full_name\',      \'record_table\' => \'baptismal_records\',       \'record_name\' => \'child_full_name\'],
    \'confirmation\'    => [\'table\' => \'confirmation_certificate_requests\',    \'name_col\' => \'confirmand_full_name\',  \'record_table\' => \'confirmation_records\',    \'record_name\' => \'confirmand_full_name\'],
    \'first_communion\' => [\'table\' => \'first_communion_certificate_requests\', \'name_col\' => \'communicant_full_name\', \'record_table\' => \'first_communion_records\', \'record_name\' => \'communicant_full_name\'],
    \'marriage\'        => [\'table\' => \'marriage_certificate_requests\',        \'name_col\' => \'party_a_full_name\',     \'record_table\' => \'marriage_records\',        \'record_name\' => \'husband_full_name\'],
];

$requestId = (int) ($_GET[\'id\'] ?? 0);
if (!$requestId) { redirect_to(\'/admin/requests/queue.php\'); exit; }

function humanize_label(string $s): string
{
    return ucwords(str_replace(\'_\', \' \', $s));
}

// ---- Load request data ----';

$parts[] = '
$stmt = $pdo->prepare(\'SELECT * FROM certificate_requests WHERE id = ?\');
$stmt->execute([$requestId]);
$req = $stmt->fetch();
if (!$req) { redirect_to(\'/admin/requests/queue.php\'); exit; }

$config       = CERT_EXT[$req[\'sacrament_type\']] ?? null;
$extRow       = null;
$matchedRecord = null;
if ($config) {
    $stmt = $pdo->prepare("SELECT * FROM {$config[\'table\']} WHERE request_id = ?");
    $stmt->execute([$requestId]);
    $extRow = $stmt->fetch();
    if ($extRow && !empty($extRow[\'matched_record_id\'])) {
        $stmt = $pdo->prepare("SELECT * FROM {$config[\'record_table\']} WHERE id = ?");
        $stmt->execute([$extRow[\'matched_record_id\']]);
        $matchedRecord = $stmt->fetch();
    }
}

$processedByUser = null;
if (!empty($req[\'processed_by\'])) {
    $stmt = $pdo->prepare(\'SELECT full_name FROM users WHERE id = ?\');
    $stmt->execute([$req[\'processed_by\']]);
    $processedByUser = $stmt->fetchColumn();
}

// ---- Audit history ----
$auditStmt = $pdo->prepare(
    \'SELECT al.*, u.full_name AS user_name FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.target_table = ? AND al.target_id = ? ORDER BY al.created_at DESC\'
);
$auditStmt->execute([\'certificate_requests\', $requestId]);
$auditHistory = $auditStmt->fetchAll();
';

$content = implode("\n", $parts);
echo "Part 1: " . strlen($content) . " bytes\n";

// Write first part to temp file - we will append the rest in subsequent steps.
// For now, just show we can build it.
file_put_contents($tmp, $content);
echo "Written part 1 to temp\n";
