<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('secretary');
header('Content-Type: application/json; charset=utf-8');

function application_person_link_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    application_person_link_response(405, ['error' => 'Use POST to link a person.']);
}
if (!csrf_verify()) {
    application_person_link_response(403, ['error' => 'Your session expired. Refresh the page and try again.']);
}

$applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
$role = (string) ($_POST['person_role'] ?? '');
$createPerson = isset($_POST['create_person']);
$personField = $role === 'spouse' ? 'spouse_person_id' : 'person_id';
if (!$applicationId || $applicationId < 1 || !in_array($role, ['applicant', 'spouse'], true)) {
    application_person_link_response(400, ['error' => 'The application or person role is invalid.']);
}

$identity = [];
if ($createPerson) {
    $identity = [
        'first_name' => trim((string) ($_POST['new_first_name'] ?? '')),
        'middle_name' => trim((string) ($_POST['new_middle_name'] ?? '')),
        'last_name' => trim((string) ($_POST['new_last_name'] ?? '')),
        'birth_date' => trim((string) ($_POST['new_birth_date'] ?? '')),
        'father_name' => trim((string) ($_POST['new_father_name'] ?? '')),
        'mother_maiden_name' => trim((string) ($_POST['new_mother_maiden_name'] ?? '')),
        'address' => trim((string) ($_POST['new_address'] ?? '')),
    ];
    if ($identity['first_name'] === '' || $identity['last_name'] === '') {
        application_person_link_response(422, ['error' => "Enter the person's first and last name."]);
    }
    if ($identity['birth_date'] !== '') {
        $birthDate = DateTime::createFromFormat('!Y-m-d', $identity['birth_date']);
        if (!$birthDate || $birthDate->format('Y-m-d') !== $identity['birth_date']) {
            application_person_link_response(422, ['error' => 'Enter a valid date of birth.']);
        }
    }
} else {
    $personId = filter_var($_POST[$personField] ?? null, FILTER_VALIDATE_INT);
    if (!$personId || $personId < 1) {
        application_person_link_response(422, ['error' => 'Select a person from the search results first.']);
    }
}

try {
    $pdo->beginTransaction();
    $applicationStmt = $pdo->prepare(
        'SELECT sacrament_type, person_id, spouse_person_id
         FROM sacrament_applications
         WHERE id = ?
         FOR UPDATE'
    );
    $applicationStmt->execute([$applicationId]);
    $application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
    if (!$application) {
        $pdo->rollBack();
        application_person_link_response(404, ['error' => 'The application could not be found.']);
    }
    if ($role === 'spouse' && $application['sacrament_type'] !== 'marriage') {
        $pdo->rollBack();
        application_person_link_response(422, ['error' => 'Only marriage applications can link a spouse profile.']);
    }

    if ($createPerson) {
        $insert = $pdo->prepare(
            'INSERT INTO persons
                (first_name, middle_name, last_name, birth_date, father_name, mother_maiden_name, address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $identity['first_name'],
            $identity['middle_name'] !== '' ? $identity['middle_name'] : null,
            $identity['last_name'],
            $identity['birth_date'] !== '' ? $identity['birth_date'] : null,
            $identity['father_name'] !== '' ? $identity['father_name'] : null,
            $identity['mother_maiden_name'] !== '' ? $identity['mother_maiden_name'] : null,
            $identity['address'] !== '' ? $identity['address'] : null,
        ]);
        $personId = (int) $pdo->lastInsertId();
        $person = [
            'id' => $personId,
            'first_name' => $identity['first_name'],
            'middle_name' => $identity['middle_name'],
            'last_name' => $identity['last_name'],
            'birth_date' => $identity['birth_date'],
            'father_name' => $identity['father_name'],
            'mother_maiden_name' => $identity['mother_maiden_name'],
            'address' => $identity['address'],
        ];
    } else {
        $personStmt = $pdo->prepare(
            'SELECT id, first_name, middle_name, last_name, suffix, birth_date,
                    father_name, mother_maiden_name, address
             FROM persons
             WHERE id = ?'
        );
        $personStmt->execute([$personId]);
        $person = $personStmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            $pdo->rollBack();
            application_person_link_response(404, ['error' => 'That person profile no longer exists. Search again.']);
        }
    }

    $otherField = $role === 'spouse' ? 'person_id' : 'spouse_person_id';
    if ($application['sacrament_type'] === 'marriage' && (int) ($application[$otherField] ?? 0) === $personId) {
        $pdo->rollBack();
        application_person_link_response(422, ['error' => 'The two marriage applicants must have different person profiles.']);
    }

    $update = $pdo->prepare("UPDATE sacrament_applications SET {$personField} = ? WHERE id = ?");
    $update->execute([$personId, $applicationId]);
    $user = current_user();
    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, actor_name, action, target_table, target_id, description, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $audit->execute([
        $user['id'],
        $user['full_name'] ?? null,
        $createPerson ? 'sacrament_application_person_created_and_linked' : 'sacrament_application_person_linked',
        'sacrament_applications',
        (int) $applicationId,
        "Linked {$role} to master person #{$personId}.",
        client_ip(),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
    ]);
    $pdo->commit();

    application_person_link_response(200, [
        'success' => true,
        'created' => $createPerson,
        'person' => $person,
        'message' => $createPerson ? 'New person profile created and linked.' : 'Person profile linked.',
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Application person link failed: ' . $e->getMessage());
    application_person_link_response(500, ['error' => 'The person could not be linked. Please try again.']);
}
