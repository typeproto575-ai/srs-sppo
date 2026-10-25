<?php
/**
 * ============================================================================
 * SRS-SPPO — SCHEDULE AND RECORD SYSTEM OF ST. PATRICK'S PARISH OFFICE
 * ============================================================================
 *
 * FILE: public/certificate-requests/confirmation.php
 *
 * PURPOSE:
 *   Public-facing request for a copy of a Confirmation certificate. Same
 *   three-step find / confirm / contact flow as the baptismal request page
 *   -- see that file's docblock (public/certificate-requests/baptismal.php)
 *   for the full rationale. This file only notes what differs.
 *
 * DIFFERENCES FROM THE BAPTISMAL REQUEST:
 *   - Searches confirmation_records (confirmand_* columns) instead of
 *     baptismal_records, via find_confirmation_record_matches().
 *   - The register's own birthdate column is used for the search, same as
 *     baptismal (confirmation_records.birthdate).
 *   - matched_record_id is a normal, unconstrained column here (no relative
 *     path quirk), stored in confirmation_certificate_requests.
 *
 * @package     SRS-SPPO
 * @subpackage  Public — Certificate Requests
 * @filesource  public/certificate-requests/confirmation.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF + flow state
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email
require_once __DIR__ . '/../../includes/requester-mode.php';
require_once __DIR__ . '/../../includes/record-lookup.php';

$basePath = $basePath ?? base_path();

$pageTitle      = 'Confirmation Certificate Request';
$errors         = [];
$trackingNumber = null;
$requesterMode  = certificate_requester_mode();

$flowKey = 'confirmation_request_flow';
$pageUrl = $basePath . '/public/certificate-requests/confirmation.php';

/** Redirect to a step of this page (post/redirect/get) and stop. */
$goto = function (string $step) use ($pageUrl, $requesterMode): void {
    $url = $pageUrl . '?requester_mode=' . urlencode($requesterMode);
    if ($step !== 'find') {
        $url .= '&step=' . urlencode($step);
    }
    header('Location: ' . $url);
    exit;
};

// Flow state: dropped when it expires or the visitor switches self/other.
$flow = $_SESSION[$flowKey] ?? null;
if (!is_array($flow)
    || (int) ($flow['expires'] ?? 0) < time()
    || ($flow['mode'] ?? '') !== $requesterMode) {
    $flow = null;
    unset($_SESSION[$flowKey]);
}

