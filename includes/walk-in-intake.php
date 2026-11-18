<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/person-linking.php';
require_once __DIR__ . '/sacrament-data.php';

function walk_in_sacrament_types(): array
{
    return [
        'baptism' => 'Baptism',
        'first_communion' => 'First Communion',
        'confirmation' => 'Confirmation',
        'marriage' => 'Marriage',
    ];
}

function walk_in_input_string(array $input, string $key): string
{
    return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
}

function walk_in_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value
        && $value <= date('Y-m-d');
}

function walk_in_person_input_errors(array $input, string $prefix, string $label): array
{
    $idField = $prefix === 'person' ? 'person_id' : 'spouse_person_id';
    $personId = walk_in_input_string($input, $idField);
    if ($personId !== '') {
        if (filter_var($personId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return ["Select a valid {$label} from the person search."];
        }
        return [];
    }

    $firstName = walk_in_input_string($input, $prefix . '_first_name');
    $middleName = walk_in_input_string($input, $prefix . '_middle_name');
    $lastName = walk_in_input_string($input, $prefix . '_last_name');
    $suffix = walk_in_input_string($input, $prefix . '_suffix');
    $fullName = compose_structured_name($firstName, $middleName, $lastName, $suffix);
    $birthDate = walk_in_input_string($input, $prefix . '_birth_date');
    $errors = [];
    if ($firstName === '' && $lastName === '') {
        $errors[] = "Choose an existing {$label} or enter a new person's full name.";
    } elseif ($firstName === '' || $lastName === '') {
        $errors[] = "Enter the new {$label}'s first and last name.";
    } elseif (strlen($fullName) > 150) {
        $errors[] = "{$label} name must be 150 characters or fewer.";
    }
    if (strlen($firstName) > 150 || strlen($middleName) > 100 || strlen($lastName) > 100 || strlen($suffix) > 20) {
        $errors[] = "{$label} name parts exceed the supported lengths.";
    }
    if ($birthDate === '') {
        $errors[] = "Enter the new {$label}'s date of birth.";
    } elseif (!walk_in_valid_date($birthDate)) {
        $errors[] = "Enter a valid, non-future date of birth for the new {$label}.";
    }

    return $errors;
}

function walk_in_application_input_errors(array $input): array
{
    $types = walk_in_sacrament_types();
    $type = walk_in_input_string($input, 'sacrament_type');
    $errors = [];
    if (!isset($types[$type])) {
        $errors[] = 'Choose a sacrament type.';
    }
    $errors = array_merge($errors, walk_in_person_input_errors($input, 'person', 'primary person'));
    if ($type === 'marriage') {
        $errors = array_merge($errors, walk_in_person_input_errors($input, 'spouse', 'bride'));
    }

    $applicantName = walk_in_input_string($input, 'applicant_name');
    if ($applicantName === '') {
        $errors[] = 'Enter the applicant or contact name.';
    } elseif (strlen($applicantName) > 150) {
        $errors[] = 'Applicant name must be 150 characters or fewer.';
    }
    $email = walk_in_input_string($input, 'applicant_email');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $errors[] = 'Enter a valid applicant email address of 150 characters or fewer.';
    }
    if (strlen(walk_in_input_string($input, 'applicant_contact')) > 30) {
        $errors[] = 'Applicant contact must be 30 characters or fewer.';
    }
    if ($type === 'baptism' && strlen(walk_in_input_string($input, 'baptism_venue')) > 150) {
        $errors[] = 'Preferred baptism venue must be 150 characters or fewer.';
    }

    if ($type === 'marriage') {
        $personId = walk_in_input_string($input, 'person_id');
        $spouseId = walk_in_input_string($input, 'spouse_person_id');
        if ($personId !== '' && $personId === $spouseId) {
            $errors[] = 'The groom and bride must be different person profiles.';
        }
    }

    return $errors;
}

