-- =====================================================================
-- SRS-SPPO SEED: BRING EVERY DATA TABLE TO 10 ROWS
-- =====================================================================
-- Purpose: Tops up every table to 10 demo rows (INSERT IGNORE with
--          explicit IDs -- safe to re-run, never deletes real data).
--
-- HOW THE 1:1 EXTENSION TABLES WORK:
--   The certificate/sacrament extension tables are 1:1 with their parent
--   (request_id / application_id are UNIQUE) and the parent tables hold
--   exactly 10 rows split across 4 sacrament types, so extensions fill
--   EVERY parent row of their type (4/2/2/2), not 10 each.
--   Fully-independent child tables (sponsors, witnesses, godparents,
--   principal sponsors, generation log, audit log) DO reach 10.
--
-- EXCEPTION:
--   sacrament_eligibility_settings stays at 2 rows -- its UNIQUE
--   sacrament_type column only allows first_communion and confirmation.
--
-- The ALTER block below syncs an older live database first (idempotent;
-- identical to database/alter_live_db_sync.sql). If you already ran the
-- sync script, these are quick no-ops.
-- =====================================================================

USE srs_sppo_db;
-- =====================================================================
-- SRS-SPPO LIVE-DATABASE SYNC (run once in phpMyAdmin on srs_sppo_db)
-- Idempotent: every statement is safe to re-run.
-- Brings your existing database in line with the updated
-- database/srs_sppo_schema.sql (no data is lost).
-- =====================================================================

-- 1) Baptismal sponsors: godfather / godmother type (Ninong / Ninang)
ALTER TABLE baptismal_sponsors
  ADD COLUMN IF NOT EXISTS sponsor_type
    ENUM('godfather','godmother') NOT NULL DEFAULT 'godfather' AFTER sponsor_residence;

-- 2) Baptismal applications: parish-form fields.
--    Also relaxes the old NOT NULL ID-upload column (no longer collected;
--    identity is verified in person at the office counter).
ALTER TABLE baptismal_applications
  ADD COLUMN IF NOT EXISTS father_birthplace VARCHAR(150) NULL AFTER father_full_name,
  ADD COLUMN IF NOT EXISTS mother_maiden_name VARCHAR(150) NULL AFTER father_birthplace,
  ADD COLUMN IF NOT EXISTS mother_birthplace VARCHAR(150) NULL AFTER mother_maiden_name,
  ADD COLUMN IF NOT EXISTS parents_address VARCHAR(255) NULL AFTER mother_birthplace,
  ADD COLUMN IF NOT EXISTS parents_union_type ENUM('legitimate','civil','natural') NULL DEFAULT 'legitimate' AFTER parents_address,
  ADD COLUMN IF NOT EXISTS delegated_minister VARCHAR(150) NULL AFTER preferred_date,
  ADD COLUMN IF NOT EXISTS certificate_done TINYINT(1) NOT NULL DEFAULT 0 AFTER line_no,
  ADD COLUMN IF NOT EXISTS baptism_date DATE NULL AFTER preferred_date;

-- 3) Godparents on applications: type column
ALTER TABLE baptismal_application_godparents
  ADD COLUMN IF NOT EXISTS godparent_type
    ENUM('godfather','godmother') NOT NULL DEFAULT 'godfather' AFTER godparent_residence;

-- 4) Marriage applications: Kasal details (time / venue), license reference,
--    recorded priest name, and nullable ID columns (no online ID uploads).
ALTER TABLE marriage_applications
  ADD COLUMN IF NOT EXISTS preferred_wedding_time TIME NULL AFTER preferred_wedding_date,
  ADD COLUMN IF NOT EXISTS preferred_wedding_venue ENUM('church','barangay_chapel') NOT NULL DEFAULT 'church' AFTER preferred_wedding_time,
  ADD COLUMN IF NOT EXISTS marriage_license_ref VARCHAR(100) NULL AFTER preferred_wedding_venue,
  ADD COLUMN IF NOT EXISTS assigned_priest_name VARCHAR(150) NULL;

  DROP COLUMN IF EXISTS line_no,
  DROP COLUMN IF EXISTS person_id;

