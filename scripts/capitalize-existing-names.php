<?php

declare(strict_types=1);

/**
 * One-time clean-up: capitalise names that were saved before names were
 * capitalised automatically ("naruto uzumaki" becomes "Naruto Uzumaki").
 *
 * Mixed-case words ("McDonald") are left alone, so running it twice is safe.
 *
 *   php scripts/capitalize-existing-names.php           shows what would change (nothing is written)
 *   php scripts/capitalize-existing-names.php --apply   writes the changes
 *
 * Back up the database first (phpMyAdmin → Export).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$apply = in_array('--apply', $argv, true);

/** Plain text columns: table => [column => isSuffix]. */
$textColumns = [
    'persons' => ['first_name' => false, 'middle_name' => false, 'last_name' => false, 'suffix' => true, 'father_name' => false, 'mother_maiden_name' => false],
    'sacrament_participants' => ['full_name' => false],
    'sacrament_records' => ['minister_name' => false],
    'sacrament_applications' => ['applicant_name' => false],
    'certificate_requests' => ['subject_name' => false, 'requester_name' => false, 'representative_name' => false],
    'funeral_records' => ['spouse_name' => false, 'parents_name' => false, 'minister_name' => false],
];

/** JSON columns that contain names: table => [column, ...]. */
$jsonColumns = [
    'sacrament_applications' => ['details'],
    'sacrament_records' => ['details'],
    'certificate_requests' => ['subject_details'],
];

$nameListKeys = ['sponsors', 'sponsor_names', 'witnesses', 'godparent_names'];
$skipKeyPattern = '/venue|parish|church|school|place|address|file|email|document|requirement|relationship|status|date|time|channel|type|purpose/i';

$formatJson = static function (mixed $value, string $key = '') use (&$formatJson, $nameListKeys, $skipKeyPattern): mixed {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $childKey = is_string($k) ? $k : $key;
            $out[$k] = $formatJson($v, $childKey);
        }
        return $out;
    }
    if (!is_string($value) || trim($value) === '') {
        return $value;
    }
    $isNameKey = preg_match('/(^|_)(name|full_name)$/i', $key) === 1 && preg_match($skipKeyPattern, $key) !== 1;
    if ($isNameKey || in_array($key, $nameListKeys, true)) {
        return format_name_part($value);
    }
    return $value;
};

$changes = 0;
$samples = [];

$pdo->beginTransaction();
try {
    foreach ($textColumns as $table => $columns) {
        try {
            $rows = $pdo->query('SELECT id, ' . implode(', ', array_map(static fn($c) => "`$c`", array_keys($columns))) . " FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            fwrite(STDOUT, "Skipping $table (not found).\n");
            continue;
        }
        foreach ($rows as $row) {
            $set = [];
            foreach ($columns as $column => $isSuffix) {
                $old = $row[$column];
                if (!is_string($old) || trim($old) === '') {
                    continue;
                }
                $new = format_name_part($old, $isSuffix);
                if ($new !== $old) {
                    $set[$column] = $new;
                    $samples[] = "$table.$column #{$row['id']}: $old  ->  $new";
                }
            }
            if ($set !== []) {
                $changes += count($set);
                if ($apply) {
                    $sql = "UPDATE `$table` SET " . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($set))) . ' WHERE id = ?';
                    $pdo->prepare($sql)->execute([...array_values($set), $row['id']]);
                }
            }
        }
    }

    foreach ($jsonColumns as $table => $columns) {
        foreach ($columns as $column) {
            try {
                $rows = $pdo->query("SELECT id, `$column` AS payload FROM `$table` WHERE `$column` IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                fwrite(STDOUT, "Skipping $table.$column (not found).\n");
                continue;
            }
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row['payload'], true);
                if (!is_array($decoded)) {
                    continue;
                }
                $updated = $formatJson($decoded);
                if ($updated !== $decoded) {
                    $changes++;
                    $samples[] = "$table.$column #{$row['id']}: names in the saved details were capitalised";
                    if ($apply) {
                        $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ?")
                            ->execute([json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['id']]);
                    }
                }
            }
        }
    }

    if ($apply) {
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Stopped, nothing was changed: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach (array_slice($samples, 0, 25) as $line) {
    fwrite(STDOUT, $line . "\n");
}
if (count($samples) > 25) {
    fwrite(STDOUT, '... and ' . (count($samples) - 25) . " more.\n");
}
fwrite(STDOUT, $apply
    ? "Done. $changes value(s) updated.\n"
    : "Dry run: $changes value(s) would change. Run again with --apply to save them.\n");
