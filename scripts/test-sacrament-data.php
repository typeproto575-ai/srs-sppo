<?php
/**
 * scripts/test-sacrament-data.php
 * Run:  php scripts/test-sacrament-data.php
 * Needs the v2 schema and seed_demo.sql loaded. Everything it writes is rolled back at the end.
 */
require_once __DIR__ . '/../config/database.php';   // provides $pdo
require_once __DIR__ . '/../includes/sacrament-data.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name $extra\n"; }
}
function throws(callable $fn): bool {
    try { $fn(); } catch (InvalidArgumentException $e) { return true; }
    return false;
}

$pdo->beginTransaction();

// --- search
check('search "dela Cruz" finds 2 people', count(sd_search_persons($pdo, 'dela Cruz')) === 2);
$r = sd_search_persons($pdo, 'juan 1985-02-14');
check('search by name + birth date finds the right Juan', count($r) === 1 && (int) $r[0]['id'] === 10);
check('search by name + birth year', count(sd_search_persons($pdo, 'juan 1991')) === 1);
check('search with no match is empty', sd_search_persons($pdo, 'zzzz') === []);
$r = sd_search_persons($pdo, 'Beatrice');
check('search flags: Beatrice has baptism + first communion, no confirmation',
    $r && $r[0]['has_baptism'] == 1 && $r[0]['has_first_communion'] == 1 && $r[0]['has_confirmation'] == 0);

// --- profile / records
$rec = sd_get_person_records($pdo, 8);
check('Beatrice timeline: baptism, first communion, no confirmation',
    $rec['baptism'] !== null && $rec['first_communion'] !== null && $rec['confirmation'] === null);
$liza = sd_get_person_records($pdo, 6);
check('Liza (bride) finds her marriage through spouse_person_id',
    count($liza['marriages']) === 1 && $liza['marriages'][0]['partner_name'] === 'Ricardo Villanueva Mendoza');
check('legitimacy is never returned', !array_key_exists('legitimacy', $rec['baptism']));
$other = sd_get_person_records($pdo, 12);
check('Marites baptism is other_parish and not printable', $other['baptism']['source'] === 'other_parish' && !sd_can_print_certificate($other['baptism']));
check('verified this_parish record is printable', sd_can_print_certificate($rec['baptism']));

// --- record detail
$r17 = sd_get_record($pdo, 17);
check('marriage record has 4 participants and both names',
    count($r17['participants']) === 4 && $r17['spouse_name'] === 'Liza Perez Ramos');
check('marriage details decoded', ($r17['details']['groom']['age'] ?? null) === 26);
check('missing record returns null', sd_get_record($pdo, 99999) === null);

// --- family
$fam = sd_get_family($pdo, 7);
check('Roy: father Ricardo, mother Liza', ($fam['father']['id'] ?? 0) == 5 && ($fam['mother']['id'] ?? 0) == 6);
check('Ricardo has 3 children', count(sd_get_family($pdo, 5)['children']) === 3);

// --- warnings
check('Carlo confirmation warns about First Communion', count(sd_prerequisite_warnings($pdo, 9, 'confirmation')) === 1);
check('Roy confirmation duplicate is flagged', count(sd_prerequisite_warnings($pdo, 7, 'confirmation')) === 1);
check('Marites first communion has no baptism warning (other-parish baptism counts)',
    sd_prerequisite_warnings($pdo, 12, 'first_communion') === ['A First Communion is already recorded for Marites Gomez Villareal.']);

// --- save person
$newId = sd_save_person($pdo, ['first_name' => ' Test ', 'last_name' => 'Person', 'birth_date' => '2020-01-02', 'sex' => 'male']);
check('save person creates a row', $newId > 0 && sd_get_person($pdo, $newId)['first_name'] === 'Test');
sd_save_person($pdo, ['birth_place' => 'Poblacion', 'middle_name' => ''], $newId);
$p = sd_get_person($pdo, $newId);
check('update person changes only given keys and blanks become NULL', $p['birth_place'] === 'Poblacion' && $p['middle_name'] === null && $p['first_name'] === 'Test');
check('person without a last name is rejected', throws(fn() => sd_save_person($pdo, ['first_name' => 'X'])));

// --- save record
$id = sd_save_record($pdo, ['sacrament_type' => 'confirmation', 'person_id' => 8, 'event_date' => '2026-10-11',
    'book_no' => '4', 'page_no' => '2', 'line_no' => '3', 'legitimacy' => 'illegitimate', 'parents_union_type' => 'legitimate'],
    [['role' => 'sponsor', 'full_name' => 'Danilo Aguilar'], ['role' => 'sponsor', 'full_name' => '']], 1);
$saved = sd_get_record($pdo, $id);
check('new confirmation saved with 1 participant (blank name skipped)', $saved && count($saved['participants']) === 1);
check('union type ignored for non-baptism', $saved['parents_union_type'] === null);
check('legitimacy never written', $pdo->query("SELECT legitimacy FROM sacrament_records WHERE id = $id")->fetchColumn() === null);
check('new record is not verified', $saved['book_verified_at'] === null);
sd_verify_book_entry($pdo, $id, 1);
check('verify sets the stamp', sd_get_record($pdo, $id)['book_verified_at'] !== null);
sd_save_record($pdo, ['id' => $id, 'sacrament_type' => 'confirmation', 'person_id' => 8, 'event_date' => '2026-10-12']);
$after = sd_get_record($pdo, $id);
check('editing clears verification', $after['book_verified_at'] === null && $after['event_date'] === '2026-10-12');
check('participants untouched when null passed', count($after['participants']) === 1);
sd_save_record($pdo, ['id' => $id, 'person_id' => 8, 'event_date' => '2026-10-12'], []);
check('empty array removes participants', count(sd_get_record($pdo, $id)['participants']) === 0);
check('timeline now shows confirmation', sd_get_person_records($pdo, 8)['confirmation'] !== null);

// --- validation
check('spouse on a baptism is dropped, not stored', (function () use ($pdo) {
    $i = sd_save_record($pdo, ['sacrament_type' => 'baptism', 'person_id' => $GLOBALS['newId'], 'spouse_person_id' => 8, 'event_date' => '2020-02-02']);
    return sd_get_record($pdo, $i)['spouse_person_id'] === null; })());
check('marriage without a spouse rejected', throws(fn() => sd_save_record($pdo, ['sacrament_type' => 'marriage', 'person_id' => 13, 'event_date' => '2027-01-01'])));
check('unknown person rejected', throws(fn() => sd_save_record($pdo, ['sacrament_type' => 'baptism', 'person_id' => 99999, 'event_date' => '2020-01-01'])));
check('bad date rejected', throws(fn() => sd_save_record($pdo, ['sacrament_type' => 'baptism', 'person_id' => 8, 'event_date' => '01/02/2020'])));
check('bad type rejected', throws(fn() => sd_save_record($pdo, ['sacrament_type' => 'burial', 'person_id' => 8, 'event_date' => '2020-01-01'])));
check('bad participant role rejected', throws(fn() => sd_save_record($pdo, ['sacrament_type' => 'baptism', 'person_id' => 8, 'event_date' => '2020-01-01'], [['role' => 'dj', 'full_name' => 'X']])));
check('type cannot be changed on update', throws(fn() => sd_save_record($pdo, ['id' => $id, 'sacrament_type' => 'baptism', 'person_id' => 8, 'event_date' => '2020-01-01'])));

$pdo->rollBack();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