-- 6) Legacy ID columns: the live database already dropped them; nothing to relax.
-- =====================================================================
-- 1. USERS (10 secretary accounts; same demo password for all)
--    Demo login for every account: SPPOsecretary2026
-- =====================================================================
INSERT IGNORE INTO users (id, full_name, username, email, password_hash, role, is_active) VALUES
  (2, 'Marilou B. Cruz', 'm.cruz', 'm.cruz@srs-sppo.local', '$2y$10$XgX59160n.YF7NHJsxU8Tuzu9g/nfQusXolEsIidwfV.iA4JwKcFq', 'secretary', 1),
  (3, 'Josefa R. Mendoza', 'j.mendoza', 'j.mendoza@srs-sppo.local', '$2y$10$XgX59160n.YF7NHJsxU8Tuzu9g/nfQusXolEsIidwfV.iA4JwKcFq', 'secretary', 1),
  (4, 'Antonia S. Reyes', 'a.reyes', 'a.reyes@srs-sppo.local', '$2y$10$XgX59160n.YF7NHJsxU8Tuzu9g/nfQusXolEsIidwfV.iA4JwKcFq', 'secretary', 1),
  (5, 'Corazon M. Aquino', 'c.aquino', 'c.aquino@srs-sppo.local', '$2y$10$XgX59160n.YF7NHJsxU8Tuzu9g/nfQusXolEsIidwfV.iA4JwKcFq', 'secretary', 1),
  (6, 'Teresita L. Garcia', 't.garcia', 't.garcia@srs-sppo.local', '$2y$10$XgX59160n.YF7NHJsxU8Tuzu9g/nfQusXolEsIidwfV.iA4JwKcFq', 'secretary', 1),
  (7, 'Remedios P. Flores', 'r.flores', 'r.flores@srs-sppo.local', '$2y$10$XgX59160n.YF7NHJsxU8Tuzu9g/nfQusXolEsIidwfV.iA4JwKcFq', 'secretary', 1),
  (8, 'Lourdes V. Ramos', 'l.ramos', 'l.ramos@srs-sppo.local', '$2y$10$XgX59160n.YF7NHJsxU8Tuzu9g/nfQusXolEsIidwfV.iA4JwKcFq', 'secretary', 1),
  (9, 'Estrella D. Navarro', 'e.navarro', 'e.navarro@srs-sppo.local', '$2y$10$XgX59160n.YF7NHJsxU8Tuzu9g/nfQusXolEsIidwfV.iA4JwKcFq', 'secretary', 1),
  (10, 'Imelda C. Bautista', 'i.bautista', 'i.bautista@srs-sppo.local', '$2y$10$XgX59160n.YF7NHJsxU8Tuzu9g/nfQusXolEsIidwfV.iA4JwKcFq', 'secretary', 1);

-- =====================================================================
-- 2. PERSONS (10 total)
-- =====================================================================
INSERT IGNORE INTO persons (id, first_name, middle_name, last_name, suffix, maiden_name, sex, birth_date, birth_place, address, contact_number, email, baptismal_status) VALUES
  (9, 'Rafael', 'Domingo', 'Mendoza', NULL, NULL, 'male', '2010-04-12', 'Dingalan, Aurora', 'Brgy. Poblacion, Dingalan, Aurora', '0917 555 0101', 'rafael.mendoza@example.com', 'baptized_catholic'),
  (10, 'Angelica', 'Fajardo', 'Sarmiento', NULL, NULL, 'female', '2012-09-23', 'Dingalan, Aurora', 'Brgy. Tanawan, Dingalan, Aurora', '0918 555 0202', 'angelica.sarmiento@example.com', 'baptized_catholic');

-- =====================================================================
-- 3. BAPTISMAL RECORDS (10 total) -- full parish-form column set
-- =====================================================================
INSERT IGNORE INTO baptismal_records
  (id, entry_no, baptism_date, baptism_place, child_full_name, birth_date, legitimacy, union_type,
   father_name, father_birthplace, mother_name, mother_maiden_name, mother_birthplace,
   parents_origin, parents_residence, minister_name, remarks, book_no, page_no, line_no, encoded_by)
VALUES
  (6, 'B-2026-003', '2026-04-12', 'St. Patrick Parish Church', 'Diego Andrea S. Manalo', '2026-01-30', 'legitimate', 'legitimate',
   'Fernando S. Manalo', 'Dingalan, Aurora', 'Cecilia A. Manalo', 'Aquino', 'Dingalan, Aurora',
   'Dingalan, Aurora', 'Brgy. Poblacion, Dingalan, Aurora', 'Rev. Fr. Jose M. Villanueva', NULL, 'I', '1', '6', 1),
  (7, 'B-2026-004', '2026-05-10', 'St. Patrick Parish Church', 'Bea Margaret L. Ocampo', '2026-02-22', 'legitimate', 'legitimate',
   'Dennis L. Ocampo', 'Baler, Aurora', 'Ruth M. Ocampo', 'Lim', 'Baler, Aurora',
   'Baler, Aurora', 'Brgy. Tanawan, Dingalan, Aurora', 'Rev. Fr. Jose M. Villanueva', NULL, 'I', '1', '7', 1),
  (8, 'B-2026-005', '2026-06-14', 'St. Patrick Parish Church', 'Gabriel Nico R. Sarmiento', '2026-03-18', 'legitimate', 'civil',
   'Alfredo R. Sarmiento', 'Dingalan, Aurora', 'Judith F. Sarmiento', 'Fajardo', 'Dingalan, Aurora',
   'Dingalan, Aurora', 'Brgy. Caragsakan, Dingalan, Aurora', 'Rev. Fr. Eduardo S. Ramos', NULL, 'I', '1', '8', 1),
  (9, 'B-2026-006', '2026-07-19', 'St. Patrick Parish Church', 'Hannah Grace T. Delos Santos', '2026-04-05', 'legitimate', 'legitimate',
   'Marco T. Delos Santos', 'Dingalan, Aurora', 'Yolanda G. Delos Santos', 'Gonzales', 'Dingalan, Aurora',
   'Dingalan, Aurora', 'Brgy. Poblacion, Dingalan, Aurora', 'Rev. Fr. Jose M. Villanueva', NULL, 'I', '1', '9', 1),
  (10, 'B-2026-007', '2026-08-09', 'St. Patrick Parish Church', 'Ivan Lloyd P. Magbanua', '2026-05-27', 'legitimate', 'legitimate',
   'Efren P. Magbanua', 'Dingalan, Aurora', 'Divina B. Magbanua', 'Bautista', 'Dingalan, Aurora',
   'Dingalan, Aurora', 'Brgy. Tanawan, Dingalan, Aurora', 'Rev. Fr. Eduardo S. Ramos', NULL, 'I', '1', '10', 1);

