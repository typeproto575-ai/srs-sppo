<?php
/** Shared Baptismal prerequisite record finder helpers. */

if (!function_exists('baptismal_record_choices')) {
    function baptismal_record_choices(PDO $pdo, ?int $preferredPersonId = null): array
    {
        $rows = $pdo->query(
            "SELECT r.id,
                    CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) AS child_full_name,
                    p.birth_date, r.event_date AS baptism_date, p.father_name,
                    p.mother_maiden_name AS mother_name, r.book_no, r.page_no, r.line_no, r.person_id
             FROM sacrament_records r
             JOIN persons p ON p.id = r.person_id
             WHERE r.sacrament_type = 'baptism' AND r.source = 'this_parish'
             ORDER BY child_full_name, r.event_date DESC, r.id DESC
             LIMIT 2000"
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
        $stmt = $pdo->prepare(
            "SELECT id FROM sacrament_records
             WHERE id = ? AND sacrament_type = 'baptism' AND source = 'this_parish'"
        );
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('The selected Baptismal record is unavailable.');
        return $id;
    }
}
