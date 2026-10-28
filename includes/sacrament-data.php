<?php
/**
 * includes/sacrament-data.php
 *
 * ROLE: The ONLY place that holds SQL for `persons` and `sacrament_records`
 * (v2 schema, see database/v2/schema.sql and DATA_DICTIONARY.md).
 * Pages call these functions. Pages must not write their own SQL for these tables.
 *
 * USAGE:  require_once __DIR__ . '/sacrament-data.php';
 *         $people = sd_search_persons($pdo, 'dela Cruz');
 * Every function takes the shared PDO connection as its first argument.
 *
 * RULES BUILT IN
 *  - `sacrament_records.legitimacy` is internal. It is never selected here and never written.
 *  - Unknown values are NULL. Empty strings are stored as NULL.
 *  - Prerequisite problems are returned as warning strings. They never block saving.
 *  - Editing a record clears its book verification, so the entry must be checked again.
 */

if (!defined('SD_TYPES')) {
    define('SD_TYPES', ['baptism', 'first_communion', 'confirmation', 'marriage']);
    define('SD_ROLES', ['godfather', 'godmother', 'sponsor', 'principal_sponsor', 'witness']);
    define('SD_SOURCES', ['this_parish', 'other_parish']);
    // Explicit column list on purpose: `legitimacy` is NOT in it.
    define('SD_RECORD_COLUMNS',
        'r.id, r.sacrament_type, r.person_id, r.spouse_person_id, r.source, r.event_date, r.place, '
        . 'r.minister_name, r.entry_no, r.book_no, r.page_no, r.line_no, r.parents_union_type, '
        . 'r.details, r.remarks, r.book_verified_by, r.book_verified_at, r.encoded_by, r.created_at, r.updated_at');
}

/** Trim a value; empty string becomes NULL. */
function sd_clean($value)
{
    if ($value === null) {
        return null;
    }
    if (is_string($value)) {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
    return $value;
}

/** Readable label for a sacrament type. */
function sd_type_label(string $type): string
{
    $labels = [
        'baptism' => 'Baptism',
        'first_communion' => 'First Communion',
        'confirmation' => 'Confirmation',
        'marriage' => 'Marriage',
    ];
    return $labels[$type] ?? $type;
}

/** "First Middle Last Suffix" from a persons row. */
function sd_full_name(array $person): string
{
    $parts = [];
    foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $key) {
        $v = trim((string) ($person[$key] ?? ''));
        if ($v !== '') {
            $parts[] = $v;
        }
    }
    return implode(' ', $parts);
}

/** A record row is printable only if it is from this parish and checked against the physical register. */
function sd_can_print_certificate(array $record): bool
{
    return ($record['source'] ?? '') === 'this_parish' && !empty($record['book_verified_at']);
}

/** Decode the JSON `details` column into an array (empty array when NULL or invalid). */
function sd_decode_record(array $row): array
{
    $decoded = null;
    if (isset($row['details']) && is_string($row['details']) && $row['details'] !== '') {
        $decoded = json_decode($row['details'], true);
    }
    $row['details'] = is_array($decoded) ? $decoded : [];
    return $row;
}

/**
 * Search persons. The query is split into words. Every word must match.
 *   word like 1985-02-14  -> exact birth date
 *   word like 1985        -> birth year
 *   any other word        -> part of first, middle or last name
 * Each result row has `full_name` plus has_baptism / has_first_communion / has_confirmation / has_marriage (0 or 1).
 */
