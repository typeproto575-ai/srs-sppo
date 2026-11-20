<?php

declare(strict_types=1);

// Local test fixtures only. Preview by default:
//   php database/v2/seed_test_scenarios.php
//   php database/v2/seed_test_scenarios.php --execute
//   php database/v2/seed_test_scenarios.php --cleanup --execute

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const TEST_SEED_MARKER = 'TEST SEED:';
const TEST_SEED_PERSON_REFERENCE_PREFIX = 'SPPO-P-TESTSEED';
const TEST_SEED_APPLICATION_PREFIX = 'SA-2026-99';
const TEST_SEED_CERTIFICATE_PREFIX = 'CR-2026-99';
const TEST_SEED_BATCH_PREFIX = 'TEST SEED:';
const TEST_SEED_RECORD_PREFIX = 'TST-SEED-';

$arguments = array_slice($argv, 1);
$knownArguments = ['--execute', '--cleanup', '--rollback'];
foreach ($arguments as $argument) {
    if (!in_array($argument, $knownArguments, true)) {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(2);
    }
}

$execute = in_array('--execute', $arguments, true);
$cleanup = in_array('--cleanup', $arguments, true);
$rollback = in_array('--rollback', $arguments, true);
if (($cleanup || $rollback) && !$execute) {
    fwrite(STDERR, "--cleanup and --rollback require --execute.\n");
    exit(2);
}
if ($cleanup && $rollback) {
    fwrite(STDERR, "--cleanup and --rollback cannot be used together.\n");
    exit(2);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/application-requirements.php';

$localHosts = ['localhost', '127.0.0.1', '::1'];
if (!in_array(strtolower(trim((string) $DB_HOST)), $localHosts, true)) {
    fwrite(STDERR, "Refusing to run: the configured database host is not local.\n");
    exit(2);
}

/**
 * Keep all fixture JSON valid and consistently UTF-8 encoded.
 */
function test_seed_json(?array $value): ?string
{
    return $value === null
        ? null
        : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Create checklist objects using the status/note shape consumed by the UI.
 */
function test_seed_requirements(array $keys, array $overrides = []): array
{
    $requirements = [];
    foreach ($keys as $key) {
        $requirements[$key] = [
            'status' => 'not_received',
            'note' => TEST_SEED_MARKER . ' Sample checklist item.',
        ];
    }

    foreach ($overrides as $key => $value) {
        $requirements[$key] = $value;
    }

    return $requirements;
}

/**
 * Upsert only people reserved for this test dataset.
 */
function test_seed_person(PDO $pdo, string $code, array $person): int
{
    $reference = TEST_SEED_PERSON_REFERENCE_PREFIX . strtoupper($code);
    $select = $pdo->prepare('SELECT id, observanda FROM persons WHERE public_reference = ? LIMIT 1');
    $select->execute([$reference]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);

    $values = [
        $person['first_name'],
        $person['middle_name'] ?? null,
        $person['last_name'],
        $person['suffix'] ?? null,
        $person['sex'] ?? null,
        $person['birth_date'] ?? null,
        $person['birth_place'] ?? 'Dingalan, Aurora',
        $person['father_name'] ?? null,
        $person['mother_maiden_name'] ?? null,
        $person['father_person_id'] ?? null,
        $person['mother_person_id'] ?? null,
        $person['address'] ?? 'Barangay Poblacion, Dingalan, Aurora',
        $person['contact_number'] ?? null,
        $person['email'] ?? null,
        $reference,
        $person['baptismal_status'] ?? null,
        (int) ($person['is_deceased'] ?? 0),
        $person['death_date'] ?? null,
        $person['observanda'] ?? (TEST_SEED_MARKER . ' Fixture managed by database/v2/seed_test_scenarios.php.'),
    ];

    if ($existing) {
        if (!str_starts_with((string) ($existing['observanda'] ?? ''), TEST_SEED_MARKER)) {
            throw new RuntimeException("Reserved test person reference is already used: {$reference}");
        }

        $update = $pdo->prepare(
            'UPDATE persons
             SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, sex = ?,
                 birth_date = ?, birth_place = ?, father_name = ?, mother_maiden_name = ?,
                 father_person_id = ?, mother_person_id = ?, address = ?, contact_number = ?,
                 email = ?, baptismal_status = ?, is_deceased = ?, death_date = ?, observanda = ?
             WHERE id = ?'
        );
        $update->execute([
            ...array_slice($values, 0, 14),
            ...array_slice($values, 15, 4),
            (int) $existing['id'],
        ]);
        return (int) $existing['id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO persons
            (first_name, middle_name, last_name, suffix, sex, birth_date, birth_place,
             father_name, mother_maiden_name, father_person_id, mother_person_id, address,
             contact_number, email, public_reference, baptismal_status, is_deceased,
             death_date, observanda)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute($values);
    return (int) $pdo->lastInsertId();
}

/**
 * Upsert a sacramental register row by its reserved test entry number.
 */
function test_seed_record(PDO $pdo, ?int $verifierId, array $record): int
{
    $entryNo = TEST_SEED_RECORD_PREFIX . $record['entry_suffix'];
    $select = $pdo->prepare(
        'SELECT id, remarks FROM sacrament_records
         WHERE sacrament_type = ? AND entry_no = ? LIMIT 1'
    );
    $select->execute([$record['sacrament_type'], $entryNo]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);
    $verifiedAt = !empty($record['verified']) ? date('Y-m-d H:i:s') : null;
    $values = [
        $record['person_id'],
        $record['spouse_person_id'] ?? null,
        $record['source'] ?? 'this_parish',
        $record['event_date'],
        $record['place'] ?? 'St. Patrick’s Parish Church, Dingalan',
        $record['minister_name'],
        $entryNo,
        $record['book_no'] ?? 'TEST 2026',
        $record['page_no'] ?? '1',
        $record['line_no'] ?? '1',
        $record['parents_union_type'] ?? null,
        $record['parents_marriage_parish'] ?? null,
        test_seed_json($record['details'] ?? null),
        TEST_SEED_MARKER . ' ' . ($record['note'] ?? 'Fictional test register entry.'),
        !empty($record['verified']) ? $verifierId : null,
        $verifiedAt,
    ];

    if ($existing) {
        if (!str_starts_with((string) ($existing['remarks'] ?? ''), TEST_SEED_MARKER)) {
            throw new RuntimeException("Reserved test register entry is already used: {$entryNo}");
        }
        $update = $pdo->prepare(
            'UPDATE sacrament_records
             SET person_id = ?, spouse_person_id = ?, source = ?, event_date = ?, place = ?,
                 minister_name = ?, book_no = ?, page_no = ?, line_no = ?, parents_union_type = ?,
                 parents_marriage_parish = ?, details = ?, remarks = ?, book_verified_by = ?,
                 book_verified_at = ?
             WHERE id = ?'
        );
        $update->execute([
            ...array_slice($values, 0, 6),
            ...array_slice($values, 7),
            (int) $existing['id'],
        ]);
        return (int) $existing['id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO sacrament_records
            (sacrament_type, person_id, spouse_person_id, source, event_date, place,
             minister_name, entry_no, book_no, page_no, line_no, parents_union_type,
             parents_marriage_parish, details, remarks, book_verified_by, book_verified_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $record['sacrament_type'],
        ...$values,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Upsert an application by request number, refusing to overwrite other data.
 */
function test_seed_application(PDO $pdo, array $application): int
{
    $select = $pdo->prepare('SELECT id, notes FROM sacrament_applications WHERE request_no = ? LIMIT 1');
    $select->execute([$application['request_no']]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);
    if ($existing && !str_starts_with((string) ($existing['notes'] ?? ''), TEST_SEED_MARKER)) {
        throw new RuntimeException('Reserved application reference is already used: ' . $application['request_no']);
    }

    $values = [
        $application['channel'] ?? 'online',
        $application['sacrament_type'],
        $application['person_id'] ?? null,
        $application['batch_id'] ?? null,
        $application['spouse_person_id'] ?? null,
        $application['applicant_name'],
        $application['applicant_email'],
        $application['applicant_contact'] ?? null,
        $application['preferred_date'] ?? null,
        $application['preferred_time'] ?? null,
        $application['status'] ?? 'new',
        test_seed_json($application['details']),
        test_seed_json($application['requirements']),
        TEST_SEED_MARKER . ' ' . $application['note'],
        $application['privacy_consent_at'] ?? date('Y-m-d H:i:s'),
        $application['created_at'] ?? date('Y-m-d H:i:s'),
    ];

    if ($existing) {
        $update = $pdo->prepare(
            'UPDATE sacrament_applications
             SET channel = ?, sacrament_type = ?, person_id = ?, batch_id = ?, spouse_person_id = ?,
                 applicant_name = ?, applicant_email = ?, applicant_contact = ?, preferred_date = ?,
                 preferred_time = ?, status = ?, details = ?, requirements = ?, notes = ?,
                 privacy_consent_at = ?, created_at = ?, record_id = NULL, paid_at = NULL,
                 paid_by = NULL, processed_by = NULL, assigned_to = NULL,
                 rejection_reason = NULL, anonymized_at = NULL
             WHERE id = ?'
        );
        $update->execute([...$values, (int) $existing['id']]);
        return (int) $existing['id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO sacrament_applications
            (request_no, channel, sacrament_type, person_id, batch_id, spouse_person_id,
             applicant_name, applicant_email, applicant_contact, preferred_date, preferred_time,
             status, details, requirements, notes, privacy_consent_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([$application['request_no'], ...$values]);
    return (int) $pdo->lastInsertId();
}

/**
 * Upsert a shared First Communion batch using the existing schema fields.
 */
function test_seed_batch(PDO $pdo, string $name, string $schoolYear): int
{
    $select = $pdo->prepare(
        'SELECT id FROM sacrament_batches
         WHERE sacrament_type = \'first_communion\' AND batch_name = ? LIMIT 1'
    );
    $select->execute([$name]);
    $batchId = $select->fetchColumn();
    if ($batchId !== false) {
        $update = $pdo->prepare(
            'UPDATE sacrament_batches
             SET grade_level = \'general\', school_year = ?
             WHERE id = ?'
        );
        $update->execute([$schoolYear, (int) $batchId]);
        return (int) $batchId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO sacrament_batches
            (sacrament_type, grade_level, school_year, batch_name, created_by)
         VALUES (\'first_communion\', \'general\', ?, ?, NULL)'
    );
    $insert->execute([$schoolYear, $name]);
    return (int) $pdo->lastInsertId();
}

/**
 * Upsert a test calendar event by its reserved title.
 */
function test_seed_schedule(PDO $pdo, array $event): int
{
    $select = $pdo->prepare('SELECT id, notes FROM schedule_events WHERE title = ? LIMIT 1');
    $select->execute([$event['title']]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);
    if ($existing && !str_starts_with((string) ($existing['notes'] ?? ''), TEST_SEED_MARKER)) {
        throw new RuntimeException('Reserved schedule title is already used: ' . $event['title']);
    }
    $values = [
        $event['event_type'],
        $event['public_details'] ?? null,
        $event['event_date'],
        $event['start_time'] ?? null,
        $event['end_time'] ?? null,
        $event['application_id'] ?? null,
        $event['batch_id'] ?? null,
        TEST_SEED_MARKER . ' ' . $event['note'],
    ];
    if ($existing) {
        $update = $pdo->prepare(
            'UPDATE schedule_events
             SET event_type = ?, public_details = ?, event_date = ?, start_time = ?,
                 end_time = ?, application_id = ?, batch_id = ?, notes = ?
             WHERE id = ?'
        );
        $update->execute([...$values, (int) $existing['id']]);
        return (int) $existing['id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO schedule_events
            (event_type, title, public_details, event_date, start_time, end_time,
             application_id, batch_id, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
    );
    $insert->execute([
        $values[0],
        $event['title'],
        $values[1],
        $values[2],
        $values[3],
        $values[4],
        $values[5],
        $values[6],
        $values[7],
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Upsert a certificate request that is ready to preview/print from its linked
 * verified register row. Printing still requires the secretary's normal flow.
 */
function test_seed_certificate_request(PDO $pdo, array $request): int
{
    $select = $pdo->prepare('SELECT id, notes FROM certificate_requests WHERE request_no = ? LIMIT 1');
    $select->execute([$request['request_no']]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);
    if ($existing && !str_starts_with((string) ($existing['notes'] ?? ''), TEST_SEED_MARKER)) {
        throw new RuntimeException('Reserved certificate reference is already used: ' . $request['request_no']);
    }

    $values = [
        $request['sacrament_type'],
        $request['subject_name'],
        test_seed_json($request['subject_details']),
        $request['requester_name'],
        $request['requester_email'],
        $request['requester_contact_number'],
        $request['purpose'] ?? 'personal',
        $request['person_id'],
        $request['record_id'],
        $request['status'] ?? 'for_signature',
        TEST_SEED_MARKER . ' ' . $request['note'],
    ];
    if ($existing) {
        $update = $pdo->prepare(
            'UPDATE certificate_requests
             SET sacrament_type = ?, subject_name = ?, subject_details = ?, requester_name = ?,
                 requester_relationship = \'self\', requester_email = ?, requester_contact_number = ?,
                 purpose = ?, person_id = ?, record_id = ?, status = ?, notes = ?,
                 privacy_consent_at = COALESCE(privacy_consent_at, NOW()),
                 matched_by = NULL, matched_at = NOW(), certificate_no = NULL, printed_by = NULL,
                 printed_at = NULL, signed_at = NULL, claimant_type = NULL, representative_name = NULL,
                 representative_relationship = NULL, id_verified = 0, claimant_notes = NULL,
                 released_by = NULL, released_at = NULL, paid_at = NULL, paid_by = NULL,
                 rejection_reason = NULL, anonymized_at = NULL
             WHERE id = ?'
        );
        $update->execute([...$values, (int) $existing['id']]);
        return (int) $existing['id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO certificate_requests
            (request_no, channel, sacrament_type, subject_name, subject_details,
             requester_name, requester_relationship, requester_email, requester_contact_number,
             purpose, privacy_consent_at, person_id, record_id, matched_at, status, notes)
         VALUES (?, \'onsite\', ?, ?, ?, ?, \'self\', ?, ?, ?, NOW(), ?, ?, NOW(), ?, ?)'
    );
    $insert->execute([$request['request_no'], ...$values]);
    return (int) $pdo->lastInsertId();
}

/**
 * Upsert a funeral row by the person profile's reserved seed reference.
 */
function test_seed_funeral(PDO $pdo, int $personId, array $funeral): int
{
    $select = $pdo->prepare(
        'SELECT id, sacraments_received FROM funeral_records WHERE person_id = ? LIMIT 1'
    );
    $select->execute([$personId]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);
    $metadata = [
        'sacraments' => $funeral['sacraments'],
        'service_variant' => $funeral['service_variant'],
        'contact_name' => $funeral['contact_name'],
        'contact_email' => $funeral['contact_email'],
        'seed_tag' => 'TEST SEED',
    ];
    $sacramentsJson = test_seed_json($metadata);
    $values = [
        $funeral['date_of_death'],
        $funeral['date_of_burial'] ?? null,
        $funeral['age_at_death'],
        $funeral['civil_status'] ?? null,
        $funeral['spouse_name'] ?? null,
        $funeral['parents_name'] ?? null,
        $sacramentsJson,
        $funeral['cause_of_death'] ?? 'Test data only; no real person or event.',
        $funeral['place_of_burial'] ?? null,
        'mass',
        $funeral['service_date'] ?? null,
        $funeral['service_time'] ?? null,
        $funeral['minister_name'],
        $funeral['book_no'],
        $funeral['page_no'],
        $funeral['line_no'],
        $funeral['contact_number'],
    ];
    if ($existing) {
        $currentMetadata = json_decode((string) ($existing['sacraments_received'] ?? ''), true);
        if (!is_array($currentMetadata) || ($currentMetadata['seed_tag'] ?? '') !== 'TEST SEED') {
            throw new RuntimeException('A funeral row already exists for a reserved test person.');
        }
        $update = $pdo->prepare(
            'UPDATE funeral_records
             SET date_of_death = ?, date_of_burial = ?, age_at_death = ?, civil_status = ?,
                 spouse_name = ?, parents_name = ?, sacraments_received = ?, cause_of_death = ?,
                 place_of_burial = ?, service_type = ?, service_date = ?, service_time = ?,
                 minister_name = ?, book_no = ?, page_no = ?, line_no = ?, contact_number = ?
             WHERE id = ?'
        );
        $update->execute([...$values, (int) $existing['id']]);
        return (int) $existing['id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO funeral_records
            (person_id, date_of_death, date_of_burial, age_at_death, civil_status,
             spouse_name, parents_name, sacraments_received, cause_of_death, place_of_burial,
             service_type, service_date, service_time, minister_name, book_no, page_no,
             line_no, contact_number, encoded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
    );
    $insert->execute([$personId, ...$values]);
    return (int) $pdo->lastInsertId();
}

function test_seed_cleanup(PDO $pdo): array
{
    $deleted = [];
    foreach ([
        'schedule_events' => "DELETE FROM schedule_events WHERE notes LIKE 'TEST SEED:%'",
        'certificate_requests' => "DELETE FROM certificate_requests WHERE notes LIKE 'TEST SEED:%'",
        'sacrament_applications' => "DELETE FROM sacrament_applications WHERE notes LIKE 'TEST SEED:%'",
        'sacrament_participants' => "DELETE FROM sacrament_participants
            WHERE record_id IN (
                SELECT id FROM sacrament_records
                WHERE entry_no LIKE 'TST-SEED-%' AND remarks LIKE 'TEST SEED:%'
            )",
        'funeral_records' => "DELETE FROM funeral_records
            WHERE person_id IN (
                SELECT id FROM persons
                WHERE public_reference LIKE 'SPPO-P-TESTSEED%'
                  AND observanda LIKE 'TEST SEED:%'
            )
              AND JSON_UNQUOTE(JSON_EXTRACT(sacraments_received, '$.seed_tag')) = 'TEST SEED'",
        'sacrament_records' => "DELETE FROM sacrament_records
            WHERE entry_no LIKE 'TST-SEED-%' AND remarks LIKE 'TEST SEED:%'",
        'sacrament_batches' => "DELETE FROM sacrament_batches
            WHERE batch_name LIKE 'TEST SEED:%'",
        'persons' => "DELETE FROM persons
            WHERE public_reference LIKE 'SPPO-P-TESTSEED%'
              AND observanda LIKE 'TEST SEED:%'",
    ] as $table => $sql) {
        $deleted[$table] = $pdo->exec($sql);
    }

    return $deleted;
}

try {
    if (!$execute) {
        echo "Connected to the configured local MySQL database. No rows were changed.\n";
        echo "Run with --execute to add the TEST SEED fixtures, or --cleanup --execute to remove them.\n";
        exit(0);
    }

    $pdo->beginTransaction();
    if ($cleanup) {
        $deleted = test_seed_cleanup($pdo);
        if ($rollback) {
            $pdo->rollBack();
            echo "Cleanup preview rolled back; no rows were changed.\n";
        } else {
            $pdo->commit();
            echo "Removed only TEST SEED rows: " . json_encode($deleted) . "\n";
        }
        exit(0);
    }

    $today = new DateTimeImmutable('today');
    $futureScheduleDate = $today->modify('+3 days')->format('Y-m-d');
    $scheduledBirthday = (new DateTimeImmutable($futureScheduleDate))->modify('-21 years')->format('Y-m-d');
    $unscheduledMinorBirthday = $today->modify('-21 years')->modify('+1 day')->format('Y-m-d');
    $verifierIdValue = $pdo->query(
        "SELECT id FROM users WHERE role = 'secretary' AND is_active = 1 ORDER BY id LIMIT 1"
    )->fetchColumn();
    $verifierId = $verifierIdValue === false ? null : (int) $verifierIdValue;

    $people = [];
    $people['funeral_subject'] = test_seed_person($pdo, 'F001', [
        'first_name' => 'Rogelio Antonio',
        'middle_name' => 'Manalo',
        'last_name' => 'Santos',
        'sex' => 'male',
        'birth_date' => '1982-03-07',
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Pedro Santos',
        'mother_maiden_name' => 'Beatriz Manalo',
        'address' => 'Purok 2, Barangay Poblacion, Dingalan, Aurora',
        'contact_number' => '09175550101',
        'email' => 'test.funeral.subject@example.test',
        'baptismal_status' => 'baptized_catholic',
        'is_deceased' => 1,
        'death_date' => '2026-11-12',
    ]);
    $people['funeral_spouse'] = test_seed_person($pdo, 'F002', [
        'first_name' => 'Lourdes Teresa',
        'middle_name' => 'Ramos',
        'last_name' => 'Santos',
        'sex' => 'female',
        'birth_date' => '1980-11-15',
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Manuel Santos',
        'mother_maiden_name' => 'Rosa Ramos',
        'address' => 'Barangay Poblacion, Dingalan, Aurora',
        'contact_number' => '09175550102',
        'email' => 'test.funeral.spouse@example.test',
        'baptismal_status' => 'baptized_catholic',
    ]);

    $people['group_verified'] = test_seed_person($pdo, 'G001', [
        'first_name' => 'Leandro Miguel',
        'middle_name' => 'Rivera',
        'last_name' => 'Cruz',
        'sex' => 'male',
        'birth_date' => '2017-05-09',
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Ramon Cruz',
        'mother_maiden_name' => 'Elena Rivera',
        'address' => 'Barangay Poblacion, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);
    $people['group_prerequisite'] = test_seed_person($pdo, 'G003', [
        'first_name' => 'Mateo Luis',
        'middle_name' => 'Domingo',
        'last_name' => 'Reyes',
        'sex' => 'male',
        'birth_date' => '2016-09-10',
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Luis Reyes',
        'mother_maiden_name' => 'Carla Domingo',
        'address' => 'Barangay Matawe, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);
    $people['group_unverified'] = test_seed_person($pdo, 'G004', [
        'first_name' => 'Bianca Rose',
        'middle_name' => 'Manalo',
        'last_name' => 'Garcia',
        'sex' => 'female',
        'birth_date' => '2016-06-11',
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Pedro Garcia',
        'mother_maiden_name' => 'Rita Manalo',
        'address' => 'Barangay Aplaya, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);
    $people['group_external'] = test_seed_person($pdo, 'G005', [
        'first_name' => 'Daniel Rafael',
        'middle_name' => 'Bautista',
        'last_name' => 'Flores',
        'sex' => 'male',
        'birth_date' => '2015-03-01',
        'birth_place' => 'Baler, Aurora',
        'father_name' => 'Miguel Flores',
        'mother_maiden_name' => 'Cecilia Bautista',
        'address' => 'Barangay Poblacion, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);

    $people['individual'] = test_seed_person($pdo, 'I001', [
        'first_name' => 'Sofia Isabel',
        'middle_name' => 'Navarro',
        'last_name' => 'Dela Cruz',
        'sex' => 'female',
        'birth_date' => '2015-04-22',
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Andres Dela Cruz',
        'mother_maiden_name' => 'Miriam Navarro',
        'address' => 'Purok 4, Barangay Poblacion, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);
    $people['confirmation_internal'] = test_seed_person($pdo, 'C001', [
        'first_name' => 'Ramon Miguel',
        'middle_name' => 'Santos',
        'last_name' => 'Rivera',
        'sex' => 'male',
        'birth_date' => '2012-02-20',
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Noel Rivera',
        'mother_maiden_name' => 'Teresa Santos',
        'address' => 'Barangay Poblacion, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);
    $people['confirmation_external'] = test_seed_person($pdo, 'C002', [
        'first_name' => 'Theresa Marie',
        'middle_name' => 'Bautista',
        'last_name' => 'Mendoza',
        'sex' => 'female',
        'birth_date' => '2013-08-03',
        'birth_place' => 'Baler, Aurora',
        'father_name' => 'Rafael Mendoza',
        'mother_maiden_name' => 'Lucia Bautista',
        'address' => 'Barangay Poblacion, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);

    $people['marriage_unscheduled_groom'] = test_seed_person($pdo, 'M001', [
        'first_name' => 'Paolo Gabriel',
        'middle_name' => 'Navarro',
        'last_name' => 'Reyes',
        'sex' => 'male',
        'birth_date' => $unscheduledMinorBirthday,
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Felipe Reyes',
        'mother_maiden_name' => 'Celia Navarro',
        'address' => 'Barangay Poblacion, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);
    $people['marriage_unscheduled_bride'] = test_seed_person($pdo, 'M002', [
        'first_name' => 'Maria Elena',
        'middle_name' => 'Santos',
        'last_name' => 'Garcia',
        'sex' => 'female',
        'birth_date' => '2002-05-04',
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Ramon Garcia',
        'mother_maiden_name' => 'Lourdes Santos',
        'address' => 'Barangay Poblacion, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);
    $people['marriage_scheduled_groom'] = test_seed_person($pdo, 'M003', [
        'first_name' => 'Enrique Mateo',
        'middle_name' => 'Rivera',
        'last_name' => 'Dizon',
        'sex' => 'male',
        'birth_date' => $scheduledBirthday,
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Arturo Dizon',
        'mother_maiden_name' => 'Natividad Rivera',
        'address' => 'Barangay Matawe, Dingalan, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);
    $people['marriage_scheduled_bride'] = test_seed_person($pdo, 'M004', [
        'first_name' => 'Ana Patricia',
        'middle_name' => 'Manalo',
        'last_name' => 'Villanueva',
        'sex' => 'female',
        'birth_date' => '2002-12-05',
        'birth_place' => 'Baler, Aurora',
        'father_name' => 'Jose Villanueva',
        'mother_maiden_name' => 'Isabel Manalo',
        'address' => 'Barangay Poblacion, Baler, Aurora',
        'baptismal_status' => 'baptized_catholic',
    ]);
    $people['funeral_manual'] = test_seed_person($pdo, 'F003', [
        'first_name' => 'Rogelio Antonio',
        'middle_name' => 'Mendoza',
        'last_name' => 'Bautista',
        'sex' => 'male',
        'birth_date' => '1951-01-15',
        'birth_place' => 'Dingalan, Aurora',
        'father_name' => 'Pedro Bautista',
        'mother_maiden_name' => 'Carmen Mendoza',
        'address' => 'Barangay Poblacion, Dingalan, Aurora',
        'contact_number' => '09175550103',
        'email' => 'test.funeral.manual@example.test',
        'baptismal_status' => null,
        'is_deceased' => 1,
        'death_date' => '2025-08-12',
        'observanda' => 'TEST SEED: Created for the manual funeral workflow; no parishioner profile existed before this test entry.',
    ]);

    $baseBaptismDetails = static function (string $residence): array {
        return [
            'father_birthplace' => 'Dingalan, Aurora',
            'mother_birthplace' => 'Dingalan, Aurora',
            'parents_residence' => $residence,
        ];
    };
    $recordIds = [];
    $recordDefinitions = [
        [
            'key' => 'funeral_baptism',
            'person' => 'funeral_subject',
            'type' => 'baptism',
            'suffix' => 'BAP-FUN',
            'date' => '1982-03-14',
            'minister' => 'Fr. TEST Antonio Reyes',
            'verified' => true,
            'parents_union_type' => 'canonical',
        ],
        [
            'key' => 'funeral_communion',
            'person' => 'funeral_subject',
            'type' => 'first_communion',
            'suffix' => 'COM-FUN',
            'date' => '1990-05-20',
            'minister' => 'Fr. TEST Miguel Dizon',
            'verified' => true,
            'details' => ['domicile' => 'Purok 2, Barangay Poblacion, Dingalan, Aurora'],
        ],
        [
            'key' => 'funeral_confirmation',
            'person' => 'funeral_subject',
            'type' => 'confirmation',
            'suffix' => 'CON-FUN',
            'date' => '1995-08-20',
            'minister' => 'Most Rev. TEST Rafael Santos',
            'verified' => true,
        ],
        [
            'key' => 'funeral_marriage',
            'person' => 'funeral_subject',
            'spouse' => 'funeral_spouse',
            'type' => 'marriage',
            'suffix' => 'MAR-FUN',
            'date' => '2004-01-17',
            'minister' => 'Fr. TEST Gabriel Rivera',
            'verified' => true,
            'details' => [
                'groom' => ['civil_status' => 'single', 'age' => 21, 'origin' => 'Dingalan, Aurora', 'residence' => 'Dingalan, Aurora'],
                'bride' => ['civil_status' => 'single', 'age' => 23, 'origin' => 'Dingalan, Aurora', 'residence' => 'Dingalan, Aurora'],
                'license_ref' => 'TEST-MARRIAGE-LICENSE-01',
                'banns_dates' => ['2003-12-21', '2003-12-28', '2004-01-04'],
            ],
        ],
        [
            'key' => 'group_verified_baptism',
            'person' => 'group_verified',
            'type' => 'baptism',
            'suffix' => 'BAP-GRP',
            'date' => '2017-05-14',
            'minister' => 'Fr. TEST Antonio Reyes',
            'verified' => true,
            'parents_union_type' => 'canonical',
        ],
        [
            'key' => 'group_unverified_baptism',
            'person' => 'group_unverified',
            'type' => 'baptism',
            'suffix' => 'BAP-UNV',
            'date' => '2016-06-19',
            'minister' => 'Fr. TEST Miguel Dizon',
            'verified' => false,
            'parents_union_type' => 'canonical',
        ],
        [
            'key' => 'group_external_baptism',
            'person' => 'group_external',
            'type' => 'baptism',
            'suffix' => 'BAP-EXT',
            'date' => '2015-03-08',
            'minister' => 'Fr. TEST Andres Lim',
            'source' => 'other_parish',
            'place' => 'St. Andrew Parish, Baler, Aurora',
            'verified' => false,
            'parents_union_type' => 'canonical',
        ],
        [
            'key' => 'individual_baptism',
            'person' => 'individual',
            'type' => 'baptism',
            'suffix' => 'BAP-IND',
            'date' => '2015-05-03',
            'minister' => 'Fr. TEST Gabriel Rivera',
            'verified' => true,
            'parents_union_type' => 'canonical',
        ],
        [
            'key' => 'confirmation_internal_baptism',
            'person' => 'confirmation_internal',
            'type' => 'baptism',
            'suffix' => 'BAP-CIN',
            'date' => '2012-03-04',
            'minister' => 'Fr. TEST Antonio Reyes',
            'verified' => true,
            'parents_union_type' => 'canonical',
        ],
        [
            'key' => 'confirmation_internal_communion',
            'person' => 'confirmation_internal',
            'type' => 'first_communion',
            'suffix' => 'COM-CIN',
            'date' => '2020-05-24',
            'minister' => 'Fr. TEST Miguel Dizon',
            'verified' => true,
            'details' => ['domicile' => 'Barangay Poblacion, Dingalan, Aurora'],
        ],
        [
            'key' => 'confirmation_external_baptism',
            'person' => 'confirmation_external',
            'type' => 'baptism',
            'suffix' => 'BAP-CEX',
            'date' => '2013-08-11',
            'minister' => 'Fr. TEST Andres Lim',
            'source' => 'other_parish',
            'place' => 'St. Andrew Parish, Baler, Aurora',
            'verified' => false,
            'parents_union_type' => 'canonical',
        ],
    ];

    foreach ([
        ['person' => 'marriage_unscheduled_groom', 'suffix' => 'MU-G', 'type' => 'groom'],
        ['person' => 'marriage_unscheduled_bride', 'suffix' => 'MU-B', 'type' => 'bride'],
        ['person' => 'marriage_scheduled_groom', 'suffix' => 'MS-G', 'type' => 'groom'],
        ['person' => 'marriage_scheduled_bride', 'suffix' => 'MS-B', 'type' => 'bride'],
    ] as $marriageProfile) {
        $profileKey = $marriageProfile['person'];
        $recordDefinitions[] = [
            'key' => $profileKey . '_baptism',
            'person' => $profileKey,
            'type' => 'baptism',
            'suffix' => 'BAP-' . $marriageProfile['suffix'],
            'date' => '2006-04-02',
            'minister' => 'Fr. TEST Antonio Reyes',
            'verified' => true,
            'parents_union_type' => 'canonical',
        ];
        $recordDefinitions[] = [
            'key' => $profileKey . '_confirmation',
            'person' => $profileKey,
            'type' => 'confirmation',
            'suffix' => 'CON-' . $marriageProfile['suffix'],
            'date' => '2019-09-15',
            'minister' => 'Most Rev. TEST Rafael Santos',
            'verified' => true,
        ];
    }

    foreach ($recordDefinitions as $definition) {
        $personKey = $definition['person'];
        $personInfo = $pdo->prepare(
            'SELECT first_name, middle_name, last_name, suffix, father_name,
                    mother_maiden_name, address
             FROM persons WHERE id = ?'
        );
        $personInfo->execute([$people[$personKey]]);
        $person = $personInfo->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            throw new RuntimeException('A test profile could not be reloaded.');
        }

        $details = $definition['details'] ?? null;
        if ($definition['type'] === 'baptism') {
            $details = $baseBaptismDetails((string) $person['address']);
        }
        $recordIds[$definition['key']] = test_seed_record($pdo, $verifierId, [
            'person_id' => $people[$personKey],
            'spouse_person_id' => isset($definition['spouse']) ? $people[$definition['spouse']] : null,
            'sacrament_type' => $definition['type'],
            'entry_suffix' => $definition['suffix'],
            'event_date' => $definition['date'],
            'place' => $definition['place'] ?? 'St. Patrick’s Parish Church, Dingalan',
            'minister_name' => $definition['minister'],
            'source' => $definition['source'] ?? 'this_parish',
            'book_no' => 'TEST 2026',
            'page_no' => str_pad((string) (count($recordIds) + 1), 2, '0', STR_PAD_LEFT),
            'line_no' => '1',
            'parents_union_type' => $definition['parents_union_type'] ?? null,
            'parents_marriage_parish' => null,
            'details' => $details,
            'verified' => $definition['verified'],
        ]);
    }

    $participantRecords = [
        [$recordIds['funeral_baptism'], 'godfather', 'TEST SEED: Manuel Rivera', 'Dingalan, Aurora', 1],
        [$recordIds['funeral_baptism'], 'godmother', 'TEST SEED: Elena Santos', 'Dingalan, Aurora', 2],
        [$recordIds['funeral_marriage'], 'principal_sponsor', 'TEST SEED: Ninong Ramon Cruz', 'Dingalan, Aurora', 1],
        [$recordIds['funeral_marriage'], 'principal_sponsor', 'TEST SEED: Ninang Maria Reyes', 'Dingalan, Aurora', 2],
    ];
    foreach (array_unique(array_column($participantRecords, 0)) as $recordId) {
        $pdo->prepare('DELETE FROM sacrament_participants WHERE record_id = ?')->execute([$recordId]);
    }
    $participantInsert = $pdo->prepare(
        'INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($participantRecords as $participant) {
        $participantInsert->execute($participant);
    }

    $communionKeys = [
        'origin_baptismal_certificate',
        'first_reconciliation',
        'catechism_completion',
        'parent_guardian_commitment',
    ];
    $groupOpenBatch = test_seed_batch(
        $pdo,
        TEST_SEED_BATCH_PREFIX . ' Holy Communion — Group Alpha — 2026',
        '2026'
    );
    $groupScheduledBatch = test_seed_batch(
        $pdo,
        TEST_SEED_BATCH_PREFIX . ' Holy Communion — Group Beta — 2026',
        '2026'
    );
    $coordinator = [
        'name' => 'Marina Lourdes Reyes',
        'number' => '09175550201',
        'email' => 'test.communion.coordinator@example.test',
    ];
    $openCandidates = [
        ['Leandro Miguel Rivera Cruz', $people['group_verified'], 'verified', 'Baptismal Certificate is on file: Book TEST 2026, Page 05, Line 1.'],
        ['Althea Grace Navarro Cruz', null, 'not_received', 'No profile is linked yet; confirm the candidate identity.'],
        ['Mateo Luis Domingo Reyes', $people['group_prerequisite'], 'not_received', 'Profile indicates Catholic Baptism, but no parish Baptism record is linked.'],
        ['Bianca Rose Manalo Garcia', $people['group_unverified'], 'not_received', 'A parish Baptism record exists but is not yet verified against the register.'],
        ['Daniel Rafael Bautista Flores', $people['group_external'], 'not_received', 'Origin-parish Baptism record; review the original certificate.'],
        ['Isabel Mae Villanueva Dizon', null, 'not_received', 'No profile is linked yet; confirm the candidate identity.'],
    ];
    foreach ($openCandidates as $index => [$name, $personId, $baptismStatus, $baptismNote]) {
        $requirements = test_seed_requirements($communionKeys, [
            'origin_baptismal_certificate' => [
                'status' => $baptismStatus,
                'note' => TEST_SEED_MARKER . ' ' . $baptismNote,
            ],
        ]);
        $details = [
            'candidate_name' => $name,
            'formation_path' => 'batch',
            'school_grade' => 'general',
            'school_year' => '2026',
            'group_label' => 'TEST SEED: Group Alpha — Dingalan Catechetical Community',
            'group_reference' => 'TST-COM-GRP-OPEN-2026',
            'intake_mode' => 'group',
            'contact_person_name' => $coordinator['name'],
            'contact_person_number' => $coordinator['number'],
            'contact_person_email' => $coordinator['email'],
            'preferred_priest' => '',
            'communion_venue' => 'other',
            'communion_venue_other' => 'Community Chapel (TEST), Barangay Poblacion, Dingalan, Aurora',
        ];
        $number = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
        test_seed_application($pdo, [
            'request_no' => TEST_SEED_APPLICATION_PREFIX . $number,
            'sacrament_type' => 'first_communion',
            'person_id' => $personId,
            'batch_id' => $groupOpenBatch,
            'applicant_name' => $name,
            'applicant_email' => $coordinator['email'],
            'applicant_contact' => $coordinator['number'],
            'status' => 'new',
            'details' => $details,
            'requirements' => $requirements,
            'note' => 'Submitted group intake candidate ' . ($index + 1) . ' of 6; TEST-COM-GRP-OPEN-2026.',
        ]);
    }

    $scheduledGroupCandidates = [
        'Rafael Tomas Aguilar',
        'Camille Rose Mendoza',
        'Joaquin Luis Villanueva',
    ];
    $scheduledGroupContact = [
        'name' => 'Teresa Mae Aguilar',
        'number' => '09175550202',
        'email' => 'test.communion.scheduled@example.test',
    ];
    foreach ($scheduledGroupCandidates as $index => $name) {
        $details = [
            'candidate_name' => $name,
            'formation_path' => 'batch',
            'school_grade' => 'general',
            'school_year' => '2026',
            'group_label' => 'TEST SEED: Group Beta — Holy Communion',
            'group_reference' => 'TST-COM-GRP-SCHEDULED-2026',
            'intake_mode' => 'group',
            'contact_person_name' => $scheduledGroupContact['name'],
            'contact_person_number' => $scheduledGroupContact['number'],
            'contact_person_email' => $scheduledGroupContact['email'],
            'preferred_priest' => '',
            'communion_venue' => 'other',
            'communion_venue_other' => 'TEST Community Chapel, Barangay Matawe, Dingalan, Aurora',
        ];
        test_seed_application($pdo, [
            'request_no' => TEST_SEED_APPLICATION_PREFIX . (string) (11 + $index),
            'sacrament_type' => 'first_communion',
            'batch_id' => $groupScheduledBatch,
            'applicant_name' => $name,
            'applicant_email' => $scheduledGroupContact['email'],
            'applicant_contact' => $scheduledGroupContact['number'],
            'status' => 'scheduled',
            'details' => $details,
            'requirements' => test_seed_requirements($communionKeys, [
                'origin_baptismal_certificate' => [
                    'status' => 'received',
                    'note' => TEST_SEED_MARKER . ' Received for scheduled group demonstration.',
                ],
            ]),
            'note' => 'Approved and scheduled as one group batch; TEST-COM-GRP-SCHEDULED-2026.',
        ]);
    }
    test_seed_schedule($pdo, [
        'event_type' => 'other',
        'title' => TEST_SEED_BATCH_PREFIX . ' Holy Communion Group Beta — shared schedule',
        'public_details' => 'Venue: TEST Community Chapel, Barangay Matawe, Dingalan, Aurora',
        'event_date' => $today->modify('+30 days')->format('Y-m-d'),
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
        'batch_id' => $groupScheduledBatch,
        'note' => 'Confirmed shared group schedule fixture.',
    ]);

    $individualBirthDate = '2015-04-22';
    $individualAge = (new DateTimeImmutable($individualBirthDate))->diff($today)->y;
    test_seed_application($pdo, [
        'request_no' => TEST_SEED_APPLICATION_PREFIX . '21',
        'sacrament_type' => 'first_communion',
        'person_id' => $people['individual'],
        'batch_id' => null,
        'applicant_name' => 'Sofia Isabel Navarro Dela Cruz',
        'applicant_email' => 'test.communion.individual@example.test',
        'applicant_contact' => '09175550203',
        'status' => 'new',
        'details' => [
            'candidate_name' => 'Sofia Isabel Navarro Dela Cruz',
            'formation_path' => 'individual_catchup',
            'school_grade' => '',
            'school_year' => null,
            'group_label' => '',
            'group_reference' => null,
            'intake_mode' => 'individual',
            'candidate_birth_date' => $individualBirthDate,
            'age_at_submission' => $individualAge,
            'age_eligibility_status' => 'Age-eligible',
            'father_name' => 'Andres Dela Cruz',
            'mother_maiden_name' => 'Miriam Navarro',
            'parent_guardian_name' => 'Miriam Navarro',
            'contact_person_name' => 'Miriam Navarro',
            'contact_person_number' => '09175550203',
            'contact_person_email' => 'test.communion.individual@example.test',
            'preferred_priest' => '',
        ],
        'requirements' => test_seed_requirements($communionKeys, [
            'origin_baptismal_certificate' => [
                'status' => 'verified',
                'note' => TEST_SEED_MARKER . ' Verified on file: Book TEST 2026, Page 08, Line 1.',
            ],
        ]),
        'note' => 'Individual Communion; no batch and no group venue; TEST-COM-IND-2026.',
    ]);

    $confirmationKeys = [
        'psa_birth_certificate',
        'origin_baptismal_certificate',
        'first_communion_verified',
        'confirmation_sponsor_details',
        'confirmation_seminar',
        'parent_guardian_commitment',
    ];
    foreach ([
        [
            'code' => '31',
            'person' => 'confirmation_internal',
            'name' => 'Ramon Miguel Santos Rivera',
            'birth_date' => '2012-02-20',
            'baptized_here' => 'this_parish',
            'baptism_parish' => '',
            'baptism_status' => 'verified',
            'baptism_note' => 'Verified on file: Book TEST 2026, Page 09, Line 1.',
            'communion_status' => 'verified',
            'communion_note' => 'Verified on file: Book TEST 2026, Page 10, Line 1.',
        ],
        [
            'code' => '32',
            'person' => 'confirmation_external',
            'name' => 'Theresa Marie Bautista Mendoza',
            'birth_date' => '2013-08-03',
            'baptized_here' => 'elsewhere',
            'baptism_parish' => 'St. Andrew Parish, Baler, Aurora',
            'baptism_status' => 'not_received',
            'baptism_note' => 'Origin-parish certificate for Confirmation purposes is still required.',
            'communion_status' => 'not_received',
            'communion_note' => 'Confirm the Holy Communion record with the applicant.',
        ],
    ] as $confirmation) {
        $age = (new DateTimeImmutable($confirmation['birth_date']))->diff($today)->y;
        test_seed_application($pdo, [
            'request_no' => TEST_SEED_APPLICATION_PREFIX . $confirmation['code'],
            'sacrament_type' => 'confirmation',
            'person_id' => $people[$confirmation['person']],
            'applicant_name' => $confirmation['name'],
            'applicant_email' => 'test.confirmation.' . $confirmation['code'] . '@example.test',
            'applicant_contact' => '0917555030' . $confirmation['code'],
            'status' => 'new',
            'details' => [
                'applicant_full_name' => $confirmation['name'],
                'birth_date' => $confirmation['birth_date'],
                'age_at_submission' => $age,
                'age_eligibility_status' => $age >= 12 ? 'Age-eligible' : 'Not yet age-eligible',
                'father_name' => $confirmation['person'] === 'confirmation_internal' ? 'Noel Rivera' : 'Rafael Mendoza',
                'mother_maiden_name' => $confirmation['person'] === 'confirmation_internal' ? 'Teresa Santos' : 'Lucia Bautista',
                'birth_place' => $confirmation['person'] === 'confirmation_internal' ? 'Dingalan, Aurora' : 'Baler, Aurora',
                'parent_guardian_name' => $confirmation['person'] === 'confirmation_internal' ? 'Teresa Santos' : 'Lucia Bautista',
                'guardian_relationship' => 'parent',
                'guardian_relationship_other' => '',
                'baptized_here' => $confirmation['baptized_here'],
                'baptism_parish' => $confirmation['baptism_parish'],
                'formation_path' => 'individual_catchup',
                'school_grade' => '',
                'group_label' => '',
                'school_year' => null,
                'sponsor_names' => ['TEST SEED: Andrea Santos'],
            ],
            'requirements' => test_seed_requirements($confirmationKeys, [
                'origin_baptismal_certificate' => [
                    'status' => $confirmation['baptism_status'],
                    'note' => TEST_SEED_MARKER . ' ' . $confirmation['baptism_note'],
                ],
                'first_communion_verified' => [
                    'status' => $confirmation['communion_status'],
                    'note' => TEST_SEED_MARKER . ' ' . $confirmation['communion_note'],
                ],
            ]),
            'note' => 'Confirmation pre-application: ' . $confirmation['baptized_here'] . ' Baptism scenario.',
        ]);
    }

    $marriageAddresses = [
        'unscheduled_groom' => 'Barangay Poblacion, Dingalan, Aurora',
        'unscheduled_bride' => 'Barangay Matawe, Dingalan, Aurora',
        'scheduled_groom' => 'Barangay Matawe, Dingalan, Aurora',
        'scheduled_bride' => 'Barangay Poblacion, Baler, Aurora',
    ];
    $marriageSponsorLists = [
        'godfathers' => ['TEST SEED: Ninong Manuel Reyes', 'TEST SEED: Ninong Pedro Santos'],
        'godmothers' => ['TEST SEED: Ninang Elena Cruz', 'TEST SEED: Ninang Rosa Villanueva'],
    ];
    $marriageScenarios = [
        [
            'code' => '41',
            'groom' => 'marriage_unscheduled_groom',
            'bride' => 'marriage_unscheduled_bride',
            'groom_name' => 'Paolo Gabriel Navarro Reyes',
            'bride_name' => 'Maria Elena Santos Garcia',
            'groom_birth_date' => $unscheduledMinorBirthday,
            'bride_birth_date' => '2002-05-04',
            'groom_address' => $marriageAddresses['unscheduled_groom'],
            'bride_address' => $marriageAddresses['unscheduled_bride'],
            'schedule_date' => null,
            'status' => 'approved',
            'label' => 'unscheduled; age reference falls back to today',
        ],
        [
            'code' => '42',
            'groom' => 'marriage_scheduled_groom',
            'bride' => 'marriage_scheduled_bride',
            'groom_name' => 'Enrique Mateo Rivera Dizon',
            'bride_name' => 'Ana Patricia Manalo Villanueva',
            'groom_birth_date' => $scheduledBirthday,
            'bride_birth_date' => '2002-12-05',
            'groom_address' => $marriageAddresses['scheduled_groom'],
            'bride_address' => $marriageAddresses['scheduled_bride'],
            'schedule_date' => $futureScheduleDate,
            'status' => 'scheduled',
            'label' => 'confirmed schedule; groom turns 21 on the scheduled date',
        ],
    ];
    $marriageRequirementKeys = [
        'psa_birth_certificate_groom', 'psa_birth_certificate_bride',
        'psa_cenomar_groom', 'psa_cenomar_bride', 'psa_marriage_certificate_couple',
        'psa_death_certificate_groom', 'psa_death_certificate_bride',
        'psa_prior_marriage_certificate_groom', 'psa_prior_marriage_certificate_bride',
        'civil_annulment_finality_groom', 'civil_annulment_finality_bride',
        'annotated_psa_marriage_certificate_groom', 'annotated_psa_marriage_certificate_bride',
        'church_declaration_nullity_groom', 'church_declaration_nullity_bride',
        'origin_baptismal_certificate_groom', 'origin_baptismal_certificate_bride',
        'transfer_letter', 'canonical_interview', 'marriage_banns', 'pre_cana_seminar',
        'marriage_license_application', 'origin_confirmation_certificate_groom',
        'origin_confirmation_certificate_bride',
    ];
    foreach ($marriageScenarios as $scenario) {
        $groomId = $people[$scenario['groom']];
        $brideId = $people[$scenario['bride']];
        $details = [
            'groom' => [
                'full_name' => $scenario['groom_name'],
                'birth_date' => $scenario['groom_birth_date'],
                'birthplace' => 'Dingalan, Aurora',
                'current_address' => $scenario['groom_address'],
                'contact_number' => '09175550401',
                'email' => 'test.marriage.' . $scenario['code'] . '.groom@example.test',
                'religion' => 'Roman Catholic',
                'father_name' => 'Felipe Reyes',
                'mother_maiden_name' => 'Celia Navarro',
                'marital_status' => 'single',
                'baptized' => 'yes',
                'confirmed' => 'yes',
                'previous_marriage' => 'no',
            ],
            'bride' => [
                'full_name' => $scenario['bride_name'],
                'birth_date' => $scenario['bride_birth_date'],
                'birthplace' => 'Dingalan, Aurora',
                'current_address' => $scenario['bride_address'],
                'contact_number' => '09175550402',
                'email' => 'test.marriage.' . $scenario['code'] . '.bride@example.test',
                'religion' => 'Roman Catholic',
                'father_name' => 'Arturo Garcia',
                'mother_maiden_name' => 'Natividad Santos',
                'marital_status' => 'single',
                'baptized' => 'yes',
                'confirmed' => 'yes',
                'previous_marriage' => 'no',
            ],
            'preferred_wedding_date' => $scenario['schedule_date'],
            'preferred_wedding_time' => $scenario['schedule_date'] !== null ? '10:00' : '',
            'preferred_wedding_venue' => 'other',
            'preferred_wedding_venue_other' => 'TEST Community Chapel, Dingalan, Aurora',
            'preferred_officiant' => 'Fr. TEST Gabriel Rivera',
            'civilly_married' => 'no',
            'civil_marriage_date' => '',
            'civil_marriage_place' => '',
            'marriage_eligibility_declaration' => true,
            'required_documents' => ['PSA birth certificates', 'PSA CENOMAR'],
            'principal_sponsors' => $marriageSponsorLists,
        ];
        $requirements = marriage_application_requirement_states(
            'single',
            'single',
            'other',
            $pdo,
            $groomId,
            $brideId,
            $scenario['groom_address'],
            $scenario['bride_address']
        );
        foreach ($marriageRequirementKeys as $key) {
            if (!isset($requirements[$key])) {
                throw new RuntimeException("Marriage requirement helper omitted {$key}.");
            }
        }
        test_seed_application($pdo, [
            'request_no' => TEST_SEED_APPLICATION_PREFIX . $scenario['code'],
            'sacrament_type' => 'marriage',
            'person_id' => $groomId,
            'spouse_person_id' => $brideId,
            'applicant_name' => $scenario['groom_name'],
            'applicant_email' => $details['groom']['email'],
            'applicant_contact' => $details['groom']['contact_number'],
            'preferred_date' => $scenario['schedule_date'],
            'preferred_time' => $scenario['schedule_date'] !== null ? '10:00:00' : null,
            'status' => $scenario['status'],
            'details' => $details,
            'requirements' => $requirements,
            'note' => 'Holy Matrimony age scenario: ' . $scenario['label'] . '; Ninong and Ninang lists are separate.',
        ]);

        if ($scenario['schedule_date'] !== null) {
            $applicationIdStmt = $pdo->prepare(
                'SELECT id FROM sacrament_applications WHERE request_no = ? LIMIT 1'
            );
            $applicationIdStmt->execute([TEST_SEED_APPLICATION_PREFIX . $scenario['code']]);
            test_seed_schedule($pdo, [
                'event_type' => 'wedding',
                'title' => TEST_SEED_BATCH_PREFIX . ' Holy Matrimony — confirmed age scenario',
                'public_details' => 'Venue: TEST Community Chapel, Dingalan, Aurora',
                'event_date' => $scenario['schedule_date'],
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
                'application_id' => (int) $applicationIdStmt->fetchColumn(),
                'note' => 'Confirmed wedding schedule fixture for schedule-based age review.',
            ]);
        }
    }

    $legacyRequirements = test_seed_requirements($communionKeys, [
        'origin_baptismal_certificate' => [
            'status' => 'verified',
            'note' => 'TEST SEED: Legacy verified status; certificate checked against Book TEST 2024, Page 04, Line 7.',
            'document_date' => '2024-05-12',
            'reference_no' => 'TST-LEGACY-BAP-01',
            'issuing_parish' => 'St. Patrick’s Parish, Dingalan',
        ],
        'first_reconciliation' => [
            'status' => 'received',
            'note' => 'TEST SEED: Legacy received status retained.',
        ],
    ]);
    test_seed_application($pdo, [
        'request_no' => TEST_SEED_APPLICATION_PREFIX . '51',
        'sacrament_type' => 'first_communion',
        'person_id' => $people['individual'],
        'applicant_name' => 'Sofia Isabel Navarro Dela Cruz',
        'applicant_email' => 'test.communion.legacy@example.test',
        'applicant_contact' => '09175550203',
        'status' => 'requirements_pending',
        'details' => [
            'candidate_name' => 'Sofia Isabel Navarro Dela Cruz',
            'formation_path' => 'individual_catchup',
            'intake_mode' => 'individual',
            'candidate_birth_date' => $individualBirthDate,
            'father_name' => 'Andres Dela Cruz',
            'mother_maiden_name' => 'Miriam Navarro',
            'parent_guardian_name' => 'Miriam Navarro',
            'contact_person_name' => 'Miriam Navarro',
            'contact_person_number' => '09175550203',
            'contact_person_email' => 'test.communion.legacy@example.test',
        ],
        'requirements' => $legacyRequirements,
        'created_at' => '2024-06-15 09:00:00',
        'privacy_consent_at' => '2024-06-15 09:00:00',
        'note' => 'Older compatibility fixture for legacy verified checklist states.',
    ]);

    $confirmationRecord = [
        'funeral_baptism' => $recordIds['funeral_baptism'],
        'funeral_communion' => $recordIds['funeral_communion'],
        'funeral_confirmation' => $recordIds['funeral_confirmation'],
        'funeral_marriage' => $recordIds['funeral_marriage'],
    ];
    foreach ([
        ['BAP', '01', 'baptism', $people['funeral_subject'], $confirmationRecord['funeral_baptism'], 'Rogelio Antonio Manalo Santos', '1982-03-07', '1982-03-14', 'TEST SEED: Lourdes Teresa Ramos Santos', ['birth_date' => '1982-03-07', 'father_name' => 'Pedro Santos', 'mother_maiden_name' => 'Beatriz Manalo']],
        ['COM', '02', 'first_communion', $people['funeral_subject'], $confirmationRecord['funeral_communion'], 'Rogelio Antonio Manalo Santos', '1982-03-07', '1990-05-20', 'TEST SEED: Lourdes Teresa Ramos Santos', ['birth_date' => '1982-03-07', 'father_name' => 'Pedro Santos', 'mother_maiden_name' => 'Beatriz Manalo']],
        ['CON', '03', 'confirmation', $people['funeral_subject'], $confirmationRecord['funeral_confirmation'], 'Rogelio Antonio Manalo Santos', '1982-03-07', '1995-08-20', 'TEST SEED: Lourdes Teresa Ramos Santos', ['birth_date' => '1982-03-07', 'father_name' => 'Pedro Santos', 'mother_maiden_name' => 'Beatriz Manalo']],
        ['MAR', '04', 'marriage', $people['funeral_subject'], $confirmationRecord['funeral_marriage'], 'Rogelio Antonio Manalo Santos', '1982-03-07', '2004-01-17', 'TEST SEED: Lourdes Teresa Ramos Santos', ['spouse_name' => 'Lourdes Teresa Ramos Santos', 'marriage_date' => '2004-01-17']],
    ] as [$shortType, $requestSuffix, $type, $personId, $recordId, $subjectName, $birthDate, $eventDate, $requesterName, $subjectDetails]) {
        test_seed_certificate_request($pdo, [
            'request_no' => TEST_SEED_CERTIFICATE_PREFIX . $requestSuffix,
            'sacrament_type' => $type,
            'subject_name' => $subjectName,
            'subject_details' => $subjectDetails,
            'requester_name' => $requesterName,
            'requester_email' => 'test.certificate.' . strtolower($shortType) . '@example.test',
            'requester_contact_number' => '09175550501',
            'purpose' => 'personal',
            'person_id' => $personId,
            'record_id' => $recordId,
            'status' => 'for_signature',
            'note' => 'Certificate preview fixture; TEST SEED minister is stored on the linked register row.',
        ]);
    }

    test_seed_funeral($pdo, $people['funeral_subject'], [
        'date_of_death' => '2026-11-12',
        'date_of_burial' => '2026-11-14',
        'age_at_death' => 44,
        'civil_status' => 'married',
        'spouse_name' => 'Lourdes Teresa Ramos Santos',
        'parents_name' => 'Father: Pedro Santos; Mother: Beatriz Manalo',
        'sacraments' => ['baptism', 'confirmation', 'marriage', 'anointing'],
        'service_variant' => 'mass_with_wake',
        'service_date' => '2026-11-13',
        'service_time' => '18:00:00',
        'minister_name' => 'Fr. TEST Gabriel Rivera',
        'book_no' => 'TEST FB-01',
        'page_no' => '11',
        'line_no' => '4',
        'contact_name' => 'Marina Lourdes Reyes',
        'contact_email' => 'test.funeral.wake@example.test',
        'contact_number' => '09175550601',
        'cause_of_death' => 'Test data only; non-clinical placeholder.',
        'place_of_burial' => 'Dingalan Municipal Cemetery',
    ]);
    test_seed_funeral($pdo, $people['funeral_manual'], [
        'date_of_death' => '2025-08-12',
        'date_of_burial' => '2025-08-15',
        'age_at_death' => 74,
        'civil_status' => 'widowed',
        'spouse_name' => 'Carmen Lucia Mendoza Bautista',
        'parents_name' => 'Father: Pedro Bautista; Mother: Carmen Mendoza',
        'sacraments' => [],
        'service_variant' => 'mass_death_anniversary',
        'service_date' => $today->modify('+60 days')->format('Y-m-d'),
        'service_time' => '08:00:00',
        'minister_name' => 'Fr. TEST Antonio Reyes',
        'book_no' => 'TEST FB-02',
        'page_no' => '12',
        'line_no' => '2',
        'contact_name' => 'Rogelio Antonio Bautista',
        'contact_email' => 'test.funeral.anniversary@example.test',
        'contact_number' => '09175550602',
        'cause_of_death' => 'Test data only; non-clinical placeholder.',
        'place_of_burial' => 'Dingalan Municipal Cemetery',
    ]);

    if ($rollback) {
        $pdo->rollBack();
        echo "Seed preview rolled back; no rows were committed.\n";
    } else {
        $pdo->commit();
        echo "Inserted or refreshed TEST SEED fixtures.\n";
        echo "Group request references: " . TEST_SEED_APPLICATION_PREFIX . "01–06 (open), "
            . TEST_SEED_APPLICATION_PREFIX . "11–13 (scheduled).\n";
        echo "Other applications: " . TEST_SEED_APPLICATION_PREFIX . "21 (individual), "
            . TEST_SEED_APPLICATION_PREFIX . "31–32 (Confirmation), "
            . TEST_SEED_APPLICATION_PREFIX . "41–42 (Marriage), "
            . TEST_SEED_APPLICATION_PREFIX . "51 (legacy requirements).\n";
        echo "Cleanup: php database/v2/seed_test_scenarios.php --cleanup --execute\n";
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Test seeder failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
