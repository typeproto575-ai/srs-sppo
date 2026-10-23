<?php
/**
 * ============================================================================
 * SRS-SPPO — PUBLIC RECORD LOOKUP (certificate request flow)
 * ============================================================================
 *
 * FILE: includes/record-lookup.php
 *
 * PURPOSE:
 *   Lets a parishioner find out, BEFORE filling in contact details, whether
 *   the parish register entry they want is already in the system. The public
 *   certificate request page calls find_baptismal_record_matches() with the
 *   five identity details (first name, last name, birthdate, father's name,
 *   mother's maiden name) and gets back the matching register rows, if any.
 *
 * WHAT A MATCH MEANS:
 *   A match is a CLAIM, not an approval. The request is still reviewed by the
 *   parish secretary, checked against the physical register, and released
 *   only at pickup with a valid ID. A "no match" is NOT proof that the person
 *   was never baptized here -- older register entries may not be encoded yet --
 *   so the page offers a manual-search fallback instead of a dead end.
 *
 * PRIVACY / ANTI-GUESSING RULES (keep these when editing):
 *   - Birthdate must match EXACTLY, and every other supplied detail must match
 *     too. There is no partial or "closest" result.
 *   - Callers must show the same generic "not found" message whichever field
 *     was wrong, and must never echo record fields the visitor did not
 *     already supply beyond the recorded name and the baptism date.
 *   - Lookups are rate limited per IP (see record_search_allowed()).
 *
 * HOW NAMES ARE COMPARED:
 *   Case, accents (Ñ -> n), punctuation and suffixes (Jr., III) are ignored.
 *   Middle names are ignored for parents. See the record_lookup_* helpers.
 *
 * @package     SRS-SPPO
 * @subpackage  Includes — Public record lookup
 */

/** Searches allowed per client IP per rolling hour (successful or not). */
define('PUBLIC_RECORD_SEARCHES_PER_IP_PER_HOUR', 10);

/** form_type label used in submission_rate_limits for search attempts. */
define('RECORD_SEARCH_LIMIT_TYPE', 'record_search');

/**
 * Lower-case a name, strip accents/punctuation, and collapse whitespace.
 * "  María-Ángela  DELA Cruz. " -> "maria angela dela cruz"
 */
function record_lookup_normalize(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    // Common Latin accents (kept explicit so it behaves the same on XAMPP
    // and Linux; iconv //TRANSLIT differs between platforms).
    $value = strtr($value, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
        'Ñ' => 'N', 'Ç' => 'C',
    ]);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^a-z0-9\s]+/', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

/**
 * Name -> list of comparable words, with generational suffixes removed
 * ("Santos Jr." and "Santos" are the same surname).
 *
 * @return string[]
 */
function record_lookup_tokens(string $value): array
{
    $normalized = record_lookup_normalize($value);
    if ($normalized === '') {
        return [];
    }
    $skip = ['jr', 'sr', 'ii', 'iii', 'iv'];
    return array_values(array_filter(
        explode(' ', $normalized),
        static function ($word) use ($skip) { return $word !== '' && !in_array($word, $skip, true); }
    ));
}

/**
 * True when every word of the shorter list appears in the longer one.
 * "Maria" vs "Maria Angela" -> true; "Maria" vs "Ana" -> false.
 *
 * @param string[] $a
 * @param string[] $b
 */
