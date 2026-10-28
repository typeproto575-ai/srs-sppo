-- =====================================================================
-- SRS-SPPO DEFENSE DEMO DATA (additive, repeatable, local/demo only)
-- =====================================================================
-- Adds interconnected fictional people, register entries, applications,
-- certificate requests, checklist rows, calendar items, announcements,
-- and clearly-labelled sample audit history. It never deletes data or
-- overwrites a workflow status that staff may already have changed.
--
-- Import after the current canonical schema. Existing installations must
-- first apply the required schema migrations, including 039, 045, and 046.
-- Do not run this against a production parish database.
-- =====================================================================

USE srs_sppo_db;

START TRANSACTION;

-- Eleven fictional master profiles. Eva, Marco, and Nina are also used by
-- seed_process_demo.sql; the email checks keep this compatible with it.
INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, email, baptismal_status)
SELECT 'Eva', 'Demo', 'Santos', 'female', '2014-06-15', 'Dingalan, Aurora',
       'Ramon Santos', 'Maria Cruz', 'Poblacion, Dingalan, Aurora',
       'eva.santos@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'eva.santos@demo.test');
INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, email, baptismal_status)
SELECT 'Marco', 'Demo', 'Reyes', 'male', '1997-04-20', 'Dingalan, Aurora',
       'Jose Reyes', 'Ana Garcia', 'marco.reyes@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'marco.reyes@demo.test');
INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, email, baptismal_status)
SELECT 'Nina', 'Demo', 'Cruz', 'female', '1998-09-08', 'Dingalan, Aurora',
       'Pedro Cruz', 'Rosa Santos', 'nina.cruz@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'nina.cruz@demo.test');

INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, contact_number, email,
   baptismal_status)
SELECT 'Lia', 'Demo', 'Cruz', 'female', '2013-04-04', 'Dingalan, Aurora',
       'Pedro Cruz', 'Rosa Santos', 'Poblacion, Dingalan, Aurora',
       '09170000005', 'lia.cruz@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'lia.cruz@demo.test');

INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, contact_number, email,
   baptismal_status)
SELECT 'Daniel', 'Demo', 'Reyes', 'male', '1994-01-19', 'Dingalan, Aurora',
       'Jose Reyes', 'Ana Garcia', 'Dingalan, Aurora',
       '09170000006', 'daniel.reyes@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'daniel.reyes@demo.test');

INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, contact_number, email,
   baptismal_status)
SELECT 'Emma', 'Demo', 'Reyes', 'female', '2012-02-11', 'Dingalan, Aurora',
       'Daniel Reyes', 'Ana Garcia', 'Dingalan, Aurora',
       '09170000007', 'emma.reyes@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'emma.reyes@demo.test');

INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, contact_number, email,
   baptismal_status)
SELECT 'Carlos', 'Demo', 'Mendoza', 'male', '1995-08-03', 'Dingalan, Aurora',
       'Rogelio Mendoza', 'Elena Santos', 'Dingalan, Aurora',
       '09170000008', 'carlos.mendoza@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'carlos.mendoza@demo.test');

INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, contact_number, email,
   baptismal_status)
SELECT 'Rosa', 'Demo', 'Santos', 'female', '1996-11-22', 'Dingalan, Aurora',
       'Felipe Santos', 'Celia Cruz', 'Dingalan, Aurora',
       '09170000009', 'rosa.santos@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'rosa.santos@demo.test');

INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, contact_number, email,
   baptismal_status)
SELECT 'Sofia', 'Demo', 'Garcia', 'female', '2016-09-17', 'Dingalan, Aurora',
       'Miguel Garcia', 'Maria Lopez', 'Dingalan, Aurora',
       '09170000010', 'sofia.garcia@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'sofia.garcia@demo.test');

INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, contact_number, email,
   baptismal_status)
SELECT 'Andre', 'Demo', 'Lopez', 'male', '2001-05-30', 'Dingalan, Aurora',
       'Ramon Lopez', 'Lourdes Reyes', 'Dingalan, Aurora',
       '09170000011', 'andre.lopez@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'andre.lopez@demo.test');

INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, contact_number, email,
   baptismal_status)
SELECT 'Elena', 'Demo', 'Ramos', 'female', '1992-07-12', 'Dingalan, Aurora',
       'Mario Ramos', 'Carmen Flores', 'Dingalan, Aurora',
       '09170000012', 'elena.ramos@demo.test', 'baptized_catholic'
WHERE NOT EXISTS (SELECT 1 FROM persons WHERE email = 'elena.ramos@demo.test');

SET @p_eva = (SELECT id FROM persons WHERE email = 'eva.santos@demo.test' ORDER BY id LIMIT 1);
SET @p_marco = (SELECT id FROM persons WHERE email = 'marco.reyes@demo.test' ORDER BY id LIMIT 1);
SET @p_nina = (SELECT id FROM persons WHERE email = 'nina.cruz@demo.test' ORDER BY id LIMIT 1);
SET @p_lia = (SELECT id FROM persons WHERE email = 'lia.cruz@demo.test' ORDER BY id LIMIT 1);
SET @p_daniel = (SELECT id FROM persons WHERE email = 'daniel.reyes@demo.test' ORDER BY id LIMIT 1);
SET @p_emma = (SELECT id FROM persons WHERE email = 'emma.reyes@demo.test' ORDER BY id LIMIT 1);
SET @p_carlos = (SELECT id FROM persons WHERE email = 'carlos.mendoza@demo.test' ORDER BY id LIMIT 1);
SET @p_rosa = (SELECT id FROM persons WHERE email = 'rosa.santos@demo.test' ORDER BY id LIMIT 1);
SET @p_sofia = (SELECT id FROM persons WHERE email = 'sofia.garcia@demo.test' ORDER BY id LIMIT 1);
SET @p_andre = (SELECT id FROM persons WHERE email = 'andre.lopez@demo.test' ORDER BY id LIMIT 1);
SET @p_elena = (SELECT id FROM persons WHERE email = 'elena.ramos@demo.test' ORDER BY id LIMIT 1);

