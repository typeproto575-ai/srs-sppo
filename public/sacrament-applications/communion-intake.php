<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/application-requirements.php';

$basePath = base_path();
$pageTitle = 'Holy Communion Pre-Application';
$errors = [];
$trackingNumber = null;
$successMembers = [];
$requestNo = '';
$emailDeliveryWarning = false;
$submissionMode = null;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (($_GET['reset'] ?? '') === '1') {
    unset($_SESSION['communion_intake']);
    header('Location: first-communion.php');
    exit;
}
if (!isset($_SESSION['communion_intake']) || !is_array($_SESSION['communion_intake'])) {
    $_SESSION['communion_intake'] = [
        'current_step' => 1,
        'form_data' => ['mode' => 'group'],
    ];
}
$wizard = &$_SESSION['communion_intake'];
$formData = &$wizard['form_data'];
$formData['mode'] = in_array($formData['mode'] ?? '', ['group', 'individual'], true)
    ? $formData['mode']
    : 'group';

if (($_GET['start'] ?? '') === 'profile') {
    if ((current_user()['role'] ?? '') !== 'secretary') {
        http_response_code(403);
        exit('Only parish secretaries can start a profile-linked walk-in pre-application.');
    }
    $personId = filter_var($_GET['person_id'] ?? null, FILTER_VALIDATE_INT);
    $person = $personId ? public_application_profile($pdo, (int) $personId) : null;
    if (!$person) {
        $errors[] = 'The selected parishioner profile could not be found. Search for the person again.';
    } else {
        $formData = array_merge(public_application_profile_fields($person, 'applicant'), [
            'mode' => 'individual',
            'contact_number' => (string) ($person['contact_number'] ?? ''),
            'email' => (string) ($person['email'] ?? ''),
            'notify_name' => compose_structured_name(
                (string) ($person['first_name'] ?? ''),
                (string) ($person['middle_name'] ?? ''),
                (string) ($person['last_name'] ?? ''),
                (string) ($person['suffix'] ?? '')
            ),
            'notify_contact' => (string) ($person['contact_number'] ?? ''),
            'notify_email' => (string) ($person['email'] ?? ''),
        ]);
        $wizard['current_step'] = 1;
        session_write_close();
        header('Location: first-communion.php?mode=individual');
        exit;
    }
}

