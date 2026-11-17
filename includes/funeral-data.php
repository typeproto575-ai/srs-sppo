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

function funeral_contact_email_error(string $email): ?string
{
    if ($email === '') {
        return null;
    }

    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)
        ? null
        : 'Enter a valid family contact email address.';
}

function funeral_schedule_notification_due(?array $existing, array $values): bool
{
    $email = funeral_input_string($values['contact_email'] ?? '');
    $serviceDate = funeral_input_string($values['service_date'] ?? '');
    if ($serviceDate === '' || $email === '' || funeral_contact_email_error($email) !== null) {
        return false;
    }
    if ($existing === null) {
        return true;
    }

    return $serviceDate !== funeral_input_string($existing['service_date'] ?? '')
        || substr(funeral_input_string($values['service_time'] ?? ''), 0, 5)
            !== substr(funeral_input_string($existing['service_time'] ?? ''), 0, 5)
        || funeral_input_string($values['service_type'] ?? '')
            !== funeral_input_string($existing['service_type'] ?? '')
        || strcasecmp($email, funeral_input_string($existing['contact_email'] ?? '')) !== 0;
}