-- Five Baptism entries; Eva's sample entry is inserted only when absent.
INSERT INTO baptismal_records
  (entry_no, baptism_date, baptism_place, child_birthplace, child_full_name,
   child_first_name, child_middle_name, child_last_name, birth_date,
   legitimacy, father_name, mother_name, mother_maiden_name, parents_origin,
   parents_residence, minister_name, remarks, book_no, page_no, line_no, person_id)
SELECT 'DEMO-B-001', '2015-03-15', 'Saint Patrick''s Parish', 'Dingalan, Aurora',
       'Eva Demo Santos', 'Eva', 'Demo', 'Santos', '2014-06-15', 'legitimate',
       'Ramon Santos', 'Maria Cruz', 'Maria Cruz', 'Dingalan, Aurora',
       'Poblacion, Dingalan, Aurora', 'Rev. Fr. Sample Parish',
       'DEMO ONLY: fictional defense walkthrough entry.', 'DEMO-1', '1', '1', @p_eva
WHERE NOT EXISTS (SELECT 1 FROM baptismal_records WHERE entry_no = 'DEMO-B-001');

INSERT INTO baptismal_records
  (entry_no, baptism_date, baptism_place, child_birthplace, child_full_name,
   child_first_name, child_middle_name, child_last_name, birth_date,
   legitimacy, father_name, mother_name, mother_maiden_name, parents_origin,
   parents_residence, minister_name, remarks, book_no, page_no, line_no, person_id)
SELECT 'DEF-B-002', '2013-07-14', 'Saint Patrick''s Parish', 'Dingalan, Aurora',
       'Lia Demo Cruz', 'Lia', 'Demo', 'Cruz', '2013-04-04', 'legitimate',
       'Pedro Cruz', 'Rosa Santos', 'Rosa Santos', 'Dingalan, Aurora',
       'Poblacion, Dingalan, Aurora', 'Rev. Fr. Sample Parish',
       'DEMO ONLY: fictional defense walkthrough entry.', 'DEF-1', '2', '1', @p_lia
WHERE NOT EXISTS (SELECT 1 FROM baptismal_records WHERE entry_no = 'DEF-B-002');

INSERT INTO baptismal_records
  (entry_no, baptism_date, baptism_place, child_birthplace, child_full_name,
   child_first_name, child_middle_name, child_last_name, birth_date,
   legitimacy, father_name, mother_name, mother_maiden_name, parents_origin,
   parents_residence, minister_name, remarks, book_no, page_no, line_no, person_id)
SELECT 'DEF-B-003', '1994-04-17', 'Saint Patrick''s Parish', 'Dingalan, Aurora',
       'Daniel Demo Reyes', 'Daniel', 'Demo', 'Reyes', '1994-01-19', 'legitimate',
       'Jose Reyes', 'Ana Garcia', 'Ana Garcia', 'Dingalan, Aurora',
       'Dingalan, Aurora', 'Rev. Fr. Sample Parish',
       'DEMO ONLY: fictional defense walkthrough entry.', 'DEF-1', '3', '1', @p_daniel
WHERE NOT EXISTS (SELECT 1 FROM baptismal_records WHERE entry_no = 'DEF-B-003');

INSERT INTO baptismal_records
  (entry_no, baptism_date, baptism_place, child_birthplace, child_full_name,
   child_first_name, child_middle_name, child_last_name, birth_date,
   legitimacy, father_name, mother_name, mother_maiden_name, parents_origin,
   parents_residence, minister_name, remarks, book_no, page_no, line_no, person_id)
SELECT 'DEF-B-004', '2012-05-20', 'Saint Patrick''s Parish', 'Dingalan, Aurora',
       'Emma Demo Reyes', 'Emma', 'Demo', 'Reyes', '2012-02-11', 'legitimate',
       'Daniel Reyes', 'Ana Garcia', 'Ana Garcia', 'Dingalan, Aurora',
       'Dingalan, Aurora', 'Rev. Fr. Sample Parish',
       'DEMO ONLY: fictional defense walkthrough entry.', 'DEF-1', '4', '1', @p_emma
WHERE NOT EXISTS (SELECT 1 FROM baptismal_records WHERE entry_no = 'DEF-B-004');

INSERT INTO baptismal_records
  (entry_no, baptism_date, baptism_place, child_birthplace, child_full_name,
   child_first_name, child_middle_name, child_last_name, birth_date,
   legitimacy, father_name, mother_name, mother_maiden_name, parents_origin,
   parents_residence, minister_name, remarks, book_no, page_no, line_no, person_id)
SELECT 'DEF-B-005', '2017-01-15', 'Saint Patrick''s Parish', 'Dingalan, Aurora',
       'Sofia Demo Garcia', 'Sofia', 'Demo', 'Garcia', '2016-09-17', 'legitimate',
       'Miguel Garcia', 'Maria Lopez', 'Maria Lopez', 'Dingalan, Aurora',
       'Dingalan, Aurora', 'Rev. Fr. Sample Parish',
       'DEMO ONLY: fictional defense walkthrough entry.', 'DEF-1', '5', '1', @p_sofia
WHERE NOT EXISTS (SELECT 1 FROM baptismal_records WHERE entry_no = 'DEF-B-005');

SET @r_eva_b = (SELECT id FROM baptismal_records WHERE entry_no = 'DEMO-B-001' ORDER BY id LIMIT 1);
SET @r_lia_b = (SELECT id FROM baptismal_records WHERE entry_no = 'DEF-B-002' ORDER BY id LIMIT 1);
SET @r_daniel_b = (SELECT id FROM baptismal_records WHERE entry_no = 'DEF-B-003' ORDER BY id LIMIT 1);
SET @r_emma_b = (SELECT id FROM baptismal_records WHERE entry_no = 'DEF-B-004' ORDER BY id LIMIT 1);
SET @r_sofia_b = (SELECT id FROM baptismal_records WHERE entry_no = 'DEF-B-005' ORDER BY id LIMIT 1);

INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type)
SELECT r.id, 'Luz Demo Reyes', 'Dingalan, Aurora', 'godmother' FROM baptismal_records r
WHERE r.entry_no = 'DEMO-B-001' AND NOT EXISTS
  (SELECT 1 FROM baptismal_sponsors s WHERE s.baptismal_record_id = r.id AND s.sponsor_name = 'Luz Demo Reyes');
INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type)
SELECT r.id, 'Jose Demo Cruz', 'Dingalan, Aurora', 'godfather' FROM baptismal_records r
WHERE r.entry_no = 'DEMO-B-001' AND NOT EXISTS
  (SELECT 1 FROM baptismal_sponsors s WHERE s.baptismal_record_id = r.id AND s.sponsor_name = 'Jose Demo Cruz');
INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type)
SELECT r.id, CONCAT('Sponsor ', r.entry_no), 'Dingalan, Aurora', 'godmother' FROM baptismal_records r
WHERE r.entry_no IN ('DEF-B-002','DEF-B-003','DEF-B-004','DEF-B-005')
  AND NOT EXISTS (SELECT 1 FROM baptismal_sponsors s
                  WHERE s.baptismal_record_id = r.id AND s.sponsor_name = CONCAT('Sponsor ', r.entry_no));
INSERT INTO baptismal_sponsors (baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type)
SELECT r.id, CONCAT('Sponsor ', r.entry_no, ' II'), 'Dingalan, Aurora', 'godfather' FROM baptismal_records r
WHERE r.entry_no IN ('DEF-B-002','DEF-B-003','DEF-B-004','DEF-B-005')
  AND NOT EXISTS (SELECT 1 FROM baptismal_sponsors s
                  WHERE s.baptismal_record_id = r.id AND s.sponsor_name = CONCAT('Sponsor ', r.entry_no, ' II'));

-- Four First Communion and three Confirmation records reuse those Baptisms.
INSERT INTO first_communion_records
  (entry_no, communion_date, communicant_full_name, communicant_first_name,
   communicant_middle_name, communicant_last_name, domicile, father_name,
   mother_maiden_name, minister_name, baptismal_record_id, remarks,
   book_no, page_no, line_no, person_id)
SELECT 'DEF-FC-001', '2022-05-08', 'Eva Demo Santos', 'Eva', 'Demo', 'Santos',
       'Poblacion, Dingalan, Aurora', 'Ramon Santos', 'Maria Cruz',
       'Rev. Fr. Sample Parish', @r_eva_b, 'DEMO ONLY: fictional register entry.',
       'DEF-FC', '1', '1', @p_eva
WHERE NOT EXISTS (SELECT 1 FROM first_communion_records WHERE entry_no = 'DEF-FC-001');
INSERT INTO first_communion_records
  (entry_no, communion_date, communicant_full_name, communicant_first_name,
   communicant_middle_name, communicant_last_name, domicile, father_name,
   mother_maiden_name, minister_name, baptismal_record_id, remarks,
   book_no, page_no, line_no, person_id)
SELECT 'DEF-FC-002', '2022-05-08', 'Lia Demo Cruz', 'Lia', 'Demo', 'Cruz',
       'Poblacion, Dingalan, Aurora', 'Pedro Cruz', 'Rosa Santos',
       'Rev. Fr. Sample Parish', @r_lia_b, 'DEMO ONLY: fictional register entry.',
       'DEF-FC', '2', '1', @p_lia
WHERE NOT EXISTS (SELECT 1 FROM first_communion_records WHERE entry_no = 'DEF-FC-002');
INSERT INTO first_communion_records
  (entry_no, communion_date, communicant_full_name, communicant_first_name,
   communicant_middle_name, communicant_last_name, domicile, father_name,
   mother_maiden_name, minister_name, baptismal_record_id, remarks,
   book_no, page_no, line_no, person_id)
SELECT 'DEF-FC-003', '2021-05-09', 'Emma Demo Reyes', 'Emma', 'Demo', 'Reyes',
       'Dingalan, Aurora', 'Daniel Reyes', 'Ana Garcia',
       'Rev. Fr. Sample Parish', @r_emma_b, 'DEMO ONLY: fictional register entry.',
       'DEF-FC', '3', '1', @p_emma
WHERE NOT EXISTS (SELECT 1 FROM first_communion_records WHERE entry_no = 'DEF-FC-003');
INSERT INTO first_communion_records
  (entry_no, communion_date, communicant_full_name, communicant_first_name,
   communicant_middle_name, communicant_last_name, domicile, father_name,
   mother_maiden_name, minister_name, baptismal_record_id, remarks,
   book_no, page_no, line_no, person_id)
SELECT 'DEF-FC-004', '2005-05-08', 'Daniel Demo Reyes', 'Daniel', 'Demo', 'Reyes',
       'Dingalan, Aurora', 'Jose Reyes', 'Ana Garcia',
       'Rev. Fr. Sample Parish', @r_daniel_b, 'DEMO ONLY: fictional register entry.',
       'DEF-FC', '4', '1', @p_daniel
WHERE NOT EXISTS (SELECT 1 FROM first_communion_records WHERE entry_no = 'DEF-FC-004');
SET @r_daniel_fc = (SELECT id FROM first_communion_records WHERE entry_no = 'DEF-FC-004' ORDER BY id LIMIT 1);

INSERT INTO confirmation_records
  (entry_no, confirmation_date, confirmand_full_name, confirmand_first_name,
   confirmand_middle_name, confirmand_last_name, birthdate, father_name,
   mother_maiden_name, sponsor_name, minister_name, baptismal_record_id,
   remarks, book_no, page_no, line_no, person_id)
SELECT 'DEF-CONF-001', '2026-07-12', 'Eva Demo Santos', 'Eva', 'Demo', 'Santos',
       '2014-06-15', 'Ramon Santos', 'Maria Cruz', 'Luz Demo Reyes',
       'Most Rev. Sample Bishop', @r_eva_b, 'DEMO ONLY: fictional register entry.',
       'DEF-CONF', '1', '1', @p_eva