function walk_in_certificate_input_errors(array $input): array
{
    $types = walk_in_sacrament_types();
    $type = walk_in_input_string($input, 'sacrament_type');
    $errors = [];
    if (!isset($types[$type])) {
        $errors[] = 'Choose a sacrament type.';
    }
    $errors = array_merge($errors, walk_in_person_input_errors($input, 'person', 'certificate subject'));
    if ($type === 'marriage') {
        $errors = array_merge($errors, walk_in_person_input_errors($input, 'spouse', 'bride'));
    }

    $relationship = walk_in_input_string($input, 'requester_relationship');
    if (!in_array($relationship, ['self', 'parent', 'sibling', 'child', 'authorized_representative'], true)) {
        $errors[] = 'Choose the requester relationship.';
    } elseif ($relationship !== 'self') {
        $requesterName = walk_in_input_string($input, 'requester_name');
        if ($requesterName === '') {
            $errors[] = 'Enter the requester name.';
        } elseif (strlen($requesterName) > 150) {
            $errors[] = 'Requester name must be 150 characters or fewer.';
        }
    }
    $email = walk_in_input_string($input, 'requester_email');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $errors[] = 'Enter a valid requester email address of 150 characters or fewer.';
    }
    if (strlen(walk_in_input_string($input, 'requester_contact')) > 30) {
        $errors[] = 'Requester contact must be 30 characters or fewer.';
    }

    if ($type === 'marriage') {
        $personId = walk_in_input_string($input, 'person_id');
        $spouseId = walk_in_input_string($input, 'spouse_person_id');
        if ($personId !== '' && $personId === $spouseId) {
            $errors[] = 'The groom and bride must be different person profiles.';
        }
    }

    return $errors;
}

function walk_in_resolve_person(PDO $pdo, array $input, string $prefix): array
{
    $idField = $prefix === 'person' ? 'person_id' : 'spouse_person_id';
    $rawId = walk_in_input_string($input, $idField);
    if ($rawId !== '') {
        $personId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($personId === false) {
            throw new InvalidArgumentException('Select a valid person from the person search.');
        }
        $personStmt = $pdo->prepare(
            'SELECT id, first_name, middle_name, last_name, suffix, birth_date
             FROM persons WHERE id = ?'
        );
        $personStmt->execute([(int) $personId]);
        $person = $personStmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            throw new InvalidArgumentException('The selected person no longer exists. Search again.');
        }
        return $person;
    }

    $firstName = walk_in_input_string($input, $prefix . '_first_name');
    $middleName = walk_in_input_string($input, $prefix . '_middle_name');
    $lastName = walk_in_input_string($input, $prefix . '_last_name');
    $suffix = walk_in_input_string($input, $prefix . '_suffix');
    $birthDate = walk_in_input_string($input, $prefix . '_birth_date');
    $personId = save_person_identity($pdo, null, [
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'suffix' => $suffix,
        'birth_date' => $birthDate,
    ]);

    $personStmt = $pdo->prepare(
        'SELECT id, first_name, middle_name, last_name, suffix, birth_date
         FROM persons WHERE id = ?'
    );
    $personStmt->execute([$personId]);
    $person = $personStmt->fetch(PDO::FETCH_ASSOC);
    if (!$person) {
        throw new RuntimeException('The new person profile could not be loaded.');
    }
    return $person;
}

function walk_in_person_full_name(array $person): string
{
    return trim(implode(' ', array_filter([
        $person['first_name'] ?? '',
        $person['middle_name'] ?? '',
        $person['last_name'] ?? '',
        $person['suffix'] ?? '',
    ])));
}

function walk_in_session_context(array $user): array
{
    $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $channel = ($user['role'] ?? '') === 'secretary' ? 'onsite' : null;
    if ($userId === false || $channel === null) {
        throw new InvalidArgumentException('A signed-in secretary is required to create a walk-in request.');
    }

    return ['user_id' => (int) $userId, 'channel' => $channel];
}

function walk_in_application_details(string $type, array $person, ?array $spouse, string $venue): array
{
    $name = walk_in_person_full_name($person);
    $birthDate = (string) ($person['birth_date'] ?? '');
    if ($type === 'baptism') {
        $details = ['child_name' => $name, 'child_birth_date' => $birthDate];
        if ($venue !== '') {
            $details['baptism_venue'] = $venue;
        }
        return $details;
    }
    if ($type === 'marriage' && $spouse !== null) {
        return [
            'groom' => ['full_name' => $name, 'birth_date' => $birthDate],
            'bride' => [
                'full_name' => walk_in_person_full_name($spouse),
                'birth_date' => (string) ($spouse['birth_date'] ?? ''),
            ],
        ];
    }
    return ['candidate_name' => $name, 'candidate_birth_date' => $birthDate];
}

function walk_in_requirements(string $type): array
{
    $allRequirements = sd_application_requirement_keys();
    $requirements = [];
    foreach ($allRequirements[$type] ?? [] as $key) {
        $requirements[$key] = ['status' => 'not_received', 'note' => ''];
    }
    return $requirements;
}