-- Baptismal sponsors (10 total; godfather/godmother type)
INSERT IGNORE INTO baptismal_sponsors (id, baptismal_record_id, sponsor_name, sponsor_residence, sponsor_type) VALUES
  (5, 6, 'Ricardo S. Manalo', 'Brgy. Poblacion, Dingalan, Aurora', 'godfather'),
  (6, 6, 'Marissa T. Aquino', 'Brgy. Tanawan, Dingalan, Aurora', 'godmother'),
  (7, 7, 'Corazon L. Reyes', 'Baler, Aurora', 'godmother'),
  (8, 8, 'Danilo F. Cruz', 'Brgy. Caragsakan, Dingalan, Aurora', 'godfather'),
  (9, 9, 'Gloria M. Santos', 'Brgy. Poblacion, Dingalan, Aurora', 'godmother'),
  (10, 10, 'Roberto B. Lim', 'Brgy. Tanawan, Dingalan, Aurora', 'godfather');

-- =====================================================================
-- 4. CONFIRMATION RECORDS (10 total)
-- =====================================================================
INSERT IGNORE INTO confirmation_records
  (id, entry_no, confirmation_date, confirmand_full_name, birthdate,
   father_name, mother_maiden_name, sponsor_name, minister_name, baptismal_record_id,
   remarks, book_no, page_no, line_no, encoded_by)
VALUES
  (3, 'C-2025-008', '2025-05-25', 'Trisha Anne M. Salvador', '2010-08-14',
   'Ruben M. Salvador', 'Divina M. Salvador', 'Corazon M. Salvador',
   'Most Rev. Reynaldo G. Evangelista, D.D.', NULL, NULL, 'I', '1', '3', 1),
  (4, 'C-2025-009', '2025-05-25', 'Miguel Luis A. Bautista', '2010-11-02',
   'Anthony A. Bautista', 'Stella A. Bautista', 'Peter A. Bautista',
   'Most Rev. Reynaldo G. Evangelista, D.D.', NULL, NULL, 'I', '1', '4', 1),
  (5, 'C-2025-010', '2025-10-19', 'Angelo D. Marquez', '2009-03-27',
   'Efren D. Marquez', 'Sylvia D. Marquez', 'Ramon D. Marquez',
   'Most Rev. Reynaldo G. Evangelista, D.D.', NULL, NULL, 'I', '1', '5', 1),
  (6, 'C-2025-011', '2025-10-19', 'Kimberly T. Aguilar', '2011-06-30',
   'Renato T. Aguilar', 'Marites T. Aguilar', 'Lourdes T. Aguilar',
   'Most Rev. Reynaldo G. Evangelista, D.D.', NULL, NULL, 'I', '1', '6', 1),
  (7, 'C-2026-003', '2026-03-29', 'Joshua P. Del Rosario', '2011-09-08',
   'Danilo P. Del Rosario', 'Elena P. Del Rosario', 'Arturo P. Del Rosario',
   'Most Rev. Reynaldo G. Evangelista, D.D.', NULL, NULL, 'I', '1', '7', 1),
  (8, 'C-2026-004', '2026-03-29', 'Nicole E. Ventura', '2012-01-16',
   'Raul E. Ventura', 'Susana E. Ventura', 'Mila E. Ventura',
   'Most Rev. Reynaldo G. Evangelista, D.D.', NULL, NULL, 'I', '1', '8', 1),
  (9, 'C-2026-005', '2026-04-26', 'Francis Ivan G. Ocampo', '2010-05-21',
   'Alberto G. Ocampo', 'Rosa G. Ocampo', 'Nestor G. Ocampo',
   'Most Rev. Reynaldo G. Evangelista, D.D.', NULL, NULL, 'I', '1', '9', 1),
  (10, 'C-2026-006', '2026-04-26', 'Mariel S. Fajardo', '2011-12-11',
   'Conrado S. Fajardo', 'Amparo S. Fajardo', 'Teresita S. Fajardo',
   'Most Rev. Reynaldo G. Evangelista, D.D.', NULL, NULL, 'I', '1', '10', 1);