function sd_search_persons(PDO $pdo, string $query, int $limit = 20): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $tokens = array_slice(preg_split('/[\s,]+/', $query, -1, PREG_SPLIT_NO_EMPTY), 0, 5);
    $where = [];
    $params = [];
    foreach ($tokens as $tok) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tok)) {
            $where[] = 'p.birth_date = ?';
            $params[] = $tok;
        } elseif (preg_match('/^\d{4}$/', $tok)) {
            $where[] = 'YEAR(p.birth_date) = ?';
            $params[] = (int) $tok;
        } else {
            $like = '%' . addcslashes($tok, '%_\\') . '%';
            $where[] = '(p.first_name LIKE ? OR p.middle_name LIKE ? OR p.last_name LIKE ?)';
            array_push($params, $like, $like, $like);
        }
    }
    $limit = max(1, min(100, $limit));
    $has = function (string $type): string {
        if ($type === 'marriage') {
            return "EXISTS (SELECT 1 FROM sacrament_records s WHERE s.sacrament_type = 'marriage' "
                . "AND (s.person_id = p.id OR s.spouse_person_id = p.id))";
        }
        return "EXISTS (SELECT 1 FROM sacrament_records s WHERE s.sacrament_type = '$type' AND s.person_id = p.id)";
    };
    $sql = 'SELECT p.*, '
        . $has('baptism') . ' AS has_baptism, '
        . $has('first_communion') . ' AS has_first_communion, '
        . $has('confirmation') . ' AS has_confirmation, '
        . $has('marriage') . ' AS has_marriage '
        . 'FROM persons p WHERE ' . implode(' AND ', $where)
        . ' ORDER BY p.last_name, p.first_name, p.birth_date LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['full_name'] = sd_full_name($row);
    }
    return $rows;
}

/** One persons row (with `full_name`), or null. */
function sd_get_person(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM persons WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['full_name'] = sd_full_name($row);
    return $row;
}

