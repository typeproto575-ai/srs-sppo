-- Targeted cleanup for rows inserted by database/v2/seed_demo.sql.
-- Back up the database first. This does not delete users and does not
-- truncate tables. Rows must match the seed's fixed identifiers and
-- identifying values; non-matching rows are preserved.
--
-- Review the candidate counts before running this file on a hosted database.
-- Change the USE statement below to the host's database name, or remove it
-- after selecting the intended database in phpMyAdmin.

USE srs_sppo_db;

SELECT 'persons' AS table_name, COUNT(*) AS candidate_rows
FROM persons
WHERE (id, first_name, last_name, birth_date) IN (
  (1, 'Teodoro', 'Mendoza', '1925-09-05'),
  (2, 'Felisa', 'Aguilar', '1928-01-19'),
  (3, 'Ernesto', 'Mendoza', '1950-11-12'),
  (4, 'Rosario', 'Villanueva', '1953-04-22'),
  (5, 'Ricardo', 'Mendoza', '1976-05-17'),
  (6, 'Liza', 'Ramos', '1979-08-30'),
  (7, 'Roy', 'Mendoza', '2003-06-14'),
  (8, 'Beatrice', 'Mendoza', '2006-02-20'),
  (9, 'Carlo', 'Mendoza', '2015-11-03'),
  (10, 'Juan', 'dela Cruz', '1985-02-14'),
  (11, 'Juan', 'dela Cruz', '1991-09-30'),
  (12, 'Marites', 'Villareal', '1994-03-08'),
  (13, 'Kevin', 'Bautista', '1996-09-02'),
  (14, 'Joanna', 'Pascual', '1998-12-21')
)
UNION ALL
SELECT 'sacrament_records', COUNT(*)
FROM sacrament_records
WHERE (id, sacrament_type, person_id, event_date) IN (
  (1, 'baptism', 1, '1925-09-20'),
  (2, 'baptism', 2, '1928-02-05'),
  (3, 'marriage', 1, '1948-06-12'),
  (4, 'baptism', 3, '1950-12-03'),
  (5, 'first_communion', 3, '1959-05-24'),
  (6, 'confirmation', 3, '1964-08-16'),
  (7, 'baptism', 4, '1953-05-17'),
  (8, 'first_communion', 4, '1961-05-21'),
  (9, 'confirmation', 4, '1966-09-04'),
  (10, 'marriage', 3, '1974-12-28'),
  (11, 'baptism', 5, '1976-06-20'),
  (12, 'first_communion', 5, '1984-05-27'),
  (13, 'confirmation', 5, '1990-10-07'),
  (14, 'baptism', 6, '1979-09-23'),
  (15, 'first_communion', 6, '1987-05-24'),
  (16, 'confirmation', 6, '1993-08-29'),
  (17, 'marriage', 5, '2002-05-18'),
  (18, 'baptism', 7, '2003-07-06'),
  (19, 'first_communion', 7, '2011-05-22'),
  (20, 'confirmation', 7, '2016-10-09'),
  (21, 'baptism', 8, '2006-03-26'),
  (22, 'first_communion', 8, '2014-05-25'),
  (23, 'baptism', 9, '2015-12-20'),
  (24, 'baptism', 10, '1985-03-17'),
  (25, 'first_communion', 10, '1993-05-23'),
  (26, 'baptism', 11, '1991-10-20'),
  (27, 'baptism', 12, '1994-04-10'),
  (28, 'first_communion', 12, '2002-05-26'),
  (29, 'confirmation', 12, '2010-09-12'),
  (30, 'baptism', 13, '1996-10-13'),
  (31, 'first_communion', 13, '2004-05-23'),
  (32, 'confirmation', 13, '2010-10-10'),
  (33, 'baptism', 14, '1999-01-24')
)
UNION ALL
SELECT 'sacrament_applications', COUNT(*)
FROM sacrament_applications
WHERE request_no IN ('SA-2026-0001', 'SA-2026-0002', 'SA-2026-0003')
UNION ALL
SELECT 'certificate_requests', COUNT(*)
FROM certificate_requests
WHERE request_no IN (
  'CR-2026-0001', 'CR-2026-0002', 'CR-2026-0003',
  'CR-2026-0004', 'CR-2026-0005', 'CR-2026-0006'
);