-- =====================================================================
-- 5. FIRST COMMUNION RECORDS (10 total)
-- =====================================================================
INSERT IGNORE INTO first_communion_records
  (id, entry_no, communion_date, communicant_full_name,
   father_name, mother_maiden_name, minister_name, baptismal_record_id,
   remarks, book_no, page_no, line_no, encoded_by)
VALUES
  (3, 'FC-2026-002', '2026-03-14', 'Cassandra Marie D. Gatchalian',
   'Ramon D. Gatchalian', 'Lourdes D. Gatchalian', 'Rev. Fr. Jose M. Villanueva', 4);
   NULL, 'I', '1', '3', 1)
  (4, 'FC-2026-003', '2026-03-14', 'Joshua P. Del Rosario',
   'Danilo P. Del Rosario', 'Elena P. Del Rosario', 'Rev. Fr. Jose M. Villanueva', NULL,
   NULL, 'I', '1', '4', 1),
  (5, 'FC-2026-004', '2026-03-14', 'Nicole E. Ventura',
   'Raul E. Ventura', 'Susana E. Ventura', 'Rev. Fr. Jose M. Villanueva', NULL,
   NULL, 'I', '1', '5', 1),
  (6, 'FC-2025-010', '2025-11-30', 'Angelo D. Marquez',
   'Efren D. Marquez', 'Sylvia D. Marquez', 'Rev. Fr. Eduardo S. Ramos', NULL,
   NULL, 'I', '1', '6', 1),
  (7, 'FC-2025-011', '2025-11-30', 'Kimberly T. Aguilar',
   'Renato T. Aguilar', 'Marites T. Aguilar', 'Rev. Fr. Eduardo S. Ramos', NULL,
   NULL, 'I', '1', '7', 1),
  (8, 'FC-2025-012', '2025-11-30', 'Miguel Luis A. Bautista',
   'Anthony A. Bautista', 'Stella A. Bautista', 'Rev. Fr. Eduardo S. Ramos', NULL,
   NULL, 'I', '1', '8', 1),
  (9, 'FC-2026-005', '2026-05-16', 'Rafael Domingo Mendoza',
   'Ernesto M. Mendoza', 'Cecilia R. Mendoza', 'Rev. Fr. Jose M. Villanueva', NULL,
   NULL, 'I', '1', '9', 1),
  (10, 'FC-2026-006', '2026-05-16', 'Angelica F. Sarmiento',
   'Victor S. Sarmiento', 'Rowena F. Sarmiento', 'Rev. Fr. Jose M. Villanueva', NULL,
   NULL, 'I', '1', '10', 1);

-- =====================================================================
-- 6. MARRIAGE RECORDS (10 total)
-- =====================================================================
INSERT IGNORE INTO marriage_records
  (id, entry_no, marriage_date, husband_full_name, wife_maiden_name,
   husband_birthdate, husband_birthplace, wife_birthdate, wife_birthplace,
   officiating_minister, remarks, book_no, page_no, line_no, encoded_by)
VALUES
  (3, 'M-2024-015', '2024-11-30', 'Roberto C. Villanueva', 'Josephine A. Cruz',
   '1988-05-04', 'Dingalan, Aurora', '1990-09-12', 'Dingalan, Aurora',
   'Rev. Fr. Jose M. Villanueva', NULL, 'I', '1', '3', 1),
  (4, 'M-2025-002', '2025-01-25', 'Arnel T. Domingo', 'Rosalie M. Fajardo',
   '1991-02-14', 'Dingalan, Aurora', '1993-07-08', 'Maria Aurora, Aurora',
   'Rev. Fr. Eduardo S. Ramos', NULL, 'I', '1', '4', 1),
  (5, 'M-2025-008', '2025-05-10', 'Ericson L. Manalastas', 'Divina B. Soriano',
   '1989-12-01', 'Baler, Aurora', '1992-04-19', 'Dingalan, Aurora',
   'Rev. Fr. Jose M. Villanueva', NULL, 'I', '1', '5', 1),
  (6, 'M-2025-013', '2025-08-23', 'Ferdinand R. Aguinaldo', 'Melinda S. Torres',
   '1990-06-22', 'Dingalan, Aurora', '1991-10-30', 'Dingalan, Aurora',
   'Rev. Fr. Eduardo S. Ramos', NULL, 'I', '1', '6', 1),
  (7, 'M-2025-017', '2025-10-18', 'Gilbert A. Mercado', 'Nenita D. Salvador',
   '1987-03-09', 'Dingalan, Aurora', '1989-08-15', 'Maria Aurora, Aurora',
   'Rev. Fr. Jose M. Villanueva', NULL, 'I', '1', '7', 1),
  (8, 'M-2026-001', '2026-01-17', 'Hernando G. Lopez', 'Charito M. Delgado',
   '1992-07-19', 'Dingalan, Aurora', '1994-02-25', 'Dingalan, Aurora',
   'Rev. Fr. Jose M. Villanueva', NULL, 'I', '1', '8', 1),
  (9, 'M-2026-003', '2026-03-21', 'Irineo B. Gonzales', 'Marilou T. Panganiban',
   '1993-11-05', 'Maria Aurora, Aurora', '1995-05-23', 'Dingalan, Aurora',
   'Rev. Fr. Eduardo S. Ramos', NULL, 'I', '1', '9', 1),
  (10, 'M-2026-006', '2026-06-06', 'Jerome F. Alcantara', 'Rowena V. Enrile',
   '1991-09-28', 'Dingalan, Aurora', '1993-12-03', 'Dingalan, Aurora',
   'Rev. Fr. Jose M. Villanueva', NULL, 'I', '1', '10', 1);

