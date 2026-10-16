-- =====================================================================
-- SRS-SPPO DEMO / SEED DATA
-- =====================================================================
-- Rebuilds a coherent local demo dataset with 21 rows for every supported
-- sacrament type. Each numbered person is linked across Baptismal,
-- Confirmation, and First Communion records, requests, and applications.
-- Marriage records are included; all supported workflows are seeded.
--
-- Create the first administrator separately with scripts/create-admin.php.
-- =====================================================================

USE srs_sppo_db;

-- =====================================================================
-- 1. RESET THE DEMO DATASET
-- =====================================================================
DELETE FROM certificate_generation_log;
DELETE FROM baptismal_certificate_requests;
DELETE FROM confirmation_certificate_requests;
DELETE FROM first_communion_certificate_requests;
DELETE FROM marriage_certificate_requests;
DELETE FROM certificate_requests;

DELETE FROM baptismal_application_godparents;
DELETE FROM marriage_principal_sponsors;
DELETE FROM baptismal_applications;
DELETE FROM confirmation_applications;
DELETE FROM first_communion_applications;
DELETE FROM marriage_applications;
DELETE FROM sacrament_applications;

DELETE FROM baptismal_sponsors;
DELETE FROM marriage_witnesses;
DELETE FROM confirmation_records;
DELETE FROM first_communion_records;
DELETE FROM marriage_records;
DELETE FROM baptismal_records;
DELETE FROM persons;
DELETE FROM audit_logs WHERE action = 'demo_seed';

-- =====================================================================
-- 2. GENERATE 21 ROWS FOR EVERY SACRAMENT TYPE
-- =====================================================================
DELIMITER $$

