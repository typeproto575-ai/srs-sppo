<?php
/** Shared helpers for linking register rows to the persons identity index. */

if (!function_exists('person_identity_choices')) {
    function person_identity_choices(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT id, first_name, middle_name, last_name, suffix, maiden_name, birth_date
             FROM persons ORDER BY last_name, first_name, middle_name LIMIT 2000'
        )->fetchAll();
    }

    function person_identity_label(array $person): string
    {
        $label = trim(implode(' ', array_filter([
            $person['first_name'] ?? '',
            $person['middle_name'] ?? '',
            $person['last_name'] ?? '',
            $person['suffix'] ?? '',
        ])));
        if (!empty($person['maiden_name'])) $label .= ' (maiden: ' . $person['maiden_name'] . ')';
        if (!empty($person['birth_date'])) $label .= ' - born ' . date('Y-m-d', strtotime($person['birth_date']));
        return $label;
    }

    function posted_person_id(PDO $pdo, string $key): ?int
    {
        $raw = trim((string) ($_POST[$key] ?? ''));
        if ($raw === '') return null;
        $id = (int) $raw;
        if ($id <= 0) throw new InvalidArgumentException('Choose a valid person identity.');
        $stmt = $pdo->prepare('SELECT id FROM persons WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('The selected person identity does not exist.');
        return $id;
    }
}
