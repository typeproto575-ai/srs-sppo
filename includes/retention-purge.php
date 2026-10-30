<?php
/**
 * =====================================================================
 * SRS-SPPO RETENTION PURGE — shared dry-run / execute engine
 * =====================================================================
 * Rules:
 *   - Sacramental register entries (persons, *_records, witnesses,
 *     marriage witnesses) are permanent. They are never read, changed or
 *     flagged here; retention_guard() throws if one is ever passed in.
 *     is_archived does not trigger anything.
 *   - Submissions: only FINISHED rows older than
 *     SRS_RETENTION_SUBMISSIONS_MONTHS are anonymized:
 *       certificate_requests   released / rejected / cancelled
 *       sacrament_applications completed / rejected / cancelled
 *       correction_requests    completed / rejected
 *     "approved" and every other status is open and never touched.
 *     Type, channel, status and dates stay for statistics.
 *   - audit_logs older than SRS_RETENTION_AUDIT_MONTHS are SCRUBBED, not
 *     deleted: ip_address / user_agent / reason are cleared and the
 *     description becomes '[scrubbed]'. Action, user, role, target and
 *     timestamp stay.
 *   - email_delivery_log and notifications older than the audit period,
 *     submission_rate_limits older than SRS_RETENTION_RATELIMIT_DAYS and
 *     old expired/used password_reset_tokens are DELETED.
 *   - The dry run and the execute use the SAME selection rules
 *     (retention_targets()), so their counts match.
 *   - Everything runs in ONE transaction and rolls back on any error.
 *     One audit_logs row with counts only is written on success.
 * =====================================================================
 */

const RETENTION_ANON_NAME  = '[anonymized]';
const RETENTION_ANON_EMAIL = 'anonymized@purged.local';
const RETENTION_ANON_DATE  = '1900-01-01';
const RETENTION_SCRUBBED   = '[scrubbed]';

/**
 * Tables that must never be read or changed by the purge.
 */
function retention_protected_tables(): array
{
    return [
        'persons',
        'baptismal_records',
        'confirmation_records',
        'first_communion_records',
        'marriage_records',
        'marriage_witnesses',
        'users',
    ];
}

/**
 * @throws RuntimeException if a protected register table is passed in.
 */
function retention_guard(string $tableName): void
{
    if (in_array(strtolower($tableName), retention_protected_tables(), true)) {
        throw new RuntimeException("Retention purge refused: '{$tableName}' is a protected permanent table.");
    }
}

/**
 * Cutoff datetimes (Y-m-d H:i:s) for each retention bucket.
 */
function retention_cutoffs(): array
{
    $subMonths   = max(1, defined('SRS_RETENTION_SUBMISSIONS_MONTHS') ? (int) SRS_RETENTION_SUBMISSIONS_MONTHS : 60);
    $auditMonths = max(1, defined('SRS_RETENTION_AUDIT_MONTHS') ? (int) SRS_RETENTION_AUDIT_MONTHS : 36);
    $rateDays    = max(1, defined('SRS_RETENTION_RATELIMIT_DAYS') ? (int) SRS_RETENTION_RATELIMIT_DAYS : 30);
    return [
        'submissions'  => date('Y-m-d H:i:s', strtotime("-{$subMonths} months")),
        'audit'        => date('Y-m-d H:i:s', strtotime("-{$auditMonths} months")),
        'ratelimit'    => date('Y-m-d H:i:s', strtotime("-{$rateDays} days")),
        'sub_months'   => $subMonths,
        'audit_months' => $auditMonths,
        'rate_days'    => $rateDays,
    ];
}

/**
 * ONE definition of what each bucket selects. Used by both the dry run
 * and the execute, so the counts always agree.
 * bucket => [table, where (with ? placeholders), params, action]
 */