CREATE PROCEDURE seed_demo_rows()
BEGIN
  DECLARE i INT DEFAULT 1;
  DECLARE baptism_request_id INT;
  DECLARE confirmation_request_id INT;
  DECLARE communion_request_id INT;
  DECLARE marriage_request_id INT;
  DECLARE baptism_application_id INT;
  DECLARE confirmation_application_id INT;
  DECLARE communion_application_id INT;
  DECLARE marriage_application_id INT;
  DECLARE person_name VARCHAR(150);
  DECLARE parent_name VARCHAR(150);
  DECLARE husband_name VARCHAR(150);
  DECLARE wife_name VARCHAR(150);
  DECLARE person_birthdate DATE;
  DECLARE baptism_date DATE;
  DECLARE communion_date DATE;
  DECLARE confirmation_date DATE;
  DECLARE marriage_date DATE;

  WHILE i <= 21 DO
    SET person_name = ELT(i,
      'Mateo Rafael De Guzman', 'Sofia Isabel Reyes', 'Lucas Gabriel Fernandez',
      'Cassandra Marie Gatchalian', 'Jonas Rafael Aquino', 'Gianna Louise Navarro',
      'Rafael Miguel Bautista', 'Amelia Grace Villanueva', 'Nathaniel Jose Ramos',
      'Clara Beatriz Santos', 'Andrei Paolo Mercado', 'Isabella Mae Flores',
      'Daniel Xavier Cruz', 'Therese Anne Mendoza', 'Gabriel Luis Castillo',
      'Bianca Nicole Herrera', 'Enzo Martin Dela Rosa', 'Patricia Joy Lim',
      'Samuel Andre Navarro', 'Mikaela Rose Torres', 'Adrian Carlo Santiago');
    SET parent_name = ELT(i,
      'Elena De Guzman', 'Karen Reyes', 'Ana Fernandez', 'Lourdes Gatchalian',
      'Fe Aquino', 'Marites Navarro', 'Ramon Bautista', 'Grace Villanueva',
      'Mercy Ramos', 'Teresa Santos', 'Liza Mercado', 'Joy Flores',
      'Maria Cruz', 'Anne Mendoza', 'Cecilia Castillo', 'Nina Herrera',
      'Rosa Dela Rosa', 'Carla Lim', 'Helen Navarro', 'Teresa Torres',
      'Lorna Santiago');
    SET husband_name = ELT(i,
      'Juan Miguel Dela Cruz', 'Paolo Angelo Ramirez', 'Marco Antonio Bautista',
      'Luis Enrique Santos', 'Daniel Carlo Reyes', 'Ramon Felipe Aquino',
      'Jose Manuel Villanueva', 'Victor Andres Flores', 'Miguel Rafael Cruz',
      'Antonio Gabriel Mendoza', 'Francisco Luis Mercado', 'Benjamin Carlo Torres',
      'Noel Patrick Castillo', 'Eduardo Miguel Herrera', 'Ricardo Jose Lim',
      'Andres Mateo Navarro', 'Carlos Daniel Santiago', 'Roberto Luis Ramos',
      'Gabriel Tomas Fernandez', 'Fernando Paolo Gatchalian', 'Hector Juan Flores');
    SET wife_name = ELT(i,
      'Maria Teresa Santos', 'Katrina Mae Lopez', 'Camille Joyce Garcia',
      'Angela Marie Cruz', 'Samantha Rose Aquino', 'Beatrice Louise Navarro',
      'Patricia Anne Reyes', 'Juliana Grace Ramos', 'Monica Isabel Flores',
      'Elena Sofia Mendoza', 'Carla Marie Mercado', 'Rosa Mae Torres',
      'Diana Nicole Castillo', 'Lourdes Anne Herrera', 'Teresa Joy Lim',
      'Marissa Claire Santos', 'Andrea Mae Dela Rosa', 'Catherine Rose Aquino',
      'Bianca Faith Navarro', 'Michelle Anne Santiago', 'Laura Isabel Bautista');
    SET person_birthdate = DATE_ADD('2014-01-15', INTERVAL i MONTH);
    SET baptism_date = DATE_ADD('2015-02-15', INTERVAL i MONTH);
    SET communion_date = DATE_ADD('2026-03-14', INTERVAL i DAY);
    SET confirmation_date = DATE_ADD('2026-05-25', INTERVAL i DAY);
    SET marriage_date = DATE_ADD('2025-12-01', INTERVAL i DAY);

    INSERT INTO persons
      (id, first_name, middle_name, last_name, sex, birth_date, birth_place,
       address, baptismal_status)
    VALUES
      (i, person_name, NULL, NULL, 'male', person_birthdate, 'Dingalan, Aurora',
       CONCAT('Brgy. Poblacion, Dingalan, Aurora, House ', i), 'baptized_catholic');

    INSERT INTO persons
      (id, first_name, last_name, sex, birth_date, birth_place, baptismal_status)
    VALUES
      (21 + i, husband_name, NULL, 'male', DATE_ADD('1990-03-15', INTERVAL i MONTH),
       'Gapan, Nueva Ecija', 'baptized_catholic'),
      (42 + i, wife_name, NULL, 'female', DATE_ADD('1993-01-07', INTERVAL i MONTH),
       'Dingalan, Aurora', 'baptized_catholic');

    INSERT INTO baptismal_records
      (id, entry_no, baptism_date, child_full_name, birth_date, legitimacy,
       father_name, mother_name, parents_origin, parents_residence,
       minister_name, remarks, book_no, page_no, line_no, encoded_by, person_id)
    VALUES
      (i, CONCAT('B-2015-', LPAD(i, 3, '0')), baptism_date, person_name,
       person_birthdate, 'legitimate', CONCAT('Ramon A. ', LPAD(i, 2, '0'), ' De Guzman'),
       parent_name, 'Dingalan, Aurora', CONCAT('Brgy. Poblacion, Dingalan, Aurora, House ', i),
       'Rev. Fr. Jose M. Villanueva', 'Connected demo baptismal record.',
       'B-1', LPAD(i + 10, 2, '0'), '1', NULL, i);

    INSERT INTO baptismal_sponsors
      (id, baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type)
    VALUES
      (i, i, CONCAT('Antonio V. ', LPAD(i, 2, '0'), ' Cruz'), 'Dingalan, Aurora', 'godfather');

    INSERT INTO confirmation_records
      (id, entry_no, confirmation_date, confirmand_full_name, birthdate,
       father_name, mother_maiden_name, sponsor_name, minister_name, baptismal_record_id,
       remarks, book_no, page_no, line_no, encoded_by, person_id)
    VALUES
      (i, CONCAT('C-2026-', LPAD(i, 3, '0')), confirmation_date, person_name,
       person_birthdate, CONCAT('Ramon A. ', LPAD(i, 2, '0'), ' De Guzman'), parent_name,
       CONCAT('Veronica D. ', LPAD(i, 2, '0'), ' Gatchalian'),
       'Most Rev. Reynaldo G. Evangelista, D.D.', i,
       'Linked to the corresponding baptismal record.', 'C-2', LPAD(i + 6, 2, '0'), '2', NULL, i);

    INSERT INTO first_communion_records
      (id, entry_no, communion_date, communicant_full_name,
       father_name, mother_maiden_name, minister_name, baptismal_record_id,
       remarks, book_no, page_no, line_no, encoded_by, person_id)
    VALUES
      (i, CONCAT('FC-2026-', LPAD(i, 3, '0')), communion_date, person_name,
       CONCAT('Ramon A. ', LPAD(i, 2, '0'), ' De Guzman'), parent_name,
       'Rev. Fr. Jose M. Villanueva', i, 'Linked to the corresponding baptismal record.',
       'FC-1', LPAD(i + 4, 2, '0'), '1', NULL, i);

    INSERT INTO marriage_records
      (id, entry_no, marriage_date, husband_full_name, wife_maiden_name,
       husband_birthdate, husband_birthplace, wife_birthdate, wife_birthplace,
      officiating_minister, remarks, book_no, page_no, line_no, encoded_by,
      husband_person_id, wife_person_id)
    VALUES
      (i, CONCAT('M-2025-', LPAD(i, 3, '0')), marriage_date,
      husband_name, wife_name,
       DATE_ADD('1990-03-15', INTERVAL i MONTH), 'Gapan, Nueva Ecija',
       DATE_ADD('1993-01-07', INTERVAL i MONTH), 'Dingalan, Aurora',
       'Rev. Fr. Jose M. Villanueva', 'Connected demo marriage record.',
      'M-3', LPAD(i + 17, 2, '0'), '1', NULL, 21 + i, 42 + i);

    INSERT INTO marriage_witnesses
      (id, marriage_record_id, witness_name, witness_residence)
    VALUES
      (i, i, CONCAT('Ramon Witness ', LPAD(i, 2, '0')), 'Dingalan, Aurora');

    SET baptism_request_id = i;
    SET confirmation_request_id = 21 + i;
    SET communion_request_id = 42 + i;
    SET marriage_request_id = 63 + i;

    INSERT INTO certificate_requests
      (id, tracking_number, sacrament_type, requester_name, requester_relationship,
       requester_email, requester_contact_number, purpose, notes,
       status, date_submitted, date_processed, processed_by)
    VALUES
      (baptism_request_id, CONCAT('SPPO-2026-', LPAD(i, 6, '0')), 'baptismal', parent_name,
       'parent', CONCAT('family', LPAD(i, 2, '0'), '@example.com'), CONCAT('0917 111 ', LPAD(i, 4, '0')),
       'school', 'Matched to the baptismal register record.',
       IF(MOD(i, 3) = 0, 'released', IF(MOD(i, 3) = 1, 'pending', 'approved')),
       DATE_ADD('2026-07-01 09:00:00', INTERVAL i DAY),
       IF(MOD(i, 3) = 1, NULL, DATE_ADD('2026-07-03 10:00:00', INTERVAL i DAY)), NULL),
      (confirmation_request_id, CONCAT('SPPO-2026-', LPAD(21 + i, 6, '0')), 'confirmation', parent_name,
       'parent', CONCAT('family', LPAD(i, 2, '0'), '@example.com'), CONCAT('0917 222 ', LPAD(i, 4, '0')),
       'school', 'Matched to the confirmation register record.',
       IF(MOD(i, 3) = 0, 'ready_for_pickup', IF(MOD(i, 3) = 1, 'under_review', 'approved')),
       DATE_ADD('2026-07-10 09:00:00', INTERVAL i DAY),
       IF(MOD(i, 3) = 1, NULL, DATE_ADD('2026-07-12 10:00:00', INTERVAL i DAY)), NULL),
      (communion_request_id, CONCAT('SPPO-2026-', LPAD(42 + i, 6, '0')), 'first_communion', parent_name,
       'parent', CONCAT('family', LPAD(i, 2, '0'), '@example.com'), CONCAT('0917 333 ', LPAD(i, 4, '0')),
       'school', 'Matched to the First Communion register record.',
       IF(MOD(i, 3) = 0, 'released', IF(MOD(i, 3) = 1, 'pending', 'under_review')),
       DATE_ADD('2026-08-01 09:00:00', INTERVAL i DAY),
      IF(MOD(i, 3) = 1, NULL, DATE_ADD('2026-08-03 10:00:00', INTERVAL i DAY)), NULL),
          (marriage_request_id, CONCAT('SPPO-2026-', LPAD(63 + i, 6, '0')), 'marriage',
      husband_name, 'self',
      CONCAT('couple', LPAD(i, 2, '0'), '@example.com'), CONCAT('0920 444 ', LPAD(i, 4, '0')),
      'civil_registration', 'Matched to the marriage register record.',
      IF(MOD(i, 3) = 0, 'released', IF(MOD(i, 3) = 1, 'pending', 'approved')),
      DATE_ADD('2026-06-01 09:00:00', INTERVAL i DAY),
      IF(MOD(i, 3) = 1, NULL, DATE_ADD('2026-06-03 10:00:00', INTERVAL i DAY)), NULL);

    INSERT INTO baptismal_certificate_requests
      (id, request_id, child_full_name, father_full_name, mother_full_name,
       birthplace, birthdate, date_of_baptism, officiating_minister,
       sponsors_text, book_no, page_no, line_no, matched_record_id)
    VALUES
      (i, baptism_request_id, person_name, CONCAT('Ramon A. ', LPAD(i, 2, '0'), ' De Guzman'), parent_name,
       'Dingalan, Aurora', person_birthdate, baptism_date, 'Rev. Fr. Jose M. Villanueva',
       CONCAT('Antonio V. ', LPAD(i, 2, '0'), ' Cruz'), 'B-1', LPAD(i + 10, 2, '0'), '1', i);

    INSERT INTO confirmation_certificate_requests
      (id, request_id, confirmand_full_name, father_full_name, mother_full_name,
       date_of_confirmation, officiating_minister, sponsor_name,
       book_no, page_no, line_no, matched_record_id)
    VALUES
      (i, confirmation_request_id, person_name, CONCAT('Ramon A. ', LPAD(i, 2, '0'), ' De Guzman'), parent_name,
       confirmation_date, 'Most Rev. Reynaldo G. Evangelista, D.D.',
       CONCAT('Veronica D. ', LPAD(i, 2, '0'), ' Gatchalian'), 'C-2', LPAD(i + 6, 2, '0'), '2', i);

    INSERT INTO first_communion_certificate_requests
      (id, request_id, communicant_full_name, father_full_name, mother_full_name,
       date_of_first_communion, officiating_minister,
       book_no, page_no, line_no, matched_record_id)
    VALUES
      (i, communion_request_id, person_name, CONCAT('Ramon A. ', LPAD(i, 2, '0'), ' De Guzman'), parent_name,
       communion_date, 'Rev. Fr. Jose M. Villanueva', 'FC-1', LPAD(i + 4, 2, '0'), '1', i);

    INSERT INTO marriage_certificate_requests
      (id, request_id, party_a_full_name, party_a_father_name, party_a_mother_name,
       party_a_baptized_in_church_of, party_a_date_of_baptism,
       party_a_book_no, party_a_page_no, party_a_line_no,
       party_b_full_name, party_b_father_name, party_b_mother_name,
       party_b_baptized_in_church_of, party_b_date_of_baptism,
       party_b_book_no, party_b_page_no, party_b_line_no,
       marriage_date, officiating_minister, matched_record_id)
    VALUES
      (i, marriage_request_id, husband_name,
       'Ramon Dela Cruz', 'Lucia Dela Cruz', 'Saint Patrick''s Parish', baptism_date,
       'B-1', LPAD(i + 10, 2, '0'), '1',
      wife_name,
       'Pedro Santos', 'Rosa Santos', 'Saint Patrick''s Parish', baptism_date,
       'B-1', LPAD(i + 10, 2, '0'), '1', marriage_date,
       'Rev. Fr. Jose M. Villanueva', i);

    SET baptism_application_id = i;
    SET confirmation_application_id = 21 + i;
    SET communion_application_id = 42 + i;
    SET marriage_application_id = 63 + i;

    INSERT INTO sacrament_applications
      (id, tracking_number, sacrament_type, status, date_submitted, date_processed,
       processed_by, notes)
    VALUES
      (baptism_application_id, CONCAT('SPPO-APP-2026-', LPAD(i, 6, '0')), 'baptismal', 'completed',
       DATE_ADD('2015-01-01 09:30:00', INTERVAL i DAY), DATE_ADD('2015-02-01 10:00:00', INTERVAL i DAY),
       NULL, 'Connected baptismal application.'),
      (confirmation_application_id, CONCAT('SPPO-APP-2026-', LPAD(21 + i, 6, '0')), 'confirmation', 'completed',
       DATE_ADD('2026-04-01 09:30:00', INTERVAL i DAY), DATE_ADD('2026-05-01 10:00:00', INTERVAL i DAY),
       NULL, 'Connected confirmation application.'),
      (communion_application_id, CONCAT('SPPO-APP-2026-', LPAD(42 + i, 6, '0')), 'first_communion', 'completed',
       DATE_ADD('2026-02-01 09:30:00', INTERVAL i DAY), DATE_ADD('2026-03-01 10:00:00', INTERVAL i DAY),
        NULL, 'Connected First Communion application.'),
            (marriage_application_id, CONCAT('SPPO-APP-2026-', LPAD(63 + i, 6, '0')), 'marriage', 'completed',
        DATE_ADD('2025-08-01 09:30:00', INTERVAL i DAY), DATE_ADD('2025-12-01 10:00:00', INTERVAL i DAY),
        NULL, 'Connected marriage application.');

    INSERT INTO baptismal_applications
      (id, application_id, child_full_name, child_birthdate, child_birthplace,
       father_full_name, mother_maiden_name, parent_guardian_name,
       contact_number, email, preferred_date, baptism_date, certificate_done, notes)
    VALUES
      (i, baptism_application_id, person_name, person_birthdate, 'Dingalan, Aurora',
       CONCAT('Ramon A. ', LPAD(i, 2, '0'), ' De Guzman'), parent_name, parent_name,
       CONCAT('0917 444 ', LPAD(i, 4, '0')), CONCAT('family', LPAD(i, 2, '0'), '@example.com'),
       baptism_date, baptism_date, 1, 'Application connected to the baptismal record.');

    INSERT INTO confirmation_applications
      (id, application_id, applicant_full_name, applicant_birthdate,
       parent_guardian_name, contact_number, email, baptized_here,
       baptismal_record_id, baptismal_cert_status, preferred_batch,
       sponsor_name, age_eligibility_flag)
    VALUES
      (i, confirmation_application_id, person_name, person_birthdate, parent_name,
       CONCAT('0917 555 ', LPAD(i, 4, '0')), CONCAT('family', LPAD(i, 2, '0'), '@example.com'),
       1, i, 'submitted', CONCAT('Batch - May 2026 #', i),
       CONCAT('Veronica D. ', LPAD(i, 2, '0'), ' Gatchalian'), 1);

    INSERT INTO first_communion_applications
      (id, application_id, applicant_full_name, applicant_birthdate,
       parent_guardian_name, contact_number, email, baptized_here,
       baptismal_record_id, baptismal_cert_status, preferred_batch,
       age_eligibility_flag)
    VALUES
      (i, communion_application_id, person_name, person_birthdate, parent_name,
       CONCAT('0917 666 ', LPAD(i, 4, '0')), CONCAT('family', LPAD(i, 2, '0'), '@example.com'),
       1, i, 'submitted', CONCAT('Batch - March 2026 #', i), 1);

    INSERT INTO marriage_applications
      (id, application_id, groom_full_name, groom_birthdate, groom_birthplace,
       groom_contact_number, groom_email, groom_marital_status, groom_baptized_here,
       groom_baptismal_cert_status, groom_confirmation_cert_status,
       bride_full_name, bride_birthdate, bride_birthplace, bride_contact_number,
       bride_email, bride_marital_status, bride_baptized_here,
       bride_baptismal_cert_status, bride_confirmation_cert_status,
       preferred_wedding_date, preferred_wedding_time, preferred_wedding_venue,
       marriage_license_ref, pre_cana_seminar_completed, pre_cana_completion_date,
       canonical_interview_completed, canonical_interview_date,
       banns_date_1, banns_date_2, banns_date_3, assigned_priest_name)
    VALUES
      (i, marriage_application_id, husband_name,
       DATE_ADD('1990-03-15', INTERVAL i MONTH), 'Gapan, Nueva Ecija',
       CONCAT('0920 555 ', LPAD(i, 4, '0')), CONCAT('couple', LPAD(i, 2, '0'), '@example.com'),
       'single', 1, 'submitted', 'submitted',
      wife_name,
       DATE_ADD('1993-01-07', INTERVAL i MONTH), 'Dingalan, Aurora',
       CONCAT('0920 666 ', LPAD(i, 4, '0')), CONCAT('couple', LPAD(i, 2, '0'), '@example.com'),
       'single', 1, 'submitted', 'submitted',
       marriage_date, '15:00:00', 'church', CONCAT('ML-2025-', LPAD(i, 4, '0')),
       1, DATE_ADD('2025-09-01', INTERVAL i DAY), 1, DATE_ADD('2025-10-01', INTERVAL i DAY),
       DATE_ADD('2025-10-10', INTERVAL i DAY), DATE_ADD('2025-10-17', INTERVAL i DAY),
       DATE_ADD('2025-10-24', INTERVAL i DAY), 'Rev. Fr. Jose M. Villanueva');

    SET i = i + 1;
  END WHILE;
END$$

DELIMITER ;
CALL seed_demo_rows();
DROP PROCEDURE seed_demo_rows;

-- All supported record types are seeded.

-- =====================================================================
-- 4. AUDIT CONTEXT
-- =====================================================================
INSERT INTO audit_logs
  (user_id, action, target_table, target_id, description, created_at)
VALUES
  (NULL, 'demo_seed', 'baptismal_records', 1,
   'Demo dataset reset: 21 people linked across baptism, confirmation, and First Communion.',
   '2026-09-10 09:00:00');
