<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../certificates/print-coordinates.php';
require_once __DIR__ . '/../certificates/baptismal-certificate.php';
require_once __DIR__ . '/../certificates/sacramental-certificate.php';
require_once __DIR__ . '/../../includes/sacrament-data.php';
require_login();

$basePath = $basePath ?? base_path();
$requestId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($requestId === false || $requestId === null || $requestId < 1) {
    redirect_to('/admin/requests/queue.php');
}
$requestId = (int) $requestId;
$printUrl = $basePath . '/admin/requests/certificate-print.php?id=' . $requestId;
$detailUrl = $basePath . '/admin/requests/certificate-detail.php?id=' . $requestId;
$pageTitle = 'Print Certificate';
$printOnly = true;
$notice = null;
$shouldPrint = false;
$printMode = 'overlay';
$allowedStatuses = ['for_signature', 'ready', 'released'];

$requestStmt = $pdo->prepare('SELECT * FROM certificate_requests WHERE id = ?');
$requestStmt->execute([$requestId]);
$req = $requestStmt->fetch(PDO::FETCH_ASSOC);
if (!$req) {
    redirect_to('/admin/requests/queue.php');
}

$recordId = (int) ($req['record_id'] ?? 0);
$source = null;
$canPrint = $recordId > 0 && in_array((string) $req['status'], $allowedStatuses, true);
if ($canPrint) {
    $source = sd_certificate_source($pdo, $recordId);
    $canPrint = $source !== null
        && (string) ($source['sacrament_type'] ?? '') === (string) $req['sacrament_type']
        && sd_can_print_certificate($source);
}

if (!$canPrint) {
    if ($recordId < 1) {
        $notice = 'This request does not have a linked parish record.';
    } elseif (!in_array((string) $req['status'], $allowedStatuses, true)) {
        $notice = 'This request is not at a stage where its certificate can be printed.';
    } else {
        $notice = 'The linked record is not verified for printing or is not a record from this parish.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['print_certificate'])) {
    if (!csrf_verify()) {
        $notice = 'Your session token is invalid or expired. Refresh the page and try again.';
    } elseif (!$canPrint) {
        $notice = 'This certificate is not available to print. Return to the request details for more information.';
    } else {
        $submittedMode = $_POST['print_mode'] ?? 'standard';
        if ($req['sacrament_type'] === 'baptism') {
            if (!is_string($submittedMode) || !in_array($submittedMode, ['overlay', 'full'], true)) {
                $notice = 'Choose a valid baptism certificate print format.';
            } else {
                $printMode = $submittedMode;
            }
        }

        if ($notice === null) {
            try {
                $pdo->beginTransaction();
                $lockStmt = $pdo->prepare('SELECT * FROM certificate_requests WHERE id = ? FOR UPDATE');
                $lockStmt->execute([$requestId]);
                $currentReq = $lockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$currentReq) {
                    throw new DomainException('This certificate request no longer exists.');
                }
                if (empty($currentReq['record_id']) || !in_array((string) $currentReq['status'], $allowedStatuses, true)) {
                    throw new DomainException('This request is no longer at a stage where its certificate can be printed.');
                }

                $currentRecordId = (int) $currentReq['record_id'];
                $currentSource = sd_certificate_source($pdo, $currentRecordId);
                if (
                    !$currentSource
                    || (string) ($currentSource['sacrament_type'] ?? '') !== (string) $currentReq['sacrament_type']
                    || !sd_can_print_certificate($currentSource)
                ) {
                    throw new DomainException('The linked record is not verified for printing or is not a record from this parish.');
                }

                $certificateNo = trim((string) ($currentReq['certificate_no'] ?? ''));
                if ($certificateNo === '') {
                    $certificateNo = 'C-' . date('Y') . '-' . str_pad((string) $requestId, 5, '0', STR_PAD_LEFT);
                    $updateStmt = $pdo->prepare(
                        "UPDATE certificate_requests
                         SET certificate_no = ?, printed_by = ?, printed_at = NOW()
                         WHERE id = ? AND (certificate_no IS NULL OR certificate_no = '')"
                    );
                    $updateStmt->execute([$certificateNo, (int) current_user()['id'], $requestId]);
                    if ($updateStmt->rowCount() !== 1) {
                        throw new RuntimeException('Certificate number was not saved.');
                    }
                    $currentReq['certificate_no'] = $certificateNo;
                    $currentReq['printed_by'] = (int) current_user()['id'];
                    $currentReq['printed_at'] = date('Y-m-d H:i:s');
                }

                log_activity(
                    $pdo,
                    (int) current_user()['id'],
                    'certificate.print',
                    'certificate_requests',
                    $requestId,
                    'Certificate ' . $certificateNo . ' printed or reprinted.'
                );
                $pdo->commit();

                $req = $currentReq;
                $source = $currentSource;
                $canPrint = true;
                $shouldPrint = true;
            } catch (DomainException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $notice = $e->getMessage();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Certificate print action failed for request ' . $requestId . ': ' . $e->getMessage());
                $notice = 'The print action could not be recorded. No changes were saved; refresh and try again.';
            }
        }
    }
}

$bcLayout = baptismal_blank_stock_layout();
$certificateMarkup = '';
if ($canPrint && $source !== null) {
    if ($req['sacrament_type'] === 'baptism') {
        $values = baptismal_certificate_values($source, $req, $source['sponsor_names'] ?? []);
        $certificateMarkup = baptismal_certificate_svg($bcLayout, $values);
    } else {
        $certificateMarkup = sacramental_certificate_html((string) $req['sacrament_type'], $source, $req, $basePath);
    }
}