/** Participants (godparents, sponsors, witnesses) of one record, in display order. */
function sd_get_participants(PDO $pdo, int $recordId): array
{
    $stmt = $pdo->prepare('SELECT id, record_id, role, full_name, residence, sort_order '
        . 'FROM sacrament_participants WHERE record_id = ? ORDER BY sort_order, id');
    $stmt->execute([$recordId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** One sacrament record with decoded details, participants, person_name and spouse_name. Null if missing. */
function sd_get_record(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT ' . SD_RECORD_COLUMNS . ' FROM sacrament_records r WHERE r.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row = sd_decode_record($row);
    $row['participants'] = sd_get_participants($pdo, (int) $row['id']);
    $person = sd_get_person($pdo, (int) $row['person_id']);
    $row['person_name'] = $person['full_name'] ?? null;
    $spouse = $row['spouse_person_id'] ? sd_get_person($pdo, (int) $row['spouse_person_id']) : null;
    $row['spouse_name'] = $spouse['full_name'] ?? null;
    return $row;
}

/**
 * The four-card timeline for one person.
 * Returns ['baptism' => row|null, 'first_communion' => row|null, 'confirmation' => row|null, 'marriages' => [rows]].
 * A marriage row also has `partner_id` and `partner_name` (the other spouse).
 * Marriages are found through person_id (groom) OR spouse_person_id (bride).
 */
function sd_get_person_records(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare('SELECT ' . SD_RECORD_COLUMNS . ' FROM sacrament_records r '
        . 'WHERE r.person_id = ? OR r.spouse_person_id = ? ORDER BY r.event_date, r.id');
    $stmt->execute([$personId, $personId]);
    $result = ['baptism' => null, 'first_communion' => null, 'confirmation' => null, 'marriages' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = sd_decode_record($row);
        if ($row['sacrament_type'] === 'marriage') {
            $partnerId = ((int) $row['person_id'] === $personId) ? $row['spouse_person_id'] : $row['person_id'];
            $partner = $partnerId ? sd_get_person($pdo, (int) $partnerId) : null;
            $row['partner_id'] = $partnerId ? (int) $partnerId : null;
            $row['partner_name'] = $partner['full_name'] ?? null;
            $result['marriages'][] = $row;
        } elseif ($result[$row['sacrament_type']] === null) {
            $result[$row['sacrament_type']] = $row;
        }
    }
    return $result;
}

/** Linked family: ['father' => person|null, 'mother' => person|null, 'children' => [persons]]. */
function sd_get_family(PDO $pdo, int $personId): array
{
    $person = sd_get_person($pdo, $personId);
    $family = ['father' => null, 'mother' => null, 'children' => []];
    if (!$person) {
        return $family;
    }
    if (!empty($person['father_person_id'])) {
        $family['father'] = sd_get_person($pdo, (int) $person['father_person_id']);
    }
    if (!empty($person['mother_person_id'])) {
        $family['mother'] = sd_get_person($pdo, (int) $person['mother_person_id']);
    }
    $stmt = $pdo->prepare('SELECT * FROM persons WHERE father_person_id = ? OR mother_person_id = ? '
        . 'ORDER BY birth_date, id');
    $stmt->execute([$personId, $personId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $child) {
        $child['full_name'] = sd_full_name($child);
        $family['children'][] = $child;
    }
    return $family;
}

/**
 * Prerequisite WARNINGS for recording $type for one person. Never blocks saving.
 * For a marriage call this once for each spouse.
 * Returns a list of plain-language strings (empty list = nothing to flag).
 */
function sd_prerequisite_warnings(PDO $pdo, int $personId, string $type): array
{
    $person = sd_get_person($pdo, $personId);
    if (!$person) {
        return ['Person not found.'];
    }
    $recs = sd_get_person_records($pdo, $personId);
    $name = $person['full_name'];
    $baptized = $recs['baptism'] !== null || ($person['baptismal_status'] ?? '') === 'baptized_catholic';
    $warnings = [];
    if ($type === 'baptism') {
        if ($recs['baptism'] !== null) {
            $warnings[] = "A baptism is already recorded for $name.";
        }
    } elseif ($type === 'first_communion') {
        if (!$baptized) {
            $warnings[] = "No baptism on record for $name. Check the origin baptismal certificate.";
        }
        if ($recs['first_communion'] !== null) {
            $warnings[] = "A First Communion is already recorded for $name.";
        }
    } elseif ($type === 'confirmation') {
        if (!$baptized) {
            $warnings[] = "No baptism on record for $name. Check the origin baptismal certificate.";
        }
        if ($recs['first_communion'] === null) {
            $warnings[] = "No First Communion on record for $name.";
        }
        if ($recs['confirmation'] !== null) {
            $warnings[] = "A Confirmation is already recorded for $name.";
        }
    } elseif ($type === 'marriage') {
        if (!$baptized) {
            $warnings[] = "No baptism on record for $name. Marriage requires a baptized Catholic.";
        }
    }
    return $warnings;
}

/**
 * Create or update a persons row (identity only).
 * Pass $id to update (only the keys present in $data change). Returns the person id.
 * Throws InvalidArgumentException on bad input.
 */
function sd_save_person(PDO $pdo, array $data, ?int $id = null): int
{
    $allowed = ['first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'birth_date', 'birth_place',
        'father_name', 'mother_maiden_name', 'father_person_id', 'mother_person_id', 'address',
        'contact_number', 'email', 'baptismal_status', 'is_deceased', 'death_date'];
    $values = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $values[$key] = sd_clean($data[$key]);
        }
    }
    if ($id === null) {
        if (empty($values['first_name']) || empty($values['last_name'])) {
            throw new InvalidArgumentException('First name and last name are required.');
        }
    } else {
        foreach (['first_name', 'last_name'] as $req) {
            if (array_key_exists($req, $values) && $values[$req] === null) {
                throw new InvalidArgumentException('First name and last name cannot be empty.');
            }
        }
    }
    if (isset($values['sex']) && !in_array($values['sex'], ['male', 'female'], true)) {
        throw new InvalidArgumentException('Sex must be male or female.');
    }
    if (isset($values['baptismal_status'])
        && !in_array($values['baptismal_status'], ['baptized_catholic', 'baptized_non_catholic', 'unbaptized'], true)) {
        throw new InvalidArgumentException('Invalid baptismal status.');
    }
    foreach (['birth_date', 'death_date'] as $dateKey) {
        if (isset($values[$dateKey]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values[$dateKey])) {
            throw new InvalidArgumentException("$dateKey must be YYYY-MM-DD.");
        }
    }
    if (array_key_exists('is_deceased', $values)) {
        $values['is_deceased'] = empty($values['is_deceased']) ? 0 : 1;
    }
    if ($id === null) {
        $cols = array_keys($values);
        $stmt = $pdo->prepare('INSERT INTO persons (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $stmt->execute(array_values($values));
        return (int) $pdo->lastInsertId();
    }
    if (!sd_get_person($pdo, $id)) {
        throw new InvalidArgumentException('Person not found.');
    }
    if ($values) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($values)));
        $stmt = $pdo->prepare("UPDATE persons SET $set WHERE id = ?");
        $stmt->execute(array_merge(array_values($values), [$id]));
    }
    return $id;
}