WHERE NOT EXISTS (SELECT 1 FROM confirmation_records WHERE entry_no = 'DEF-CONF-001');
INSERT INTO confirmation_records
  (entry_no, confirmation_date, confirmand_full_name, confirmand_first_name,
   confirmand_middle_name, confirmand_last_name, birthdate, father_name,
   mother_maiden_name, sponsor_name, minister_name, baptismal_record_id,
   remarks, book_no, page_no, line_no, person_id)
SELECT 'DEF-CONF-002', '2026-04-20', 'Lia Demo Cruz', 'Lia', 'Demo', 'Cruz',
       '2013-04-04', 'Pedro Cruz', 'Rosa Santos', 'Maria Demo Garcia',
       'Most Rev. Sample Bishop', @r_lia_b, 'DEMO ONLY: fictional register entry.',
       'DEF-CONF', '2', '1', @p_lia
WHERE NOT EXISTS (SELECT 1 FROM confirmation_records WHERE entry_no = 'DEF-CONF-002');
INSERT INTO confirmation_records
  (entry_no, confirmation_date, confirmand_full_name, confirmand_first_name,
   confirmand_middle_name, confirmand_last_name, birthdate, father_name,
   mother_maiden_name, sponsor_name, minister_name, baptismal_record_id,
   remarks, book_no, page_no, line_no, person_id)
SELECT 'DEF-CONF-003', '2010-03-21', 'Daniel Demo Reyes', 'Daniel', 'Demo', 'Reyes',
       '1994-01-19', 'Jose Reyes', 'Ana Garcia', 'Jose Demo Lopez',
       'Most Rev. Sample Bishop', @r_daniel_b, 'DEMO ONLY: fictional register entry.',
       'DEF-CONF', '3', '1', @p_daniel
WHERE NOT EXISTS (SELECT 1 FROM confirmation_records WHERE entry_no = 'DEF-CONF-003');

-- Two linked Marriage entries with principal witnesses.
INSERT INTO marriage_records
  (entry_no, marriage_date, husband_full_name, husband_first_name,
   husband_middle_name, husband_last_name, wife_maiden_name, wife_first_name,
   wife_middle_name, wife_last_name, husband_birthdate, husband_birthplace,
   husband_civil_status, husband_age, husband_origin, husband_residence,
   husband_parents_name, wife_birthdate, wife_birthplace, wife_civil_status,
   wife_age, wife_origin, wife_residence, wife_parents_name,
   officiating_minister, remarks, book_no, page_no, line_no,
   husband_person_id, wife_person_id)
SELECT 'DEF-M-001', '2020-06-20', 'Carlos Demo Mendoza', 'Carlos', 'Demo', 'Mendoza',
       'Santos', 'Rosa', 'Demo', 'Santos', '1995-08-03', 'Dingalan, Aurora',
       'Single', 24, 'Dingalan, Aurora', 'Dingalan, Aurora',
       'Rogelio Mendoza and Elena Santos', '1996-11-22', 'Dingalan, Aurora',
       'Single', 23, 'Dingalan, Aurora', 'Dingalan, Aurora',
       'Felipe Santos and Celia Cruz', 'Rev. Fr. Sample Parish',
       'DEMO ONLY: fictional register entry.', 'DEF-M', '1', '1', @p_carlos, @p_rosa
WHERE NOT EXISTS (SELECT 1 FROM marriage_records WHERE entry_no = 'DEF-M-001');
INSERT INTO marriage_records
  (entry_no, marriage_date, husband_full_name, husband_first_name,
   husband_middle_name, husband_last_name, wife_maiden_name, wife_first_name,
   wife_middle_name, wife_last_name, husband_birthdate, husband_birthplace,
   husband_civil_status, husband_age, husband_origin, husband_residence,
   husband_parents_name, wife_birthdate, wife_birthplace, wife_civil_status,
   wife_age, wife_origin, wife_residence, wife_parents_name,
   officiating_minister, remarks, book_no, page_no, line_no,
   husband_person_id, wife_person_id)
SELECT 'DEF-M-002', '2024-06-22', 'Andre Demo Lopez', 'Andre', 'Demo', 'Lopez',
       'Ramos', 'Elena', 'Demo', 'Ramos', '2001-05-30', 'Dingalan, Aurora',
       'Single', 23, 'Dingalan, Aurora', 'Dingalan, Aurora',
       'Ramon Lopez and Lourdes Reyes', '1992-07-12', 'Dingalan, Aurora',
       'Single', 31, 'Dingalan, Aurora', 'Dingalan, Aurora',
       'Mario Ramos and Carmen Flores', 'Rev. Fr. Sample Parish',
       'DEMO ONLY: fictional register entry.', 'DEF-M', '2', '1', @p_andre, @p_elena
WHERE NOT EXISTS (SELECT 1 FROM marriage_records WHERE entry_no = 'DEF-M-002');

INSERT INTO marriage_witnesses (marriage_record_id, witness_name, witness_residence)
SELECT r.id, 'Luz Demo Reyes', 'Dingalan, Aurora' FROM marriage_records r
WHERE r.entry_no = 'DEF-M-001' AND NOT EXISTS
  (SELECT 1 FROM marriage_witnesses w WHERE w.marriage_record_id = r.id AND w.witness_name = 'Luz Demo Reyes');
INSERT INTO marriage_witnesses (marriage_record_id, witness_name, witness_residence)
SELECT r.id, 'Pedro Demo Cruz', 'Dingalan, Aurora' FROM marriage_records r
WHERE r.entry_no = 'DEF-M-001' AND NOT EXISTS
  (SELECT 1 FROM marriage_witnesses w WHERE w.marriage_record_id = r.id AND w.witness_name = 'Pedro Demo Cruz');