function record_lookup_words_overlap(array $a, array $b): bool
{
    if (empty($a) || empty($b)) {
        return false;
    }
    [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
    foreach ($short as $word) {
        if (!in_array($word, $long, true)) {
            return false;
        }
    }
    return true;
}

/**
 * Does the visitor's first + last name match the register entry?
 * Uses the structured first/last columns when the record has them, and the
 * full-name string for older rows that were encoded before those columns.
 */
function record_lookup_person_matches(string $firstIn, string $lastIn, array $record): bool
{
    return record_lookup_name_matches(
        $firstIn,
        $lastIn,
        (string) ($record['child_full_name'] ?? ''),
        (string) ($record['child_first_name'] ?? ''),
        (string) ($record['child_last_name'] ?? '')
    );
}

/**
 * Core of record_lookup_person_matches(), parameterized on the record's
 * name columns so it works for baptismal_records (child_*), confirmation_records
 * (confirmand_*) and first_communion_records (communicant_*) alike.
 */
function record_lookup_name_matches(string $firstIn, string $lastIn, string $recFullName, string $recFirstName, string $recLastName): bool
{
    $inFirst = record_lookup_tokens($firstIn);
    $inLast  = record_lookup_tokens($lastIn);
    if (empty($inFirst) || empty($inLast)) {
        return false;
    }

    $recFirst = record_lookup_tokens($recFirstName);
    $recLast  = record_lookup_tokens($recLastName);
    if (!empty($recFirst) && !empty($recLast)) {
        return $inLast === $recLast && record_lookup_words_overlap($inFirst, $recFirst);
    }

    // Legacy row: only the full name string is available. The surname must be
    // the tail of it and the given name must be somewhere before that.
    $full = record_lookup_tokens($recFullName);
    $n = count($inLast);
    if (count($full) <= $n || array_slice($full, -$n) !== $inLast) {
        return false;
    }
    return record_lookup_words_overlap($inFirst, array_slice($full, 0, count($full) - $n));
}

/**
 * Does a typed parent name match any of the recorded spellings?
 * Needs at least a first and last word from the visitor. Middle names and
 * initials are ignored: "Ramon Reyes" matches "Ramon A. Santos Reyes".
 * A register entry that holds only a surname matches when that surname
 * appears in what the visitor typed.
 *
 * @param string[] $candidates Recorded spellings (any may be empty)
 */
function record_lookup_parent_matches(string $input, array $candidates): bool
{
    $in = record_lookup_tokens($input);
    if (count($in) < 2) {
        return false;
    }
    foreach ($candidates as $candidate) {
        $rec = record_lookup_tokens((string) $candidate);
        if (empty($rec)) {
            continue;
        }
        if (count($rec) === 1) {
            if (in_array($rec[0], $in, true)) {
                return true;
            }
            continue;
        }
        if ($in[0] === $rec[0] && end($in) === end($rec)) {
            return true;
        }
    }
    return false;
}

/**
 * Find baptismal register entries that match what the visitor typed.
 *
 * Required in $input: first_name, last_name, birthdate (YYYY-MM-DD),
 * father_name, mother_name. The father's name is checked when the register
 * lists one; if it is absent from the source register, matching uses the
 * remaining required identity details.
 *
 * @return array[] Up to 5 rows: id, child_full_name, baptism_date
 */
function find_baptismal_record_matches(PDO $pdo, array $input): array
{
    $birthdate = (string) ($input['birthdate'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$dt || $dt->format('Y-m-d') !== $birthdate) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, child_full_name, child_first_name, child_last_name, baptism_date,
                father_name, mother_name, mother_maiden_name
           FROM baptismal_records
          WHERE birth_date = ?
          ORDER BY baptism_date ASC, id ASC
          LIMIT 50'
    );
    $stmt->execute([$birthdate]);

    $matches = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!record_lookup_person_matches((string) $input['first_name'], (string) $input['last_name'], $row)) {
            continue;
        }
        // Mother is always required. The register form labels the mother's
        // column "Mother's Maiden Name"; the separate maiden-name column is
        // optional, so accept either spelling.
        if (!record_lookup_parent_matches((string) ($input['mother_name'] ?? ''), [$row['mother_name'], $row['mother_maiden_name']])) {
            continue;
        }
        $recordedFather = trim((string) ($row['father_name'] ?? ''));
        if ($recordedFather !== '' && !record_lookup_parent_matches((string) ($input['father_name'] ?? ''), [$recordedFather])) {
            continue;
        }
        $matches[] = [
            'id'              => (int) $row['id'],
            'child_full_name' => (string) $row['child_full_name'],
            'baptism_date'    => $row['baptism_date'],
        ];
        if (count($matches) >= 5) {
            break;
        }
    }
    return $matches;
}

/**
 * Load one register entry by id for the request the visitor is submitting.
 * Only the fields the request needs are returned.
 */