$currentStep = (int) ($wizard['current_step'] ?? 1);
if ($currentStep < 1 || $currentStep > 3) {
    $currentStep = 1;
    $wizard['current_step'] = 1;
}
$steps = [
    1 => 'Application type',
    2 => 'Details',
    3 => 'Review',
];
$action = is_string($_POST['_action'] ?? null) ? $_POST['_action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Your session expired. Please submit the form again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['next', 'back'], true)) {
    $postedData = $_POST;
    unset($postedData['_action'], $postedData['csrf_token']);
    foreach ($postedData as $key => $value) {
        if (is_array($value)) {
            $formData[$key] = array_values(array_filter($value, 'is_scalar'));
        } elseif (is_scalar($value)) {
            $formData[$key] = trim((string) $value);
        }
    }
    if (isset($_POST['mode']) && in_array($_POST['mode'], ['group', 'individual'], true)) {
        $formData['mode'] = $_POST['mode'];
    }
    $mode = $formData['mode'];
    if ($mode === 'group') {
        unset($formData['candidate_birthdates'], $formData['candidate_birthdates_text']);
    }

    if ($action === 'back') {
        $wizard['current_step'] = max(1, $currentStep - 1);
    } elseif ($currentStep === 1) {
        if (!in_array($mode, ['group', 'individual'], true)) {
            $errors[] = 'Choose a group or individual application.';
        }
        if (!$errors) {
            $wizard['current_step'] = 2;
        }
    } elseif ($currentStep === 2) {
        $formData['privacy_consent'] = ($_POST['privacy_consent'] ?? '') === '1' ? '1' : '0';
        if (mb_strlen((string) ($formData['preferred_priest'] ?? ''), 'UTF-8') > 150) {
            $errors[] = 'Requested officiating priest name must be 150 characters or fewer.';
        }
        if ($mode === 'group') {
            if (mb_strlen((string) ($formData['group_label'] ?? ''), 'UTF-8') > 80) {
                $errors[] = 'The group or organization name must be 80 characters or fewer.';
            }
            $venue = (string) ($formData['communion_venue'] ?? 'parish_church');
            if (!in_array($venue, ['parish_church', 'other'], true)) {
                $errors[] = 'Choose a valid preferred venue for the group.';
            } elseif ($venue === 'other') {
                $otherVenue = trim((string) ($formData['communion_venue_other'] ?? ''));
                if ($otherVenue === '') {
                    $errors[] = 'Enter the group’s preferred venue.';
                } elseif (mb_strlen($otherVenue, 'UTF-8') > 255) {
                    $errors[] = 'The preferred venue must be 255 characters or fewer.';
                }
            }
            try {
                $formData['candidates'] = communion_roster_candidates((string) ($formData['roster_text'] ?? ''));
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
            if (trim((string) ($formData['notify_name'] ?? '')) === '') {
                $errors[] = 'Person to be notified name is required.';
            } elseif (($nameError = validate_full_name((string) $formData['notify_name'])) !== null) {
                $errors[] = $nameError;
            }
            if (trim((string) ($formData['notify_contact'] ?? '')) === ''
                || !is_valid_phone_number((string) ($formData['notify_contact'] ?? ''))) {
                $errors[] = 'Enter a valid contact number for the person to be notified.';
            }
            if (!filter_var((string) ($formData['notify_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address for the person to be notified.';
            }
        } else {
            $name = compose_person_name($formData, 'applicant');
            if (trim((string) ($formData['applicant_first'] ?? '')) === '') {
                $errors[] = "Applicant's first name is required.";
            }
            if (trim((string) ($formData['applicant_last'] ?? '')) === '') {
                $errors[] = "Applicant's last name is required.";
            }
            if (($nameError = validate_full_name($name)) !== null) {
                $errors[] = $nameError;
            }
            $birthDate = trim((string) ($formData['applicant_birthdate'] ?? ''));
            if ($birthDate === '') {
                $errors[] = "Applicant's date of birth is required.";
            } elseif (is_future_date($birthDate)) {
                $errors[] = "Applicant's date of birth cannot be in the future.";
            } else {
                $assessment = sacrament_age_assessment($pdo, 'first_communion', calculate_age($birthDate));
                if ($assessment['status'] !== 'Age-eligible') {
                    $errors[] = 'The applicant is not yet age-eligible for Holy Communion. The minimum age is '
                        . (int) $assessment['range']['min_age'] . ' years.';
                }
            }
            if (trim((string) ($formData['notify_name'] ?? '')) === '') {
                $errors[] = 'Person to be notified name is required.';
            } elseif (($nameError = validate_full_name((string) $formData['notify_name'])) !== null) {
                $errors[] = $nameError;
            }
            if (trim((string) ($formData['notify_contact'] ?? '')) === ''
                || !is_valid_phone_number((string) ($formData['notify_contact'] ?? ''))) {
                $errors[] = 'Enter a valid contact number for the person to be notified.';
            }
            if (!filter_var((string) ($formData['notify_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address for the person to be notified.';
            }
            foreach (['father_name' => "Father's name", 'mother_name' => "Mother's maiden name"] as $field => $label) {
                if (mb_strlen((string) ($formData[$field] ?? ''), 'UTF-8') > 150) {
                    $errors[] = $label . ' must be 150 characters or fewer.';
                }
            }
        }
        if ($formData['privacy_consent'] !== '1') {
            $errors[] = 'You must accept the Privacy Notice before continuing.';
        }
        if (!$errors) {
            $wizard['current_step'] = 3;
        }
    }

    if (!$errors) {
        session_write_close();
        $target = 'first-communion.php' . ($formData['mode'] === 'individual' ? '?mode=individual' : '');
        header('Location: ' . $target);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'final_submit' && $currentStep === 3 && csrf_verify()) {
    $formData['certify_accuracy'] = ($_POST['certify_accuracy'] ?? '') === '1' ? '1' : '0';
    if ($formData['certify_accuracy'] !== '1') {
        $errors[] = 'Please certify that the pre-application information is true and correct.';
    }
    $mode = (string) $formData['mode'];
    $submissionMode = $mode;
    $candidates = $mode === 'group' ? ($formData['candidates'] ?? []) : [[
        'full_name' => compose_person_name($formData, 'applicant'),
        'first_name' => (string) ($formData['applicant_first'] ?? ''),
        'middle_name' => (string) ($formData['applicant_middle'] ?? ''),
        'last_name' => (string) ($formData['applicant_last'] ?? ''),
        'suffix' => (string) ($formData['applicant_suffix'] ?? ''),
        'birth_date' => (string) ($formData['applicant_birthdate'] ?? ''),
    ]];
    if (!is_array($candidates) || $candidates === []) {
        $errors[] = 'Add at least one candidate before submitting.';
    }
    if ($mode === 'individual') {
        foreach ($candidates as $candidate) {
            $birthDate = (string) ($candidate['birth_date'] ?? '');
            if ($birthDate === '' || is_future_date($birthDate)) {
                $errors[] = 'A valid date of birth is required for the applicant.';
                break;
            }
            $assessment = sacrament_age_assessment($pdo, 'first_communion', calculate_age($birthDate));
            if ($assessment['status'] !== 'Age-eligible') {
                $errors[] = 'The applicant is not yet age-eligible for Holy Communion. The minimum age is '
                    . (int) $assessment['range']['min_age'] . ' years.';
                break;
            }
        }
    }
    $notifyName = trim((string) ($formData['notify_name'] ?? ''));
    $notifyContact = trim((string) ($formData['notify_contact'] ?? ''));
    $notifyEmail = trim((string) ($formData['notify_email'] ?? ''));
    if ($notifyName === '' || !is_valid_phone_number($notifyContact) || !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'The person to be notified must have a name, valid contact number, and valid email address.';
    }
    if ($mode === 'individual' && !empty($formData['applicant_person_id'])) {
        try {
            $formData['applicant_person_id'] = public_selected_person_id(
                $pdo,
                $formData['applicant_person_id'],
                (string) ($formData['applicant_first'] ?? ''),
                (string) ($formData['applicant_middle'] ?? ''),
                (string) ($formData['applicant_last'] ?? ''),
                (string) ($formData['applicant_suffix'] ?? ''),
                (string) ($formData['applicant_birthdate'] ?? '')
            );
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
    if (!$errors) {
        $submissionCap = public_submission_allowed($pdo, [$notifyEmail]);
        if (!$submissionCap['ok']) {
            $errors[] = $submissionCap['reason'];
        }
    }

    $isSecretary = (current_user()['role'] ?? '') === 'secretary';
    $channel = $isSecretary ? 'onsite' : 'online';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $groupReference = $mode === 'group' ? 'COMM-' . generate_tracking_token() : null;
            $groupLabel = trim((string) ($formData['group_label'] ?? ''));
            $submissionRefs = [];
            $applicationIds = [];
            $batchId = null;
            if ($mode === 'group') {
                $calendarYear = date('Y');
                $batchName = ($groupLabel !== '' ? $groupLabel . ' — ' : '')
                    . 'Holy Communion — ' . $calendarYear;
                $createBatch = $pdo->prepare(
                    'INSERT INTO sacrament_batches (sacrament_type, grade_level, school_year, batch_name, created_by)
                     VALUES (\'first_communion\', \'general\', ?, ?, ?)'
                );
                $createBatch->execute([
                    $calendarYear,
                    $batchName,
                    $isSecretary ? (int) current_user()['id'] : null,
                ]);
                $batchId = (int) $pdo->lastInsertId();
            }
            foreach ($candidates as $candidate) {
                $name = trim((string) ($candidate['full_name'] ?? ''));
                $birthDate = $mode === 'individual' ? (string) ($candidate['birth_date'] ?? '') : '';
                $ageAssessment = $mode === 'individual'
                    ? sacrament_age_assessment($pdo, 'first_communion', calculate_age($birthDate))
                    : null;
                $identity = [
                    'birth_date' => $birthDate,
                ];
                if ($mode === 'individual') {
                    $identity += [
                        'first_name' => (string) ($candidate['first_name'] ?? ''),
                        'middle_name' => (string) ($candidate['middle_name'] ?? ''),
                        'last_name' => (string) ($candidate['last_name'] ?? ''),
                        'suffix' => (string) ($candidate['suffix'] ?? ''),
                        'birth_place' => (string) ($formData['applicant_birthplace'] ?? ''),
                        'father_name' => (string) ($formData['father_name'] ?? ''),
                        'mother_maiden_name' => (string) ($formData['mother_name'] ?? ''),
                    ];
                }
                $personId = null;
                if ($mode === 'individual' && !empty($formData['applicant_person_id'])) {
                    $personId = (int) $formData['applicant_person_id'];
                }
                $profile = null;
                if ($mode === 'individual') {
                    $profile = public_person_reference($pdo, $personId, $identity);
                    $personId = (int) $profile['person_id'];
                    public_assert_no_open_sacrament_application($pdo, 'first_communion', [$personId], $profile['reference']);
                }
                $baptismRequirement = $personId !== null
                    ? verified_parish_record_requirement($pdo, $personId, 'baptism')
                    : null;
                $requestNo = generate_request_no($pdo, 'sacrament_applications');
                $details = [
                    'candidate_name' => $name,
                    'formation_path' => $mode === 'group' ? 'batch' : 'individual_catchup',
                    'school_grade' => $mode === 'group' ? 'general' : '',
                    'school_year' => $mode === 'group' ? date('Y') : null,
                    'group_label' => $mode === 'group' ? $groupLabel : '',
                    'group_reference' => $groupReference,
                    'intake_mode' => $mode,
                    'contact_person_name' => $notifyName,
                    'contact_person_number' => $notifyContact,
                    'contact_person_email' => $notifyEmail,
                    'preferred_priest' => trim((string) ($formData['preferred_priest'] ?? '')),
                ];
                if ($mode === 'group') {
                    $venue = (string) ($formData['communion_venue'] ?? 'parish_church');
                    $details['communion_venue'] = $venue;
                    $details['communion_venue_other'] = $venue === 'other'
                        ? trim((string) ($formData['communion_venue_other'] ?? ''))
                        : '';
                } else {
                    $details += [
                        'candidate_birth_date' => $birthDate,
                        'age_at_submission' => $ageAssessment['age'],
                        'age_eligibility_status' => $ageAssessment['status'],
                        'father_name' => (string) ($identity['father_name'] ?? ''),
                        'mother_maiden_name' => (string) ($identity['mother_maiden_name'] ?? ''),
                    ];
                }
                $requirements = [
                    'origin_baptismal_certificate' => $baptismRequirement
                        ?? ['status' => 'not_received', 'note' => ''],
                    'first_reconciliation' => ['status' => 'not_received', 'note' => ''],
                    'catechism_completion' => ['status' => 'not_received', 'note' => ''],
                    'parent_guardian_commitment' => [
                        'status' => $mode === 'group'
                            ? 'not_received'
                            : (calculate_age($birthDate) < 18 ? 'not_received' : 'not_applicable'),
                        'note' => $mode === 'group'
                            ? 'Date of birth is not collected on the group form; confirm whether a parent or guardian commitment applies.'
                            : '',
                    ],
                ];
                $insert = $pdo->prepare(
                    "INSERT INTO sacrament_applications
                     (request_no, channel, sacrament_type, person_id, batch_id, applicant_name, applicant_email,
                      applicant_contact, status, details, requirements, privacy_consent_at)
                     VALUES (?, ?, 'first_communion', ?, ?, ?, ?, ?, 'new', ?, ?, NOW())"
                );
                $insert->execute([
                    $requestNo,
                    $channel,
                    $personId,
                    $batchId,
                    $name,
                    $notifyEmail,
                    $notifyContact,
                    json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    json_encode($requirements, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                $applicationId = (int) $pdo->lastInsertId();
                $applicationIds[] = $applicationId;
                $submissionRefs[] = [
                    'name' => $name,
                    'reference' => (string) ($profile['reference'] ?? ''),
                    'request_no' => $requestNo,
                    'person_id' => $personId,
                    'application_id' => $applicationId,
                ];
                log_activity(
                    $pdo,
                    $isSecretary ? (int) current_user()['id'] : null,
                    'sacrament_application_submitted',
                    'sacrament_applications',
                    $applicationId,
                    "Holy Communion pre-application submitted for {$name}"
                );
            }
            $firstApplicationId = (int) $applicationIds[0];
            $firstRequestNo = (string) $submissionRefs[0]['request_no'];
            if ($channel === 'online') {
                notify(
                    $pdo,
                    'application_new',
                    'New Holy Communion pre-application',
                    $mode === 'group'
                        ? count($submissionRefs) . ' candidates submitted for ' . ($groupLabel !== '' ? $groupLabel : 'a group') . ' (' . $firstRequestNo . ')'
                        : 'Holy Communion pre-application for ' . $submissionRefs[0]['name'] . ' (' . $firstRequestNo . ')',
                    '/admin/requests/applications-queue.php'
                );
                record_public_submission($pdo, [$notifyEmail], 'application_first_communion');
            }
            $pdo->commit();
            $requestNo = $firstRequestNo;
            $trackingNumber = $mode === 'group'
                ? (string) $submissionRefs[0]['request_no']
                : (string) $submissionRefs[0]['reference'];
            foreach ($submissionRefs as $member) {
                $successMembers[] = $member;
            }
            if ($isSecretary) {
                redirect_to('/admin/requests/applications-queue.php?added=' . rawurlencode($firstRequestNo));
                exit;
            }
            try {
                if ($mode === 'group') {
                    $requestedGroupVenue = ($formData['communion_venue'] ?? 'parish_church') === 'other'
                        ? trim((string) ($formData['communion_venue_other'] ?? ''))
                        : 'St. Patrick’s Parish Church';
                    $emailSent = mail_communion_group_received(
                        $notifyEmail,
                        $groupLabel,
                        $submissionRefs,
                        $pdo,
                        $firstApplicationId,
                        $requestedGroupVenue
                    );
                } else {
                    $emailSent = mail_request_received(
                        $notifyEmail,
                        $trackingNumber,
                        'Holy Communion Application',
                        $pdo,
                        'sacrament_applications',
                        $firstApplicationId,
                        $firstRequestNo
                    );
                }
                $emailDeliveryWarning = !$emailSent;
            } catch (Throwable $mailException) {
                error_log('Holy Communion receipt email failed after application save: ' . $mailException->getMessage());
                $emailDeliveryWarning = true;
            }
            unset($_SESSION['communion_intake']);
        } catch (InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $trackingNumber = null;
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $trackingNumber = null;
            error_log('Holy Communion application failed: ' . $exception->getMessage());
            $errors[] = 'Something went wrong while submitting the pre-application. Please try again.';
        }
    }
}

$mode = $submissionMode ?? (string) ($formData['mode'] ?? 'group');
require_once __DIR__ . '/../../includes/header.php';
function communion_old(string $key): string
{
    global $formData;
    return e(is_scalar($formData[$key] ?? null) ? (string) $formData[$key] : '');
}
?>
<section class="request-form communion-intake">
    <header class="communion-page-heading">
        <p class="communion-kicker">PARISH SACRAMENT INTAKE</p>
        <h1>Holy Communion Pre-Application</h1>
        <p>Choose how you are applying. The Parish Office will review the details before confirming preparation or a date.</p>
    </header>
    <?php if ($trackingNumber !== null): ?>
        <div class="alert alert-success" role="status">
            <h2>Your pre-application has been submitted</h2>
            <p>The Parish Office will review the <?= $mode === 'group' ? 'candidate information' : 'applicant information' ?> and contact the person listed for updates. Submission does not confirm approval or a date.</p>
            <?php if ($mode === 'group'): ?>
                <p>Keep the request numbers below. Use a candidate’s request number and the group contact email to track that application.</p>
            <?php else: ?>
                <p>Keep the person reference below. Use it with the notification email address to view this individual request.</p>
            <?php endif; ?>
            <?php if ($emailDeliveryWarning): ?>
                <p class="field-hint">Your application was saved, but receipt email delivery could not be confirmed. Keep the references below and contact the Parish Office if you do not receive the email.</p>
            <?php endif; ?>
            <ul>
                <?php foreach ($successMembers as $member): ?>
                    <li><?= e($member['name']) ?> — Request no. <strong><?= e($member['request_no']) ?></strong>
                        <?php if ($member['reference'] !== ''): ?> · Person reference <?= e($member['reference']) ?><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <p>Prepare the candidate’s PSA birth certificate or local civil registrar birth certificate and Baptismal Certificate if no verified parish record is on file. The Parish Office will confirm preparation requirements.</p>
            <a class="btn" href="<?= e($basePath . '/public/track-request.php') ?>">View My Requests</a>
        </div>
    <?php else: ?>
        <?php if ($currentStep === 1): $applicationType = 'first_communion'; require __DIR__ . '/../../includes/public-requirements-notice.php'; endif; ?>
        <?php if ($errors !== []): ?>
            <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <ol class="request-steps" aria-label="Pre-application progress">
            <?php foreach ($steps as $step => $label): ?>
                <?php $state = $step === $currentStep ? 'is-current' : ($currentStep > $step ? 'is-done' : ''); ?>
                <li class="<?= $state ?>"<?= $step === $currentStep ? ' aria-current="step"' : '' ?>><span><?= $step ?></span> <?= e($label) ?></li>
            <?php endforeach; ?>
        </ol>
        <h2 class="form-stage-heading"><?= $currentStep === 1 ? 'Choose an application type' : ($currentStep === 2 ? 'Application details' : 'Review your information') ?></h2>
        <form method="post" class="communion-intake-form" data-communion-mode="<?= e($mode) ?>" data-preserve-scroll="false">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="<?= $currentStep < 3 ? 'next' : 'final_submit' ?>">
            <?php if ($currentStep === 1): ?>
                <fieldset>
                    <legend>Choose a format</legend>
                    <p class="communion-step-intro">Communion is often celebrated as a group, but individual applications are welcome too.</p>
                    <div class="communion-mode-options" role="radiogroup" aria-label="Communion application type">
                        <label class="communion-choice">
                            <input type="radio" name="mode" value="group" <?= $mode === 'group' ? 'checked' : '' ?>>
                            <span class="communion-choice-icon" aria-hidden="true">
                                <svg viewBox="0 0 32 32" fill="none"><circle cx="12" cy="10" r="4" /><circle cx="23" cy="12" r="3" /><path d="M4.5 25v-2a7.5 7.5 0 0 1 15 0v2H4.5Z" /><path d="M21 19a5.5 5.5 0 0 1 6.5 5.4V25H22" /></svg>
                            </span>
                            <span class="communion-choice-copy">
                                <strong>Group Communion</strong>
                                <span>Submit a candidate roster with one person to coordinate updates.</span>
                            </span>
                            <span class="communion-choice-tag">Default</span>
                        </label>
                        <label class="communion-choice">
                            <input type="radio" name="mode" value="individual" <?= $mode === 'individual' ? 'checked' : '' ?>>
                            <span class="communion-choice-icon" aria-hidden="true">
                                <svg viewBox="0 0 32 32" fill="none"><circle cx="16" cy="10" r="5" /><path d="M6.5 26v-2.5a9.5 9.5 0 0 1 19 0V26h-19Z" /></svg>
                            </span>
                            <span class="communion-choice-copy">
                                <strong>Individual Communion</strong>
                                <span>Submit one applicant’s details, including an individual catch-up case where applicable.</span>
                            </span>
                            <span class="communion-choice-tag">One applicant</span>
                        </label>
                    </div>
                    <p class="communion-step-note" data-communion-mode-note><?= $mode === 'group'
                        ? '<strong>What happens next?</strong> The parish reviews the group together while keeping one request number and individual record per candidate.'
                        : '<strong>What happens next?</strong> The parish reviews this applicant’s request individually. Date of birth and parish-profile lookup support accurate record matching.' ?></p>
                </fieldset>
            <?php elseif ($currentStep === 2): ?>
                <fieldset>
                    <legend><?= $mode === 'group' ? 'Group details' : 'Applicant details' ?></legend>
                    <?php if ($mode === 'group'): ?>
                        <label>Group or organization name <span class="field-hint">(optional)</span>
                            <input type="text" name="group_label" maxlength="80" value="<?= communion_old('group_label') ?>" placeholder="For example, Parish Catechism Group">
                        </label>
                        <label>Candidate names <span class="required-marker" aria-hidden="true">*</span>
                            <span class="field-hint">Enter one full name per line.</span>
                            <textarea name="roster_text" rows="6" maxlength="15000" data-communion-roster required><?= communion_old('roster_text') ?></textarea>
                        </label>
                        <label>Requested venue for the group
                            <select name="communion_venue" data-communion-venue>
                                <option value="parish_church" <?= ($formData['communion_venue'] ?? 'parish_church') === 'parish_church' ? 'selected' : '' ?>>This parish church (St. Patrick’s Parish)</option>
                                <option value="other" <?= ($formData['communion_venue'] ?? '') === 'other' ? 'selected' : '' ?>>Another parish church, chapel, or community venue</option>
                            </select>
                        </label>
                        <label data-communion-other-venue <?= ($formData['communion_venue'] ?? '') === 'other' ? '' : 'hidden' ?>>Requested venue name and location <span class="required-marker" aria-hidden="true">*</span>
                            <input type="text" name="communion_venue_other" maxlength="255" value="<?= communion_old('communion_venue_other') ?>" <?= ($formData['communion_venue'] ?? '') === 'other' ? 'required' : 'disabled' ?>>
                        </label>
                        <fieldset class="communion-notify-person">
                            <legend>Group contact</legend>
                            <p class="field-hint">This person will receive the receipt and shared schedule update.</p>
                            <label>Full name <span class="required-marker" aria-hidden="true">*</span> <input type="text" name="notify_name" maxlength="150" value="<?= communion_old('notify_name') ?>" required></label>
                            <div class="inline-fields">
                                <label>Contact number <span class="required-marker" aria-hidden="true">*</span> <input type="tel" name="notify_contact" maxlength="30" value="<?= communion_old('notify_contact') ?>" required></label>
                                <label>Email address <span class="required-marker" aria-hidden="true">*</span> <input type="email" name="notify_email" maxlength="150" value="<?= communion_old('notify_email') ?>" required></label>
                            </div>
                        </fieldset>
                    <?php else: ?>
                        <div class="public-person-lookup" data-public-person-lookup
                             data-lookup-context="preapplication"
                             data-endpoint="<?= e($basePath . '/public/certificate-request.php') ?>"
                             data-name-inputs="applicant_first,applicant_middle,applicant_last,applicant_suffix"
                             data-birth-field="applicant_birthdate" data-birth-place-field="applicant_birthplace" data-person-id-field="applicant_person_id"
                             data-contact-field="notify_contact" data-email-field="notify_email"
                             data-father-value-field="father_name" data-mother-value-field="mother_name">
                            <h3 class="lookup-title">Already registered with the parish?</h3>
                            <p class="field-hint">A person reference can fill recorded information. Keep the reference private.</p>
                            <div data-person-results aria-live="polite"></div>
                        </div>
                        <h3 class="form-subheading">Applicant</h3>
                        <div class="communion-form-grid">
                            <label>First name <span class="required-marker" aria-hidden="true">*</span> <input type="text" name="applicant_first" autocomplete="given-name" value="<?= communion_old('applicant_first') ?>" required></label>
                            <label>Middle name <span class="field-hint">(optional)</span> <input type="text" name="applicant_middle" autocomplete="additional-name" value="<?= communion_old('applicant_middle') ?>"></label>
                            <label>Last name <span class="required-marker" aria-hidden="true">*</span> <input type="text" name="applicant_last" autocomplete="family-name" value="<?= communion_old('applicant_last') ?>" required></label>
                            <label>Suffix <span class="field-hint">(optional)</span> <input type="text" name="applicant_suffix" autocomplete="honorific-suffix" value="<?= communion_old('applicant_suffix') ?>"></label>
                            <label>Date of birth <span class="required-marker" aria-hidden="true">*</span> <input type="date" name="applicant_birthdate" max="<?= e(date('Y-m-d')) ?>" value="<?= communion_old('applicant_birthdate') ?>" required></label>
                            <label>Place of birth <span class="field-hint">(optional)</span> <input type="text" name="applicant_birthplace" maxlength="150" value="<?= communion_old('applicant_birthplace') ?>"></label>
                        </div>
                        <input type="hidden" name="applicant_person_id" value="<?= communion_old('applicant_person_id') ?>">
                        <h3 class="form-subheading">Family information <span class="field-hint">(optional)</span></h3>
                        <div class="inline-fields">
                            <label>Father’s name <input type="text" name="father_name" maxlength="150" value="<?= communion_old('father_name') ?>"></label>
                            <label>Mother’s maiden name <input type="text" name="mother_name" maxlength="150" value="<?= communion_old('mother_name') ?>"></label>
                        </div>
                        <fieldset class="communion-notify-person">
                            <legend>Person to be notified</legend>
                            <label>Full name <span class="required-marker" aria-hidden="true">*</span> <input type="text" name="notify_name" maxlength="150" value="<?= communion_old('notify_name') ?>" required></label>
                            <div class="inline-fields">
                                <label>Contact number <span class="required-marker" aria-hidden="true">*</span> <input type="tel" name="notify_contact" maxlength="30" value="<?= communion_old('notify_contact') ?>" required></label>
                                <label>Email address <span class="required-marker" aria-hidden="true">*</span> <input type="email" name="notify_email" maxlength="150" value="<?= communion_old('notify_email') ?>" required></label>
                            </div>
                            <p class="field-hint">Updates and scheduling information will be sent to this contact.</p>
                        </fieldset>
                    <?php endif; ?>
                    <label class="communion-priest-field">Requested officiating priest <span class="field-hint">(optional)</span>
                        <input type="text" name="preferred_priest" maxlength="150" value="<?= communion_old('preferred_priest') ?>">
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="privacy_consent" value="1" <?= ($formData['privacy_consent'] ?? '') === '1' ? 'checked' : '' ?> required>
                        <span>I have read and agree to the <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a> and am authorized to submit these details.</span>
                    </label>
                </fieldset>
            <?php else: ?>
                <fieldset>
                    <legend>Phase 3 of 3 — Review and submit</legend>
                    <?php
                    $reviewBirthDate = trim((string) ($formData['applicant_birthdate'] ?? ''));
                    $reviewBirthDateTimestamp = $reviewBirthDate !== '' ? strtotime($reviewBirthDate) : false;
                    ?>
                    <dl class="communion-review-grid">
                    <?php if ($mode === 'group'): ?>
                        <div>
                            <dt>Application type</dt>
                            <dd>Group Communion</dd>
                        </div>
                        <?php if (trim((string) ($formData['group_label'] ?? '')) !== ''): ?>
                            <div>
                                <dt>Group or organization</dt>
                                <dd><?= communion_old('group_label') ?></dd>
                            </div>
                        <?php endif; ?>
                        <div class="communion-review-grid__wide">
                            <dt>Candidates <span class="communion-review-count">(<?= count((array) ($formData['candidates'] ?? [])) ?>)</span></dt>
                            <dd>
                                <ul class="communion-review-candidates">
                                    <?php foreach ((array) ($formData['candidates'] ?? []) as $candidate): ?>
                                        <li><?= e((string) ($candidate['full_name'] ?? '')) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </dd>
                        </div>
                        <div>
                            <dt>Requested venue</dt>
                            <dd>
                                <?= ($formData['communion_venue'] ?? 'parish_church') === 'other'
                                    ? communion_old('communion_venue_other')
                                    : 'St. Patrick’s Parish Church' ?>
                                <span class="communion-review-note">Requested; subject to parish confirmation</span>
                            </dd>
                        </div>
                        <div>
                            <dt>Person to be notified</dt>
                            <dd><?= communion_old('notify_name') ?></dd>
                        </div>
                        <div>
                            <dt>Contact number</dt>
                            <dd><?= communion_old('notify_contact') ?></dd>
                        </div>
                        <div>
                            <dt>Email address</dt>
                            <dd><?= communion_old('notify_email') ?></dd>
                        </div>
                    <?php else: ?>
                        <div>
                            <dt>Applicant</dt>
                            <dd><?= e(compose_person_name($formData, 'applicant')) ?></dd>
                        </div>
                        <div>
                            <dt>Date of birth</dt>
                            <dd><?= $reviewBirthDateTimestamp !== false ? e(date('F j, Y', $reviewBirthDateTimestamp)) : '—' ?></dd>
                        </div>
                        <?php if (trim((string) ($formData['applicant_birthplace'] ?? '')) !== ''): ?>
                            <div>
                                <dt>Place of birth</dt>
                                <dd><?= communion_old('applicant_birthplace') ?></dd>
                            </div>
                        <?php endif; ?>
                        <div>
                            <dt>Father’s name</dt>
                            <dd><?= communion_old('father_name') ?: '—' ?></dd>
                        </div>
                        <div>
                            <dt>Mother’s maiden name</dt>
                            <dd><?= communion_old('mother_name') ?: '—' ?></dd>
                        </div>
                        <div>
                            <dt>Person to be notified</dt>
                            <dd><?= communion_old('notify_name') ?></dd>
                        </div>
                        <div>
                            <dt>Contact number</dt>
                            <dd><?= communion_old('notify_contact') ?></dd>
                        </div>
                        <div>
                            <dt>Email address</dt>
                            <dd><?= communion_old('notify_email') ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (trim((string) ($formData['preferred_priest'] ?? '')) !== ''): ?>
                        <div>
                            <dt>Requested officiating priest</dt>
                            <dd><?= communion_old('preferred_priest') ?></dd>
                        </div>
                    <?php endif; ?>
                    </dl>
                    <p class="alert alert-info communion-review-notice" role="note">The Parish Office will review the Baptism record and preparation requirements. This pre-application does not confirm approval or a schedule.</p>
                    <label class="checkbox-label">
                        <input type="checkbox" name="certify_accuracy" value="1" <?= ($formData['certify_accuracy'] ?? '') === '1' ? 'checked' : '' ?> required>
                        <span>I certify that the information is true and correct.</span>
                    </label>
                </fieldset>
            <?php endif; ?>
            <div class="form-actions">
                <?php if ($currentStep > 1): ?><button type="submit" class="btn btn-secondary" formnovalidate data-communion-back>Back</button><?php endif; ?>
                <?php if ($currentStep < 3): ?><button type="submit" name="_action" value="next" class="btn"><?= $currentStep === 1 ? 'Continue to details' : 'Continue to review' ?></button><?php else: ?><button type="submit" name="_action" value="final_submit" class="btn">Submit Pre-Application</button><?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</section>
<script>
(function () {
    var form = document.querySelector('.communion-intake-form');
    if (!form) return;
    var backButton = form.querySelector('[data-communion-back]');
    var actionInput = form.querySelector('input[name="_action"]');
    if (backButton && actionInput) {
        backButton.addEventListener('click', function () {
            actionInput.value = 'back';
        });
    }
    var modeNote = form.querySelector('[data-communion-mode-note]');
    if (modeNote) {
        var updateModeNote = function () {
            var selectedMode = form.querySelector('input[name="mode"]:checked');
            var isGroup = selectedMode && selectedMode.value === 'group';
            modeNote.replaceChildren();
            var heading = document.createElement('strong');
            heading.textContent = 'What happens next?';
            modeNote.appendChild(heading);
            modeNote.appendChild(document.createTextNode(isGroup
                ? ' The parish reviews the group together while keeping one request number and individual record per candidate.'
                : ' The parish reviews this applicant’s request individually. Date of birth and parish-profile lookup support accurate record matching.'));
        };
        form.querySelectorAll('input[name="mode"]').forEach(function (radio) {
            radio.addEventListener('change', updateModeNote);
        });
        updateModeNote();
    }
    var venueSelect = form.querySelector('[data-communion-venue]');
    var otherVenue = form.querySelector('[data-communion-other-venue]');
    if (venueSelect && otherVenue) {
        var otherVenueInput = otherVenue.querySelector('input');
        var updateVenue = function () {
            var isOther = venueSelect.value === 'other';
            otherVenue.hidden = !isOther;
            otherVenueInput.disabled = !isOther;
            otherVenueInput.required = isOther;
        };
        venueSelect.addEventListener('change', updateVenue);
        updateVenue();
    }
}());
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