INSERT INTO marriage_witnesses (marriage_record_id, witness_name, witness_residence)
SELECT r.id, 'Ana Demo Garcia', 'Dingalan, Aurora' FROM marriage_records r
WHERE r.entry_no = 'DEF-M-002' AND NOT EXISTS
  (SELECT 1 FROM marriage_witnesses w WHERE w.marriage_record_id = r.id AND w.witness_name = 'Ana Demo Garcia');
INSERT INTO marriage_witnesses (marriage_record_id, witness_name, witness_residence)
SELECT r.id, 'Jose Demo Reyes', 'Dingalan, Aurora' FROM marriage_records r
WHERE r.entry_no = 'DEF-M-002' AND NOT EXISTS
  (SELECT 1 FROM marriage_witnesses w WHERE w.marriage_record_id = r.id AND w.witness_name = 'Jose Demo Reyes');

SET @r_lia_c = (SELECT id FROM confirmation_records WHERE entry_no = 'DEF-CONF-002' ORDER BY id LIMIT 1);
SET @r_carlos_m = (SELECT id FROM marriage_records WHERE entry_no = 'DEF-M-001' ORDER BY id LIMIT 1);

-- Four active applications. Status names deliberately use the application's
-- real enum/workflow rather than the informal labels in the supplied guide.
INSERT INTO sacrament_applications
  (tracking_number, submission_channel, sacrament_type, status, notes)
VALUES
  ('SPPO-APP-2026-BAPDEFENSEABCDEF', 'online', 'baptismal', 'submitted',
   'DEMO ONLY: new-child application; link or create a person after review.'),
  ('SPPO-APP-2026-CNFDEFENSABCDEFG', 'online', 'confirmation', 'approved',
   'DEMO ONLY: eligible application with baptism record and prerequisites complete.'),
  ('SPPO-APP-2026-FCDEFENSABCDEFGH', 'online', 'first_communion', 'scheduled',
   'DEMO ONLY: approved/scheduled application suitable for register prefill.'),
  ('SPPO-APP-2026-MARRDEFENSABCDEF', 'online', 'marriage', 'interview_scheduled',
   'DEMO ONLY: marriage application with two distinct applicants; external documents to review.')
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
SET @a_bap = (SELECT id FROM sacrament_applications WHERE tracking_number = 'SPPO-APP-2026-BAPDEFENSEABCDEF');
SET @a_conf = (SELECT id FROM sacrament_applications WHERE tracking_number = 'SPPO-APP-2026-CNFDEFENSABCDEFG');
SET @a_fc = (SELECT id FROM sacrament_applications WHERE tracking_number = 'SPPO-APP-2026-FCDEFENSABCDEFGH');
SET @a_mar = (SELECT id FROM sacrament_applications WHERE tracking_number = 'SPPO-APP-2026-MARRDEFENSABCDEF');

INSERT INTO baptismal_applications
  (application_id, child_full_name, child_birthdate, child_birthplace,
   father_full_name, mother_maiden_name, parent_guardian_name,
   contact_number, email, preferred_date, notes)
SELECT @a_bap, 'Noah Demo Santos', '2025-02-14', 'Dingalan, Aurora',
       'Ramon Santos', 'Maria Cruz', 'Ramon Santos', '09170000001',
       'noah.family@demo.test', DATE_ADD(CURDATE(), INTERVAL 45 DAY),
       'DEMO ONLY: new child, not yet in the master person index.'
WHERE NOT EXISTS (SELECT 1 FROM baptismal_applications WHERE application_id = @a_bap);

INSERT INTO confirmation_applications
  (application_id, applicant_full_name, applicant_birthdate,
   parent_guardian_name, father_full_name, mother_maiden_name,
   contact_number, email, baptized_here, baptism_date, baptismal_record_id,
   baptismal_cert_status, psa_birth_certificate_status,
   catechetical_formation_status, parental_consent, preferred_batch,
   sponsor_name, age_eligibility_flag, seminar_attended)
SELECT @a_conf, 'Emma Demo Reyes', '2012-02-11', 'Daniel Reyes',
       'Daniel Reyes', 'Ana Garcia', '09170000007', 'emma.reyes@demo.test',
       1, '2012-05-20', @r_emma_b, 'submitted', 'verified', 'completed',
       1, 'DEMO CONFIRMATION BATCH', 'Maria Demo Lopez', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM confirmation_applications WHERE application_id = @a_conf);

INSERT INTO first_communion_applications
  (application_id, applicant_full_name, applicant_birthdate,
   parent_guardian_name, contact_number, email, baptized_here,
   baptismal_record_id, baptismal_cert_status, preferred_batch,
   age_eligibility_flag, seminar_attended)
SELECT @a_fc, 'Sofia Demo Garcia', '2016-09-17', 'Miguel Garcia',
       '09170000010', 'sofia.garcia@demo.test', 1, @r_sofia_b,
       'submitted', 'DEMO FIRST COMMUNION BATCH', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM first_communion_applications WHERE application_id = @a_fc);

INSERT INTO marriage_applications
  (application_id, groom_full_name, groom_birthdate, groom_birthplace,
   groom_current_address, groom_email, groom_father_name, groom_mother_maiden_name,
   groom_marital_status, groom_baptized_here, groom_confirmed,
   groom_baptismal_cert_status, groom_confirmation_cert_status,
   bride_full_name, bride_birthdate, bride_birthplace, bride_current_address,
   bride_email, bride_father_name, bride_mother_maiden_name,
   bride_marital_status, bride_baptized_here, bride_confirmed,
   bride_baptismal_cert_status, bride_confirmation_cert_status,
   preferred_wedding_date, preferred_wedding_time, preferred_wedding_venue,
   groom_cenomar_status, bride_cenomar_status, marriage_license_status,
   pre_cana_seminar_completed, canonical_interview_completed)
SELECT @a_mar, 'Marco Demo Reyes', '1997-04-20', 'Dingalan, Aurora',
       'Dingalan, Aurora', 'marco.reyes@demo.test', 'Jose Reyes', 'Ana Garcia',
       'single', 0, 0, 'submitted', 'submitted',
       'Nina Demo Cruz', '1998-09-08', 'Dingalan, Aurora',
       'Dingalan, Aurora', 'nina.cruz@demo.test', 'Pedro Cruz', 'Rosa Santos',
       'single', 0, 0, 'submitted', 'submitted',
       DATE_ADD(CURDATE(), INTERVAL 120 DAY), '10:00:00', 'church',
       'verified', 'verified', 'pending', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM marriage_applications WHERE application_id = @a_mar);