-- Marriage witnesses (10 total)
INSERT IGNORE INTO marriage_witnesses (id, marriage_record_id, witness_name, witness_residence) VALUES
  (4, 3, 'Arturo C. Villanueva', 'Brgy. Poblacion, Dingalan, Aurora'),
  (5, 4, 'Manuel M. Domingo', 'Brgy. Tanawan, Dingalan, Aurora'),
  (6, 5, 'Benjamin L. Soriano', 'Baler, Aurora'),
  (7, 6, 'Cristina S. Aguinaldo', 'Brgy. Poblacion, Dingalan, Aurora'),
  (8, 7, 'Dominador A. Salvador', 'Maria Aurora, Aurora'),
  (9, 8, 'Eduardo M. Delgado', 'Brgy. Caragsakan, Dingalan, Aurora'),
  (10, 10, 'Felicidad V. Enrile', 'Brgy. Tanawan, Dingalan, Aurora');


-- =====================================================================
-- 8. CERTIFICATE REQUESTS (10 total; one extension row per request)
--    Existing 1-6 + new 7-10. Type mix: baptismal 4, confirmation 2,
--    first communion 2, marriage 2.
-- =====================================================================
INSERT IGNORE INTO certificate_requests
  (id, tracking_number, sacrament_type, requester_name, requester_relationship,
   requester_relationship_other, requester_email, requester_contact_number,
   purpose, purpose_other, notes, status,
   date_submitted, date_processed, processed_by)
VALUES
  (7, 'SPPO-2026-000037', 'baptismal', 'Ramon A. De Guzman', 'father', NULL,
   'ramon.deguzman@example.com', '0923 777 8899',
   'legal', NULL, 'Certified true copy for legal purposes.',
   'under_review', '2026-09-05 10:00:00', '2026-09-06 09:00:00', NULL),
  (8, 'SPPO-2026-000038', 'confirmation', 'Mercy M. Tolentino', 'parent', NULL,
   'mercy.tolentino@example.com', '0924 888 9900',
   'school', NULL, NULL,
   'pending', '2026-09-06 11:30:00', NULL, NULL),
  (9, 'SPPO-2026-000039', 'first_communion', 'Danilo P. Del Rosario', 'parent', NULL,
   'danilo.delrosario@example.com', '0925 999 0011',
   'marriage', NULL, 'Required for church marriage requirements.',
   'approved', '2026-09-07 13:00:00', '2026-09-08 10:30:00', NULL),
  (10, 'SPPO-2026-000040', 'marriage', 'Charito M. Delgado', 'spouse', NULL,
   'charito.delgado@example.com', '0926 000 1122',
   'immigration', NULL, NULL,
   'ready_for_pickup', '2026-09-08 15:45:00', '2026-09-09 09:15:00', NULL);

-- Baptismal certificate request details (fills every baptismal request: 1,5,6,7)
INSERT IGNORE INTO baptismal_certificate_requests
  (id, request_id, child_full_name, father_full_name, mother_full_name,
   birthplace, birthdate, date_of_baptism, officiating_minister,
   sponsors_text, book_no, page_no, line_no, matched_record_id)
VALUES
  (4, 7, 'Mateo Rafael A. De Guzman', 'Ramon A. De Guzman', 'Elena V. De Guzman',
   'Dingalan, Aurora', '2025-11-20', '2026-02-15', 'Rev. Fr. Jose M. Villanueva',
   'Antonio V. De Guzman; Marlene B. Cruz', 'I', '1', '1', 1);

-- Confirmation certificate request details (fills every confirmation request: 2,8)
INSERT IGNORE INTO confirmation_certificate_requests
  (id, request_id, confirmand_full_name, father_full_name, mother_full_name,
   date_of_confirmation, officiating_minister, sponsor_name,
   book_no, page_no, line_no, matched_record_id)