/**
 * Create or update a sacrament record (+ its participants) in one transaction.
 *
 * $data keys: id (update when present), sacrament_type, person_id, spouse_person_id (marriage only),
 *   source, event_date, place, minister_name, entry_no, book_no, page_no, line_no,
 *   parents_union_type (baptism only), details (array), remarks.
 *   `legitimacy` is ignored on purpose.
 * $participants: list of ['role' =>, 'full_name' =>, 'residence' =>, 'sort_order' =>].
 *   null = leave participants as they are (update). An array replaces all of them.
 * $userId: the secretary, stored as encoded_by on insert.
 * A marriage needs both person_id (groom) and spouse_person_id (bride) to exist in persons.
 * An update clears book_verified_by / book_verified_at: verify the entry again afterwards.
 * Returns the record id. Throws InvalidArgumentException on bad input.
 */
function sd_save_record(PDO $pdo, array $data, ?array $participants = null, ?int $userId = null): int
{
    $id = !empty($data['id']) ? (int) $data['id'] : null;
    $existing = null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT sacrament_type FROM sacrament_records WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new InvalidArgumentException('Record not found.');
        }
    }
    $type = sd_clean($data['sacrament_type'] ?? ($existing['sacrament_type'] ?? null));
    if (!in_array($type, SD_TYPES, true)) {
        throw new InvalidArgumentException('Invalid sacrament type.');
    }
    if ($existing && $existing['sacrament_type'] !== $type) {
        throw new InvalidArgumentException('The sacrament type of a record cannot be changed.');
    }
    $personId = (int) ($data['person_id'] ?? 0);
    if ($personId <= 0 || !sd_get_person($pdo, $personId)) {
        throw new InvalidArgumentException('A valid person is required.');
    }
    $spouseId = sd_clean($data['spouse_person_id'] ?? null);
    if ($type === 'marriage') {
        if ($spouseId === null || !sd_get_person($pdo, (int) $spouseId)) {
            throw new InvalidArgumentException('A marriage needs both spouses to have a profile.');
        }
        if ((int) $spouseId === $personId) {
            throw new InvalidArgumentException('The two spouses must be different people.');
        }
        $spouseId = (int) $spouseId;
    } else {
        $spouseId = null;
    }
    $eventDate = sd_clean($data['event_date'] ?? null);
    if ($eventDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        throw new InvalidArgumentException('Event date is required (YYYY-MM-DD).');
    }
    $source = sd_clean($data['source'] ?? 'this_parish') ?? 'this_parish';
    if (!in_array($source, SD_SOURCES, true)) {
        throw new InvalidArgumentException('Invalid source.');
    }
    $union = sd_clean($data['parents_union_type'] ?? null);
    if ($type !== 'baptism') {
        $union = null;
    } elseif ($union !== null && !in_array($union, ['legitimate', 'civil', 'natural'], true)) {
        throw new InvalidArgumentException('Invalid parents union type.');
    }
    $details = $data['details'] ?? null;
    if (is_string($details)) {
        $details = trim($details) === '' ? null : json_decode($details, true);
        if ($details === null && trim((string) ($data['details'] ?? '')) !== '') {
            throw new InvalidArgumentException('Details must be valid JSON.');
        }
    }
    $detailsJson = (is_array($details) && $details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;

    $clean = [];
    foreach ($participants ?? [] as $p) {
        $name = sd_clean($p['full_name'] ?? null);
        if ($name === null) {
            continue;
        }
        $role = $p['role'] ?? '';
        if (!in_array($role, SD_ROLES, true)) {
            throw new InvalidArgumentException('Invalid participant role.');
        }
        $clean[] = [$role, $name, sd_clean($p['residence'] ?? null), count($clean) + 1];
    }

    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        if ($id === null) {
            $stmt = $pdo->prepare('INSERT INTO sacrament_records (sacrament_type, person_id, spouse_person_id, source, '
                . 'event_date, place, minister_name, entry_no, book_no, page_no, line_no, parents_union_type, '
                . 'details, remarks, encoded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$type, $personId, $spouseId, $source, $eventDate, sd_clean($data['place'] ?? null),
                sd_clean($data['minister_name'] ?? null), sd_clean($data['entry_no'] ?? null),
                sd_clean($data['book_no'] ?? null), sd_clean($data['page_no'] ?? null),
                sd_clean($data['line_no'] ?? null), $union, $detailsJson, sd_clean($data['remarks'] ?? null), $userId]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare('UPDATE sacrament_records SET person_id = ?, spouse_person_id = ?, source = ?, '
                . 'event_date = ?, place = ?, minister_name = ?, entry_no = ?, book_no = ?, page_no = ?, line_no = ?, '
                . 'parents_union_type = ?, details = ?, remarks = ?, book_verified_by = NULL, book_verified_at = NULL '
                . 'WHERE id = ?');
            $stmt->execute([$personId, $spouseId, $source, $eventDate, sd_clean($data['place'] ?? null),
                sd_clean($data['minister_name'] ?? null), sd_clean($data['entry_no'] ?? null),
                sd_clean($data['book_no'] ?? null), sd_clean($data['page_no'] ?? null),
                sd_clean($data['line_no'] ?? null), $union, $detailsJson, sd_clean($data['remarks'] ?? null), $id]);
        }
        if ($participants !== null) {
            $pdo->prepare('DELETE FROM sacrament_participants WHERE record_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order) '
                . 'VALUES (?,?,?,?,?)');
            foreach ($clean as $row) {
                $ins->execute([$id, $row[0], $row[1], $row[2], $row[3]]);
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $id;
}

/** Mark a record as checked against the physical register. Throws if the record does not exist. */
function sd_verify_book_entry(PDO $pdo, int $recordId, int $userId): void
{
    $stmt = $pdo->prepare('SELECT id FROM sacrament_records WHERE id = ?');
    $stmt->execute([$recordId]);
    if (!$stmt->fetch()) {
        throw new InvalidArgumentException('Record not found.');
    }
    $pdo->prepare('UPDATE sacrament_records SET book_verified_by = ?, book_verified_at = NOW() WHERE id = ?')
        ->execute([$userId, $recordId]);
}

/**
 * Number of sacrament records per type, for the dashboard counts.
 * Returns ['baptism' => n, 'first_communion' => n, 'confirmation' => n, 'marriage' => n].
 */
function sd_count_records_by_type(PDO $pdo): array
{
    $counts = array_fill_keys(SD_TYPES, 0);
    $stmt = $pdo->query('SELECT sacrament_type, COUNT(*) AS n FROM sacrament_records GROUP BY sacrament_type');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['sacrament_type']] = (int) $row['n'];
    }
    return $counts;
}

