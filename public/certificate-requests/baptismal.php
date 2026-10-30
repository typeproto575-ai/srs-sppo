<?php
/**
 * ============================================================================
 * SRS-SPPO — SCHEDULE AND RECORD SYSTEM OF ST. PATRICK'S PARISH OFFICE
 * ============================================================================
 *
 * FILE: public/certificate-requests/baptismal.php
 *
 * PURPOSE:
 *   Public-facing request for a copy of a Baptismal certificate, for someone
 *   baptized at this parish. Short three-step flow instead of one long form.
 *
 * HOW IT WORKS:
 *   0. Visitor picks "For myself" or "For someone else" (requester-mode.php).
 *   1. FIND   — five identity details: first name, last name, birthdate,
 *               father's full name, mother's maiden name.
 *               includes/record-lookup.php searches the encoded register.
 *               Searches are rate limited per IP; every one is audit-logged.
 *   2. CONFIRM— found: show the recorded name + baptism date and let the
 *               visitor continue (or pick one if several match).
 *               Not found: NOT a dead end. Older register entries may not be
 *               encoded yet, so the visitor can ask the secretary to search
 *               the register book by hand (path = "manual").
 *   3. CONTACT— email, contact number (+ requester name and
 *               relationship when requesting for someone else) and the Terms
 *               checkbox. On submit, two rows are inserted in one transaction:
 *                 a. certificate_requests          — shared request data
 *                 b. baptismal_certificate_requests — sacrament details, with
 *                    matched_record_id set when the visitor confirmed a match.
 *
 *   A match is a claim, not an approval: the secretary still reviews the
 *   request, verifies the physical register and releases it in person.
 *
 * STATE BETWEEN STEPS:
 *   Kept in $_SESSION['baptismal_request_flow'] (30 minutes). The record id
 *   used at submit time always comes from the session -- never from a field the
 *   browser can edit -- and is re-checked against the register on submit.
 *
 * SECURITY NOTES:
 *   - CSRF token required on every POST (csrf_verify() + csrf_field()).
 *   - All output is escaped with e(); all SQL uses prepared statements.
 *   - The "not found" message is identical whichever detail was wrong, and
 *     only the recorded name + baptism date are shown for a match.
 *   - No online ID upload or payment -- identity is verified at pickup.
 *   - Abuse guards: 10 searches per IP per hour (record-lookup.php) and
 *     3 submissions per email / 20 per IP per 24h (functions.php).
 *
 * DATABASE TABLES TOUCHED:
 *   - baptismal_records (read-only lookup)
 *   - certificate_requests, baptismal_certificate_requests (insert)
 *   - audit_logs, notifications, submission_rate_limits
 *
 * RELATED FILES:
 *   - includes/record-lookup.php           (matching rules + search limiter)
 *   - includes/requester-mode.php          (self / someone-else choice)
 *   - admin/requests/queue.php             (secretary processes the request)
 *   - admin/certificates/generate.php      (uses matched_record_id)
 *
 * @package     SRS-SPPO
 * @subpackage  Public — Certificate Requests
 * @filesource  public/certificate-requests/baptismal.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // session for CSRF + flow state
require_once __DIR__ . '/../../includes/mail.php'; // confirmation email
require_once __DIR__ . '/../../includes/requester-mode.php';
require_once __DIR__ . '/../../includes/record-lookup.php';

$basePath = $basePath ?? base_path();

$pageTitle      = 'Baptismal Certificate Request';
$errors         = [];
$trackingNumber = null;
$requesterMode  = certificate_requester_mode();

$flowKey = 'baptismal_request_flow';
$pageUrl = $basePath . '/public/certificate-requests/baptismal.php';

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
            'record_first' => trim($_POST['record_first'] ?? ''),
            'record_middle' => trim($_POST['record_middle'] ?? ''),
            'record_last' => trim($_POST['record_last'] ?? ''),
            'record_suffix' => trim($_POST['record_suffix'] ?? ''),
            'first_name' => trim($_POST['record_first'] ?? ''),
            'last_name'  => trim($_POST['record_last'] ?? ''),
            'birthdate'  => trim($_POST['birthdate'] ?? ''),
            'father_first' => trim($_POST['father_first'] ?? ''),
            'father_middle' => trim($_POST['father_middle'] ?? ''),
            'father_last' => trim($_POST['father_last'] ?? ''),
            'father_suffix' => trim($_POST['father_suffix'] ?? ''),
            'mother_first' => trim($_POST['mother_first'] ?? ''),
            'mother_middle' => trim($_POST['mother_middle'] ?? ''),
            'mother_last' => trim($_POST['mother_last'] ?? ''),
            'mother_suffix' => trim($_POST['mother_suffix'] ?? ''),
        ];
        $search['father_name'] = compose_person_name($search, 'father');
        $search['mother_name'] = compose_person_name($search, 'mother');

        if ($search['first_name'] === '' && $search['last_name'] === '') {
            $errors[] = "Please enter the child's first name or last name.";
        }

        $birth = DateTime::createFromFormat('Y-m-d', $search['birthdate']);
        if (!$birth || $birth->format('Y-m-d') !== $search['birthdate']) {
            $errors[] = 'Please enter a valid birthdate.';
        } elseif (is_future_date($search['birthdate'])) {
            $errors[] = 'Birthdate cannot be in the future.';
        }

        if (($parentError = record_lookup_parent_search_error($search['father_name'], $search['mother_name'])) !== null) {
            $errors[] = $parentError;
        }

        if (empty($errors)) {
            $cap = record_search_allowed($pdo);
            if (!$cap['ok']) {
                $errors[] = $cap['reason'];
            }
        }

        if (empty($errors)) {
            record_search_log($pdo);
            $matches = find_baptismal_record_matches($pdo, $search);
            log_activity($pdo, null, 'public_record_lookup', 'baptismal_records', null,
                'Public baptismal record search: ' . count($matches) . ' match(es)');

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

    // ---- Step 2: details recorded (NEUTRAL RESULT) ---------------------------
    // The visitor is NEVER shown whether a record exists: the register search
    // must not act as an existence oracle. Exactly one match is taken as the
    // request's claim; zero or several fall back to a manual register search by
    // the secretary.
    if ($action === 'choose' && $flow) {
        $ids = array_values(array_map('intval', $flow['record_ids'] ?? []));
        if (count($ids) === 1) {
            $flow['selected_id'] = $ids[0];
            $flow['path']        = 'match';
        } else {
            $flow['selected_id'] = null;
            $flow['path']        = 'manual';
        }
        $_SESSION[$flowKey]  = $flow;
        $goto('contact');
    }
    if ($action === 'manual' && $flow) {
        $flow['selected_id'] = null;
        $flow['path']        = 'manual';
        $_SESSION[$flowKey]  = $flow;
        $goto('contact');
    }

    // ---- Step 3: contact details + submit ---------------------------------
    if ($action === 'submit' && $flow && !empty($flow['path'])) {
        $requesterName         = compose_person_name($_POST, 'requester');
        $requesterRelationship = $_POST['requester_relationship'] ?? '';
        $requesterEmail        = trim($_POST['requester_email'] ?? '');
        $requesterContact      = trim($_POST['requester_contact_number'] ?? '');
        $purpose               = 'unspecified';
        $termsAccepted         = ($_POST['terms_accepted'] ?? '') === '1';

        // Subject details come from the confirmed register entry, or (manual
        // search) from what the visitor typed in step 1. Never from this form.
        $matchedId   = null;
        $baptismDate = null;
        $minister    = '';
        if ($flow['path'] === 'match') {
            $rec = load_baptismal_record_for_request($pdo, (int) $flow['selected_id']);
            if (!$rec || !in_array((int) $rec['id'], $flow['record_ids'], true)) {
                unset($_SESSION[$flowKey]);
                $flow   = null;
                $step   = 'find';
                $errors[] = 'That record is no longer available. Please search again.';
            } else {
                $matchedId   = (int) $rec['id'];
                $childName   = trim((string) $rec['child_full_name']);
                $fatherName  = trim((string) ($rec['father_name'] ?? ''));
                $motherName  = trim((string) ($rec['mother_name'] ?? ''));
                if ($motherName === '') {
                    $motherName = trim((string) ($rec['mother_maiden_name'] ?? ''));
                }
                $birthdate   = (string) $rec['birth_date'];
                $baptismDate = $rec['baptism_date'] ?: null;
                $minister    = trim((string) ($rec['minister_name'] ?? ''));
            }
        } else {
            $s          = $flow['search'];
            $childName  = compose_person_name($s, 'record');
            $fatherName = $s['father_name'];
            $motherName = $s['mother_name'];
            $birthdate  = $s['birthdate'];
        }

        if ($flow) {
            if ($requesterMode === 'self') {
                $requesterRelationship = 'self';
                $requesterName = $childName;
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

                // Online pre-application: when a register match was found the
                // requester pauses at "awaiting payment" (payment is handled
                // outside the system and marked paid by hand later). A no-match
                // submission keeps the manual-search flow and stays 'pending'.
                $initialStatus = $matchedId ? 'awaiting_payment' : 'pending';

                $stmt = $pdo->prepare(
                    "INSERT INTO certificate_requests
                        (tracking_number, submission_channel, sacrament_type, requester_name, requester_relationship,
                         requester_relationship_other, requester_email, requester_contact_number,
                         purpose, status)
                     VALUES (?, 'online', 'baptismal', ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $trackingNumber, $requesterName, $requesterRelationship, '',
                    $requesterEmail, $requesterContact, $purpose, $initialStatus,
                ]);
                $requestId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    "INSERT INTO baptismal_certificate_requests
                        (request_id, child_full_name, father_full_name, mother_full_name,
                         birthplace, birthdate, date_of_baptism, officiating_minister,
                         sponsors_text, book_no, page_no, line_no, matched_record_id)
                     VALUES (?, ?, ?, ?, '', ?, ?, ?, '', '', '', '', ?)"
                );
                $stmt->execute([
                    $requestId, $childName, $fatherName, $motherName, $birthdate,
                    $baptismDate, $minister, $matchedId,
                ]);

                $how = $matchedId
                    ? "matched to register entry #{$matchedId} by the requester"
                    : 'no online match; manual register search requested';
                log_activity($pdo, null, 'certificate_request_submitted', 'certificate_requests', $requestId,
                    "Baptismal certificate request submitted by {$requesterName} ({$how})");

                notify($pdo, 'request_new', 'New certificate request',
                    "Baptismal certificate request from {$requesterName} ({$trackingNumber})"
                    . ($matchedId ? ' - record found online' : ' - needs manual register search'),
                    '/admin/requests/queue.php?status=pending');

                // Count this success against the abuse caps (same transaction,
                // so a later rollback never consumes quota).
                record_public_submission($pdo, [$requesterEmail], 'certificate_baptismal');

                $pdo->commit();
                unset($_SESSION[$flowKey]);

                try {
                    if (mail_request_received($requesterEmail, $trackingNumber, 'Baptismal Certificate Request')) {
                        log_activity($pdo, null, 'confirmation_email_sent', 'certificate_requests', $requestId, "Confirmation email sent to {$requesterEmail}");
                    }
                } catch (Throwable $e) {
                    error_log('Baptismal certificate request post-submit notification failed: ' . $e->getMessage());
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
    $q   = $pdo->prepare("SELECT id, child_full_name, baptism_date FROM baptismal_records WHERE id IN ($in) ORDER BY baptism_date ASC, id ASC");
    $q->execute($flow['record_ids']);
    $matches = $q->fetchAll();
}
$selectedRecord = null;
if ($flow && $step === 'contact' && ($flow['path'] ?? '') === 'match') {
    $q = $pdo->prepare('SELECT id, child_full_name, baptism_date FROM baptismal_records WHERE id = ?');
    $q->execute([(int) $flow['selected_id']]);
    $selectedRecord = $q->fetch() ?: null;
}

$stepLabels = ['find' => 'Find the record', 'result' => 'Confirm', 'contact' => 'Your contact'];
$stepOrder  = array_keys($stepLabels);
$modeQuery  = '?requester_mode=' . urlencode($requesterMode);

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="request-form">
    <h1>Baptismal Certificate Request</h1>

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
            <?php render_certificate_requester_choice('baptismal certificate'); ?>
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
                    <legend>Details on the baptismal record</legend>
                    <?= person_name_fields('record', "Child's name", $flow['search'] ?? $_POST) ?>
                    <label>Birthdate *
                        <input type="date" name="birthdate" max="<?= e(date('Y-m-d')) ?>" value="<?= $searchVal('birthdate') ?>" required>
                    </label>
                    <?= person_name_fields('father', "Father's name", $flow['search'] ?? $_POST, false) ?>
                    <?= person_name_fields('mother', "Mother's maiden name", $flow['search'] ?? $_POST, false) ?>
                </fieldset>
                <p class="field-hint">Enter one or both parents' names. Leave any name part blank if it does not apply, and leave a parent's fields blank if you do not know their name.</p>
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
                    <?php foreach ($matches as $i => $m): ?>
                        <label class="record-card">
                            <input type="radio" name="record_id" value="<?= (int) $m['id'] ?>" <?= count($matches) === 1 ? 'checked' : '' ?> required>
                            <span>
                                <strong><?= e($m['child_full_name']) ?></strong>
                                <small>Baptized <?= e($formatDate($m['baptism_date'])) ?></small>
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
                    <strong><?= e($selectedRecord['child_full_name']) ?></strong>
                    <span>Baptized <?= e($formatDate($selectedRecord['baptism_date'])) ?></span>
                <?php else: ?>
                    <strong><?= e(compose_person_name($flow['search'] ?? [], 'record')) ?></strong>
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
                        <?= person_name_fields('requester', 'Your name', $_POST) ?>
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