START TRANSACTION;

DELETE FROM notifications
WHERE (channel = 'inapp' AND type = 'request_new'
  AND title = 'New certificate request'
  AND message = 'Confirmation certificate for Juan dela Cruz'
  AND entity_type = 'certificate_requests' AND entity_id = 4
  AND created_at = '2026-10-27 20:30:00')
   OR (channel = 'inapp' AND type = 'application_new'
  AND title = 'New baptism application'
  AND message = 'Application from Grace Delos Santos'
  AND entity_type = 'sacrament_applications' AND entity_id = 1
  AND created_at = '2026-10-26 18:40:00')
   OR (channel = 'inapp' AND type = 'request_new'
  AND title = 'New certificate request'
  AND message = 'Baptism certificate for Beatrice Mendoza'
  AND entity_type = 'certificate_requests' AND entity_id = 2
  AND created_at = '2026-10-25 19:10:00')
   OR (channel = 'email' AND type = 'request_ready'
  AND title = 'Your certificate is ready for pickup'
  AND recipient = 'liza.ramos@example.com'
  AND delivery_status = 'sent'
  AND entity_type = 'certificate_requests' AND entity_id = 2
  AND created_at = '2026-10-27 16:01:00');

DELETE FROM audit_logs
WHERE actor_name = 'Demo Secretary'
  AND ip_address = '127.0.0.1'
  AND (
    (action = 'login' AND description = 'Secretary signed in'
      AND created_at = '2026-10-28 08:30:00')
    OR (action = 'certificate.match'
      AND description = 'Linked CR-2026-0005 to person 5 and the marriage record'
      AND created_at = '2026-10-28 08:40:00')
    OR (action = 'certificate.print'
      AND description = 'Printed M-2026-0001 (for signature)'
      AND created_at = '2026-10-28 08:50:00')
    OR (action = 'certificate.release'
      AND description = 'Released to representative; ID verified at counter'
      AND created_at = '2026-10-22 11:05:00')
    OR (action = 'certificate.release'
      AND description = 'Released to requester; ID verified at counter'
      AND created_at = '2026-10-14 10:20:00')
    OR (action = 'record.verify'
      AND description = 'Entry checked against the physical register'
      AND created_at = '2026-10-20 09:00:00')
  );

DELETE FROM schedule_events
WHERE (id, event_type, title, event_date) IN (
  (1, 'mass', 'All Saints'' Day Mass', '2026-11-01'),
  (2, 'baptism', 'Baptism', '2026-11-22'),
  (3, 'wedding', 'Wedding Mass', '2026-12-12'),
  (4, 'mass', 'Simbang Gabi (first night)', '2026-12-16')
);

DELETE FROM certificate_requests
WHERE request_no IN (
  'CR-2026-0001', 'CR-2026-0002', 'CR-2026-0003',
  'CR-2026-0004', 'CR-2026-0005', 'CR-2026-0006'
);

DELETE FROM sacrament_applications
WHERE request_no IN ('SA-2026-0001', 'SA-2026-0002', 'SA-2026-0003');

