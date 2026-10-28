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
check('count by type matches the seed', sd_count_records_by_type($pdo) === ['baptism' => 14, 'first_communion' => 9, 'confirmation' => 7, 'marriage' => 3]);

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


// --- logbook numbering
$aId = sd_save_record($pdo, ['sacrament_type' => 'baptism', 'person_id' => 8, 'event_date' => '2026-01-01',
    'entry_no' => 'T-009', 'book_no' => 'I', 'page_no' => '3', 'line_no' => '10']);
$n = sd_next_logbook_numbers($pdo, 'baptism');
check('next logbook: entry +1, line overflows to next page',
    $n === ['entry_no' => 'T-010', 'book_no' => 'I', 'page_no' => '4', 'line_no' => '1']);
sd_save_record($pdo, ['sacrament_type' => 'baptism', 'person_id' => 8, 'event_date' => '2026-01-02',
    'entry_no' => 'T-010', 'book_no' => 'I', 'page_no' => '500', 'line_no' => '10']);
$n = sd_next_logbook_numbers($pdo, 'baptism');
check('next logbook: last page of a book rolls to book II', $n['book_no'] === 'II' && $n['page_no'] === '1' && $n['line_no'] === '1');
sd_save_record($pdo, ['sacrament_type' => 'baptism', 'person_id' => 8, 'event_date' => '2026-01-03',
    'entry_no' => 'old', 'book_no' => 'B-1', 'page_no' => '17', 'line_no' => '9']);
check('next logbook: odd legacy numbering restarts at I/1/1', sd_next_logbook_numbers($pdo, 'baptism')['book_no'] === 'I');

// --- certificate source
$cs18 = sd_certificate_source($pdo, 18);
check('cert source 18 is not null', $cs18 !== null);
$keys18 = array_keys($cs18 ?? []);
echo 'INFO  cert source 18 keys: ' . implode(', ', $keys18) . "\n";
check('cert source 18 has child_full_name', array_key_exists('child_full_name', $cs18 ?? []));
check('cert source 18 has baptism_date', array_key_exists('baptism_date', $cs18 ?? []));
check('cert source 18 child_full_name is Roy Ramos Mendoza', ($cs18['child_full_name'] ?? '') === 'Roy Ramos Mendoza');

$cs17 = sd_certificate_source($pdo, 17);
check('cert source 17 is not null', $cs17 !== null);
echo 'INFO  cert source 17 keys: ' . implode(', ', array_keys($cs17 ?? [])) . "\n";
check('cert source 17 has husband_full_name', array_key_exists('husband_full_name', $cs17 ?? []));
check('cert source 17 has wife_maiden_name', array_key_exists('wife_maiden_name', $cs17 ?? []));
check('cert source 17 wife_maiden_name is birth surname (Ramos)', ($cs17['wife_maiden_name'] ?? '') === 'Ramos');

$cs5 = sd_certificate_source($pdo, 5);
check('cert source 5 is not null', $cs5 !== null);
echo 'INFO  cert source 5 keys: ' . implode(', ', array_keys($cs5 ?? [])) . "\n";
check('cert source 5 has communicant_full_name', array_key_exists('communicant_full_name', $cs5 ?? []));
check('cert source 5 has communion_date', array_key_exists('communion_date', $cs5 ?? []));

$cs32 = sd_certificate_source($pdo, 32);
check('cert source 32 is not null', $cs32 !== null);
echo 'INFO  cert source 32 keys: ' . implode(', ', array_keys($cs32 ?? [])) . "\n";
check('cert source 32 has confirmand_full_name', array_key_exists('confirmand_full_name', $cs32 ?? []));

// All four: no legitimacy key, and 'legitimacy' not in any value
foreach ([18, 17, 5, 32] as $rid) {
    $cs = sd_certificate_source($pdo, $rid);
    check("cert source $rid has no legitimacy key", !array_key_exists('legitimacy', $cs ?? []));
    $allValues = json_encode($cs);
    check("cert source $rid has no 'legitimacy' in any value", strpos($allValues, 'legitimacy') === false);
}

// Missing record returns null
check('cert source 99999 returns null', sd_certificate_source($pdo, 99999) === null);

// Sponsors key present for records 17 and 18
check('cert source 17 has sponsors key', array_key_exists('sponsors', $cs17 ?? []));
check('cert source 18 has sponsors key', array_key_exists('sponsors', $cs18 ?? []));
check('cert source 17 sponsors count is 4', count($cs17['sponsors'] ?? []) === 4);
check('cert source 18 sponsors count is 2', count($cs18['sponsors'] ?? []) === 2);

$pdo->rollBack();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
