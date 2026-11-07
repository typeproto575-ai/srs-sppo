<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/sacrament-data.php';

$basePath = $basePath ?? base_path();
$pageTitle = (current_user()['role'] ?? '') === 'secretary'
    ? 'Walk-in Certificate Request'
    : 'Request a Certificate';
$trackingNumber = null;

if (($_GET['person_lookup'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    $now = time();
    $attempts = $_SESSION['public_person_lookup_attempts'] ?? [];
    $attempts = is_array($attempts) ? array_values(array_filter($attempts, static fn($time) => is_int($time) && $time > $now - 900)) : [];
    if (count($attempts) >= 30) {
        http_response_code(429);
        echo json_encode(['error' => 'Search limit reached. Please wait before trying again.']);
        exit;
    }
    $attempts[] = $now;
    $_SESSION['public_person_lookup_attempts'] = $attempts;

    $fullName = trim(is_string($_GET['name'] ?? null) ? $_GET['name'] : '');
    $birthDate = trim(is_string($_GET['birth_date'] ?? null) ? $_GET['birth_date'] : '');
    $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $date = DateTime::createFromFormat('Y-m-d', $birthDate);
    if (count($parts) < 2 || strlen($fullName) > 150 || !$date || $date->format('Y-m-d') !== $birthDate) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a full name and valid date of birth to search.']);
        exit;
    }

    $parts = array_values(array_filter($parts, static fn($part) => strlen($part) >= 2));
    if (count($parts) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    $conditions = [];
    $params = [$birthDate];
    foreach ($parts as $part) {
        $conditions[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR suffix LIKE ?)";
        $term = '%' . addcslashes($part, '\\_%') . '%';
        array_push($params, $term, $term, $term, $term);
    }
    $sql = 'SELECT id, first_name, middle_name, last_name, suffix, birth_date, father_name, mother_maiden_name
            FROM persons
            WHERE birth_date = ? AND ' . implode(' AND ', $conditions) . '
            ORDER BY last_name, first_name
            LIMIT 5';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $person) {
            $results[] = [
                'id' => (int) $person['id'],
                'first_name' => $person['first_name'],
                'middle_name' => $person['middle_name'] ?? '',
                'last_name' => $person['last_name'],
                'suffix' => $person['suffix'] ?? '',
                'birth_date' => $person['birth_date'],
                'father_name' => $person['father_name'] ?? '',
                'mother_maiden_name' => $person['mother_maiden_name'] ?? '',
            ];
        }
        echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('SRS-SPPO public person lookup failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Person search is temporarily unavailable. Please try again later.']);
    }
    exit;
}

$submitted = isset($_GET['submitted']) && $_GET['submitted'] === '1';
if ($submitted && is_string($_SESSION['certificate_success'] ?? null)) {
    $trackingNumber = $_SESSION['certificate_success'];
    unset($_SESSION['certificate_success']);
}

$values = [
    'certificate_type' => '',
    'subject_name' => '',
    'person_id' => '',
    'spouse_name' => '',
    'marriage_date' => '',
    'birth_date' => '',
    'father_name' => '',
    'mother_maiden_name' => '',
    'event_date' => '',
    'requester_name' => '',
    'requester_relationship' => '',
    'requester_relationship_other' => '',
    'requester_email' => '',
    'requester_contact_number' => '',
    'purpose' => '',
    'purpose_other' => '',
];
$certificateTypes = [
    'baptism' => 'Baptism',
    'first_communion' => 'First Communion',
    'confirmation' => 'Confirmation',
    'marriage' => 'Marriage',
];
$certificatePreparation = [
    'baptism' => [
        'The name and birthdate recorded in the parish register.',
        "The father's name and mother's maiden name, if known.",
        'The approximate Baptism date, if known.',
    ],
    'first_communion' => [
        'The name and birthdate recorded in the parish register.',
        'Parent names and the approximate First Communion date, if known.',
    ],
    'confirmation' => [
        'The name and birthdate recorded in the parish register.',
        'Parent names and the approximate Confirmation date, if known.',
    ],
    'marriage' => [
        'Both spouses’ names and the approximate marriage date.',
    ],
];
$walkInRecord = null;
$walkInRecordId = null;
$walkInRecordInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['walkin_record_id'] ?? null)
    : ($_GET['record_id'] ?? null);
