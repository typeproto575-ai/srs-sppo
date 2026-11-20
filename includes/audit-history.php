<?php

declare(strict_types=1);

function audit_correction_data(string $description): ?array
{
    $data = json_decode($description, true);
    if (!is_array($data)
        || !in_array($data['format'] ?? '', [
            'person-correction-v1',
            'record-correction-v1',
            'funeral-correction-v1',
            'application-correction-v1',
        ], true)) {
        return null;
    }

    return $data;
}

function audit_correction_rows(array $correctionData): array
{
    $format = (string) ($correctionData['format'] ?? '');
    $changes = $correctionData['changes'] ?? null;
    if (!is_array($changes)) {
        return [];
    }

    $rows = [];
    if ($format === 'record-correction-v1') {
        foreach ($changes as $section => $change) {
            if (!is_array($change)
                || !array_key_exists('before', $change)
                || !array_key_exists('after', $change)) {
                continue;
            }
            audit_correction_flatten_diff(
                $rows,
                audit_correction_label((string) $section),
                $change['before'],
                $change['after']
            );
        }
        return $rows;
    }
    if ($format === 'application-correction-v1') {
        foreach ($changes as $section => $change) {
            if (!is_array($change)) {
                continue;
            }
            if (array_key_exists('before', $change) && array_key_exists('after', $change)) {
                audit_correction_flatten_diff(
                    $rows,
                    audit_correction_label((string) $section),
                    $change['before'],
                    $change['after']
                );
                continue;
            }
            foreach ($change as $fieldPath => $fieldChange) {
                if (!is_array($fieldChange)
                    || !array_key_exists('before', $fieldChange)
                    || !array_key_exists('after', $fieldChange)) {
                    continue;
                }
                audit_correction_flatten_diff(
                    $rows,
                    audit_correction_label((string) $section) . ' · ' . audit_correction_label((string) $fieldPath),
                    $fieldChange['before'],
                    $fieldChange['after']
                );
            }
        }
        return $rows;
    }

    foreach ($changes as $field => $change) {
        if (!is_array($change)
            || !array_key_exists('before', $change)
            || !array_key_exists('after', $change)) {
            continue;
        }
        audit_correction_flatten_diff(
            $rows,
            audit_correction_label((string) $field),
            $change['before'],
            $change['after']
        );
    }

    return $rows;
}

function audit_correction_label(string $field): string
{
    if (str_contains($field, '.')) {
        return implode(' · ', array_map(
            static fn(string $part): string => ctype_digit($part)
                ? 'Entry ' . ((int) $part + 1)
                : audit_correction_label($part),
            explode('.', $field)
        ));
    }

    static $labels = [
        'record' => 'Record',
        'person' => 'Person profile',
        'spouse' => 'Spouse profile',
        'participants' => 'Sponsors and witnesses',
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'last_name' => 'Last name',
        'suffix' => 'Suffix',
        'sex' => 'Sex',
        'birth_date' => 'Date of birth',
        'birth_place' => 'Place of birth',
        'father_name' => "Father's name",
        'mother_maiden_name' => "Mother's maiden name",
        'father_person_id' => 'Father profile',
        'mother_person_id' => 'Mother profile',
        'baptismal_status' => 'Baptismal status',
        'address' => 'Address',
        'contact_number' => 'Contact number',
        'contact_email' => 'Family contact email',
        'observanda' => 'Profile notes',
        'sacrament_type' => 'Sacrament',
        'person_id' => 'Linked person',
        'spouse_person_id' => 'Linked spouse',
        'applicant_name' => 'Applicant / submitter name',
        'applicant_email' => 'Applicant / submitter email',
        'applicant_contact' => 'Applicant / submitter contact number',
        'submitted_details' => 'Submitted form details',
        'source' => 'Record source',
        'event_date' => 'Event date',
        'place' => 'Place',
        'minister_name' => 'Minister',
        'entry_no' => 'Entry number',
        'book_no' => 'Book',
        'page_no' => 'Page',
        'line_no' => 'Line',
        'parents_union_type' => "Parents' union type",
        'parents_marriage_parish' => "Parents' marriage parish",
        'details' => 'Additional details',
        'date_of_death' => 'Date of death',
        'date_of_burial' => 'Date of burial',
        'age_at_death' => 'Age at death',
        'civil_status' => 'Civil status',
        'spouse_name' => 'Spouse name',
        'parents_name' => 'Parents name',
        'sacraments_received' => 'Sacraments received',
        'place_of_burial' => 'Place of burial',
        'service_type' => 'Blessing or Mass',
        'service_date' => 'Service date',
        'service_time' => 'Service time',
        'person_name' => 'Person',
        'person_is_deceased' => 'Person marked deceased',
        'person_death_date' => 'Person profile death date',
        'previous_person_id' => 'Former linked profile',
        'previous_person_name' => 'Former linked person',
        'previous_person_is_deceased' => 'Former profile marked deceased',
        'previous_person_death_date' => 'Former profile death date',
        'sort_order' => 'Order',
        'role' => 'Role',
        'full_name' => 'Full name',
        'residence' => 'Residence',
    ];

    return $labels[$field] ?? ucwords(str_replace(['_', '-'], ' ', $field));
}