$certificateNo = trim((string) ($req['certificate_no'] ?? ''));
if ($certificateNo === '') {
    $certificateNo = 'C-' . date('Y') . '-' . str_pad((string) $requestId, 5, '0', STR_PAD_LEFT);
}

require_once __DIR__ . '/../../includes/admin-header.php';
?>
<style>
    @page {
        size: <?= (float) $bcLayout['page']['width_in'] ?>in <?= (float) $bcLayout['page']['height_in'] ?>in;
        margin: 0;
    }
    @media print {
        body {
            margin: 0 !important;
            background: #fff;
        }
        body:has(.blank-stock-print-wrapper) .site-master-header,
        body:has(.blank-stock-print-wrapper) .admin-sidebar,
        body:has(.blank-stock-print-wrapper) .site-footer,
        body:has(.blank-stock-print-wrapper) .admin-content > :not(.cert-preview),
        body:has(.blank-stock-print-wrapper) .cert-preview > :not(.blank-stock-print-wrapper) {
            display: none !important;
        }
        body:has(.blank-stock-print-wrapper) .admin-layout {
            display: block;
            min-height: 0;
            background: #fff;
        }
        body:has(.blank-stock-print-wrapper) .admin-content {
            padding: 0;
            margin: 0;
        }
        .blank-stock-print-wrapper {
            margin: 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
            max-width: none !important;
            width: auto !important;
        }
        /* True physical size; calibration offsets nudge the printed values. */
        .blank-stock-print-wrapper .bc-svg {
            width: <?= (float) $bcLayout['page']['width_in'] ?>in !important;
            height: <?= (float) $bcLayout['page']['height_in'] ?>in !important;
            transform: translate(<?= (float) $bcLayout['calibration']['offset_x_in'] ?>in, <?= (float) $bcLayout['calibration']['offset_y_in'] ?>in);
        }
        .blank-stock-print-wrapper .sc-svg {
            width: <?= (float) $bcLayout['page']['width_in'] ?>in !important;
            height: <?= (float) $bcLayout['page']['height_in'] ?>in !important;
        }
        /* Default print = values only, for the pre-printed stock. */
        .bc-template { display: none; }
        body[data-cert-print="full"] .bc-template { display: initial; }
    }
    <?php if ($printOnly): ?>
    .print-only-hidden { display: none !important; }
    <?php endif; ?>
    .blank-stock-print-wrapper {
        width: 100%;
        max-width: <?= (float) $bcLayout['page']['width_in'] ?>in;
        margin: 0 auto;
        background: #fff;
        box-shadow: 0 0 0 1px rgba(0,0,0,0.12), 0 4px 18px rgba(0,0,0,0.08);
    }
    .blank-stock-print-wrapper .bc-svg {
        display: block;
        width: 100%;
        height: auto;
    }
    .blank-stock-print-wrapper .sc-svg {
        display: block;
        width: 100%;
        height: auto;
    }
</style>

<section class="admin-page-heading">
    <div>
        <p class="admin-queue-kicker">Certificate request</p>
        <h1>Print certificate</h1>
        <p><?= e(sd_type_label((string) $req['sacrament_type'])) ?> — <?= e($req['subject_name']) ?></p>
        <p>Certificate number: <?= e($certificateNo) ?></p>
    </div>
    <a class="btn btn-secondary" href="<?= e($detailUrl) ?>">Back to request</a>
</section>

<?php if ($notice !== null): ?>
    <div class="alert alert-warning" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<?php if (!$canPrint): ?>
    <p><?= e($notice ?? 'This certificate is not available to print.') ?></p>
    <p><a class="btn btn-secondary" href="<?= e($detailUrl) ?>">Return to certificate details</a></p>
<?php else: ?>
    <section class="cert-preview">
        <?php if ($req['sacrament_type'] === 'baptism'): ?>
            <div class="blank-stock-print-wrapper" aria-label="Baptismal certificate preview">
                <?= $certificateMarkup ?>
            </div>
        <?php else: ?>
            <?= $certificateMarkup ?>
        <?php endif; ?>

        <form method="post" action="<?= e($printUrl) ?>" class="cert-action-toolbar">
            <?= csrf_field() ?>
            <input type="hidden" name="print_certificate" value="1">
            <?php if ($req['sacrament_type'] === 'baptism'): ?>
                <button type="submit" name="print_mode" value="overlay" class="btn btn-primary">Print on Blank Stock</button>
                <button type="submit" name="print_mode" value="full" class="btn btn-secondary">Print Full Certificate</button>
            <?php else: ?>
                <button type="submit" class="btn btn-primary">Print Certificate</button>
            <?php endif; ?>
        </form>

        <?php if ($req['sacrament_type'] === 'baptism'): ?>
            <?= baptismal_certificate_fit_script() ?>
        <?php else: ?>
            <?= sacramental_certificate_fit_script() ?>
        <?php endif; ?>
    </section>
    <?php if ($shouldPrint): ?>
        <script>
            window.addEventListener('load', function () {
                <?php if ($req['sacrament_type'] === 'baptism'): ?>
                window.bcPrint(<?= json_encode($printMode) ?>);
                <?php else: ?>
                window.print();
                <?php endif; ?>
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin-footer.php'; ?>
