-- =====================================================================
-- SRS-SPPO QUICK PROCESS DEMO DATA
-- =====================================================================
-- Adds a small, repeatable set of fictional sample records without
-- deleting or replacing existing records. All sample names and email
-- addresses use an obvious DEMO label / reserved .test domain.
--
-- Run after importing the current canonical schema. For existing databases,
-- verify required schema updates are complete, including migrations
-- 039_master_person_profile.sql and
-- 045_link_sacrament_applications_to_persons.sql.
-- Safe to run again: existing demo rows keep their current status. Use only
-- on a local/demo database, never on a production parish database.
-- Do not use the full reset seed_demo_data.sql for a populated database.
-- =====================================================================

USE srs_sppo_db;

START TRANSACTION;

-- Master profiles: Eva already has a baptismal register entry; both
-- marriage applicants have profiles for the two-person matching exercise.
SET @demo_eva_id = (
  SELECT id FROM persons WHERE email = 'eva.santos@demo.test' ORDER BY id LIMIT 1
);
INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, address, email, baptismal_status)
SELECT 'Eva', 'Demo', 'Santos', 'female', '2014-06-15', 'Dingalan, Aurora',
       'Ramon Santos', 'Maria Cruz', 'Poblacion, Dingalan, Aurora',
       'eva.santos@demo.test', 'baptized_catholic'
WHERE @demo_eva_id IS NULL;
SET @demo_eva_id = (
  SELECT id FROM persons WHERE email = 'eva.santos@demo.test' ORDER BY id LIMIT 1
);

SET @demo_marco_id = (
  SELECT id FROM persons WHERE email = 'marco.reyes@demo.test' ORDER BY id LIMIT 1
);
INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, email, baptismal_status)
SELECT 'Marco', 'Demo', 'Reyes', 'male', '1997-04-20', 'Dingalan, Aurora',
       'Jose Reyes', 'Ana Garcia', 'marco.reyes@demo.test', 'baptized_catholic'
WHERE @demo_marco_id IS NULL;
SET @demo_marco_id = (
  SELECT id FROM persons WHERE email = 'marco.reyes@demo.test' ORDER BY id LIMIT 1
);

SET @demo_nina_id = (
  SELECT id FROM persons WHERE email = 'nina.cruz@demo.test' ORDER BY id LIMIT 1
);
INSERT INTO persons
  (first_name, middle_name, last_name, sex, birth_date, birth_place,
   father_name, mother_maiden_name, email, baptismal_status)
SELECT 'Nina', 'Demo', 'Cruz', 'female', '1998-09-08', 'Dingalan, Aurora',
       'Pedro Cruz', 'Rosa Santos', 'nina.cruz@demo.test', 'baptized_catholic'
WHERE @demo_nina_id IS NULL;
SET @demo_nina_id = (
  SELECT id FROM persons WHERE email = 'nina.cruz@demo.test' ORDER BY id LIMIT 1
);

-- One official register entry makes Eva's master profile and profile actions
-- easy to demonstrate. Other sacraments remain missing so applications can
-- be matched to the same person.
SET @demo_eva_baptism_id = (
  SELECT id FROM baptismal_records
  WHERE person_id = @demo_eva_id AND entry_no = 'DEMO-B-001'
  ORDER BY id LIMIT 1
);
INSERT INTO baptismal_records
  (entry_no, baptism_date, baptism_place, child_birthplace, child_full_name,
   child_first_name, child_middle_name, child_last_name, birth_date,
   legitimacy, father_name, mother_name, mother_maiden_name, parents_origin,
   parents_residence, minister_name, remarks, book_no, page_no, line_no, person_id)
SELECT 'DEMO-B-001', '2015-03-15', 'Saint Patrick''s Parish',
       'Dingalan, Aurora', 'Eva Demo Santos', 'Eva', 'Demo', 'Santos',
       '2014-06-15', 'legitimate', 'Ramon Santos', 'Maria Cruz', 'Maria Cruz',
       'Dingalan, Aurora', 'Poblacion, Dingalan, Aurora',
       'Rev. Fr. Sample Parish', 'Fictional quick-process demonstration record.',
       'DEMO-1', '1', '1', @demo_eva_id
WHERE @demo_eva_baptism_id IS NULL;
SET @demo_eva_baptism_id = (
  SELECT id FROM baptismal_records
  WHERE person_id = @demo_eva_id AND entry_no = 'DEMO-B-001'
  ORDER BY id LIMIT 1
);

-- Public online baptism application: a new child with no master profile yet.
INSERT INTO sacrament_applications
  (tracking_number, submission_channel, sacrament_type, status, notes)
VALUES
  ('DEMO-BAP-001', 'online', 'baptismal', 'submitted',
   'DEMO ONLY: New child application; review requirements and identity.')
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
SET @demo_bap_app_id = LAST_INSERT_ID();
INSERT INTO baptismal_applications
  (application_id, child_full_name, child_birthdate, child_birthplace,
   father_full_name, mother_maiden_name, parent_guardian_name,
   contact_number, email, preferred_date, notes)