function audit_correction_value(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Not recorded';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if (!is_array($value)) {
        return (string) $value;
    }
    if ($value === []) {
        return 'None recorded';
    }

    $parts = [];
    foreach ($value as $key => $item) {
        $formatted = audit_correction_value($item);
        $parts[] = is_string($key)
            ? audit_correction_label($key) . ': ' . $formatted
            : $formatted;
    }

    return implode('; ', $parts);
}

function audit_correction_flatten_diff(
    array &$rows,
    string $path,
    mixed $before,
    mixed $after
): void {
    if (is_array($before)
        && is_array($after)
        && !array_is_list($before)
        && !array_is_list($after)) {
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $nextPath = $path === ''
                ? audit_correction_label((string) $key)
                : $path . ' · ' . audit_correction_label((string) $key);
            audit_correction_flatten_diff(
                $rows,
                $nextPath,
                $before[$key] ?? null,
                $after[$key] ?? null
            );
        }
        return;
    }

    if ($before === $after) {
        return;
    }

    $rows[] = [
        'field' => $path,
        'before' => audit_correction_value($before),
        'after' => audit_correction_value($after),
    ];
}

function audit_correction_summary(array $correctionData): string
{
    $rows = audit_correction_rows($correctionData);
    if ($rows === []) {
        return 'No field changes recorded.';
    }

    $previewRows = array_slice($rows, 0, 3);
    $summary = implode('; ', array_map(static function (array $row): string {
        $before = strlen($row['before']) > 80 ? substr($row['before'], 0, 77) . '...' : $row['before'];
        $after = strlen($row['after']) > 80 ? substr($row['after'], 0, 77) . '...' : $row['after'];
        return $row['field'] . ': ' . $before . ' -> ' . $after;
    }, $previewRows));
    if (count($rows) > count($previewRows)) {
        $summary .= '; +' . (count($rows) - count($previewRows)) . ' more';
    }

    return $summary;
}

function audit_correction_full_summary(array $correctionData): string
{
    $rows = audit_correction_rows($correctionData);
    if ($rows === []) {
        return 'No field changes recorded.';
    }

    return implode('; ', array_map(
        static fn(array $row): string => $row['field'] . ': ' . $row['before'] . ' -> ' . $row['after'],
        $rows
    ));
}

function audit_render_correction_details(array $correctionData): void
{
    $rows = audit_correction_rows($correctionData);
    if ($rows === []) {
        echo '<p class="field-hint">No field changes recorded.</p>';
        return;
    }

    echo '<details class="audit-correction-details">';
    echo '<summary>Changed values (' . count($rows) . ')</summary>';
    echo '<div class="table-scroll"><table class="admin-table audit-correction-table">';
    echo '<thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><th scope="row">' . e($row['field']) . '</th>';
        echo '<td>' . e($row['before']) . '</td><td>' . e($row['after']) . '</td></tr>';
    }
    echo '</tbody></table></div></details>';
}
