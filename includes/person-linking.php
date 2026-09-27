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

    /**
     * Section label => bridge table/column for the sacraments that link one
     * person, plus the two marriage roles. Shared by the person profile page,
     * the persons directory and the delete guard, so these table and column
     * names live in exactly one place instead of a copy per page.
     */
    function person_bridge_sections(): array
    {
        return [
            'Baptismal'       => ['type' => 'baptismal',       'table' => 'baptismal_records',       'column' => 'person_id', 'name' => 'child_full_name',       'date' => 'baptism_date'],
            'Confirmation'    => ['type' => 'confirmation',    'table' => 'confirmation_records',    'column' => 'person_id', 'name' => 'confirmand_full_name',  'date' => 'confirmation_date'],
            'First Communion' => ['type' => 'first_communion', 'table' => 'first_communion_records', 'column' => 'person_id', 'name' => 'communicant_full_name', 'date' => 'communion_date'],
        ];
    }

    function person_bridge_marriage_roles(): array
    {
        return [
            'As husband' => ['type' => 'marriage', 'table' => 'marriage_records', 'column' => 'husband_person_id', 'name' => 'wife_maiden_name', 'date' => 'marriage_date'],
            'As wife'    => ['type' => 'marriage', 'table' => 'marriage_records', 'column' => 'wife_person_id',    'name' => 'husband_full_name', 'date' => 'marriage_date'],
        ];
    }

    // Deletes a person only if they have zero linked rows across every bridge
    // table. Recomputes the count itself rather than trusting a caller-
    // supplied number, since that number could be stale (e.g. a link was
    // added in another tab after the caller's page loaded).
    //
    // Reads the person row itself for two reasons: it proves the row still
    // exists before anything is deleted, and it lets this one function decide
    // what the audit entry calls the person — rather than every caller passing
    // its own label, which is how two entry points end up wording the same
    // deletion differently.
    function person_delete_if_unlinked(PDO $pdo, int $personId, int $userId): array
    {
        $p = $pdo->prepare('SELECT * FROM persons WHERE id = ?');
        $p->execute([$personId]);
        $person = $p->fetch();
        if (!$person) {
            return ['ok' => false, 'error' => 'That person no longer exists — it may already have been deleted.'];
        }

        $allSections = array_merge(person_bridge_sections(), person_bridge_marriage_roles());
        $total = 0;
        foreach ($allSections as $sec) {
            $c = $pdo->prepare("SELECT COUNT(*) FROM {$sec['table']} WHERE {$sec['column']} = ?");
            $c->execute([$personId]);
            $total += (int) $c->fetchColumn();
        }
        if ($total > 0) {
            return ['ok' => false, 'error' => "This person still has {$total} linked record" . ($total === 1 ? '' : 's') . ". Remove every link before deleting."];
        }

        // person_identity_label() rather than the shorter name-only form some
        // pages use: it carries the birth date, which is what actually tells
        // two people with the same name apart when reading the audit log.
        $label = person_identity_label($person);
        $del = $pdo->prepare('DELETE FROM persons WHERE id = ?');
        $del->execute([$personId]);
        // Still checked: the row could have gone between the read above and
        // this DELETE. Without it the audit log would record a deletion that
        // never actually happened.
        if ($del->rowCount() < 1) {
            return ['ok' => false, 'error' => 'That person no longer exists — it may already have been deleted.'];
        }
        log_activity($pdo, $userId, 'delete_person', 'persons', $personId,
            "Deleted person {$label} (#{$personId}) — no linked records remained.");
        return ['ok' => true, 'error' => null];
    }
}
