<?php

declare(strict_types=1);

function funeral_parse_date(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function funeral_input_string(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

function funeral_validate(array $input, ?string $personBirthDate): array
{
    $errors = [];
    foreach ([
        'date_of_death' => 'date of death',
        'date_of_burial' => 'date of burial',
        'service_type' => 'service type',
        'civil_status' => 'civil status',
        'spouse_name' => 'spouse name',
        'age_at_death' => 'age at death',
        'service_time' => 'service time',
        'service_date' => 'service date',
    ] as $field => $label) {
        if (array_key_exists($field, $input) && !is_scalar($input[$field])) {
            $errors[] = "Enter a valid {$label}.";
        }
    }

    $deathValue = funeral_input_string($input['date_of_death'] ?? '');
    $deathDate = funeral_parse_date($deathValue);
    $birthDate = funeral_parse_date($personBirthDate);

    if ($deathValue === '') {
        $errors[] = 'Date of death is required.';
    } elseif ($deathDate === null) {
        $errors[] = 'Enter a valid date of death.';
    } else {
        if ($deathDate > new DateTimeImmutable('today')) {
            $errors[] = 'Date of death cannot be in the future.';
        }
        if ($birthDate !== null && $deathDate < $birthDate) {
            $errors[] = 'Date of death cannot be before the person\'s date of birth.';
        }
    }

    $burialValue = funeral_input_string($input['date_of_burial'] ?? '');
    $burialDate = $burialValue === '' ? null : funeral_parse_date($burialValue);
    if ($burialValue !== '' && $burialDate === null) {
        $errors[] = 'Enter a valid date of burial.';
    } elseif ($burialDate !== null && $deathDate !== null && $burialDate < $deathDate) {
        $errors[] = 'Date of burial cannot be before date of death.';
    }

    $serviceType = funeral_input_string($input['service_type'] ?? '');
    if (!in_array($serviceType, ['mass', 'blessing'], true)) {
        $errors[] = 'Choose Mass or Blessing for the service type.';
    }

    $civilStatus = funeral_input_string($input['civil_status'] ?? '');
    if ($civilStatus !== '' && !in_array($civilStatus, ['single', 'married', 'widowed'], true)) {
        $errors[] = 'Choose a valid civil status.';
    }
    if ($civilStatus === 'single' && funeral_input_string($input['spouse_name'] ?? '') !== '') {
        $errors[] = 'A single person cannot have a spouse name.';
    }

    $ageValue = funeral_input_string($input['age_at_death'] ?? '');
    if ($ageValue !== '' && (!preg_match('/^\d+$/D', $ageValue) || (int) $ageValue > 130)) {
        $errors[] = 'Age at death must be a whole number from 0 to 130.';
    }

    $serviceTime = funeral_input_string($input['service_time'] ?? '');
    if ($serviceTime !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $serviceTime)) {
        $errors[] = 'Enter a valid service time in HH:MM format.';
    }

    $serviceDateValue = funeral_input_string($input['service_date'] ?? '');
    $serviceDate = $serviceDateValue === '' ? null : funeral_parse_date($serviceDateValue);
    if ($serviceDateValue !== '' && $serviceDate === null) {
        $errors[] = 'Enter a valid service date.';
    } elseif ($serviceDate !== null && $deathDate !== null && $serviceDate < $deathDate) {
        $errors[] = 'Service date cannot be before date of death.';
    }

    return $errors;
}

function funeral_suggested_sacraments(PDO $pdo, int $personId): array
{
    if ($personId < 1) {
        throw new InvalidArgumentException('A valid person ID is required.');
    }

    $stmt = $pdo->prepare(
        "SELECT DISTINCT sacrament_type
         FROM sacrament_records
         WHERE sacrament_type IN ('baptism', 'confirmation', 'marriage')
           AND (person_id = ? OR (sacrament_type = 'marriage' AND spouse_person_id = ?))"
    );
    $stmt->execute([$personId, $personId]);

    $found = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter(
        ['baptism', 'confirmation', 'marriage'],
        static fn(string $type): bool => isset($found[$type])
    ));
}

function funeral_calculated_age(?string $birth, string $death): ?int
{
    $birthDate = funeral_parse_date($birth);
    $deathDate = funeral_parse_date($death);
    if ($birthDate === null || $deathDate === null || $birthDate > $deathDate) {
        return null;
    }

    return $birthDate->diff($deathDate)->y;
}

