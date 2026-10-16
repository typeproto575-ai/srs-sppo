<?php
/**
 * =====================================================================
 * SRS-SPPO PUBLIC CERTIFICATE VERIFICATION
 * =====================================================================
 * Purpose: Allow the public to verify a certificate's authenticity using
 *           a unique token (UUIDv4) found on the printed document.
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$basePath = base_path();
$token = trim((string) ($_GET['token'] ?? ''));
$notice = null;
$verifiedData = null;

if (!empty($token)) {
    $tokenKey = 'verify_token:' . hash('sha256', $token);
    $rateLimited = lookup_is_throttled($pdo, 'lookup_verify', $tokenKey, 8, 15, 15);
    $tokenPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    if ($rateLimited) {
        $notice = 'Too many verification attempts. Please try again later.';
    } elseif (!preg_match($tokenPattern, $token)) {
        lookup_record_attempt($pdo, 'lookup_verify', $tokenKey);
        $notice = 'Invalid verification token. The certificate could not be found in our records.';
    } else {
        // Only printed certificates that are ready for pickup or released can verify.
        $stmt = $pdo->prepare("
            SELECT cr.tracking_number, cr.sacrament_type, cr.requester_name, cr.status, cr.anonymized_at,
                   ext.matched_record_id
            FROM certificate_requests cr
            LEFT JOIN (
                SELECT request_id, matched_record_id FROM baptismal_certificate_requests
                UNION ALL SELECT request_id, matched_record_id FROM confirmation_certificate_requests
                UNION ALL SELECT request_id, matched_record_id FROM first_communion_certificate_requests
                UNION ALL SELECT request_id, matched_record_id FROM marriage_certificate_requests
            ) ext ON ext.request_id = cr.id
            WHERE cr.verification_code = ?
              AND cr.status IN ('ready_for_pickup', 'released')
        ");
        $stmt->execute([$token]);
        $verifiedData = $stmt->fetch();

        if (!$verifiedData) {
            lookup_record_attempt($pdo, 'lookup_verify', $tokenKey);
            $notice = 'Invalid verification token. The certificate could not be found in our records.';
        } elseif (!empty($verifiedData['anonymized_at'])) {
            // Retention purge already anonymized this request: show nothing personal.
            $verifiedData = null;
            $notice = 'This record has been archived.';
        }
    }

} else {
    $notice = 'No verification token provided.';
}

// Basic layout for a public-facing page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Certificate - St. Patrick Parish</title>
    <?php $publicStyleVersion = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time(); ?>
    <link rel="stylesheet" href="<?= $basePath ?>/assets/css/style.css?v=<?= (int) $publicStyleVersion ?>">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 2rem auto; padding: 0 1rem; }
        .verify-card { border: 1px solid #ddd; border-radius: 8px; padding: 2rem; background: #f9f9f9; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .status-valid { color: #155724; background: #d4edda; border: 1px solid #c3e6cb; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; font-weight: bold; text-align: center; }
        .status-invalid { color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; font-weight: bold; text-align: center; }
        .data-row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 0.5rem 0; }
        .data-label { font-weight: 600; color: #666; }
        .back-link { display: block; margin-top: 2rem; text-align: center; color: #0056b3; text-decoration: none; }
    </style>
</head>
<body>
    <div class="verify-card">
        <h2 style="text-align:center; margin-top:0;">Certificate Verification</h2>
        
        <?php if ($notice): ?>
            <?php $noticeIsError = (strpos($notice, 'Invalid') !== false || strpos($notice, 'Too many') !== false); ?>
            <div class="<?= $noticeIsError ? 'status-invalid' : 'status-valid' ?>"<?= $noticeIsError ? '' : ' style="background:#eef1f4;color:#3c4a57;border:1px solid #d5dbe1;"' ?>>
                <?= e($notice) ?>
            </div>
        <?php endif; ?>

        <?php if ($verifiedData): ?>
            <div class="status-valid" style="background: #d4edda; color: #155724; border: 1px solid #c3e6cb;">
                ✓ This is an authentic certificate issued by St. Patrick Parish.
            </div>
            <div style="margin-top: 1.5rem;">
                <div class="data-row">
                    <span class="data-label">Tracking No.</span>
                    <span><?= e($verifiedData['tracking_number']) ?></span>
                </div>
                <div class="data-row">
                    <span class="data-label">Sacrament</span>
                    <span><?= e(ucwords(str_replace('_', ' ', $verifiedData['sacrament_type']))) ?></span>
                </div>
                <div class="data-row">
                    <span class="data-label">Requester</span>
                    <span><?= e($verifiedData['requester_name']) ?></span>
                </div>
                <div class="data-row">
                    <span class="data-label">Current Status</span>
                    <span><?= e(ucwords(str_replace('_', ' ', $verifiedData['status']))) ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <a href="<?= $basePath ?>/public/index.php" class="back-link">&larr; Back to Home</a>
</body>
</html>