$step = 'find';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $step   = ['find' => 'find', 'choose' => 'result', 'manual' => 'result', 'submit' => 'contact'][$action] ?? 'find';

    if ($requesterMode === '') {
        $errors[] = 'Please choose whether you are requesting for yourself or someone else.';
        $action = '';
    } elseif (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
        $action = '';
    }

    // ---- Step 1: search the register --------------------------------------
    if ($action === 'find') {
        $search = [
            'first_name'  => trim($_POST['first_name'] ?? ''),
            'last_name'   => trim($_POST['last_name'] ?? ''),
            'birthdate'   => trim($_POST['birthdate'] ?? ''),
            'father_name' => trim($_POST['father_name'] ?? ''),
            'mother_name' => trim($_POST['mother_name'] ?? ''),
        ];

        if ($search['first_name'] === '') $errors[] = 'First name is required.';
        if ($search['last_name'] === '')  $errors[] = 'Last name is required.';

        $birth = DateTime::createFromFormat('Y-m-d', $search['birthdate']);
        if (!$birth || $birth->format('Y-m-d') !== $search['birthdate']) {
            $errors[] = 'Please enter a valid birthdate.';
        } elseif (is_future_date($search['birthdate'])) {
            $errors[] = 'Birthdate cannot be in the future.';
        }

        if (count(record_lookup_tokens($search['mother_name'])) < 2) {
            $errors[] = "Please enter the mother's maiden name (first and last name).";
        }
        if (count(record_lookup_tokens($search['father_name'])) < 2) {
            $errors[] = "Please enter the father's first and last name.";
        }

        if (empty($errors)) {
            $cap = record_search_allowed($pdo);
            if (!$cap['ok']) {
                $errors[] = $cap['reason'];
            }
        }

        if (empty($errors)) {
            record_search_log($pdo);
            $matches = find_confirmation_record_matches($pdo, $search);
            log_activity($pdo, null, 'public_record_lookup', 'confirmation_records', null,
                'Public confirmation record search: ' . count($matches) . ' match(es)');

            $_SESSION[$flowKey] = [
                'mode'        => $requesterMode,
                'expires'     => time() + 1800,
                'search'      => $search,
                'record_ids'  => array_map(static function ($m) { return (int) $m['id']; }, $matches),
                'selected_id' => null,
                'path'        => null,
            ];
            $goto('result');
        }
    }

    // ---- Step 2: confirm the found record, or ask for a manual search -----
    if ($action === 'choose' && $flow) {
        $rid = (int) ($_POST['record_id'] ?? 0);
        if ($rid > 0 && in_array($rid, $flow['record_ids'], true)) {
            $flow['selected_id'] = $rid;
            $flow['path']        = 'match';
            $_SESSION[$flowKey]  = $flow;
            $goto('contact');
        }
        $errors[] = 'Please select the record to continue.';
    }
    if ($action === 'manual' && $flow) {
        $flow['selected_id'] = null;
        $flow['path']        = 'manual';
        $_SESSION[$flowKey]  = $flow;
        $goto('contact');
    }

    // ---- Step 3: contact details + submit ---------------------------------
    if ($action === 'submit' && $flow && !empty($flow['path'])) {
        $requesterName         = trim($_POST['requester_name'] ?? '');
        $requesterRelationship = $_POST['requester_relationship'] ?? '';
        $requesterEmail        = trim($_POST['requester_email'] ?? '');
        $requesterContact      = trim($_POST['requester_contact_number'] ?? '');
        $purpose                = 'unspecified';
        $termsAccepted          = ($_POST['terms_accepted'] ?? '') === '1';

        // Subject details come from the confirmed register entry, or (manual
        // search) from what the visitor typed in step 1. Never from this form.
        $matchedId         = null;
        $confirmationDate  = null;
        $baptismDate       = null;
        $minister          = '';
        $sponsorName       = '';
        if ($flow['path'] === 'match') {
            $rec = load_confirmation_record_for_request($pdo, (int) $flow['selected_id']);
            if (!$rec || !in_array((int) $rec['id'], $flow['record_ids'], true)) {
                unset($_SESSION[$flowKey]);
                $flow   = null;
                $step   = 'find';
                $errors[] = 'That record is no longer available. Please search again.';
            } else {
                $matchedId        = (int) $rec['id'];
                $confirmandName   = trim((string) $rec['confirmand_full_name']);
                $fatherName       = trim((string) ($rec['father_name'] ?? ''));
                $motherMaidenName = trim((string) ($rec['mother_maiden_name'] ?? ''));
                $birthdate        = (string) $rec['birthdate'];
                $confirmationDate = $rec['confirmation_date'] ?: null;
                $minister         = trim((string) ($rec['minister_name'] ?? ''));
                $sponsorName      = trim((string) ($rec['sponsor_name'] ?? ''));
                if (!empty($rec['baptismal_record_id'])) {
                    $bap = load_baptismal_record_for_request($pdo, (int) $rec['baptismal_record_id']);
                    $baptismDate = $bap['baptism_date'] ?? null;
                }
            }
        } else {
            $s                = $flow['search'];
            $confirmandName   = trim($s['first_name'] . ' ' . $s['last_name']);
            $fatherName       = $s['father_name'];
            $motherMaidenName = $s['mother_name'];
            $birthdate        = $s['birthdate'];
        }

        if ($flow) {
            if ($requesterMode === 'self') {
                $requesterRelationship = 'self';
                $requesterName = $confirmandName;
            }
            if ($requesterMode === 'other' && $requesterName === '') $errors[] = 'Your full name is required.';
            if ($requesterMode === 'other' && $requesterName !== '') {
                if (($e = validate_full_name($requesterName)) !== null) $errors[] = $e;
            }
            if ($requesterMode === 'other' && !certificate_requester_is_relative($requesterRelationship)) {
                $errors[] = 'Please select your relationship to the person named on the certificate.';
            }
            if ($requesterEmail === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
            if ($requesterContact === '') {
                $errors[] = 'Contact number is required.';
            } elseif (!is_valid_phone_number($requesterContact)) {
                $errors[] = 'Please enter a valid contact number (at least 10 digits).';
            }
            if (!$termsAccepted) $errors[] = 'You must accept the Terms and Conditions before submitting.';
        }

        // Abuse guard: rolling 24h cap per email and per IP (only after the
        // fields validate, so fixing a typo never burns quota).
        if ($flow && empty($errors)) {
            $submissionCap = public_submission_allowed($pdo, [$requesterEmail]);
            if (!$submissionCap['ok']) {
                $errors[] = $submissionCap['reason'];
            }
        }

        if ($flow && empty($errors)) {
            try {
                $pdo->beginTransaction();

                $trackingNumber = generate_tracking_number($pdo, 'certificate_requests');

                // Online pre-application: matched -> "awaiting payment"; no-match
                // keeps the manual-search flow and stays 'pending'.
                $initialStatus = $matchedId ? 'awaiting_payment' : 'pending';

                $stmt = $pdo->prepare(
                    "INSERT INTO certificate_requests
                        (tracking_number, submission_channel, sacrament_type, requester_name, requester_relationship,
                         requester_relationship_other, requester_email, requester_contact_number,
                         purpose, status)
                     VALUES (?, 'online', 'confirmation', ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $trackingNumber, $requesterName, $requesterRelationship, '',
                    $requesterEmail, $requesterContact, $purpose, $initialStatus,
                ]);
                $requestId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    "INSERT INTO confirmation_certificate_requests
                        (request_id, confirmand_full_name, father_full_name, mother_full_name,
                         date_of_confirmation, date_of_baptism, place_of_baptism,
                         officiating_minister, sponsor_name, book_no, page_no, line_no, matched_record_id)
                     VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, '', '', '', ?)"
                );
                $stmt->execute([
                    $requestId, $confirmandName, $fatherName, $motherMaidenName,
                    $confirmationDate, $baptismDate, $minister, $sponsorName, $matchedId,
                ]);

                $how = $matchedId
                    ? "matched to register entry #{$matchedId} by the requester"
                    : 'no online match; manual register search requested';
                log_activity($pdo, null, 'certificate_request_submitted', 'certificate_requests', $requestId,
                    "Confirmation certificate request submitted by {$requesterName} ({$how})");

                notify($pdo, 'request_new', 'New certificate request',
                    "Confirmation certificate request from {$requesterName} ({$trackingNumber})"
                    . ($matchedId ? ' - record found online' : ' - needs manual register search'),
                    '/admin/requests/queue.php?status=pending');

                // Count this success against the abuse caps (same transaction,
                // so a later rollback never consumes quota).
                record_public_submission($pdo, [$requesterEmail], 'certificate_confirmation');

                $pdo->commit();
                unset($_SESSION[$flowKey]);

                try {
                    if (mail_request_received($requesterEmail, $trackingNumber, 'Confirmation Certificate Request')) {
                        log_activity($pdo, null, 'confirmation_email_sent', 'certificate_requests', $requestId, "Confirmation email sent to {$requesterEmail}");
                    }
                } catch (Throwable $e) {
                    error_log('Confirmation certificate request post-submit notification failed: ' . $e->getMessage());
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Never show the success panel for a rolled-back submission.
                $trackingNumber = null;
                $errors[] = 'Something went wrong while submitting your request. Please try again.';
            }
        }
    } elseif ($action === 'submit') {
        // Session ran out between steps: start over rather than fail silently.
        $errors[] = 'Your session expired. Please search for the record again.';
        $step = 'find';
    }
} else {
    // GET: honour ?step= only when the flow state allows it.
    $wanted = $_GET['step'] ?? 'find';
    if ($requesterMode !== '' && $wanted === 'result') {
        if (!$flow) $goto('find');
        $step = 'result';
    } elseif ($requesterMode !== '' && $wanted === 'contact') {
        if (!$flow) $goto('find');
        if (empty($flow['path'])) $goto('result');
        $step = 'contact';
    }
}