function retention_targets(): array
{
    $c = retention_cutoffs();
    return [
        'certificate_requests' => [
            'table'  => 'certificate_requests',
            'where'  => "status IN ('released','rejected','cancelled') AND anonymized_at IS NULL"
                      . ' AND COALESCE(date_processed, cancelled_at, date_submitted) < ?',
            'params' => [$c['submissions']],
            'action' => 'anonymize',
        ],
        'sacrament_applications' => [
            'table'  => 'sacrament_applications',
            'where'  => "status IN ('completed','rejected','cancelled') AND anonymized_at IS NULL"
                      . ' AND COALESCE(date_processed, date_submitted) < ?',
            'params' => [$c['submissions']],
            'action' => 'anonymize',
        ],
        'correction_requests' => [
            'table'  => 'correction_requests',
            'where'  => "status IN ('completed','rejected') AND anonymized_at IS NULL AND updated_at < ?",
            'params' => [$c['submissions']],
            'action' => 'anonymize',
        ],
        'audit_logs' => [
            'table'  => 'audit_logs',
            'where'  => "created_at < ? AND (ip_address IS NOT NULL OR user_agent IS NOT NULL"
                      . " OR reason IS NOT NULL OR COALESCE(description, '') <> '" . RETENTION_SCRUBBED . "')",
            'params' => [$c['audit']],
            'action' => 'scrub',
        ],
        'email_delivery_log' => [
            'table'  => 'email_delivery_log',
            'where'  => 'created_at < ?',
            'params' => [$c['audit']],
            'action' => 'delete',
        ],
        'notifications' => [
            'table'  => 'notifications',
            'where'  => 'created_at < ?',
            'params' => [$c['audit']],
            'action' => 'delete',
        ],
        'submission_rate_limits' => [
            'table'  => 'submission_rate_limits',
            'where'  => 'submitted_at < ?',
            'params' => [$c['ratelimit']],
            'action' => 'delete',
        ],
        'password_reset_tokens' => [
            'table'  => 'password_reset_tokens',
            'where'  => '(expires_at < ? OR (used_at IS NOT NULL AND used_at < ?))',
            'params' => [$c['ratelimit'], $c['ratelimit']],
            'action' => 'delete',
        ],
    ];
}

/**
 * Count what a purge would touch, without changing anything.
 * Returns bucket => count pairs.
 */
function retention_dry_run(PDO $pdo): array
{
    $counts = [];
    foreach (retention_targets() as $bucket => $t) {
        retention_guard($t['table']);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $t['table'] . ' WHERE ' . $t['where']);
        $stmt->execute($t['params']);
        $counts[$bucket] = (int) $stmt->fetchColumn();
    }
    return $counts;
}

/**
 * Run one guarded UPDATE/DELETE and return the affected row count.
 */