$walkInRecordInput = is_string($walkInRecordInput) ? trim($walkInRecordInput) : '';
$errors = [];

if ($walkInRecordInput !== '' && (current_user()['role'] ?? '') === 'secretary') {
    require_role('secretary');
}

if ($walkInRecordInput !== '') {
    $parsedRecordId = filter_var($walkInRecordInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$parsedRecordId) {
        $errors[] = 'The selected register record is invalid. Open the request from the sacramental record again.';
    } elseif ((current_user()['role'] ?? '') !== 'secretary') {
        http_response_code(403);
        $errors[] = 'Only parish secretaries can create a walk-in request from a sacramental record.';
    } else {
        $candidateRecord = sd_get_record($pdo, (int) $parsedRecordId);
        $candidatePerson = $candidateRecord ? sd_get_person($pdo, (int) $candidateRecord['person_id']) : null;
        if (!$candidateRecord || !$candidatePerson || !sd_can_print_certificate($candidateRecord)) {
            $errors[] = 'This record is unavailable for certificate requests. It must be verified and belong to this parish.';
        } elseif (!isset($certificateTypes[$candidateRecord['sacrament_type']])) {
            $errors[] = 'This sacrament type cannot be requested through this form.';
        } else {
            $walkInRecordId = (int) $parsedRecordId;
            $walkInRecord = $candidateRecord;
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $values['certificate_type'] = $candidateRecord['sacrament_type'];
                $values['subject_name'] = $candidatePerson['full_name'];
                $values['person_id'] = (string) $candidatePerson['id'];
                $values['birth_date'] = (string) ($candidatePerson['birth_date'] ?? '');
                $values['father_name'] = (string) ($candidatePerson['father_name'] ?? '');
                $values['mother_maiden_name'] = (string) ($candidatePerson['mother_maiden_name'] ?? '');
                $values['event_date'] = (string) ($candidateRecord['event_date'] ?? '');
                if ($candidateRecord['sacrament_type'] === 'marriage') {
                    $values['spouse_name'] = (string) ($candidateRecord['spouse_name'] ?? '');
                    $values['marriage_date'] = (string) ($candidateRecord['event_date'] ?? '');
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requestedType = $_GET['type'] ?? null;
    if (!$walkInRecord && is_string($requestedType) && isset($certificateTypes[$requestedType])) {
        $values['certificate_type'] = $requestedType;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$submitted) {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        foreach (array_keys($values) as $key) {
            if (isset($_POST[$key]) && is_string($_POST[$key])) {
                $values[$key] = trim($_POST[$key]);
            }
        }
        if ($walkInRecord) {
            $person = sd_get_person($pdo, (int) $walkInRecord['person_id']);
            if (!$person) {
                $errors[] = 'The person linked to this register record is unavailable.';
            } else {
                $values['certificate_type'] = $walkInRecord['sacrament_type'];
                $values['subject_name'] = $person['full_name'];
                $values['person_id'] = (string) $person['id'];
                $values['birth_date'] = (string) ($person['birth_date'] ?? '');
                $values['father_name'] = (string) ($person['father_name'] ?? '');
                $values['mother_maiden_name'] = (string) ($person['mother_maiden_name'] ?? '');
                $values['event_date'] = (string) ($walkInRecord['event_date'] ?? '');
                if ($walkInRecord['sacrament_type'] === 'marriage') {
                    $values['spouse_name'] = (string) ($walkInRecord['spouse_name'] ?? '');
                    $values['marriage_date'] = (string) ($walkInRecord['event_date'] ?? '');
                }
            }
        }
        if ($values['requester_relationship'] === 'self') {
            $values['requester_name'] = $values['subject_name'];
        }
        $honeypot = (isset($_POST['website']) && is_string($_POST['website'])) ? trim($_POST['website']) : '';
        if ($honeypot !== '') {
            header('Location: ' . $basePath . '/public/certificate-request.php?submitted=1');
            exit;
        }

        $purposeLabels = ['School', 'Employment', 'Passport', 'Marriage', 'Personal records', 'Other'];
        $relationships = ['self', 'parent', 'sibling', 'child', 'other'];

        if (!isset($certificateTypes[$values['certificate_type']])) {
            $errors[] = 'Choose a certificate type.';
        }
        if ($values['subject_name'] === '') {
            $errors[] = 'Enter the full name on the certificate.';
        } elseif (strlen($values['subject_name']) > 150) {
            $errors[] = 'The name on the certificate must be 150 characters or fewer.';
        }
        if ($values['certificate_type'] === 'marriage' && $values['spouse_name'] === '') {
            $errors[] = 'Enter the bride\'s full name.';
        }
        foreach (['birth_date', 'event_date', 'marriage_date'] as $dateKey) {
            if ($values[$dateKey] !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $values[$dateKey]);
                if (!$d || $d->format('Y-m-d') !== $values[$dateKey]) {
                    $errors[] = 'Enter a valid date.';
                    break;
                }
            }
        }
        if ($values['requester_name'] === '') {
            $errors[] = 'Enter your full name.';
        } elseif (strlen($values['requester_name']) > 150) {
            $errors[] = 'Your name must be 150 characters or fewer.';
        }
        if (!in_array($values['requester_relationship'], $relationships, true)) {
            $errors[] = 'Choose your relationship to the person named.';
        }
        if ($values['requester_email'] === '' || !filter_var($values['requester_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($values['requester_contact_number'] !== '' && strlen($values['requester_contact_number']) > 30) {
            $errors[] = 'The contact number must be 30 characters or fewer.';
        }
        if (!in_array($values['purpose'], $purposeLabels, true)) {
            $errors[] = 'Choose a purpose.';
        }
        if ($values['purpose'] === 'Other' && $values['purpose_other'] === '') {
            $errors[] = 'Describe the purpose.';
        }
        if ($values['purpose_other'] !== '' && strlen($values['purpose_other']) > 150) {
            $errors[] = 'The purpose details must be 150 characters or fewer.';
        }
        if (empty($_POST['privacy_consent'])) {
            $errors[] = 'You must agree to the privacy notice.';
        }

        $selectedPersonId = null;
        if ($walkInRecord) {
            $selectedPersonId = (int) $walkInRecord['person_id'];
        } elseif ($values['person_id'] !== '') {
            [$personFirst, $personMiddle, $personLast, $personSuffix] = structured_name_parts($values['subject_name']);
            try {
                $selectedPersonId = public_selected_person_id(
                    $pdo,
                    $values['person_id'],
                    $personFirst,
                    $personMiddle,
                    $personLast,
                    $personSuffix,
                    $values['birth_date']
                );
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (empty($errors)) {
            $isSecretary = (current_user()['role'] ?? '') === 'secretary';
            $channel     = $isSecretary ? 'onsite' : 'online';
            $email = $values['requester_email'];
            if ($channel === 'online' && public_request_throttled($pdo, $email)) {
                $errors[] = 'We already received several requests from this email. Please wait a while, or visit the parish office.';
            } else {
                $details = [];
                if ($values['birth_date'] !== '') {
                    $details['birth_date'] = $values['birth_date'];
                }
                if ($values['father_name'] !== '') {
                    $details['father_name'] = $values['father_name'];
                }
                if ($values['mother_maiden_name'] !== '') {
                    $details['mother_maiden_name'] = $values['mother_maiden_name'];
                }
                if ($values['event_date'] !== '') {
                    $details['event_date'] = $values['event_date'];
                }
                if ($values['certificate_type'] === 'marriage') {
                    if ($values['spouse_name'] !== '') {
                        $details['spouse_name'] = $values['spouse_name'];
                    }
                    if ($values['marriage_date'] !== '') {
                        $details['marriage_date'] = $values['marriage_date'];
                    }
                }
                $subjectDetails = empty($details) ? null : json_encode($details, JSON_UNESCAPED_UNICODE);
                $requesterRelationshipOther = ($values['requester_relationship'] === 'other') ? 'Authorized Representative' : null;
                $purposeValue = $values['purpose'];
                $purposeOther = ($values['purpose'] === 'Other') ? $values['purpose_other'] : null;
                $requesterContact = ($values['requester_contact_number'] !== '') ? $values['requester_contact_number'] : null;
                $readableType = $certificateTypes[$values['certificate_type']];

                $inserted = false;
                $requestId = null;
                $requestNo = '';
                try {
                    for ($attempt = 0; $attempt < 3; $attempt++) {
                        $requestNo = generate_request_no($pdo, 'certificate_requests');
                        try {
                            $pdo->beginTransaction();
                            if ($walkInRecord) {
                                $stmt = $pdo->prepare(
                                    "INSERT INTO certificate_requests
                                        (request_no, channel, sacrament_type, subject_name, subject_details,
                                         requester_name, requester_relationship, requester_relationship_other,
                                         requester_email, requester_contact_number, purpose, purpose_other,
                                         privacy_consent_at, person_id, record_id, matched_by, matched_at, status)
                                     VALUES (?, 'onsite', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), 'matched')"
                                );
                            } else {
                                $stmt = $pdo->prepare(
                                    "INSERT INTO certificate_requests
                                        (request_no, channel, sacrament_type, subject_name, subject_details,
                                         requester_name, requester_relationship, requester_relationship_other,
                                         requester_email, requester_contact_number, purpose, purpose_other,
                                         privacy_consent_at, person_id, status)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'new')"
                                );
                            }
                            $insertParams = [$requestNo];
                            if (!$walkInRecord) {
                                $insertParams[] = $channel;
                            }
                            array_push(
                                $insertParams,
                                $values['certificate_type'],
                                $values['subject_name'],
                                $subjectDetails,
                                $values['requester_name'],
                                $values['requester_relationship'],
                                $requesterRelationshipOther,
                                $values['requester_email'],
                                $requesterContact,
                                $purposeValue,
                                $purposeOther,
                                $selectedPersonId,
                            );
                            if ($walkInRecord) {
                                array_push($insertParams, $walkInRecordId, (int) current_user()['id']);
                            }
                            $stmt->execute($insertParams);
                            $requestId = (int) $pdo->lastInsertId();
                            if ($isSecretary) {
                                $description = $walkInRecord
                                    ? 'Walk-in certificate request created from verified sacramental record #' . $walkInRecordId . '.'
                                    : 'Walk-in certificate request created.';
                                log_activity($pdo, (int) current_user()['id'], 'certificate.request.create', 'certificate_requests', $requestId, $description);
                            }
                            $pdo->commit();
                            $inserted = true;
                            break;
                        } catch (PDOException $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062 && $attempt < 2) {
                                continue;
                            }
                            throw $e;
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $e;
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('SRS-SPPO certificate request insert failed: ' . $e->getMessage());
                    $errors[] = 'We could not save your request just now. Please try again in a moment.';
                }

                if ($inserted) {
                    if ($channel === 'online') {
                        try {
                            mail_certificate_request_received(
                                $values['requester_email'],
                                $values['requester_name'],
                                $readableType,
                                $requestNo,
                                $pdo,
                                $requestId,
                                $values['requester_relationship'] === 'self'
                            );
                        } catch (Throwable $e) {
                            error_log('SRS-SPPO certificate request email failed: ' . $e->getMessage());
                        }
                    }
                    if ($isSecretary) {
                        redirect_to('/admin/requests/queue.php?added=' . rawurlencode($requestNo));
                        exit;
                    }
                    $_SESSION['certificate_success'] = $requestNo;
                    header('Location: ' . $basePath . '/public/certificate-request.php?submitted=1');
                    exit;
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($submitted): ?>
<section class="card">
    <h1>Thank you</h1>
    <?php if ($trackingNumber): ?>
        <?php
        $successHeading = 'Keep this reference number';
        $successIsApplication = false;
        $successRequiredDocuments = [
            'Bring the request reference number. If the secretary gave you a claim stub or payment slip, bring it too; if it is missing, give the secretary the reference number and certificate name so the request can be located.',
            'For an authorized representative: signed authorization letter from the person named on the certificate',
            'Original supporting document and origin parish transfer or permission letter when requested for an external record',
        ];
        if ($values['requester_relationship'] === 'self') {
            $successRequiredDocuments[] = 'A valid ID with a name matching the person on the certificate.';
        }
        require __DIR__ . '/../includes/submission-success.php';
        ?>
    <?php else: ?>
        <p>Thank you. We received your request. Please visit the parish office to pay at the counter before processing begins; your request is pre-filled there so nothing needs to be retyped. The Parish Office may email you when it is signed and ready for pickup. If you have questions, contact the office.</p>
    <?php endif; ?>
</section>
<?php else: ?>
<section class="card">
    <?php $isSecretary = (current_user()['role'] ?? '') === 'secretary'; ?>
    <h1><?= $isSecretary ? 'Walk-in certificate request' : 'Request a certificate' ?></h1>
    <?php if ($isSecretary): ?>
        <p>Use this form when someone is requesting a certificate at the Parish Office. Choose the sacrament, enter the requester's details, and submit it to the Certificate Requests queue. To start from an existing verified register entry, open that record and choose “Start walk-in certificate request.”</p>
    <?php else: ?>
        <p>Use this form to request a certificate for a sacrament that has already taken place. Choose Baptism, First Communion, Confirmation, or Marriage, then enter the details that will help the Parish Office find the register entry.</p>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= e($basePath . '/public/certificate-request.php') ?>" class="stacked-form certificate-request-wizard" data-certificate-wizard data-certificate-prefilled="<?= $walkInRecordId ? '1' : '0' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="certificate_wizard_step" value="1" data-certificate-current-step>
        <div data-certificate-phase="record">
        <?php if ($walkInRecordId): ?>
            <input type="hidden" name="walkin_record_id" value="<?= (int) $walkInRecordId ?>">
            <section class="online-intake-notice requirements-notice" aria-label="Verified register record">
                <strong>Verified sacramental record</strong>
                <p>The person and sacrament details are filled from this verified parish history entry.</p>
            </section>
        <?php endif; ?>
        <label for="certificate_type">Certificate type *</label>
        <select id="certificate_type" name="certificate_type" required>
            <option value="">-- Select --</option>
            <?php foreach ($certificateTypes as $type => $label): ?>
                <option value="<?= e($type) ?>" <?= $values['certificate_type'] === $type ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <?php $selectedPreparation = $certificatePreparation[$values['certificate_type']] ?? []; ?>
        <section class="online-intake-notice requirements-notice" aria-label="Certificate request preparation">
            <strong id="certificate-preparation-heading"><?= $values['certificate_type'] !== '' ? 'Before: prepare for a ' . e($certificateTypes[$values['certificate_type']]) . ' certificate request' : 'Before: choose a certificate type and review what to prepare' ?></strong>
            <ul id="certificate-preparation-list" aria-live="polite">
                <?php if ($selectedPreparation): ?>
                    <?php foreach ($selectedPreparation as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
                <?php else: ?>
                    <li>Select a certificate type to see the details that can help the Parish Office find the record.</li>
                <?php endif; ?>
            </ul>
            <p>Have these record details ready if you know them. Do not upload or send documents with this form.</p>
        </section>

        <h2 class="form-stage-heading">Certificate details</h2>
        <label for="subject_name"><?= $values['certificate_type'] === 'marriage' ? 'Groom\'s full name *' : 'Full name on the certificate *' ?></label>
        <input type="text" id="subject_name" name="subject_name" required maxlength="150" value="<?= e($values['subject_name']) ?>">

        <div id="marriage-only" <?= $values['certificate_type'] === 'marriage' ? '' : 'hidden' ?>>
            <label for="spouse_name">Bride's full name *</label>
            <input type="text" id="spouse_name" name="spouse_name" maxlength="150" value="<?= e($values['spouse_name']) ?>">
            <label for="marriage_date">Date of marriage (optional)</label>
            <input type="date" id="marriage_date" name="marriage_date" value="<?= e($values['marriage_date']) ?>">
        </div>

        <label for="birth_date">Date of birth (optional)</label>
        <input type="date" id="birth_date" name="birth_date" value="<?= e($values['birth_date']) ?>">
        <input type="hidden" name="person_id" value="<?= e($values['person_id']) ?>">
        <div class="public-person-lookup" data-public-person-lookup
             data-endpoint="<?= e($basePath . '/public/certificate-request.php') ?>"
             data-name-inputs="subject_name" data-birth-field="birth_date" data-person-id-field="person_id"
             data-father-inputs="father_name" data-mother-inputs="mother_maiden_name"
             data-requester-name-field="requester_name" data-relationship-field="requester_relationship">
            <p class="field-hint">Search the parish person index by full name and date of birth. Selecting a result fills matching details; you can still continue without a match.</p>
            <div data-person-results aria-live="polite"></div>
        </div>

        <label for="father_name">Father's full name (optional)</label>
        <input type="text" id="father_name" name="father_name" maxlength="150" value="<?= e($values['father_name']) ?>">

        <label for="mother_maiden_name">Mother's maiden name (optional)</label>
        <input type="text" id="mother_maiden_name" name="mother_maiden_name" maxlength="150" value="<?= e($values['mother_maiden_name']) ?>">

        <label for="event_date">Approximate date of the sacrament (optional)</label>
        <input type="date" id="event_date" name="event_date" value="<?= e($values['event_date']) ?>">
        </div>

        <div data-certificate-phase="requester">
        <h2 class="form-stage-heading">Requester details</h2>
        <label for="requester_name">Your full name *</label>
        <input type="text" id="requester_name" name="requester_name" required maxlength="150" value="<?= e($values['requester_name']) ?>">

        <label for="requester_relationship">Your relationship to the person named *</label>
        <select id="requester_relationship" name="requester_relationship" required>
            <option value="" <?= $values['requester_relationship'] === '' ? 'selected' : '' ?>>-- Select --</option>
            <option value="self" <?= $values['requester_relationship'] === 'self' ? 'selected' : '' ?>>Self</option>
            <option value="parent" <?= $values['requester_relationship'] === 'parent' ? 'selected' : '' ?>>Parent</option>
            <option value="sibling" <?= $values['requester_relationship'] === 'sibling' ? 'selected' : '' ?>>Sibling</option>
            <option value="child" <?= $values['requester_relationship'] === 'child' ? 'selected' : '' ?>>Child</option>
            <option value="other" <?= $values['requester_relationship'] === 'other' ? 'selected' : '' ?>>Authorized Representative</option>
        </select>
        <p class="field-hint" data-self-note <?= $values['requester_relationship'] === 'self' ? '' : 'hidden' ?>>If you selected Self, bring a valid ID with a name matching the person on the certificate when collecting it.</p>
        <p class="field-hint" data-representative-note <?= $values['requester_relationship'] === 'other' ? '' : 'hidden' ?>>If an authorized representative collects the certificate, bring a signed authorization letter from the person named on the certificate.</p>

        <label for="requester_email">Email *</label>
        <input type="email" id="requester_email" name="requester_email" required maxlength="150" value="<?= e($values['requester_email']) ?>">

        <label for="requester_contact_number">Contact number (optional)</label>
        <input type="text" id="requester_contact_number" name="requester_contact_number" maxlength="30" value="<?= e($values['requester_contact_number']) ?>">
        </div>

        <div data-certificate-phase="purpose">
        <h2 class="form-stage-heading">Requested use &amp; requirements</h2>
        <label for="purpose">What will you use the certificate for? *</label>
        <select id="purpose" name="purpose" required>
            <option value="" <?= $values['purpose'] === '' ? 'selected' : '' ?>>-- Select --</option>
            <option value="School" <?= $values['purpose'] === 'School' ? 'selected' : '' ?>>School</option>
            <option value="Employment" <?= $values['purpose'] === 'Employment' ? 'selected' : '' ?>>Employment</option>
            <option value="Passport" <?= $values['purpose'] === 'Passport' ? 'selected' : '' ?>>Passport</option>
            <option value="Marriage" <?= $values['purpose'] === 'Marriage' ? 'selected' : '' ?>>Marriage</option>
            <option value="Personal records" <?= $values['purpose'] === 'Personal records' ? 'selected' : '' ?>>Personal records</option>
            <option value="Other" <?= $values['purpose'] === 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
        <p class="field-hint">This helps the Parish Office process your request. It does not determine an official annotation printed on the certificate.</p>
        <div id="purpose-other" <?= $values['purpose'] === 'Other' ? '' : 'hidden' ?>>
            <label for="purpose_other">Requested-use details *</label>
            <input type="text" id="purpose_other" name="purpose_other" maxlength="150" value="<?= e($values['purpose_other']) ?>">
        </div>

        <label class="checkbox-label"><input type="checkbox" name="privacy_consent" value="1" <?= !empty($_POST['privacy_consent']) ? 'checked' : '' ?> required> I agree to the privacy notice.</label>
        </div>

        <div style="position:absolute;left:-9999px;" aria-hidden="true">
            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div data-certificate-phase="review">
            <h2 class="form-stage-heading">Review your request</h2>
            <p>Check the details before submitting. Payment is recorded at the Parish Office after submission.</p>
            <dl class="certificate-request-review" data-certificate-review></dl>
        </div>

        <button type="submit" class="btn" data-certificate-submit>Submit request</button>
    </form>
    <section class="online-intake-notice requirements-notice" aria-label="What happens after a certificate request">
        <strong>After submission: review, payment, and pickup</strong>
        <ol>
            <li>The Parish Office reviews your request and will contact you about the next step or any supporting documents needed.</li>
            <li>If asked to proceed, handle payment at the Parish Office. Keep any claim stub or payment slip the secretary gives you; if it is lost, the office can look up the request using its reference number and the certificate name.</li>
            <li>When the certificate is ready, collect it in person. The secretary verifies the request and recorded payment. If you selected Self, bring a valid ID with a name matching the person on the certificate. The Acknowledgment Receipt is placed with the completed certificate in its folder; it is not a document you need to bring. An authorized representative must bring a signed authorization letter from the person named on the certificate.</li>
            <li>For an external parish record, bring the original supporting document and transfer or permission letter if the Parish Office requests them.</li>
        </ol>
        <p>The Parish Office confirms which supporting documents apply. Certificates are not issued online.</p>
    </section>
    <script>
        (function () {
            var typeSelect = document.getElementById('certificate_type');
            var marriageOnly = document.getElementById('marriage-only');
            var subjectLabel = document.querySelector('label[for="subject_name"]');
            var preparationHeading = document.getElementById('certificate-preparation-heading');
            var preparationList = document.getElementById('certificate-preparation-list');
            var preparationDetails = <?= json_encode($certificatePreparation, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            function updateType() {
                var isMarriage = typeSelect.value === 'marriage';
                marriageOnly.hidden = !isMarriage;
                document.getElementById('spouse_name').required = isMarriage;
                subjectLabel.textContent = isMarriage ? 'Groom\'s full name *' : 'Full name on the certificate *';
                preparationHeading.textContent = typeSelect.value
                    ? 'Before: prepare for a ' + typeSelect.options[typeSelect.selectedIndex].text + ' certificate request'
                    : 'Before: choose a certificate type and review what to prepare';
                preparationList.replaceChildren();
                var details = preparationDetails[typeSelect.value] || [
                    'Select a certificate type to see the details that can help the Parish Office find the record.'
                ];
                details.forEach(function (item) {
                    var li = document.createElement('li');
                    li.textContent = item;
                    preparationList.appendChild(li);
                });
            }
            typeSelect.addEventListener('change', updateType);
            updateType();
            var relationship = document.getElementById('requester_relationship');
            var requesterName = document.getElementById('requester_name');
            var subjectName = document.getElementById('subject_name');
            var selfNote = document.querySelector('[data-self-note]');
            var representativeNote = document.querySelector('[data-representative-note]');
            var autoFilledRequesterName = relationship && requesterName && relationship.value === 'self'
                ? requesterName.value
                : null;
            if (relationship && requesterName && subjectName) {
                relationship.addEventListener('change', function () {
                    if (selfNote) selfNote.hidden = this.value !== 'self';
                    if (representativeNote) representativeNote.hidden = this.value !== 'other';
                    if (this.value === 'self') {
                        requesterName.value = subjectName.value;
                        autoFilledRequesterName = subjectName.value;
                    } else {
                        if (autoFilledRequesterName !== null && requesterName.value === autoFilledRequesterName) {
                            requesterName.value = '';
                        }
                        autoFilledRequesterName = null;
                    }
                });
                if (selfNote) selfNote.hidden = relationship.value !== 'self';
                subjectName.addEventListener('input', function () {
                    if (relationship.value === 'self') {
                        requesterName.value = this.value;
                        autoFilledRequesterName = this.value;
                    }
                });
            }
            var purposeSelect = document.getElementById('purpose');
            var purposeOther = document.getElementById('purpose-other');
            var purposeOtherInput = document.getElementById('purpose_other');
            function updatePurpose() {
                var needsOther = purposeSelect.value === 'Other';
                purposeOther.hidden = !needsOther;
                purposeOtherInput.required = needsOther;
            }
            purposeSelect.addEventListener('change', updatePurpose);
            updatePurpose();
        })();
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
