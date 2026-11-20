<?php
/**
 * Linked application, certificate-request, and sacramental activity for a person profile.
 */

function sd_get_person_activity_history(PDO $pdo, int $personId): array
{
    if ($personId < 1) {
        throw new InvalidArgumentException('A valid person ID is required.');
    }

    $stmt = $pdo->prepare(
        'SELECT activity_type, activity_id, reference_no, sacrament_type, event_at,
                created_at, status, purpose, purpose_other, requester_name,
                requester_relationship, requester_relationship_other, partner_name,
                details_json, person_id, spouse_person_id, book_no, page_no, line_no
         FROM (
            SELECT \'application\' AS activity_type, sa.id AS activity_id,
                   sa.request_no AS reference_no, sa.sacrament_type,
                   sa.created_at AS event_at, sa.created_at, sa.status,
                   NULL AS purpose, NULL AS purpose_other, NULL AS requester_name,
                   NULL AS requester_relationship, NULL AS requester_relationship_other,
                   CASE
                       WHEN sa.sacrament_type = \'marriage\' AND sa.person_id = ?
                           THEN NULLIF(CONCAT_WS(\' \', NULLIF(spouse.first_name, \'\'),
                               NULLIF(spouse.middle_name, \'\'), NULLIF(spouse.last_name, \'\'),
                               NULLIF(spouse.suffix, \'\')), \'\')
                       WHEN sa.sacrament_type = \'marriage\' AND sa.spouse_person_id = ?
                           THEN NULLIF(CONCAT_WS(\' \', NULLIF(subject.first_name, \'\'),
                               NULLIF(subject.middle_name, \'\'), NULLIF(subject.last_name, \'\'),
                               NULLIF(subject.suffix, \'\')), \'\')
                       ELSE NULL
                   END AS partner_name,
                   sa.details AS details_json, sa.person_id, sa.spouse_person_id,
                   NULL AS book_no, NULL AS page_no, NULL AS line_no
            FROM sacrament_applications sa
            LEFT JOIN persons subject ON subject.id = sa.person_id
            LEFT JOIN persons spouse ON spouse.id = sa.spouse_person_id
            WHERE sa.person_id = ? OR sa.spouse_person_id = ?

            UNION ALL

            SELECT \'certificate\' AS activity_type, cr.id AS activity_id,
                   cr.request_no AS reference_no, cr.sacrament_type,
                   cr.created_at AS event_at, cr.created_at, cr.status,
                   cr.purpose, cr.purpose_other, cr.requester_name,
                   cr.requester_relationship, cr.requester_relationship_other,
                   NULL AS partner_name, NULL AS details_json,
                   NULL AS person_id, NULL AS spouse_person_id,
                   NULL AS book_no, NULL AS page_no, NULL AS line_no
            FROM certificate_requests cr
            WHERE cr.person_id = ?

            UNION ALL

            SELECT \'record\' AS activity_type, sr.id AS activity_id,
                   NULL AS reference_no, sr.sacrament_type,
                   sr.event_date AS event_at, sr.created_at, NULL AS status,
                   NULL AS purpose, NULL AS purpose_other, NULL AS requester_name,
                   NULL AS requester_relationship, NULL AS requester_relationship_other,
                   CASE
                       WHEN sr.sacrament_type = \'marriage\' AND sr.person_id = ?
                           THEN NULLIF(CONCAT_WS(\' \', NULLIF(spouse.first_name, \'\'),
                               NULLIF(spouse.middle_name, \'\'), NULLIF(spouse.last_name, \'\'),
                               NULLIF(spouse.suffix, \'\')), \'\')
                       WHEN sr.sacrament_type = \'marriage\' AND sr.spouse_person_id = ?
                           THEN NULLIF(CONCAT_WS(\' \', NULLIF(subject.first_name, \'\'),
                               NULLIF(subject.middle_name, \'\'), NULLIF(subject.last_name, \'\'),
                               NULLIF(subject.suffix, \'\')), \'\')
                       ELSE NULL
                   END AS partner_name,
                   NULL AS details_json, sr.person_id, sr.spouse_person_id,
                   sr.book_no, sr.page_no, sr.line_no
            FROM sacrament_records sr
            LEFT JOIN persons subject ON subject.id = sr.person_id
            LEFT JOIN persons spouse ON spouse.id = sr.spouse_person_id
            WHERE sr.person_id = ? OR sr.spouse_person_id = ?
         ) AS activity_history
         ORDER BY event_at DESC, created_at DESC, activity_type ASC, activity_id DESC'
    );
    $stmt->execute([
        $personId,
        $personId,
        $personId,
        $personId,
        $personId,
        $personId,
        $personId,
        $personId,
        $personId,
    ]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($history as &$event) {
        if (
            $event['activity_type'] !== 'application'
            || $event['sacrament_type'] !== 'marriage'
            || trim((string) ($event['partner_name'] ?? '')) !== ''
        ) {
            continue;
        }

        $details = json_decode((string) ($event['details_json'] ?? ''), true);
        if (!is_array($details)) {
            continue;
        }

        $partnerKey = (int) $event['person_id'] === $personId ? 'bride' : 'groom';
        $event['partner_name'] = trim((string) ($details[$partnerKey]['full_name'] ?? ''));
    }
    unset($event);

    return $history;
}