INSERT INTO baptismal_application_godparents
  (baptismal_application_id, godparent_name, godparent_residence, godparent_type)
SELECT ba.id, 'Luz Demo Reyes', 'Dingalan, Aurora', 'godmother'
FROM baptismal_applications ba
WHERE ba.application_id = @a_bap
  AND NOT EXISTS (SELECT 1 FROM baptismal_application_godparents gp
                  WHERE gp.baptismal_application_id = ba.id AND gp.godparent_name = 'Luz Demo Reyes');
INSERT INTO baptismal_application_godparents
  (baptismal_application_id, godparent_name, godparent_residence, godparent_type)
SELECT ba.id, 'Jose Demo Cruz', 'Dingalan, Aurora', 'godfather'
FROM baptismal_applications ba
WHERE ba.application_id = @a_bap
  AND NOT EXISTS (SELECT 1 FROM baptismal_application_godparents gp
                  WHERE gp.baptismal_application_id = ba.id AND gp.godparent_name = 'Jose Demo Cruz');
INSERT INTO marriage_principal_sponsors (marriage_application_id, sponsor_name, sponsor_residence)
SELECT ma.id, 'Luz Demo Reyes', 'Dingalan, Aurora' FROM marriage_applications ma
WHERE ma.application_id = @a_mar
  AND NOT EXISTS (SELECT 1 FROM marriage_principal_sponsors ps
                  WHERE ps.marriage_application_id = ma.id AND ps.sponsor_name = 'Luz Demo Reyes');
INSERT INTO marriage_principal_sponsors (marriage_application_id, sponsor_name, sponsor_residence)
SELECT ma.id, 'Pedro Demo Cruz', 'Dingalan, Aurora' FROM marriage_applications ma
WHERE ma.application_id = @a_mar
  AND NOT EXISTS (SELECT 1 FROM marriage_principal_sponsors ps
                  WHERE ps.marriage_application_id = ma.id AND ps.sponsor_name = 'Pedro Demo Cruz');

-- Five certificate requests with distinct, real application status values.
INSERT INTO certificate_requests
  (tracking_number, submission_channel, sacrament_type, requester_name,
   requester_relationship, requester_email, requester_contact_number,
   purpose, status, notes, paid_at, match_confirmed_at)
VALUES
  ('SPPO-2026-CERTDEFENSABCDEF', 'online', 'baptismal', 'Eva Demo Santos', 'self',
   'eva.family@demo.test', '09170000004', 'unspecified', 'submitted',
   'DEMO ONLY: initial review; confirm the register match.', NULL, NULL),
  ('SPPO-2026-CERTDEFENSABCDEG', 'online', 'baptismal', 'Lia Demo Cruz', 'sibling',
   'lia.cruz@demo.test', '09170000005', 'unspecified', 'awaiting_payment',
   'DEMO ONLY: representative pickup; review authorization letter.', NULL, NULL),
  ('SPPO-2026-CERTDEFENSABCDEH', 'online', 'confirmation', 'Lia Demo Cruz', 'self',
   'lia.cruz@demo.test', '09170000005', 'unspecified', 'under_review',
   'DEMO ONLY: linked to the Confirmation register entry.', NOW(), NOW()),
  ('SPPO-2026-CERTDEFENSABCDEJ', 'online', 'first_communion', 'Daniel Demo Reyes', 'self',
   'daniel.reyes@demo.test', '09170000006', 'unspecified', 'ready_for_pickup',
   'DEMO ONLY: generated certificate awaiting collection.', NOW(), NOW()),
  ('SPPO-2026-CERTDEFENSABCDEK', 'online', 'marriage', 'Carlos Demo Mendoza', 'self',
   'carlos.mendoza@demo.test', '09170000008', 'unspecified', 'released',
   'DEMO ONLY: sample completed pickup; claimant data below is fictional.', NOW(), NOW())
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
SET @c1 = (SELECT id FROM certificate_requests WHERE tracking_number = 'SPPO-2026-CERTDEFENSABCDEF');
SET @c2 = (SELECT id FROM certificate_requests WHERE tracking_number = 'SPPO-2026-CERTDEFENSABCDEG');
SET @c3 = (SELECT id FROM certificate_requests WHERE tracking_number = 'SPPO-2026-CERTDEFENSABCDEH');
SET @c4 = (SELECT id FROM certificate_requests WHERE tracking_number = 'SPPO-2026-CERTDEFENSABCDEJ');
SET @c5 = (SELECT id FROM certificate_requests WHERE tracking_number = 'SPPO-2026-CERTDEFENSABCDEK');

INSERT INTO baptismal_certificate_requests
  (request_id, child_full_name, father_full_name, mother_full_name, birthplace,
   birthdate, date_of_baptism, officiating_minister, matched_record_id)
SELECT @c1, 'Eva Demo Santos', 'Ramon Santos', 'Maria Cruz', 'Dingalan, Aurora',
       '2014-06-15', '2015-03-15', 'Rev. Fr. Sample Parish', @r_eva_b
WHERE NOT EXISTS (SELECT 1 FROM baptismal_certificate_requests WHERE request_id = @c1);
INSERT INTO baptismal_certificate_requests
  (request_id, child_full_name, father_full_name, mother_full_name, birthplace,
   birthdate, date_of_baptism, officiating_minister, matched_record_id)
SELECT @c2, 'Lia Demo Cruz', 'Pedro Cruz', 'Rosa Santos', 'Dingalan, Aurora',
       '2013-04-04', '2013-07-14', 'Rev. Fr. Sample Parish', @r_lia_b