DELETE FROM sacrament_records
WHERE (id, sacrament_type, person_id, event_date) IN (
  (1, 'baptism', 1, '1925-09-20'),
  (2, 'baptism', 2, '1928-02-05'),
  (3, 'marriage', 1, '1948-06-12'),
  (4, 'baptism', 3, '1950-12-03'),
  (5, 'first_communion', 3, '1959-05-24'),
  (6, 'confirmation', 3, '1964-08-16'),
  (7, 'baptism', 4, '1953-05-17'),
  (8, 'first_communion', 4, '1961-05-21'),
  (9, 'confirmation', 4, '1966-09-04'),
  (10, 'marriage', 3, '1974-12-28'),
  (11, 'baptism', 5, '1976-06-20'),
  (12, 'first_communion', 5, '1984-05-27'),
  (13, 'confirmation', 5, '1990-10-07'),
  (14, 'baptism', 6, '1979-09-23'),
  (15, 'first_communion', 6, '1987-05-24'),
  (16, 'confirmation', 6, '1993-08-29'),
  (17, 'marriage', 5, '2002-05-18'),
  (18, 'baptism', 7, '2003-07-06'),
  (19, 'first_communion', 7, '2011-05-22'),
  (20, 'confirmation', 7, '2016-10-09'),
  (21, 'baptism', 8, '2006-03-26'),
  (22, 'first_communion', 8, '2014-05-25'),
  (23, 'baptism', 9, '2015-12-20'),
  (24, 'baptism', 10, '1985-03-17'),
  (25, 'first_communion', 10, '1993-05-23'),
  (26, 'baptism', 11, '1991-10-20'),
  (27, 'baptism', 12, '1994-04-10'),
  (28, 'first_communion', 12, '2002-05-26'),
  (29, 'confirmation', 12, '2010-09-12'),
  (30, 'baptism', 13, '1996-10-13'),
  (31, 'first_communion', 13, '2004-05-23'),
  (32, 'confirmation', 13, '2010-10-10'),
  (33, 'baptism', 14, '1999-01-24')
);

DELETE FROM announcements
WHERE (title = 'Certificate requests'
    AND content = 'Requests are processed at the parish office. Bring a valid government ID on pickup. A representative also brings an authorization letter and their own ID.'
    AND is_published = 1)
   OR (title = 'All Saints'' Day schedule'
    AND content = 'Holy Mass at 8:00 AM on November 1.'
    AND event_date = '2026-11-01'
    AND is_published = 1);

DELETE FROM settings
WHERE updated_by = 1
  AND (
    (setting_key = 'parish_name' AND setting_value = 'St. Patrick Parish')
    OR (setting_key = 'first_communion_min_age' AND setting_value = '8')
    OR (setting_key = 'confirmation_min_age' AND setting_value = '12')
    OR (setting_key = 'priest_approval_email' AND setting_value = 'priest@example.com')
  );

DELETE p
FROM persons AS p
LEFT JOIN persons AS child
  ON child.id NOT BETWEEN 1 AND 14
  AND (child.father_person_id = p.id OR child.mother_person_id = p.id)
WHERE (p.id, p.first_name, p.last_name, p.birth_date) IN (
  (1, 'Teodoro', 'Mendoza', '1925-09-05'),
  (2, 'Felisa', 'Aguilar', '1928-01-19'),
  (3, 'Ernesto', 'Mendoza', '1950-11-12'),
  (4, 'Rosario', 'Villanueva', '1953-04-22'),
  (5, 'Ricardo', 'Mendoza', '1976-05-17'),
  (6, 'Liza', 'Ramos', '1979-08-30'),
  (7, 'Roy', 'Mendoza', '2003-06-14'),
  (8, 'Beatrice', 'Mendoza', '2006-02-20'),
  (9, 'Carlo', 'Mendoza', '2015-11-03'),
  (10, 'Juan', 'dela Cruz', '1985-02-14'),
  (11, 'Juan', 'dela Cruz', '1991-09-30'),
  (12, 'Marites', 'Villareal', '1994-03-08'),
  (13, 'Kevin', 'Bautista', '1996-09-02'),
  (14, 'Joanna', 'Pascual', '1998-12-21')
)
  AND child.id IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM sacrament_records AS r
    WHERE r.person_id = p.id OR r.spouse_person_id = p.id
  )
  AND NOT EXISTS (
    SELECT 1 FROM sacrament_applications AS a
    WHERE a.person_id = p.id OR a.spouse_person_id = p.id
  )
  AND NOT EXISTS (
    SELECT 1 FROM certificate_requests AS c
    WHERE c.person_id = p.id
  );

COMMIT;