// A POST that needs a flow which is gone falls back to the search step.
if (in_array($step, ['result', 'contact'], true) && !$flow) {
    if (empty($errors)) $errors[] = 'Your session expired. Please search for the record again.';
    $step = 'find';
} elseif ($step === 'contact' && empty($flow['path'])) {
    $step = 'result';
}

// Values for display.
$val = static function (string $key) { return e($_POST[$key] ?? ''); };
$searchVal = static function (string $key) use ($flow) {
    return e($_POST[$key] ?? ($flow['search'][$key] ?? ''));
};
$formatDate = static function (?string $date): string {
    $ts = $date ? strtotime($date) : false;
    return $ts ? date('F j, Y', $ts) : '';
};

$matches = [];
if ($flow && $step === 'result' && !empty($flow['record_ids'])) {
    $in  = implode(',', array_fill(0, count($flow['record_ids']), '?'));
    $q   = $pdo->prepare("SELECT id, confirmand_full_name, confirmation_date FROM confirmation_records WHERE id IN ($in) ORDER BY confirmation_date ASC, id ASC");
    $q->execute($flow['record_ids']);
    $matches = $q->fetchAll();
}
$selectedRecord = null;
if ($flow && $step === 'contact' && ($flow['path'] ?? '') === 'match') {
    $q = $pdo->prepare('SELECT id, confirmand_full_name, confirmation_date FROM confirmation_records WHERE id = ?');
    $q->execute([(int) $flow['selected_id']]);
    $selectedRecord = $q->fetch() ?: null;
}