WHERE NOT EXISTS (SELECT 1 FROM baptismal_certificate_requests WHERE request_id = @c2);
INSERT INTO confirmation_certificate_requests
  (request_id, confirmand_full_name, father_full_name, mother_full_name,
   date_of_confirmation, date_of_baptism, place_of_baptism,
   officiating_minister, sponsor_name, matched_record_id)
SELECT @c3, 'Lia Demo Cruz', 'Pedro Cruz', 'Rosa Santos', '2026-04-20',
       '2013-07-14', 'Saint Patrick''s Parish', 'Most Rev. Sample Bishop',
       'Maria Demo Garcia', @r_lia_c
WHERE NOT EXISTS (SELECT 1 FROM confirmation_certificate_requests WHERE request_id = @c3);
INSERT INTO first_communion_certificate_requests
  (request_id, communicant_full_name, father_full_name, mother_full_name,
   date_of_first_communion, officiating_minister, matched_record_id)
SELECT @c4, 'Daniel Demo Reyes', 'Jose Reyes', 'Ana Garcia',
       '2005-05-08', 'Rev. Fr. Sample Parish', @r_daniel_fc
WHERE NOT EXISTS (SELECT 1 FROM first_communion_certificate_requests WHERE request_id = @c4);
INSERT INTO marriage_certificate_requests
  (request_id, party_a_full_name, party_a_father_name, party_a_mother_name,
   party_b_full_name, party_b_father_name, party_b_mother_name,
   marriage_date, officiating_minister, matched_record_id)
SELECT @c5, 'Carlos Demo Mendoza', 'Rogelio Mendoza', 'Elena Santos',
       'Rosa Demo Santos', 'Felipe Santos', 'Celia Cruz',
       '2020-06-20', 'Rev. Fr. Sample Parish', @r_carlos_m
WHERE NOT EXISTS (SELECT 1 FROM marriage_certificate_requests WHERE request_id = @c5);

-- Idempotent inserts preserve any checklist decisions made during the demo.
INSERT INTO workflow_requirements
  (entity_type, entity_id, requirement_key, requirement_label, status, notes)
SELECT 'certificate_request', @c2, 'authorization_letter',
       'Authorization letter for representative pickup', 'received',
       'DEMO ONLY: fictional document review sample.'
WHERE NOT EXISTS (SELECT 1 FROM workflow_requirements
                  WHERE entity_type = 'certificate_request' AND entity_id = @c2
                    AND requirement_key = 'authorization_letter');
INSERT INTO workflow_requirements
  (entity_type, entity_id, requirement_key, requirement_label, status, notes)
SELECT 'certificate_request', @c4, 'valid_id',
       'Valid government ID matching the requester', 'verified',
       'DEMO ONLY: fictional document review sample.'
WHERE NOT EXISTS (SELECT 1 FROM workflow_requirements
                  WHERE entity_type = 'certificate_request' AND entity_id = @c4
                    AND requirement_key = 'valid_id');
INSERT INTO workflow_requirements
  (entity_type, entity_id, requirement_key, requirement_label, status, notes)
SELECT 'certificate_request', @c4, 'authorization_letter',
       'Authorization letter for representative pickup', 'not_applicable',
       'DEMO ONLY: requester collects their own certificate.'
WHERE NOT EXISTS (SELECT 1 FROM workflow_requirements
                  WHERE entity_type = 'certificate_request' AND entity_id = @c4
                    AND requirement_key = 'authorization_letter');
INSERT INTO workflow_requirements
  (entity_type, entity_id, requirement_key, requirement_label, status, notes)
SELECT 'sacrament_application', @a_mar, 'origin_baptismal_certificate',
       'Origin-parish Baptismal Certificates for both applicants', 'verified',
       'DEMO ONLY: fictional external-parish document review sample.'
WHERE NOT EXISTS (SELECT 1 FROM workflow_requirements
                  WHERE entity_type = 'sacrament_application' AND entity_id = @a_mar
                    AND requirement_key = 'origin_baptismal_certificate');
INSERT INTO workflow_requirements
  (entity_type, entity_id, requirement_key, requirement_label, status, notes)
SELECT 'sacrament_application', @a_mar, 'psa_cenomar',
       'PSA CENOMAR for both marriage applicants', 'verified',
       'DEMO ONLY: fictional document review sample.'
WHERE NOT EXISTS (SELECT 1 FROM workflow_requirements
                  WHERE entity_type = 'sacrament_application' AND entity_id = @a_mar
                    AND requirement_key = 'psa_cenomar');

-- Six upcoming schedule entries. Dates remain in the future when rerun.
INSERT INTO schedule_events (event_type, title, public_details, event_date, start_time, end_time, notes)
SELECT 'baptism', 'DEMO: Community Baptism', 'Sample parish baptism schedule.',
       DATE_ADD(CURDATE(), INTERVAL 14 DAY), '09:00:00', '11:00:00', 'DEMO ONLY: fictional calendar entry.'
WHERE NOT EXISTS (SELECT 1 FROM schedule_events WHERE title = 'DEMO: Community Baptism');
INSERT INTO schedule_events (event_type, title, public_details, event_date, start_time, end_time, notes)
SELECT 'other', 'DEMO: First Communion Preparation', 'Sample catechetical preparation session.',
       DATE_ADD(CURDATE(), INTERVAL 21 DAY), '09:00:00', '12:00:00', 'DEMO ONLY: fictional calendar entry.'
WHERE NOT EXISTS (SELECT 1 FROM schedule_events WHERE title = 'DEMO: First Communion Preparation');
INSERT INTO schedule_events (event_type, title, public_details, event_date, start_time, end_time, notes)
SELECT 'other', 'DEMO: Confirmation Batch', 'Sample confirmation preparation and schedule.',
       DATE_ADD(CURDATE(), INTERVAL 30 DAY), '08:30:00', '12:00:00', 'DEMO ONLY: fictional calendar entry.'