VALUES
  (2, 8, 'Rafael Luis M. Tolentino', 'Emilio M. Tolentino', 'Mercy M. Tolentino',
   '2026-04-26', 'Most Rev. Reynaldo G. Evangelista, D.D.', 'Ramon M. Tolentino',
   'C-2', '12', '3', NULL);

-- First communion certificate request details (fills every FC request: 4,9)
INSERT IGNORE INTO first_communion_certificate_requests
  (id, request_id, communicant_full_name, father_full_name, mother_full_name,
   date_of_first_communion, officiating_minister,
   book_no, page_no, line_no, matched_record_id)
VALUES
  (2, 9, 'Joshua P. Del Rosario', 'Danilo P. Del Rosario', 'Elena P. Del Rosario',
   '2026-03-14', 'Rev. Fr. Jose M. Villanueva',
   'FC-1', '05', '3', NULL);

-- Marriage certificate request details (fills every marriage request: 3,10)
INSERT IGNORE INTO marriage_certificate_requests
  (id, request_id, party_a_full_name, party_b_full_name,
   marriage_date, officiating_minister,
   party_a_book_no, party_a_page_no, party_a_line_no,
   party_b_book_no, party_b_page_no, party_b_line_no, matched_record_id)
VALUES
  (2, 10, 'Hernando G. Lopez', 'Charito M. Delgado',
   '2026-01-17', 'Rev. Fr. Jose M. Villanueva',
   'M-3', '19', '1', 'M-3', '19', '1', 8);

-- =====================================================================
-- 9. CERTIFICATE GENERATION LOG (10 total)
-- =====================================================================
INSERT IGNORE INTO certificate_generation_log
  (id, certificate_request_id, generated_by, generated_at, print_status, pickup_date)
VALUES
  (2, 1, 1, '2026-09-02 10:00:00', 'generated', NULL),
  (3, 2, 1, '2026-08-31 09:30:00', 'generated', NULL),
  (4, 3, 1, '2026-08-23 14:00:00', 'printed', NULL),
  (5, 4, 1, '2026-08-16 10:15:00', 'released', '2026-08-20 11:00:00'),
  (6, 5, 1, '2026-07-26 09:00:00', 'released', '2026-07-28 15:00:00'),
  (7, 6, 1, '2026-09-05 11:00:00', 'generated', NULL),
  (8, 7, 1, '2026-09-06 13:00:00', 'printed', NULL),
  (9, 8, 1, '2026-09-06 16:00:00', 'generated', NULL),
  (10, 10, 1, '2026-09-09 10:00:00', 'printed', NULL);

-- =====================================================================
-- 10. SACRAMENT APPLICATIONS (10 total; one extension row per application)
--     Existing 1-4 + new 5-10. Type mix: baptismal 4, confirmation 2,
--     first communion 2, marriage 2.
-- =====================================================================
INSERT IGNORE INTO sacrament_applications
  (id, tracking_number, sacrament_type, status, date_submitted, date_processed,
   processed_by, notes)
VALUES
  (5, 'SPPO-APP-2026-000015', 'baptismal', 'submitted', '2026-09-03 10:15:00', NULL, NULL,
   'Newborn baptism; preferred date October batch.'),
  (6, 'SPPO-APP-2026-000016', 'baptismal', 'documents_pending', '2026-08-25 14:20:00', '2026-08-28 09:00:00', NULL,
   'Awaiting godparents seminar certificate.'),
  (7, 'SPPO-APP-2026-000017', 'confirmation', 'documents_pending', '2026-08-29 09:40:00', '2026-09-01 10:00:00', NULL,
   'Baptismal certificate not yet submitted.'),
  (8, 'SPPO-APP-2026-000018', 'first_communion', 'completed', '2026-04-02 08:30:00', '2026-04-10 11:00:00', NULL,
   'First Communion received March 2026.'),
  (9, 'SPPO-APP-2026-000019', 'marriage', 'banns_in_progress', '2026-06-14 13:10:00', '2026-07-01 09:00:00', NULL,
   'Second banns posted; awaiting third date.'),
  (10, 'SPPO-APP-2026-000020', 'baptismal', 'approved', '2026-07-08 16:45:00', '2026-07-15 10:30:00', NULL,
   'Approved for the August baptism batch.');

-- Baptismal application details (fills every baptismal application: 1,5,6,10)
INSERT IGNORE INTO baptismal_applications
  (id, application_id, child_full_name, child_birthdate, child_birthplace,
   father_full_name, mother_maiden_name, parent_guardian_name,
   contact_number, email, preferred_date, notes)