$stepLabels = ['find' => 'Find the record', 'result' => 'Confirm', 'contact' => 'Your contact'];
$stepOrder  = array_keys($stepLabels);
$modeQuery  = '?requester_mode=' . urlencode($requesterMode);

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="request-form">
    <h1>Confirmation Certificate Request</h1>

    <?php if ($trackingNumber): ?>
        <?php $successHeading = 'Your request has been submitted'; require __DIR__ . '/../../includes/submission-success.php'; ?>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!$requesterMode): ?>
            <?php render_certificate_requester_choice('confirmation certificate'); ?>
        <?php else: ?>

        <p class="request-context">
            For <strong><?= $requesterMode === 'self' ? 'myself' : 'someone else' ?></strong>
            &middot; <a href="<?= e($pageUrl) ?>">change</a>
        </p>

        <ol class="request-steps" aria-label="Progress">
            <?php foreach ($stepOrder as $i => $key): ?>
                <?php
                $state = $key === $step ? 'is-current' : (array_search($step, $stepOrder, true) > $i ? 'is-done' : '');
                ?>
                <li class="<?= $state ?>"<?= $key === $step ? ' aria-current="step"' : '' ?>><span><?= $i + 1 ?></span> <?= e($stepLabels[$key]) ?></li>
            <?php endforeach; ?>
        </ol>

        <?php if ($step === 'find'): ?>
            <form method="post" action="<?= e($pageUrl . $modeQuery) ?>">
                <input type="hidden" name="requester_mode" value="<?= e($requesterMode) ?>">
                <input type="hidden" name="action" value="find">
                <?= csrf_field() ?>
                <fieldset>
                    <legend>Details on the confirmation record</legend>
                    <div class="inline-fields">
                        <label>First name *
                            <input type="text" name="first_name" autocomplete="off" value="<?= $searchVal('first_name') ?>" required>
                        </label>
                        <label>Last name *
                            <input type="text" name="last_name" autocomplete="off" value="<?= $searchVal('last_name') ?>" required>
                        </label>
                    </div>
                    <label>Birthdate *
                        <input type="date" name="birthdate" max="<?= e(date('Y-m-d')) ?>" value="<?= $searchVal('birthdate') ?>" required>
                    </label>
                    <label>Father's full name *
                        <input type="text" name="father_name" autocomplete="off" value="<?= $searchVal('father_name') ?>" required>
                    </label>
                    <label>Mother's maiden full name *
                        <input type="text" name="mother_name" autocomplete="off" value="<?= $searchVal('mother_name') ?>" required>
                    </label>
                </fieldset>
                <button type="submit" class="btn">Search records</button>
                <p class="field-hint">We use these details only to look up the record. <a href="<?= e($basePath . '/public/terms.php#personal-data') ?>" target="_blank" rel="noopener noreferrer">How we use your data</a></p>
            </form>

        <?php elseif ($step === 'result'): ?>
            <?php if (!empty($matches)): ?>
                <h2>We found a matching record</h2>
                <form method="post" action="<?= e($pageUrl . $modeQuery) ?>">
                    <input type="hidden" name="requester_mode" value="<?= e($requesterMode) ?>">
                    <input type="hidden" name="action" value="choose">
                    <?= csrf_field() ?>
                    <?php foreach ($matches as $m): ?>
                        <label class="record-card">
                            <input type="radio" name="record_id" value="<?= (int) $m['id'] ?>" <?= count($matches) === 1 ? 'checked' : '' ?> required>
                            <span>
                                <strong><?= e($m['confirmand_full_name']) ?></strong>
                                <small>Confirmed <?= e($formatDate($m['confirmation_date'])) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <button type="submit" class="btn">Continue</button>
                </form>
                <form method="post" action="<?= e($pageUrl . $modeQuery) ?>" class="request-alt">
                    <input type="hidden" name="requester_mode" value="<?= e($requesterMode) ?>">
                    <input type="hidden" name="action" value="manual">
                    <?= csrf_field() ?>
                    <span class="field-hint">Not the right record?</span>
                    <button type="submit" class="link-button">Ask the secretary to search the register</button>
                </form>
            <?php else: ?>
                <h2>We couldn't find this record online</h2>
                <p>Not every register entry is on the website yet. The parish secretary can search the register book for you.</p>
                <div class="request-actions">
                    <form method="post" action="<?= e($pageUrl . $modeQuery) ?>">
                        <input type="hidden" name="requester_mode" value="<?= e($requesterMode) ?>">
                        <input type="hidden" name="action" value="manual">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn">Ask the secretary to search</button>
                    </form>
                    <a class="btn btn-secondary" href="<?= e($pageUrl . $modeQuery) ?>">Check the details again</a>
                </div>
            <?php endif; ?>

        <?php else: /* contact */ ?>
            <div class="request-summary">
                <?php if ($selectedRecord): ?>
                    <strong><?= e($selectedRecord['confirmand_full_name']) ?></strong>
                    <span>Confirmed <?= e($formatDate($selectedRecord['confirmation_date'])) ?></span>
                <?php else: ?>
                    <strong><?= e(trim(($flow['search']['first_name'] ?? '') . ' ' . ($flow['search']['last_name'] ?? ''))) ?></strong>
                    <span>Born <?= e($formatDate($flow['search']['birthdate'] ?? '')) ?> &middot; the secretary will search the register for this record</span>
                <?php endif; ?>
                <a href="<?= e($pageUrl . $modeQuery . '&step=result') ?>">change</a>
            </div>

            <form method="post" action="<?= e($pageUrl . $modeQuery . '&step=contact') ?>" data-review data-review-title="Review your certificate request" data-review-confirm="Confirm and submit request">
                <input type="hidden" name="requester_mode" value="<?= e($requesterMode) ?>">
                <input type="hidden" name="action" value="submit">
                <?= csrf_field() ?>
                <fieldset>
                    <legend>Your contact details</legend>

                    <?php if ($requesterMode === 'other'): ?>
                        <label>Your full name *
                            <input type="text" name="requester_name" autocomplete="name" value="<?= $val('requester_name') ?>" required>
                        </label>
                        <label>Your relationship to this person *
                            <select name="requester_relationship" required>
                                <option value="">-- Select --</option>
                                <?php foreach (['parent' => 'Parent', 'sibling' => 'Sibling', 'child' => 'Child'] as $k => $label): ?>
                                    <option value="<?= $k ?>" <?= ($_POST['requester_relationship'] ?? '') === $k ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>

                    <label>Email address *
                        <input type="email" name="requester_email" autocomplete="email" value="<?= $val('requester_email') ?>" required>
                    </label>
                    <label>Contact number *
                        <input type="text" name="requester_contact_number" autocomplete="tel" value="<?= $val('requester_contact_number') ?>" required>
                    </label>
                </fieldset>

                <label class="checkbox-label"><input type="checkbox" name="terms_accepted" value="1" <?= ($_POST['terms_accepted'] ?? '') === '1' ? 'checked' : '' ?> required> I agree to the <a href="<?= e($basePath . '/public/terms.php') ?>" target="_blank" rel="noopener noreferrer">Terms and Conditions</a>.</label>
                <button type="submit" class="btn">Submit request</button>
                <p class="field-hint">Nothing is released online. Bring a valid ID when you pick up the certificate.</p>
            </form>
        <?php endif; ?>

        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