WHERE NOT EXISTS (SELECT 1 FROM schedule_events WHERE title = 'DEMO: Confirmation Batch');
INSERT INTO schedule_events (event_type, title, public_details, event_date, start_time, end_time, related_request_type, related_request_id, notes)
SELECT 'wedding', 'DEMO: Pre-Cana Seminar', 'Sample marriage preparation seminar.',
       DATE_ADD(CURDATE(), INTERVAL 45 DAY), '08:00:00', '16:00:00',
       'marriage_application', @a_mar, 'DEMO ONLY: fictional calendar entry.'
WHERE NOT EXISTS (SELECT 1 FROM schedule_events WHERE title = 'DEMO: Pre-Cana Seminar');
INSERT INTO schedule_events (event_type, title, public_details, event_date, start_time, end_time, related_request_type, related_request_id, notes)
SELECT 'wedding', 'DEMO: Wedding of Marco Reyes and Nina Cruz', 'Sample marriage schedule.',
       DATE_ADD(CURDATE(), INTERVAL 120 DAY), '10:00:00', '11:00:00',
       'marriage_application', @a_mar, 'DEMO ONLY: fictional calendar entry.'
WHERE NOT EXISTS (SELECT 1 FROM schedule_events WHERE title = 'DEMO: Wedding of Marco Reyes and Nina Cruz');
INSERT INTO schedule_events (event_type, title, public_details, event_date, start_time, end_time, notes)
SELECT 'other', 'DEMO: Parish Feast Planning', 'Sample parish planning meeting.',
       DATE_ADD(CURDATE(), INTERVAL 60 DAY), '14:00:00', '15:30:00', 'DEMO ONLY: fictional calendar entry.'
WHERE NOT EXISTS (SELECT 1 FROM schedule_events WHERE title = 'DEMO: Parish Feast Planning');

-- Public content is added only when absent; existing parish copy is retained.
INSERT INTO announcements (title, content, event_date, is_published)
SELECT 'DEMO: Sacrament Schedule', 'Fictional demo announcement: contact the parish office to confirm current schedules.',
       DATE_ADD(CURDATE(), INTERVAL 14 DAY), 1
WHERE NOT EXISTS (SELECT 1 FROM announcements WHERE title = 'DEMO: Sacrament Schedule');
INSERT INTO announcements (title, content, event_date, is_published)
SELECT 'DEMO: Parish Office Hours', 'Fictional demo announcement: office hours shown here are for demonstration only.',
       DATE_ADD(CURDATE(), INTERVAL 7 DAY), 1
WHERE NOT EXISTS (SELECT 1 FROM announcements WHERE title = 'DEMO: Parish Office Hours');
INSERT INTO announcements (title, content, event_date, is_published)
SELECT 'DEMO: Confirmation Preparation', 'Fictional demo announcement: please consult parish staff for actual requirements.',
       DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1
WHERE NOT EXISTS (SELECT 1 FROM announcements WHERE title = 'DEMO: Confirmation Preparation');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'hero_eyebrow_text', 'Parish Office Online'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'hero_eyebrow_text');
INSERT INTO site_settings (setting_key, setting_value)
SELECT 'hero_subtitle_text', 'Dingalan, Aurora - parish records and certificates, easier to reach.'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'hero_subtitle_text');
INSERT INTO site_settings (setting_key, setting_value)
SELECT 'office_hours_text', 'Monday - Saturday: 8:00 AM - 12:00 NN; 2:00 PM - 5:00 PM; Sunday: Closed'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'office_hours_text');
INSERT INTO site_settings (setting_key, setting_value)
SELECT 'office_location_text', 'St. Patrick''s Parish Office, Dingalan, Aurora, Philippines'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key = 'office_location_text');

-- Demonstration-only audit rows are explicitly not represented as real
-- staff activity. Actual actions performed during the walkthrough are logged
-- by the application using the signed-in staff account.
INSERT INTO audit_logs (action, target_table, target_id, description)
SELECT 'demo_seed_sample', 'persons', @p_eva,
       'DEMO ONLY: illustrative audit-history row; no staff action occurred.'
WHERE NOT EXISTS (SELECT 1 FROM audit_logs WHERE action = 'demo_seed_sample'
                  AND target_table = 'persons' AND target_id = @p_eva);
INSERT INTO audit_logs (action, target_table, target_id, description)
SELECT 'demo_seed_sample', 'sacrament_applications', @a_mar,
       'DEMO ONLY: illustrative audit-history row; no staff action occurred.'
WHERE NOT EXISTS (SELECT 1 FROM audit_logs WHERE action = 'demo_seed_sample'
                  AND target_table = 'sacrament_applications' AND target_id = @a_mar);
INSERT INTO audit_logs (action, target_table, target_id, description)
SELECT 'demo_seed_sample', 'certificate_requests', @c5,
       'DEMO ONLY: illustrative audit-history row; no staff action occurred.'
WHERE NOT EXISTS (SELECT 1 FROM audit_logs WHERE action = 'demo_seed_sample'
                  AND target_table = 'certificate_requests' AND target_id = @c5);

-- Generation/pickup history for ready and completed sample requests.
INSERT INTO certificate_generation_log
  (certificate_request_id, generated_at, printed_at, print_status)
SELECT @c4, NOW(), NOW(), 'printed'
WHERE NOT EXISTS (SELECT 1 FROM certificate_generation_log
                  WHERE certificate_request_id = @c4);
INSERT INTO certificate_generation_log
  (certificate_request_id, generated_at, printed_at, print_status,
   pickup_date, claimant_name, claimant_relationship, claimant_notes, id_verified)
SELECT @c5, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY),
       'released', DATE_SUB(NOW(), INTERVAL 1 DAY), 'Carlos Demo Mendoza',
       'Self', 'DEMO ONLY: fictional completed-pickup sample.', 1
WHERE NOT EXISTS (SELECT 1 FROM certificate_generation_log
                  WHERE certificate_request_id = @c5);

COMMIT;

-- Quick reference: SPPO-2026-CERTDEFENSABCDEJ is ready for pickup;
-- SPPO-2026-CERTDEFENSABCDEK is already released. Eva Demo Santos is born
-- 2014-06-15. No staff accounts or credentials are created.