SELECT @demo_bap_app_id, 'Noah Demo Santos', '2025-02-14', 'Dingalan, Aurora',
       'Ramon Santos', 'Maria Cruz', 'Ramon Santos', '09170000001',
       'noah.family@demo.test', '2026-12-12',
       'DEMO ONLY: Sample Baptism application.'
WHERE NOT EXISTS (
  SELECT 1 FROM baptismal_applications WHERE application_id = @demo_bap_app_id
);

-- Confirmation application for Eva: search by name and birth date, select
-- her existing profile, then move the application through the workflow.
INSERT INTO sacrament_applications
  (tracking_number, submission_channel, sacrament_type, status, notes)
VALUES
  ('DEMO-CONF-001', 'online', 'confirmation', 'submitted',
   'DEMO ONLY: Match this applicant to Eva Demo Santos in persons.')
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
SET @demo_conf_app_id = LAST_INSERT_ID();
INSERT INTO confirmation_applications
  (application_id, applicant_full_name, applicant_birthdate,
   parent_guardian_name, father_full_name, mother_maiden_name,
   contact_number, email, baptized_here, baptismal_record_id,
   baptismal_cert_status, sponsor_name)
SELECT @demo_conf_app_id, 'Eva Demo Santos', '2014-06-15',
       'Ramon Santos', 'Ramon Santos', 'Maria Cruz',
       '09170000002', 'eva.family@demo.test', 1, @demo_eva_baptism_id,
       'submitted', 'Luz Demo Reyes'
WHERE NOT EXISTS (
  SELECT 1 FROM confirmation_applications WHERE application_id = @demo_conf_app_id
);

-- First Communion application for the same person to demonstrate identity
-- reuse across multiple sacrament workflows.
INSERT INTO sacrament_applications
  (tracking_number, submission_channel, sacrament_type, status, notes)
VALUES
  ('DEMO-FC-001', 'online', 'first_communion', 'submitted',
   'DEMO ONLY: Match this applicant to the same Eva Demo Santos profile.')
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
SET @demo_fc_app_id = LAST_INSERT_ID();
INSERT INTO first_communion_applications
  (application_id, applicant_full_name, applicant_birthdate,
   parent_guardian_name, contact_number, email, baptized_here,
   baptismal_record_id, baptismal_cert_status, preferred_batch)
SELECT @demo_fc_app_id, 'Eva Demo Santos', '2014-06-15',
       'Ramon Santos', '09170000003', 'eva.family@demo.test',
       1, @demo_eva_baptism_id, 'submitted', 'DEMO - December session'
WHERE NOT EXISTS (
  SELECT 1 FROM first_communion_applications WHERE application_id = @demo_fc_app_id
);

-- Marriage application: use the separate groom and bride match controls.
INSERT INTO sacrament_applications
  (tracking_number, submission_channel, sacrament_type, status, notes)
VALUES
  ('DEMO-MARR-001', 'online', 'marriage', 'submitted',
   'DEMO ONLY: Match both applicants to their separate master profiles.')
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
SET @demo_marriage_app_id = LAST_INSERT_ID();
INSERT INTO marriage_applications
  (application_id, groom_full_name, groom_birthdate, groom_birthplace,
   groom_email, groom_marital_status, bride_full_name, bride_birthdate,
   bride_birthplace, bride_email, bride_marital_status, preferred_wedding_date)
SELECT @demo_marriage_app_id, 'Marco Demo Reyes', '1997-04-20',
       'Dingalan, Aurora', 'marco.reyes@demo.test', 'single',
       'Nina Demo Cruz', '1998-09-08', 'Dingalan, Aurora',
       'nina.cruz@demo.test', 'single', '2027-06-12'
WHERE NOT EXISTS (
  SELECT 1 FROM marriage_applications WHERE application_id = @demo_marriage_app_id
);

-- A certificate request for Eva's existing Baptism record.
INSERT INTO certificate_requests
  (tracking_number, submission_channel, sacrament_type, requester_name,
   requester_relationship, requester_email, requester_contact_number,
   purpose, status, notes)
VALUES
  ('DEMO-CERT-001', 'online', 'baptismal', 'Eva Demo Santos',
   'self', 'eva.family@demo.test', '09170000004',
   'unspecified', 'pending',
   'DEMO ONLY: Match this request to the DEMO-B-001 Baptism register entry.')
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
SET @demo_cert_request_id = LAST_INSERT_ID();
INSERT INTO baptismal_certificate_requests
  (request_id, child_full_name, father_full_name, mother_full_name,
   birthplace, birthdate, date_of_baptism, officiating_minister, book_no, page_no, line_no)
SELECT @demo_cert_request_id, 'Eva Demo Santos', 'Ramon Santos', 'Maria Cruz',
       'Dingalan, Aurora', '2014-06-15', '2015-03-15',
       'Rev. Fr. Sample Parish', 'DEMO-1', '1', '1'
WHERE NOT EXISTS (
  SELECT 1 FROM baptismal_certificate_requests WHERE request_id = @demo_cert_request_id
);

COMMIT;

-- Quick reference: DEMO-BAP-001, DEMO-CONF-001, DEMO-FC-001,
-- DEMO-MARR-001, DEMO-CERT-001. All submissions are online and begin
-- in a reviewable state. Match Eva by DOB 2014-06-15; match both marriage
-- applicants separately.