function load_baptismal_record_for_request(PDO $pdo, int $recordId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, child_full_name, birth_date, baptism_date, father_name,
                mother_name, mother_maiden_name, minister_name
           FROM baptismal_records WHERE id = ?'
    );
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Rolling-hour cap on lookups per client IP. Fails open (allows) if the
 * limits table is missing, matching public_submission_allowed().
 *
 * @return array{ok: bool, reason: ?string}
 */
function record_search_allowed(PDO $pdo, ?string $ip = null): array
{
    $ip = $ip ?? client_ip();
    if ($ip === '' || $ip === 'unknown') {
        return ['ok' => true, 'reason' => null];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM submission_rate_limits
              WHERE ip_address = ? AND form_type = ? AND submitted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $stmt->execute([$ip, RECORD_SEARCH_LIMIT_TYPE]);
        if ((int) $stmt->fetchColumn() >= PUBLIC_RECORD_SEARCHES_PER_IP_PER_HOUR) {
            return [
                'ok'     => false,
                'reason' => 'Too many searches from this network. Please try again in an hour, or visit the parish office.',
            ];
        }
    } catch (Throwable $e) {
        return ['ok' => true, 'reason' => null];
    }
    return ['ok' => true, 'reason' => null];
}

/** Count one lookup against the hourly cap (email column is unused here). */
function record_search_log(PDO $pdo, ?string $ip = null): void
{
    $ip = $ip ?? client_ip();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO submission_rate_limits (email, ip_address, form_type) VALUES (?, ?, ?)'
        );
        $stmt->execute(['', $ip, RECORD_SEARCH_LIMIT_TYPE]);
    } catch (Throwable $e) {
        error_log('SRS-SPPO: could not record search rate limit: ' . $e->getMessage());
    }
}

/**
 * Find Confirmation register entries matching what the visitor typed.
 * Same required/optional fields and matching rules as
 * find_baptismal_record_matches() -- see that function's docblock.
 *
 * @return array[] Up to 5 rows: id, confirmand_full_name, confirmation_date
 */
function find_confirmation_record_matches(PDO $pdo, array $input): array
{
    $birthdate = (string) ($input['birthdate'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$dt || $dt->format('Y-m-d') !== $birthdate) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, confirmand_full_name, confirmand_first_name, confirmand_last_name,
                confirmation_date, father_name, mother_maiden_name
           FROM confirmation_records
          WHERE birthdate = ?
          ORDER BY confirmation_date ASC, id ASC
          LIMIT 50'
    );
    $stmt->execute([$birthdate]);

    $matches = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!record_lookup_name_matches(
            (string) $input['first_name'], (string) $input['last_name'],
            (string) $row['confirmand_full_name'], (string) $row['confirmand_first_name'], (string) $row['confirmand_last_name']
        )) {
            continue;
        }
        if (!record_lookup_parent_matches((string) ($input['mother_name'] ?? ''), [$row['mother_maiden_name']])) {
            continue;
        }
        $recordedFather = trim((string) ($row['father_name'] ?? ''));
        if ($recordedFather !== '' && !record_lookup_parent_matches((string) ($input['father_name'] ?? ''), [$recordedFather])) {
            continue;
        }
        $matches[] = [
            'id'                   => (int) $row['id'],
            'confirmand_full_name' => (string) $row['confirmand_full_name'],
            'confirmation_date'    => $row['confirmation_date'],
        ];
        if (count($matches) >= 5) {
            break;
        }
    }
    return $matches;
}

/** Load one Confirmation register entry by id for the request being submitted. */
function load_confirmation_record_for_request(PDO $pdo, int $recordId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, confirmand_full_name, birthdate, confirmation_date, father_name,
                mother_maiden_name, sponsor_name, minister_name, baptismal_record_id
           FROM confirmation_records WHERE id = ?'
    );
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Find First Communion register entries matching what the visitor typed.
 * Same required/optional fields and matching rules as
 * find_baptismal_record_matches() -- see that function's docblock.
 *
 * @return array[] Up to 5 rows: id, communicant_full_name, communion_date
 */
