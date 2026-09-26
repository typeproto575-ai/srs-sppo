<?php
/** Shared Baptismal prerequisite record finder helpers. */

if (!function_exists('baptismal_record_choices')) {
    function baptismal_record_choices(PDO $pdo, ?int $preferredPersonId = null): array
    {
        $rows = $pdo->query(
            'SELECT id, child_full_name, birth_date, baptism_date, father_name, mother_name,
                    book_no, page_no, line_no, person_id
             FROM baptismal_records
             WHERE is_archived = 0
             ORDER BY child_full_name, baptism_date DESC, id DESC
             LIMIT 2000'
        )->fetchAll();

        if ($preferredPersonId === null || $preferredPersonId <= 0) return $rows;
        usort($rows, static function (array $left, array $right) use ($preferredPersonId): int {
            return ((int) $right['person_id'] === $preferredPersonId ? 1 : 0)
                <=> ((int) $left['person_id'] === $preferredPersonId ? 1 : 0);
        });
        return $rows;
    }

    function posted_baptismal_record_id(PDO $pdo, string $key = 'baptismal_record_id'): ?int
    {
        $raw = trim((string) ($_POST[$key] ?? ''));
        if ($raw === '') return null;
        $id = (int) $raw;
        if ($id <= 0) throw new InvalidArgumentException('Choose a valid Baptismal record.');
        $stmt = $pdo->prepare('SELECT id FROM baptismal_records WHERE id = ? AND is_archived = 0');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('The selected Baptismal record is unavailable.');
        return $id;
    }
}