function walk_in_save_application(PDO $pdo, array $input, array $user): array
{
    $errors = walk_in_application_input_errors($input);
    if ($errors !== []) {
        throw new InvalidArgumentException(implode("\n", $errors));
    }
    $session = walk_in_session_context($user);

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $type = walk_in_input_string($input, 'sacrament_type');
        $person = walk_in_resolve_person($pdo, $input, 'person');
        $spouse = $type === 'marriage' ? walk_in_resolve_person($pdo, $input, 'spouse') : null;
        if ($spouse !== null && (int) $spouse['id'] === (int) $person['id']) {
            throw new InvalidArgumentException('The groom and bride must be different person profiles.');
        }
        $details = walk_in_application_details(
            $type,
            $person,
            $spouse,
            $type === 'baptism' ? walk_in_input_string($input, 'baptism_venue') : ''
        );
        $requestNo = generate_request_no($pdo, 'sacrament_applications');
        $insert = $pdo->prepare(
            "INSERT INTO sacrament_applications
                (request_no, channel, sacrament_type, person_id, spouse_person_id,
                 applicant_name, applicant_email, applicant_contact, status, details, requirements)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?)"
        );
        $insert->execute([
            $requestNo,
            $session['channel'],
            $type,
            (int) $person['id'],
            $spouse === null ? null : (int) $spouse['id'],
            walk_in_input_string($input, 'applicant_name'),
            walk_in_input_string($input, 'applicant_email'),
            walk_in_input_string($input, 'applicant_contact') ?: null,
            json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode(walk_in_requirements($type), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $applicationId = (int) $pdo->lastInsertId();
        log_activity($pdo, $session['user_id'], 'walk_in_application_created', 'sacrament_applications', $applicationId, 'Walk-in pre-application created.');
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['id' => $applicationId, 'request_no' => $requestNo];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function walk_in_save_certificate_request(PDO $pdo, array $input, array $user): array
{
    $errors = walk_in_certificate_input_errors($input);
    if ($errors !== []) {
        throw new InvalidArgumentException(implode("\n", $errors));
    }
    $session = walk_in_session_context($user);

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $type = walk_in_input_string($input, 'sacrament_type');
        $person = walk_in_resolve_person($pdo, $input, 'person');
        $spouse = $type === 'marriage' ? walk_in_resolve_person($pdo, $input, 'spouse') : null;
        if ($spouse !== null && (int) $spouse['id'] === (int) $person['id']) {
            throw new InvalidArgumentException('The groom and bride must be different person profiles.');
        }
        $details = [];
        if (!empty($person['birth_date'])) {
            $details['birth_date'] = (string) $person['birth_date'];
        }
        if ($spouse !== null) {
            $details['spouse_name'] = walk_in_person_full_name($spouse);
            if (!empty($spouse['birth_date'])) {
                $details['spouse_birth_date'] = (string) $spouse['birth_date'];
            }
            $details['spouse_person_id'] = (int) $spouse['id'];
        }
        $relationship = walk_in_input_string($input, 'requester_relationship');
        $storedRelationship = $relationship === 'authorized_representative' ? 'other' : $relationship;
        $requesterName = $relationship === 'self'
            ? walk_in_person_full_name($person)
            : walk_in_input_string($input, 'requester_name');
        $requestNo = generate_request_no($pdo, 'certificate_requests');
        $insert = $pdo->prepare(
            "INSERT INTO certificate_requests
                (request_no, channel, sacrament_type, subject_name, subject_details,
                 requester_name, requester_relationship, requester_relationship_other,
                 requester_email, requester_contact_number, person_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')"
        );
        $insert->execute([
            $requestNo,
            $session['channel'],
            $type,
            walk_in_person_full_name($person),
            $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $requesterName,
            $storedRelationship,
            $relationship === 'authorized_representative' ? 'Authorized Representative' : null,
            walk_in_input_string($input, 'requester_email'),
            walk_in_input_string($input, 'requester_contact') ?: null,
            (int) $person['id'],
        ]);
        $requestId = (int) $pdo->lastInsertId();
        log_activity($pdo, $session['user_id'], 'walk_in_certificate_request_created', 'certificate_requests', $requestId, 'Walk-in certificate request created.');
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['id' => $requestId, 'request_no' => $requestNo];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