function find_first_communion_record_matches(PDO $pdo, array $input): array
{
    $birthdate = (string) ($input['birthdate'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$dt || $dt->format('Y-m-d') !== $birthdate) {
        return [];
    }

    // First Communion records have no birthdate column of their own (the
    // register logs domicile, not birthdate) -- match through the linked
    // baptismal record instead, same as the parish office would.
    $stmt = $pdo->prepare(
        "SELECT fc.id, fc.communicant_full_name, fc.communicant_first_name, fc.communicant_last_name,
                fc.communion_date, fc.father_name, fc.mother_maiden_name
           FROM first_communion_records fc
           JOIN baptismal_records b ON b.id = fc.baptismal_record_id
          WHERE b.birth_date = ?
          ORDER BY fc.communion_date ASC, fc.id ASC
          LIMIT 50"
    );
    $stmt->execute([$birthdate]);

    $matches = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!record_lookup_name_matches(
            (string) $input['first_name'], (string) $input['last_name'],
            (string) $row['communicant_full_name'], (string) $row['communicant_first_name'], (string) $row['communicant_last_name']
        )) {
            continue;
        }
        if (!record_lookup_parent_matches((string) ($input['mother_name'] ?? ''), [$row['mother_maiden_name']])) {
            continue;
        }
        $recordedFather = trim((string) ($row['father_name'] ?? ''));
        if ($recordedFather !== '' && !record_lookup_parent_matches((string) ($input['father_name'] ?? ''), [$recordedFather])) {
            continue;
        }
        $matches[] = [
            'id'                    => (int) $row['id'],
            'communicant_full_name' => (string) $row['communicant_full_name'],
            'communion_date'        => $row['communion_date'],
        ];
        if (count($matches) >= 5) {
            break;
        }
    }
    return $matches;
}

/** Load one First Communion register entry by id for the request being submitted. */
function load_first_communion_record_for_request(PDO $pdo, int $recordId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT fc.id, fc.communicant_full_name, b.birth_date, fc.communion_date, fc.father_name,
                fc.mother_maiden_name, fc.minister_name
           FROM first_communion_records fc
           LEFT JOIN baptismal_records b ON b.id = fc.baptismal_record_id
          WHERE fc.id = ?'
    );
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Find Marriage register entries matching what the visitor typed.
 * Marriage has no parent fields to cross-check like the other sacraments;
 * the pair of full names plus the marriage date is the identity here.
 * Required in $input: husband_first_name, husband_last_name,
 * wife_first_name, wife_last_name (wife's maiden surname), marriage_date.
 *
 * @return array[] Up to 5 rows: id, husband_full_name, wife_maiden_name, marriage_date
 */
function find_marriage_record_matches(PDO $pdo, array $input): array
{
    $marriageDate = (string) ($input['marriage_date'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d', $marriageDate);
    if (!$dt || $dt->format('Y-m-d') !== $marriageDate) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, husband_full_name, husband_first_name, husband_last_name,
                wife_maiden_name, wife_first_name, wife_last_name, marriage_date
           FROM marriage_records
          WHERE marriage_date = ?
          ORDER BY id ASC
          LIMIT 50'
    );
    $stmt->execute([$marriageDate]);

    $matches = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!record_lookup_name_matches(
            (string) $input['husband_first_name'], (string) $input['husband_last_name'],
            (string) $row['husband_full_name'], (string) $row['husband_first_name'], (string) $row['husband_last_name']
        )) {
            continue;
        }
        if (!record_lookup_name_matches(
            (string) $input['wife_first_name'], (string) $input['wife_last_name'],
            (string) $row['wife_maiden_name'], (string) $row['wife_first_name'], (string) $row['wife_last_name']
        )) {
            continue;
        }
        $matches[] = [
            'id'                => (int) $row['id'],
            'husband_full_name' => (string) $row['husband_full_name'],
            'wife_maiden_name'  => (string) $row['wife_maiden_name'],
            'marriage_date'     => $row['marriage_date'],
        ];
        if (count($matches) >= 5) {
            break;
        }
    }
    return $matches;
}

/** Load one Marriage register entry by id for the request being submitted. */
function load_marriage_record_for_request(PDO $pdo, int $recordId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, husband_full_name, wife_maiden_name, marriage_date, officiating_minister
           FROM marriage_records WHERE id = ?'
    );
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