/**
 * Suggest the next logbook numbers for a sacrament type, from the newest this_parish row of that type.
 * Entry no. keeps its prefix and padding (1950-003 -> 1950-004). Line runs 1..10 per page,
 * page 1..500 per book, book in Roman numerals. A row that does not fit the scheme restarts at I / 1 / 1.
 * Suggestion only: the form fields stay editable.
 * Returns ['entry_no' => string, 'book_no' => string, 'page_no' => string, 'line_no' => string].
 */
function sd_next_logbook_numbers(PDO $pdo, string $type): array
{
    if (!in_array($type, SD_TYPES, true)) {
        throw new InvalidArgumentException('Invalid sacrament type.');
    }
    $fresh = ['entry_no' => '', 'book_no' => 'I', 'page_no' => '1', 'line_no' => '1'];
    $stmt = $pdo->prepare("SELECT entry_no, book_no, page_no, line_no FROM sacrament_records "
        . "WHERE sacrament_type = ? AND source = 'this_parish' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $fresh;
    }
    $entryNo = '';
    $entryRaw = trim((string) ($row['entry_no'] ?? ''));
    if ($entryRaw !== '' && preg_match('/^(.*?)(\d+)$/', $entryRaw, $m)) {
        $entryNo = $m[1] . str_pad((string) ((int) $m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT);
    }
    $fresh['entry_no'] = $entryNo;

    $bookRaw = strtoupper(trim((string) ($row['book_no'] ?? '')));
    $pageRaw = trim((string) ($row['page_no'] ?? ''));
    $lineRaw = trim((string) ($row['line_no'] ?? ''));
    $book = null;
    if (ctype_digit($bookRaw)) {
        $book = (int) $bookRaw;
    } elseif ($bookRaw !== '' && preg_match('/^[IVXLCDM]+$/', $bookRaw)) {
        $vals = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
        $book = 0;
        for ($i = 0, $n = strlen($bookRaw); $i < $n; $i++) {
            $v = $vals[$bookRaw[$i]];
            $next = $i + 1 < $n ? $vals[$bookRaw[$i + 1]] : 0;
            $book += ($v < $next) ? -$v : $v;
        }
    }
    if ($book === null || $book < 1 || !ctype_digit($pageRaw) || !ctype_digit($lineRaw)
        || (int) $pageRaw < 1 || (int) $lineRaw < 1) {
        return $fresh;
    }
    $page = (int) $pageRaw;
    $line = (int) $lineRaw + 1;
    if ($line > 10) {
        $line = 1;
        $page++;
    }
    if ($page > 500) {
        $page = 1;
        $book++;
    }
    $roman = '';
    $rem = $book;
    foreach ([1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L',
        40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'] as $val => $glyph) {
        while ($rem >= $val) {
            $roman .= $glyph;
            $rem -= $val;
        }
    }
    return ['entry_no' => $entryNo, 'book_no' => $roman, 'page_no' => (string) $page, 'line_no' => (string) $line];
}

function sd_certificate_source(PDO $pdo, int $recordId): ?array
{
    $record = sd_get_record($pdo, $recordId);
    if (!$record) {
        return null;
    }

    $person = sd_get_person($pdo, (int) ($record['person_id'] ?? 0));
    $spouse = !empty($record['spouse_person_id'])
        ? sd_get_person($pdo, (int) $record['spouse_person_id'])
        : null;

    switch ($record['sacrament_type'] ?? '') {
        case 'baptism':
            $record['child_full_name'] = $person ? sd_full_name($person) : null;
            $record['baptism_date'] = $record['event_date'] ?? null;
            break;
        case 'first_communion':
            $record['communicant_full_name'] = $person ? sd_full_name($person) : null;
            $record['communion_date'] = $record['event_date'] ?? null;
            break;
        case 'confirmation':
            $record['confirmand_full_name'] = $person ? sd_full_name($person) : null;
            $record['confirmation_date'] = $record['event_date'] ?? null;
            break;
        case 'marriage':
            $record['husband_full_name'] = $person ? sd_full_name($person) : null;
            $record['wife_maiden_name'] = $spouse ? $spouse['last_name'] : null;
            $record['marriage_date'] = $record['event_date'] ?? null;
            break;
    }

    $record['sponsors'] = $record['participants'] ?? [];

    // Never expose legitimacy
    unset($record['legitimacy']);

    return $record;
}
