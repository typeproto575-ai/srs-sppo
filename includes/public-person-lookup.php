<?php

declare(strict_types=1);

function public_person_lookup_record_summary(array $recordGroups): array
{
    $records = [];
    foreach (['baptism', 'first_communion', 'confirmation'] as $type) {
        if (is_array($recordGroups[$type] ?? null)) {
            $records[] = $recordGroups[$type];
        }
    }
    $marriages = is_array($recordGroups['marriages'] ?? null) ? $recordGroups['marriages'] : [];
    foreach ($marriages as $record) {
        if (is_array($record)) {
            $records[] = $record;
        }
    }

    $summary = [];
    foreach ($records as $record) {
        $type = (string) ($record['sacrament_type'] ?? '');
        if (!in_array($type, ['baptism', 'first_communion', 'confirmation', 'marriage'], true)) {
            continue;
        }

        $summary[] = [
            'type' => $type,
            'event_date' => $record['event_date'] ?? null,
            'place' => $record['place'] ?? null,
            'source' => in_array($record['source'] ?? null, ['this_parish', 'other_parish'], true)
                ? $record['source']
                : null,
            'entry_no' => $record['entry_no'] ?? null,
            'book_no' => $record['book_no'] ?? null,
            'page_no' => $record['page_no'] ?? null,
            'line_no' => $record['line_no'] ?? null,
            'is_verified' => !empty($record['book_verified_at']),
        ];
    }

    usort($summary, static fn(array $left, array $right): int =>
        strcmp((string) ($left['event_date'] ?? ''), (string) ($right['event_date'] ?? ''))
    );

    return $summary;
}