VALUES
  (2, 5, 'Zoe Marie A. Fernandez', '2026-06-02', 'Dingalan, Aurora',
   'Peter A. Fernandez', 'Sandra M. Fernandez', 'Sandra M. Fernandez',
   '0917 234 5678', 'sandra.fernandez@example.com', '2026-10-10', NULL),
  (3, 6, 'Lucas Gabriel R. Tiongson', '2026-03-15', 'Dingalan, Aurora',
   'Mark R. Tiongson', 'Jasmine L. Tiongson', 'Jasmine L. Tiongson',
   '0918 345 6789', 'jasmine.tiongson@example.com', '2026-11-14',
   'Godparents to confirm seminar schedule.'),
  (4, 10, 'Ishmael B. Grande', '2026-01-09', 'Dingalan, Aurora',
   'Noel B. Grande', 'Precious K. Grande', 'Precious K. Grande',
   '0919 456 7890', 'precious.grande@example.com', '2026-08-09', NULL);

-- Confirmation application details (fills every confirmation application: 2,7)
INSERT IGNORE INTO confirmation_applications
  (id, application_id, applicant_full_name, applicant_birthdate,
   parent_guardian_name, contact_number, email, baptized_here,
   baptismal_record_id, baptismal_cert_status, preferred_batch, sponsor_name,
   age_eligibility_flag)
VALUES
  (2, 7, 'Mariel S. Fajardo', '2011-12-11',
   'Amparo S. Fajardo', '0920 111 2233', 'amparo.fajardo@example.com', 1,
   NULL, 'pending', 'Batch - December 2026', 'Teresita S. Fajardo',
   0);

-- First communion application details (fills every FC application: 4,8)
INSERT IGNORE INTO first_communion_applications
  (id, application_id, applicant_full_name, applicant_birthdate,
   parent_guardian_name, contact_number, email, baptized_here,
   baptismal_record_id, baptismal_cert_status, preferred_batch,
   age_eligibility_flag)
VALUES
  (2, 8, 'Angelica F. Sarmiento', '2012-09-23',
   'Rowena F. Sarmiento', '0921 222 3344', 'rowena.sarmiento@example.com', 1,
   NULL, 'pending', 'Batch - June 2026',
   0);

-- Marriage application details (fills every marriage application: 3,9)
INSERT IGNORE INTO marriage_applications
  (id, application_id,
   groom_full_name, groom_birthdate, groom_birthplace, groom_contact_number, groom_email,
   groom_marital_status, groom_baptized_here, groom_baptismal_record_id,
   groom_baptismal_cert_status, groom_confirmation_cert_status,
   bride_full_name, bride_birthdate, bride_birthplace, bride_contact_number, bride_email,
   bride_marital_status, bride_baptized_here, bride_baptismal_record_id,
   bride_baptismal_cert_status, bride_confirmation_cert_status,
   preferred_wedding_date, preferred_wedding_venue, marriage_license_ref,
   pre_cana_seminar_completed, pre_cana_completion_date,
   canonical_interview_completed, canonical_interview_date,
   banns_date_1, banns_date_2, banns_date_3, assigned_priest_name)
VALUES
  (2, 9,
   'Irineo B. Gonzales', '1993-11-05', 'Maria Aurora, Aurora', '0917 321 6540', 'irineo.gonzales@example.com',
   'single', 0, NULL, 'pending', 'pending',
   'Marilou T. Panganiban', '1995-05-23', 'Dingalan, Aurora', '0918 432 7651', 'marilou.panganiban@example.com',
   'single', 1, NULL, 'pending', 'pending',
   '2026-10-24', 'church', NULL,
   0, NULL,
   1, '2026-08-20',
   '2026-09-06', NULL, NULL, 'Rev. Fr. Eduardo S. Ramos');

-- Godparents (10 total; 2-3 per baptismal application 1,5,6,10)
INSERT IGNORE INTO baptismal_application_godparents (id, baptismal_application_id, godparent_name, godparent_residence, godparent_type) VALUES
  (3, 1, 'Danilo R. Cruz', 'Brgy. Tanawan, Dingalan, Aurora', 'godfather'),
  (4, 1, 'Josephine M. Reyes', 'Brgy. Poblacion, Dingalan, Aurora', 'godmother'),
  (5, 2, 'Anthony S. Fernandez', 'Brgy. Poblacion, Dingalan, Aurora', 'godfather'),
  (6, 2, 'Rosalinda M. Aquino', 'Maria Aurora, Aurora', 'godmother'),
  (7, 3, 'Frederick L. Tiongson', 'Baler, Aurora', 'godfather'),
  (8, 3, 'Immaculada J. Reyes', 'Brgy. Tanawan, Dingalan, Aurora', 'godmother'),
  (9, 4, 'Gregorio B. Salazar', 'Brgy. Caragsakan, Dingalan, Aurora', 'godfather'),
  (10, 4, 'Aurora K. Mendez', 'Brgy. Poblacion, Dingalan, Aurora', 'godmother');