function funeral_certification_lines(
    array $record,
    array $person,
    string $purpose,
    string $requesterName,
    string $issuedOn
): array {
    $value = static function (array $source, string $key): string {
        $raw = $source[$key] ?? '';
        return is_scalar($raw) ? trim((string) $raw) : '';
    };
    $formatDate = static function (mixed $raw): string {
        $date = funeral_parse_date($raw);
        return $date?->format('F j, Y') ?? '';
    };

    $name = $value($person, 'full_name');
    if ($name === '') {
        $name = implode(' ', array_filter(array_map(
            static fn(string $part): string => trim($part),
            [
                $value($person, 'first_name'),
                $value($person, 'middle_name'),
                $value($person, 'last_name'),
                $value($person, 'suffix'),
            ]
        ), static fn(string $part): bool => $part !== ''));
    }

    $descriptors = [];
    $age = $value($record, 'age_at_death');
    if ($age !== '') {
        $descriptors[] = $age . ' years old';
    }
    $civilStatus = $value($record, 'civil_status');
    if ($civilStatus !== '') {
        $descriptors[] = ucfirst(strtolower($civilStatus));
    }
    $spouse = $value($record, 'spouse_name');
    if ($spouse !== '') {
        $descriptors[] = 'spouse of ' . $spouse;
    }
    $parents = $value($record, 'parents_name');
    if ($parents !== '') {
        $relation = match (strtolower($value($person, 'sex'))) {
            'male' => 'son',
            'female' => 'daughter',
            default => 'child',
        };
        $descriptors[] = $relation . ' of ' . $parents;
    }

    $subject = $name;
    if ($descriptors !== []) {
        $subject .= ($subject !== '' ? ', ' : '') . implode(', ', $descriptors);
    }
    $deathDate = $formatDate($record['date_of_death'] ?? null);
    $first = 'This is to certify that, according to the Liber Defunctorum of this parish, ' . $subject;
    $deathClause = $descriptors !== [] ? ', died' : ' died';
    $first .= $deathClause . ($deathDate !== '' ? ' on ' . $deathDate : '');
    $cause = $value($record, 'cause_of_death');
    if ($cause !== '') {
        $first .= ' of ' . $cause;
    }

    $serviceType = strtolower($value($record, 'service_type'));
    $serviceName = $serviceType === 'blessing' ? 'Blessing' : 'Mass';
    $service = 'A Funeral ' . $serviceName . ' was held';
    $serviceDate = $formatDate($record['service_date'] ?? null);
    if ($serviceDate !== '') {
        $service .= ' on ' . $serviceDate;
    }
    $serviceTime = $value($record, 'service_time');
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $serviceTime)) {
        $time = DateTimeImmutable::createFromFormat(
            '!H:i:s',
            strlen($serviceTime) === 5 ? $serviceTime . ':00' : $serviceTime
        );
        if ($time !== false) {
            $service .= ' at ' . $time->format('g:i A');
        }
    }
    $minister = $value($record, 'minister_name');
    if ($minister !== '') {
        $service .= ', officiated by ' . $minister;
    }

    $burialDetails = [];
    $burialDate = $formatDate($record['date_of_burial'] ?? null);
    if ($burialDate !== '') {
        $burialDetails[] = 'on ' . $burialDate;
    }
    $burialPlace = $value($record, 'place_of_burial');
    if ($burialPlace !== '') {
        $burialDetails[] = 'at ' . $burialPlace;
    }
    if ($burialDetails !== []) {
        $service .= ', and the remains were laid to rest ' . implode(' ', $burialDetails);
    }

    $register = ['This is recorded in the Liber Defunctorum'];
    foreach ([
        'book_no' => 'Book',
        'page_no' => 'Page',
        'line_no' => 'Line',
    ] as $key => $label) {
        $entryValue = $value($record, $key);
        if ($entryValue !== '') {
            $register[] = $label . ' ' . $entryValue;
        }
    }

    $issue = 'Issued';
    $issueDate = $formatDate($issuedOn);
    if ($issueDate !== '') {
        $issue .= ' on ' . $issueDate;
    }
    $requesterName = trim($requesterName);
    if ($requesterName !== '') {
        $issue .= ' at the request of ' . $requesterName;
    }
    $purpose = trim($purpose);
    if ($purpose !== '') {
        $issue .= ' for the purpose of ' . $purpose;
    }

    return [
        $first . '.',
        $service . '.',
        implode(', ', $register) . '. ' . $issue . '.',
    ];
}
