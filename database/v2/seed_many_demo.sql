-- =====================================================================
-- SRS-SPPO V2 LARGE REGISTER DEMO DATA (additive, repeatable, demo only)
-- =====================================================================
-- Adds 100 fictional master profiles and 170 linked sacramental records:
-- 100 Baptisms, 20 First Communions, 40 Confirmations, and 10 Marriages.
-- All sample email addresses use the reserved .test domain.
--
-- Run against an existing V2 demo database. This seed uses reserved person
-- IDs 1001-1100 and sacrament record ID ranges 2001-2100, 3001-3100,
-- 4001-4100, and 5001-5010. It does not create temporary tables, so it is
-- suitable for shared hosts that restrict CREATE TABLE privileges.
-- Safe to run again. Never import into a live parish database.
-- =====================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- Add parents first so the remaining profiles can link to their identities.
INSERT INTO persons
  (id, first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, contact_number, email,
   baptismal_status)
SELECT 1000 + ((t.digit * 10 + u.digit) + 1),
       CASE MOD((t.digit * 10 + u.digit), 10) + 1
         WHEN 1 THEN 'Jose' WHEN 6 THEN 'Maria' END,
       'Demo',
       CASE
         WHEN MOD((t.digit * 10 + u.digit), 10) + 1 = 6 THEN
           CASE t.digit + 1
             WHEN 1 THEN 'Alonzo' WHEN 2 THEN 'Bautista'
             WHEN 3 THEN 'Garcia' WHEN 4 THEN 'Ramos'
             WHEN 5 THEN 'Navarro' WHEN 6 THEN 'Villanueva'
             WHEN 7 THEN 'Dela Cruz' WHEN 8 THEN 'Mendoza'
             WHEN 9 THEN 'Santos' ELSE 'Cruz'
           END
         ELSE
           CASE t.digit + 1
             WHEN 1 THEN 'Mendoza' WHEN 2 THEN 'Reyes'
             WHEN 3 THEN 'Santos' WHEN 4 THEN 'Cruz'
             WHEN 5 THEN 'Garcia' WHEN 6 THEN 'Bautista'
             WHEN 7 THEN 'Villanueva' WHEN 8 THEN 'Ramos'
             WHEN 9 THEN 'Dela Cruz' ELSE 'Navarro'
           END
       END,
       CASE WHEN MOD((t.digit * 10 + u.digit), 10) + 1 = 1 THEN 'male' ELSE 'female' END,
       STR_TO_DATE(
         CONCAT(
           CASE
             WHEN MOD((t.digit * 10 + u.digit), 10) + 1 = 1 THEN 1975 + MOD(t.digit + 1, 6)
             ELSE 1978 + MOD(t.digit + 1, 5)
           END,
           '-', LPAD(MOD((t.digit + 1) * 2 + MOD((t.digit * 10 + u.digit), 10) + 1, 12) + 1, 2, '0'),
           '-', LPAD(MOD((t.digit + 1) * 3 + (MOD((t.digit * 10 + u.digit), 10) + 1) * 2, 27) + 1, 2, '0')
         ),
         '%Y-%m-%d'
       ),
       'Dingalan, Aurora',
       CONCAT('Jose Demo ', CASE t.digit + 1
         WHEN 1 THEN 'Mendoza' WHEN 2 THEN 'Reyes' WHEN 3 THEN 'Santos'
         WHEN 4 THEN 'Cruz' WHEN 5 THEN 'Garcia' WHEN 6 THEN 'Bautista'
         WHEN 7 THEN 'Villanueva' WHEN 8 THEN 'Ramos' WHEN 9 THEN 'Dela Cruz'
         ELSE 'Navarro' END),
       CASE t.digit + 1
         WHEN 1 THEN 'Alonzo' WHEN 2 THEN 'Bautista' WHEN 3 THEN 'Garcia'
         WHEN 4 THEN 'Ramos' WHEN 5 THEN 'Navarro' WHEN 6 THEN 'Villanueva'
         WHEN 7 THEN 'Dela Cruz' WHEN 8 THEN 'Mendoza' WHEN 9 THEN 'Santos'
         ELSE 'Cruz' END,
       CASE t.digit + 1
         WHEN 1 THEN 'Poblacion, Dingalan, Aurora'
         WHEN 2 THEN 'Barangay San Isidro, Dingalan, Aurora'
         WHEN 3 THEN 'Barangay Sta. Lucia, Dingalan, Aurora'
         WHEN 4 THEN 'Poblacion, Dingalan, Aurora'
         WHEN 5 THEN 'Barangay Maligaya, Dingalan, Aurora'
         WHEN 6 THEN 'Barangay San Isidro, Dingalan, Aurora'
         WHEN 7 THEN 'Barangay Butas Na Bato, Dingalan, Aurora'
         WHEN 8 THEN 'Barangay Sta. Lucia, Dingalan, Aurora'
         WHEN 9 THEN 'Poblacion, Dingalan, Aurora'
         ELSE 'Barangay Maligaya, Dingalan, Aurora' END,
       CONCAT('0917', LPAD((t.digit * 10 + u.digit) + 1, 7, '0')),
       CONCAT('many.parishioner', LPAD((t.digit * 10 + u.digit) + 1, 3, '0'), '@demo.test'),
       'baptized_catholic'
FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
CROSS JOIN (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
WHERE MOD((t.digit * 10 + u.digit), 10) + 1 IN (1, 6)
  AND NOT EXISTS (
    SELECT 1 FROM persons p
    WHERE p.id = 1000 + ((t.digit * 10 + u.digit) + 1)
       OR p.email = CONCAT('many.parishioner', LPAD((t.digit * 10 + u.digit) + 1, 3, '0'), '@demo.test')
  );

-- Eight linked children and adult siblings per family.
INSERT INTO persons
  (id, first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, father_person_id, mother_person_id,
   address, contact_number, email, baptismal_status)
SELECT
  1000 + ((t.digit * 10 + u.digit) + 1),
  CASE MOD((t.digit * 10 + u.digit), 10) + 1
    WHEN 2 THEN 'Miguel' WHEN 3 THEN 'Andres' WHEN 4 THEN 'Rafael'
    WHEN 5 THEN 'Gabriel' WHEN 7 THEN 'Ana' WHEN 8 THEN 'Sofia'
    WHEN 9 THEN 'Isabel' ELSE 'Elena'
  END,
  'Demo',
  CASE t.digit + 1
    WHEN 1 THEN 'Mendoza' WHEN 2 THEN 'Reyes' WHEN 3 THEN 'Santos'
    WHEN 4 THEN 'Cruz' WHEN 5 THEN 'Garcia' WHEN 6 THEN 'Bautista'
    WHEN 7 THEN 'Villanueva' WHEN 8 THEN 'Ramos' WHEN 9 THEN 'Dela Cruz'
    ELSE 'Navarro'
  END,
  CASE WHEN MOD((t.digit * 10 + u.digit), 10) + 1 IN (2, 3, 4, 5) THEN 'male' ELSE 'female' END,
  STR_TO_DATE(
    CONCAT(
      CASE
        WHEN MOD((t.digit * 10 + u.digit), 10) + 1 = 2 THEN 1990 + MOD(t.digit + 1, 5)
        WHEN MOD((t.digit * 10 + u.digit), 10) + 1 = 7 THEN 1992 + MOD(t.digit + 1, 4)
        WHEN MOD((t.digit * 10 + u.digit), 10) + 1 IN (3, 8) THEN 2015 + MOD(t.digit + 1, 3)
        WHEN MOD((t.digit * 10 + u.digit), 10) + 1 = 4 THEN 2008 + MOD(t.digit + 1, 4)
        WHEN MOD((t.digit * 10 + u.digit), 10) + 1 = 5 THEN 2006 + MOD(t.digit + 1, 4)
        WHEN MOD((t.digit * 10 + u.digit), 10) + 1 = 9 THEN 2009 + MOD(t.digit + 1, 4)
        ELSE 2007 + MOD(t.digit + 1, 4)
      END,
      '-', LPAD(MOD((t.digit + 1) * 2 + MOD((t.digit * 10 + u.digit), 10) + 1, 12) + 1, 2, '0'),
      '-', LPAD(MOD((t.digit + 1) * 3 + (MOD((t.digit * 10 + u.digit), 10) + 1) * 2, 27) + 1, 2, '0')
    ),
    '%Y-%m-%d'
  ),
  'Dingalan, Aurora',
  CONCAT('Jose Demo ', CASE t.digit + 1
    WHEN 1 THEN 'Mendoza' WHEN 2 THEN 'Reyes' WHEN 3 THEN 'Santos'
    WHEN 4 THEN 'Cruz' WHEN 5 THEN 'Garcia' WHEN 6 THEN 'Bautista'
    WHEN 7 THEN 'Villanueva' WHEN 8 THEN 'Ramos' WHEN 9 THEN 'Dela Cruz'
    ELSE 'Navarro' END),
  CASE t.digit + 1
    WHEN 1 THEN 'Alonzo' WHEN 2 THEN 'Bautista' WHEN 3 THEN 'Garcia'
    WHEN 4 THEN 'Ramos' WHEN 5 THEN 'Navarro' WHEN 6 THEN 'Villanueva'
    WHEN 7 THEN 'Dela Cruz' WHEN 8 THEN 'Mendoza' WHEN 9 THEN 'Santos'
    ELSE 'Cruz' END,
  father.id,
  mother.id,
  CASE t.digit + 1
    WHEN 1 THEN 'Poblacion, Dingalan, Aurora'
    WHEN 2 THEN 'Barangay San Isidro, Dingalan, Aurora'
    WHEN 3 THEN 'Barangay Sta. Lucia, Dingalan, Aurora'
    WHEN 4 THEN 'Poblacion, Dingalan, Aurora'
    WHEN 5 THEN 'Barangay Maligaya, Dingalan, Aurora'
    WHEN 6 THEN 'Barangay San Isidro, Dingalan, Aurora'
    WHEN 7 THEN 'Barangay Butas Na Bato, Dingalan, Aurora'
    WHEN 8 THEN 'Barangay Sta. Lucia, Dingalan, Aurora'
    WHEN 9 THEN 'Poblacion, Dingalan, Aurora'
    ELSE 'Barangay Maligaya, Dingalan, Aurora' END,
  CONCAT('0917', LPAD((t.digit * 10 + u.digit) + 1, 7, '0')),
  CONCAT('many.parishioner', LPAD((t.digit * 10 + u.digit) + 1, 3, '0'), '@demo.test'),
  'baptized_catholic'
FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
CROSS JOIN (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
JOIN persons father
  ON father.id = 1001 + t.digit * 10
JOIN persons mother
  ON mother.id = 1006 + t.digit * 10
WHERE MOD((t.digit * 10 + u.digit), 10) + 1 NOT IN (1, 6)
  AND NOT EXISTS (
    SELECT 1 FROM persons p
    WHERE p.id = 1000 + ((t.digit * 10 + u.digit) + 1)
       OR p.email = CONCAT('many.parishioner', LPAD((t.digit * 10 + u.digit) + 1, 3, '0'), '@demo.test')
  );

-- One Baptismal register entry and two godparents per profile.
INSERT INTO sacrament_records
  (id, sacrament_type, person_id, source, event_date, place, minister_name,
   entry_no, book_no, page_no, line_no, parents_union_type, legitimacy,
   details, remarks)
SELECT 2000 + n.person_no, 'baptism', 1000 + n.person_no, 'this_parish',
       DATE_ADD(p.birth_date, INTERVAL (18 + MOD(n.given_no, 60)) DAY),
       'Saint Patrick''s Parish', 'Rev. Fr. Sample Parish',
       CONCAT('DEMO-MANY-B-', LPAD(n.person_no, 3, '0')),
       'DEMO-MANY-B', LPAD(CEIL(n.person_no / 20), 2, '0'),
       LPAD(MOD(n.person_no - 1, 20) + 1, 2, '0'),
       'legitimate', NULL, JSON_OBJECT('parents_residence', p.address),
       'DEMO ONLY: fictional large-register demonstration entry.'
FROM (
  SELECT t.digit * 10 + u.digit + 1 AS person_no,
         MOD(t.digit * 10 + u.digit, 10) + 1 AS given_no
  FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
  CROSS JOIN (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
              UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
) n
JOIN persons p ON p.id = 1000 + n.person_no
WHERE NOT EXISTS (SELECT 1 FROM sacrament_records r WHERE r.id = 2000 + n.person_no);

INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
SELECT 2000 + n.person_no, 'godfather', CONCAT('Miguel ', n.mother_name), p.address, 1
FROM (
  SELECT t.digit * 10 + u.digit + 1 AS person_no,
         CASE t.digit + 1
           WHEN 1 THEN 'Alonzo' WHEN 2 THEN 'Bautista' WHEN 3 THEN 'Garcia'
           WHEN 4 THEN 'Ramos' WHEN 5 THEN 'Navarro' WHEN 6 THEN 'Villanueva'
           WHEN 7 THEN 'Dela Cruz' WHEN 8 THEN 'Mendoza' WHEN 9 THEN 'Santos'
           ELSE 'Cruz'
         END AS mother_name
  FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
  CROSS JOIN (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
              UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
) n
JOIN persons p ON p.id = 1000 + n.person_no
WHERE NOT EXISTS (
  SELECT 1 FROM sacrament_participants s
  WHERE s.record_id = 2000 + n.person_no AND s.role = 'godfather'
);

INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
SELECT 2000 + n.person_no, 'godmother', CONCAT('Ana ', p.last_name), p.address, 2
FROM (
  SELECT t.digit * 10 + u.digit + 1 AS person_no
  FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
  CROSS JOIN (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
              UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
) n
JOIN persons p ON p.id = 1000 + n.person_no
WHERE NOT EXISTS (
  SELECT 1 FROM sacrament_participants s
  WHERE s.record_id = 2000 + n.person_no AND s.role = 'godmother'
);

-- Twenty First Communion entries for children aged about eight.
INSERT INTO sacrament_records
  (id, sacrament_type, person_id, source, event_date, place, minister_name,
   entry_no, book_no, page_no, line_no, details, remarks)
SELECT 3000 + n.person_no, 'first_communion', 1000 + n.person_no, 'this_parish',
       DATE_ADD(p.birth_date, INTERVAL 8 YEAR),
       'Saint Patrick''s Parish', 'Rev. Fr. Sample Parish',
       CONCAT('DEMO-MANY-FC-', LPAD(n.person_no, 3, '0')),
       'DEMO-MANY-FC', LPAD(CEIL(n.person_no / 20), 2, '0'),
       LPAD(MOD(n.person_no - 1, 20) + 1, 2, '0'),
       JSON_OBJECT('domicile', p.address),
       'DEMO ONLY: fictional large-register demonstration entry.'
FROM (
  SELECT t.digit * 10 + u.digit + 1 AS person_no,
         MOD(t.digit * 10 + u.digit, 10) + 1 AS given_no
  FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
  CROSS JOIN (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
              UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
) n
JOIN persons p ON p.id = 1000 + n.person_no
WHERE n.given_no IN (3, 8)
  AND NOT EXISTS (SELECT 1 FROM sacrament_records r WHERE r.id = 3000 + n.person_no);

-- Forty Confirmation entries with linked sponsors.
INSERT INTO sacrament_records
  (id, sacrament_type, person_id, source, event_date, place, minister_name,
   entry_no, book_no, page_no, line_no, remarks)
SELECT 4000 + n.person_no, 'confirmation', 1000 + n.person_no, 'this_parish',
       DATE_ADD(p.birth_date, INTERVAL 14 YEAR),
       'Saint Patrick''s Parish', 'Most Rev. Sample Bishop',
       CONCAT('DEMO-MANY-C-', LPAD(n.person_no, 3, '0')),
       'DEMO-MANY-C', LPAD(CEIL(n.person_no / 20), 2, '0'),
       LPAD(MOD(n.person_no - 1, 20) + 1, 2, '0'),
       'DEMO ONLY: fictional large-register demonstration entry.'
FROM (
  SELECT t.digit * 10 + u.digit + 1 AS person_no,
         MOD(t.digit * 10 + u.digit, 10) + 1 AS given_no
  FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
  CROSS JOIN (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
              UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
) n
JOIN persons p ON p.id = 1000 + n.person_no
WHERE n.given_no IN (4, 5, 9, 10)
  AND NOT EXISTS (SELECT 1 FROM sacrament_records r WHERE r.id = 4000 + n.person_no);

INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
SELECT 4000 + n.person_no, 'sponsor', CONCAT('Isabel ', p.last_name), p.address, 1
FROM (
  SELECT t.digit * 10 + u.digit + 1 AS person_no,
         MOD(t.digit * 10 + u.digit, 10) + 1 AS given_no
  FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
  CROSS JOIN (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
              UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
) n
JOIN persons p ON p.id = 1000 + n.person_no
WHERE n.given_no IN (4, 5, 9, 10)
  AND NOT EXISTS (
    SELECT 1 FROM sacrament_participants s
    WHERE s.record_id = 4000 + n.person_no AND s.role = 'sponsor'
  );

-- Ten adult couples share a family timeline, with two marriage witnesses.
INSERT INTO sacrament_records
  (id, sacrament_type, person_id, spouse_person_id, source, event_date, place,
   minister_name, entry_no, book_no, page_no, line_no, details, remarks)
SELECT 5001 + f.digit,
       'marriage', husband.id, wife.id, 'this_parish',
       DATE_ADD(husband.birth_date, INTERVAL 25 YEAR),
       'Saint Patrick''s Parish', 'Rev. Fr. Sample Parish',
       CONCAT('DEMO-MANY-M-', LPAD(f.digit + 1, 3, '0')),
       'DEMO-MANY-M', LPAD(f.digit + 1, 2, '0'), '01',
       JSON_OBJECT(
         'groom', JSON_OBJECT('civil_status', 'single', 'age', 25,
                              'origin', 'Dingalan, Aurora', 'residence', husband.address),
         'bride', JSON_OBJECT(
           'civil_status', 'single',
           'age', TIMESTAMPDIFF(YEAR, wife.birth_date, DATE_ADD(husband.birth_date, INTERVAL 25 YEAR)),
           'origin', 'Dingalan, Aurora', 'residence', wife.address
         ),
         'license_ref', CONCAT('DEMO-ML-', LPAD(f.digit + 1, 4, '0'))
       ),
       'DEMO ONLY: fictional large-register demonstration entry.'
FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) f
JOIN persons husband ON husband.id = 1001 + f.digit * 10
JOIN persons wife ON wife.id = 1006 + f.digit * 10
WHERE NOT EXISTS (SELECT 1 FROM sacrament_records r WHERE r.id = 5001 + f.digit);

INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
SELECT 5001 + f.digit, 'witness', CONCAT('Ramon ', husband.last_name), husband.address, 1
FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) f
JOIN persons husband ON husband.id = 1001 + f.digit * 10
WHERE NOT EXISTS (
  SELECT 1 FROM sacrament_participants s
  WHERE s.record_id = 5001 + f.digit AND s.role = 'witness' AND s.sort_order = 1
);

INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
SELECT 5001 + f.digit, 'witness', CONCAT('Lourdes ', wife.last_name), wife.address, 2
FROM (SELECT 0 digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) f
JOIN persons wife ON wife.id = 1006 + f.digit * 10
WHERE NOT EXISTS (
  SELECT 1 FROM sacrament_participants s
  WHERE s.record_id = 5001 + f.digit AND s.role = 'witness' AND s.sort_order = 2
);

COMMIT;