function retention_exec(PDO $pdo, string $table, string $sql, array $params = []): int
{
    retention_guard($table);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** "?,?,?" placeholders for an id list. */
function retention_in(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

/**
 * Anonymize certificate requests (parent + subject tables + log + notes).
 * Nullable columns become NULL; NOT NULL columns get a fixed sentinel.
 */
function retention_anonymize_certificates(PDO $pdo, array $ids): void
{
    $in = retention_in($ids);

    retention_exec($pdo, 'baptismal_certificate_requests',
        "UPDATE baptismal_certificate_requests SET child_full_name = ?, father_full_name = ?, mother_full_name = ?,"
        . " birthplace = NULL, birthdate = ?, sponsors_text = NULL WHERE request_id IN ($in)",
        array_merge([RETENTION_ANON_NAME, RETENTION_ANON_NAME, RETENTION_ANON_NAME, RETENTION_ANON_DATE], $ids));

    retention_exec($pdo, 'confirmation_certificate_requests',
        "UPDATE confirmation_certificate_requests SET confirmand_full_name = ?, father_full_name = ?, mother_full_name = ?,"
        . " place_of_baptism = NULL, sponsor_name = NULL WHERE request_id IN ($in)",
        array_merge([RETENTION_ANON_NAME, RETENTION_ANON_NAME, RETENTION_ANON_NAME], $ids));

    retention_exec($pdo, 'first_communion_certificate_requests',
        "UPDATE first_communion_certificate_requests SET communicant_full_name = ?, father_full_name = ?, mother_full_name = ?"
        . " WHERE request_id IN ($in)",
        array_merge([RETENTION_ANON_NAME, RETENTION_ANON_NAME, RETENTION_ANON_NAME], $ids));

    retention_exec($pdo, 'marriage_certificate_requests',
        "UPDATE marriage_certificate_requests SET party_a_full_name = ?, party_a_father_name = NULL, party_a_mother_name = NULL,"
        . " party_a_baptized_in_church_of = NULL, party_b_full_name = ?, party_b_father_name = NULL, party_b_mother_name = NULL,"
        . " party_b_baptized_in_church_of = NULL WHERE request_id IN ($in)",
        array_merge([RETENTION_ANON_NAME, RETENTION_ANON_NAME], $ids));

    retention_exec($pdo, 'certificate_generation_log',
        "UPDATE certificate_generation_log SET claimant_name = NULL, claimant_relationship = NULL, claimant_notes = NULL,"
        . " cancellation_reason = NULL, reprint_reason = NULL WHERE certificate_request_id IN ($in)",
        $ids);

    retention_exec($pdo, 'workflow_requirements',
        "UPDATE workflow_requirements SET notes = NULL WHERE entity_type = 'certificate_request' AND entity_id IN ($in)",
        $ids);

    retention_exec($pdo, 'certificate_requests',
        "UPDATE certificate_requests SET requester_name = ?, requester_email = ?, requester_contact_number = NULL,"
        . " requester_mailing_address = NULL,"
        . " requester_relationship_other = NULL, purpose_other = NULL, notes = NULL, review_notes = NULL,"
        . " rejection_reason = NULL, missing_requirements_note = NULL, cancellation_reason = NULL,"
        . " anonymized_at = NOW() WHERE id IN ($in) AND anonymized_at IS NULL",
        array_merge([RETENTION_ANON_NAME, RETENTION_ANON_EMAIL], $ids));
}

/**
 * Anonymize sacrament applications (all four types, godparents, sponsors,
 * checklist notes, then the parent row).
 */
function retention_anonymize_applications(PDO $pdo, array $ids): void
{
    $in = retention_in($ids);
    $N  = RETENTION_ANON_NAME;
    $E  = RETENTION_ANON_EMAIL;
    $D  = RETENTION_ANON_DATE;

    retention_exec($pdo, 'baptismal_application_godparents',
        "UPDATE baptismal_application_godparents g JOIN baptismal_applications b ON b.id = g.baptismal_application_id"
        . " SET g.godparent_name = ?, g.godparent_residence = NULL WHERE b.application_id IN ($in)",
        array_merge([$N], $ids));

    retention_exec($pdo, 'baptismal_applications',
        "UPDATE baptismal_applications SET child_full_name = ?, child_birthdate = ?, child_birthplace = ?,"
        . " father_full_name = NULL, father_birthplace = NULL, mother_maiden_name = NULL, mother_birthplace = NULL,"
        . " parents_address = NULL, parent_guardian_name = ?, contact_number = NULL, email = ?, notes = NULL"
        . " WHERE application_id IN ($in)",
        array_merge([$N, $D, $N, $N, $E], $ids));

    retention_exec($pdo, 'confirmation_applications',
        "UPDATE confirmation_applications SET applicant_full_name = ?, applicant_birthdate = ?, applicant_gender = NULL,"
        . " applicant_birthplace = NULL, current_address = NULL, school_name = NULL, grade_level_section = NULL,"
        . " adviser_name = NULL, parent_guardian_name = ?, father_full_name = NULL, mother_maiden_name = NULL,"
        . " contact_number = NULL, email = ?, baptism_parish_name = NULL, baptism_parish_address = NULL, sponsor_name = NULL"
        . " WHERE application_id IN ($in)",
        array_merge([$N, $D, $N, $E], $ids));

    retention_exec($pdo, 'first_communion_applications',
        "UPDATE first_communion_applications SET applicant_full_name = ?, applicant_birthdate = ?, applicant_gender = NULL,"
        . " applicant_birthplace = NULL, current_address = NULL, school_name = NULL, grade_level_section = NULL,"
        . " adviser_name = NULL, parent_guardian_name = ?, father_full_name = NULL, mother_maiden_name = NULL,"
        . " contact_number = NULL, email = ?, baptism_parish_name = NULL, baptism_parish_address = NULL"
        . " WHERE application_id IN ($in)",
        array_merge([$N, $D, $N, $E], $ids));

    retention_exec($pdo, 'marriage_principal_sponsors',
        "UPDATE marriage_principal_sponsors s JOIN marriage_applications m ON m.id = s.marriage_application_id"
        . " SET s.sponsor_name = ?, s.sponsor_residence = NULL WHERE m.application_id IN ($in)",
        array_merge([$N], $ids));

    // marital_status is a NOT NULL enum, so it gets the neutral value 'single'.
    retention_exec($pdo, 'marriage_applications',
        "UPDATE marriage_applications SET"
        . " groom_full_name = ?, groom_birthdate = ?, groom_birthplace = ?, groom_current_address = NULL,"
        . " groom_address_length = NULL, groom_contact_number = NULL, groom_email = ?, groom_religion = NULL,"
        . " groom_occupation = NULL, groom_father_name = NULL, groom_mother_maiden_name = NULL,"
        . " groom_marital_status = 'single', groom_baptized_here = 0, groom_confirmed = 0, groom_previous_marriage = NULL,"
        . " bride_full_name = ?, bride_birthdate = ?, bride_birthplace = ?, bride_current_address = NULL,"
        . " bride_address_length = NULL, bride_contact_number = NULL, bride_email = ?, bride_religion = NULL,"
        . " bride_occupation = NULL, bride_father_name = NULL, bride_mother_maiden_name = NULL,"
        . " bride_marital_status = 'single', bride_baptized_here = 0, bride_confirmed = 0, bride_previous_marriage = NULL,"
        . " civil_marriage_place = NULL, marriage_license_ref = NULL"
        . " WHERE application_id IN ($in)",
        array_merge([$N, $D, $N, $E, $N, $D, $N, $E], $ids));

    retention_exec($pdo, 'workflow_requirements',
        "UPDATE workflow_requirements SET notes = NULL WHERE entity_type = 'sacrament_application' AND entity_id IN ($in)",
        $ids);

    retention_exec($pdo, 'sacrament_applications',
        "UPDATE sacrament_applications SET notes = NULL, missing_requirements_note = NULL, anonymized_at = NOW()"
        . " WHERE id IN ($in) AND anonymized_at IS NULL",
        $ids);
}

/**
 * Execute the purge. Returns bucket => affected-row pairs (same buckets as
 * the dry run). One transaction; rolls back and rethrows on any error.
 * Writes ONE audit_logs row with counts only.
 */
function retention_execute(PDO $pdo, ?int $actorId): array
{
    $done = [];
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach (retention_targets() as $bucket => $t) {
            retention_guard($t['table']);

            if ($t['action'] === 'anonymize') {
                $stmt = $pdo->prepare('SELECT id FROM ' . $t['table'] . ' WHERE ' . $t['where']);
                $stmt->execute($t['params']);
                $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                foreach (array_chunk($ids, 500) as $chunk) {
                    if ($bucket === 'certificate_requests') {
                        retention_anonymize_certificates($pdo, $chunk);
                    } elseif ($bucket === 'sacrament_applications') {
                        retention_anonymize_applications($pdo, $chunk);
                    } else { // correction_requests
                        $in = retention_in($chunk);
                        retention_exec($pdo, 'correction_requests',
                            "UPDATE correction_requests SET requester_name = ?, requester_email = ?, requested_changes = ?,"
                            . " document_path = NULL, admin_note = NULL, anonymized_at = NOW()"
                            . " WHERE id IN ($in) AND anonymized_at IS NULL",
                            array_merge([RETENTION_ANON_NAME, RETENTION_ANON_EMAIL, RETENTION_ANON_NAME], $chunk));
                    }
                }
                $done[$bucket] = count($ids);
            } elseif ($t['action'] === 'scrub') {
                $done[$bucket] = retention_exec($pdo, 'audit_logs',
                    'UPDATE audit_logs SET ip_address = NULL, user_agent = NULL, reason = NULL, description = ? WHERE ' . $t['where'],
                    array_merge([RETENTION_SCRUBBED], $t['params']));
            } else { // delete
                $done[$bucket] = retention_exec($pdo, $t['table'],
                    'DELETE FROM ' . $t['table'] . ' WHERE ' . $t['where'], $t['params']);
            }
        }

        // Counts-only audit line. No names, emails, reference numbers or IPs.
        $summary = [];
        foreach ($done as $bucket => $n) {
            $summary[] = $bucket . '=' . (int) $n;
        }
        $role = null;
        if ($actorId !== null) {
            $r = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $r->execute([$actorId]);
            $role = $r->fetchColumn() ?: null;
        }
        retention_exec($pdo, 'audit_logs',
            'INSERT INTO audit_logs (user_id, role_snapshot, action, target_table, description, created_at)'
            . ' VALUES (?, ?, ?, NULL, ?, NOW())',
            [$actorId, $role, 'retention_purged', 'Retention purge: ' . implode(', ', $summary)]);

        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $done;
}
