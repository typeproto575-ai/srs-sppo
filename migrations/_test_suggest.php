<?php
// Smoke test: application->register prefill queries + person-profile sections.
require_once __DIR__ . '/../config/database.php';

$ok = true;
function check(string $label, callable $fn) {
    global $ok;
    try { $n = $fn(); echo "[PASS] $label" . ($n !== null ? " -> $n" : '') . PHP_EOL; }
    catch (Throwable $e) { $ok = false; echo "[FAIL] $label -> " . $e->getMessage() . PHP_EOL; }
}

// 1. Baptismal prefill query (any baptismal application; approved preferred)
check('baptismal prefill query', function () use ($pdo) {
    $s = $pdo->query("SELECT sa.id, ba.child_full_name FROM sacrament_applications sa JOIN baptismal_applications ba ON ba.application_id = sa.id WHERE sa.sacrament_type = 'baptismal' ORDER BY (sa.status IN ('approved','scheduled')) DESC LIMIT 1");
    $r = $s->fetch();
    return $r ? 'app #' . $r['id'] . ' (' . $r['child_full_name'] . ')' : 'no applications yet';
});

// 2. Marriage prefill query
check('marriage prefill query', function () use ($pdo) {
    $s = $pdo->query("SELECT sa.id, ma.groom_full_name, ma.bride_full_name FROM sacrament_applications sa JOIN marriage_applications ma ON ma.application_id = sa.id WHERE sa.sacrament_type = 'marriage' ORDER BY (sa.status IN ('approved','scheduled')) DESC LIMIT 1");
    $r = $s->fetch();
    return $r ? 'app #' . $r['id'] . ' (' . $r['groom_full_name'] . ' & ' . $r['bride_full_name'] . ')' : 'no applications yet';
});

// 3. Godparents query for a baptismal application
check('godparents query', function () use ($pdo) {
    $id = (int) $pdo->query("SELECT id FROM baptismal_applications LIMIT 1")->fetchColumn() ?: 0;
    if (!$id) return 'no baptismal apps';
    $s = $pdo->prepare('SELECT COUNT(*) FROM baptismal_application_godparents WHERE baptismal_application_id = ?');
    $s->execute([$id]);
    return (int) $s->fetchColumn() . ' godparents on app ' . $id;
});

// 4. Person profile: all six section selects
check('person profile sections', function () use ($pdo) {
    $pid = (int) $pdo->query('SELECT id FROM persons ORDER BY id LIMIT 1')->fetchColumn() ?: 0;
    if (!$pid) return 'no persons';
    $sections = [
        ['baptismal_records', 'person_id', 'child_full_name', 'baptism_date'],
        ['confirmation_records', 'person_id', 'confirmand_full_name', 'confirmation_date'],
        ['first_communion_records', 'person_id', 'communicant_full_name', 'communion_date'],
        ['marriage_records', 'husband_person_id', 'husband_full_name', 'marriage_date'],
        ['marriage_records', 'wife_person_id', 'wife_maiden_name', 'marriage_date'],
        ['death_burial_records', 'person_id', 'deceased_full_name', 'date_of_death'],
    ];
    $total = 0;
    foreach ($sections as [$t, $c, $n, $d]) {
        $s = $pdo->prepare("SELECT id, {$n} AS display_name, {$d} AS event_date FROM {$t} WHERE {$c} = ?");
        $s->execute([$pid]);
        $total += count($s->fetchAll());
    }
    return "person $pid has $total linked rows";
});

echo $ok ? 'ALL PASS' : 'SOME FAILED', PHP_EOL;
exit($ok ? 0 : 1);