-- Principal sponsors (10 total; 4 on application 3, 4 on application 9)
INSERT IGNORE INTO marriage_principal_sponsors (id, marriage_application_id, sponsor_name, sponsor_residence) VALUES
  (3, 1, 'Rolando M. Cruz', 'Brgy. Poblacion, Dingalan, Aurora'),
  (4, 1, 'Evangelina S. Ramos', 'Brgy. Tanawan, Dingalan, Aurora'),
  (5, 1, 'Nestor P. Garcia', 'Maria Aurora, Aurora'),
  (6, 1, 'Carmelita D. Flores', 'Brgy. Caragsakan, Dingalan, Aurora'),
  (7, 2, 'Artemio G. Gonzales', 'Maria Aurora, Aurora'),
  (8, 2, 'Basilia T. Panganiban', 'Brgy. Poblacion, Dingalan, Aurora'),
  (9, 2, 'Ciriaco H. Mercado', 'Baler, Aurora'),
  (10, 2, 'Digna V. Alcantara', 'Brgy. Tanawan, Dingalan, Aurora');

-- =====================================================================
-- 11. SCHEDULE EVENTS (10 total)
-- =====================================================================
INSERT IGNORE INTO schedule_events
  (id, event_type, title, event_date, start_time, end_time,
   related_request_type, related_request_id, notes, created_by)
VALUES
  (5, 'mass', 'Simbang Gabi (Day 1)', '2026-12-16', '04:30:00', '05:30:00',
   NULL, NULL, 'Nine-day dawn Mass series.', NULL),
  (6, 'baptism', 'November Baptism Batch', '2026-11-14', '09:00:00', '11:00:00',
   'other', 2, 'Monthly baptism batch.', NULL),
  (7, 'wedding', 'Gonzales-Panganiban Wedding', '2026-10-24', '10:00:00', '12:00:00',
   'marriage_application', 9, 'Nuptial Mass.', NULL),
  (8, 'other', 'Feast of St. Patrick', '2027-03-17', '09:00:00', '12:00:00',
   NULL, NULL, 'Parish fiesta celebration.', NULL),
  (9, 'mass', 'Sunday Mass (PM)', '2026-09-13', '17:00:00', '18:30:00',
   NULL, NULL, 'Regular Sunday afternoon Mass.', NULL),
  (10, 'other', 'Catechism Orientation', '2026-09-20', '13:00:00', '15:00:00',
   NULL, NULL, 'Orientation for First Communion and Confirmation parents.', NULL);

-- =====================================================================
-- 12. ANNOUNCEMENTS (10 total)
-- =====================================================================
INSERT IGNORE INTO announcements (id, title, content, event_date, is_published, posted_by) VALUES
  (3, 'October Baptism Seminar', 'Parents and godparents of children to be baptized must attend the seminar on October 3, 9:00 AM at the Parish Hall.', '2026-10-03', 1, NULL),
  (4, 'First Communion Enrollment', 'Enrollment for the 2027 First Communion batch opens October 1. Please bring a copy of the baptismal certificate.', '2026-10-01', 1, NULL),
  (5, 'Confirmation Batch Registration', 'Registration for the Confirmation batch closes on November 15. Requirements are listed on the Requirements page.', '2026-11-15', 1, NULL),
  (6, 'Parish Office Hours', 'The parish office is open Monday to Saturday, 8:00 AM to 5:00 PM. Closed on Sundays and holidays.', NULL, 1, NULL),
  (7, 'Simbang Gabi Schedule', 'Simbang Gabi begins December 16 with dawn Masses at 4:30 AM, and anticipated Masses at 8:00 PM from December 15.', '2026-12-16', 1, NULL),
  (8, 'Cemetery Blessing', 'Annual blessing of the public cemetery on November 2 after the 9:00 AM Mass.', '2026-11-02', 1, NULL),
  (9, 'Marriage Banns Reminder', 'Couples with pending banns are reminded to complete the pre-Cana seminar before their wedding month.', NULL, 0, NULL),
  (10, 'Youth Camp Registration', 'Parish youth camp registration is open until November 30 at the parish office.', '2026-11-30', 0, NULL);

-- =====================================================================
-- 13. AUDIT LOG (10 total)
-- =====================================================================
INSERT IGNORE INTO audit_logs (id, user_id, action, target_table, target_id, description, created_at) VALUES
  (5, NULL, 'certificate_request_status_updated', 'certificate_requests', 3, 'Status changed to approved.', '2026-08-22 11:00:00'),
  (6, NULL, 'baptismal_record_encoded', 'baptismal_records', 6, 'Baptismal record encoded for Diego Andrea S. Manalo.', '2026-04-12 17:00:00'),
  (7, NULL, 'marriage_application_status_updated', 'sacrament_applications', 9, 'Status changed to banns_in_progress.', '2026-07-01 09:05:00'),
  (8, NULL, 'schedule_event_added', 'schedule_events', 7, 'Added: Gonzales-Panganiban Wedding.', '2026-09-10 10:00:00'),
  (9, NULL, 'announcement_posted', 'announcements', 3, 'Posted: October Baptism Seminar.', '2026-09-11 09:30:00'),
