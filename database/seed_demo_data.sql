-- =====================================================================
-- SPPO-IS DEMO / SEED DATA
-- =====================================================================
-- Purpose: Populate a freshly-imported schema (database/sppo_is_schema.sql)
--          with a small, coherent parish dataset so every screen of the
--          system can be demonstrated immediately (dashboard counts,
--          queues, certificate generation, tracking lookup, reports...).
--
-- Safe to re-run: every table below is loaded with INSERT IGNORE against
-- explicit IDs, so repeated imports are no-ops instead of duplicates.
-- It never deletes existing data.
--
-- Demo login (seeded below):
--   username: secretary    password: SPPOsecretary2026
--   Please change the password after first login.
--
-- NOTE: the `persons` table (used by the Person Mapper) is part of the
-- canonical schema; the INSERT below relies on it.
-- =====================================================================

USE sppo_is_db;

-- =====================================================================
-- 1. DEMO SECRETARY ACCOUNT
-- =====================================================================
INSERT IGNORE INTO users (id, full_name, username, email, password_hash, role, is_active)
VALUES (1, 'Rosario D. Santos', 'secretary', 'secretary@sppo-is.local',
        '$2y$10$JtvNSfH2pm8gPS.WC5a6w.WYY2e/EAIIu/p6w17.ztxH66xrHD3te',
        'secretary', 1);

-- =====================================================================
-- 2. PERSONS (master record used by the Person Mapper)
-- =====================================================================
INSERT IGNORE INTO persons (id, first_name, middle_name, last_name, suffix, maiden_name, sex, birth_date, birth_place, address, baptismal_status) VALUES
  (1, 'Mateo Rafael', 'Alcantara', 'De Guzman', NULL, NULL, 'male', '2025-11-20', 'Dingalan, Aurora', 'Brgy. Poblacion, Dingalan, Aurora', 'baptized_catholic'),
  (2, 'Sofia Isabel', 'Villanueva', 'Reyes', NULL, NULL, 'female', '2025-12-01', 'Dingalan, Aurora', 'Brgy. Tanawan, Dingalan, Aurora', 'baptized_catholic'),
  (3, 'Lucas Gabriel', 'Mercado', 'Fernandez', NULL, NULL, 'male', '2025-06-10', 'Dingalan, Aurora', 'Brgy. Caragsakan, Dingalan, Aurora', 'baptized_catholic'),
  (4, 'Cassandra Marie', 'Dela Rosa', 'Gatchalian', NULL, NULL, 'female', '2011-02-19', 'Dingalan, Aurora', 'Brgy. Poblacion, Dingalan, Aurora', 'baptized_catholic'),
  (5, 'Jonas Rafael', 'Bautista', 'Aquino', NULL, NULL, 'male', '2009-07-30', 'Dingalan, Aurora', 'Brgy. Tanawan, Dingalan, Aurora', 'baptized_catholic'),
  (6, 'Gianna Louise', 'Torres', 'Navarro', NULL, NULL, 'female', '2018-03-09', 'Dingalan, Aurora', 'Brgy. Caragsakan, Dingalan, Aurora', 'baptized_catholic'),
  (7, 'Juan Miguel', 'Panganiban', 'Dela Cruz', NULL, NULL, 'male', '1990-03-15', 'Gapan, Nueva Ecija', 'Brgy. Poblacion, Dingalan, Aurora', 'baptized_catholic'),
  (8, 'Maria Teresa', 'Santos', 'Dela Cruz', NULL, 'Santos', 'female', '1993-01-07', 'Dingalan, Aurora', 'Brgy. Poblacion, Dingalan, Aurora', 'baptized_catholic');

-- =====================================================================
-- 3. DIGITAL RECORD INDEXES (mirror the physical logbooks)
-- =====================================================================

-- --- Baptismal ---------------------------------------------------------
INSERT IGNORE INTO baptismal_records
  (id, entry_no, baptism_date, child_full_name, birth_date, legitimacy,
   father_name, mother_name, parents_origin, parents_residence,
   minister_name, remarks, book_no, page_no, line_no, encoded_by)
VALUES
  (1, 'B-2026-001', '2026-02-15', 'Mateo Rafael A. De Guzman', '2025-11-20', 'legitimate',
   'Ramon A. De Guzman', 'Elena V. De Guzman', 'Dingalan, Aurora', 'Brgy. Poblacion, Dingalan, Aurora',
   'Rev. Fr. Jose M. Villanueva', NULL, 'B-1', '12', '3', NULL),
  (2, 'B-2026-002', '2026-03-08', 'Sofia Isabel V. Reyes', '2025-12-01', 'legitimate',
   'Andres V. Reyes', 'Karen P. Reyes', 'Dingalan, Aurora', 'Brgy. Tanawan, Dingalan, Aurora',
   'Rev. Fr. Jose M. Villanueva', NULL, 'B-1', '14', '5', NULL),
  (3, 'B-2025-014', '2025-08-23', 'Lucas Gabriel M. Fernandez', '2025-06-10', 'legitimate',
   'Carlos M. Fernandez', 'Ana R. Fernandez', 'Dingalan, Aurora', 'Brgy. Caragsakan, Dingalan, Aurora',
   'Rev. Fr. Eduardo S. Ramos', NULL, 'B-1', '09', '1', NULL),
  (4, 'B-2019-031', '2019-04-14', 'Cassandra Marie D. Gatchalian', '2011-02-19', 'legitimate',
   'Ramon D. Gatchalian', 'Lourdes D. Gatchalian', 'Dingalan, Aurora', 'Brgy. Poblacion, Dingalan, Aurora',
   'Rev. Fr. Eduardo S. Ramos', 'Confirmed later -- see C-2025-007.', 'B-3', '21', '8', NULL),
  (5, 'B-2025-003', '2025-01-11', 'James Adrian B. Zulueta', '2024-11-04', 'legitimate',
   'Robert B. Zulueta', 'Grace S. Zulueta', 'Dingalan, Aurora', 'Brgy. Tanawan, Dingalan, Aurora',
   'Rev. Fr. Jose M. Villanueva', NULL, 'B-1', '06', '2', NULL);

INSERT IGNORE INTO baptismal_sponsors (id, baptismal_record_id, sponsor_name, sponsor_residence) VALUES
  (1, 1, 'Antonio V. De Guzman', 'Brgy. Poblacion, Dingalan, Aurora'),
  (2, 1, 'Marlene B. Cruz', 'Brgy. Tanawan, Dingalan, Aurora'),
  (3, 2, 'Josefina P. Reyes', 'Brgy. Caragsakan, Dingalan, Aurora'),
  (4, 4, 'Teresita D. Santos', 'Brgy. Poblacion, Dingalan, Aurora');

-- --- Confirmation ------------------------------------------------------
INSERT IGNORE INTO confirmation_records
  (id, entry_no, confirmation_date, confirmand_full_name, birthdate,
   father_name, mother_name, sponsor_name, minister_name, baptismal_record_id,
   remarks, book_no, page_no, line_no, encoded_by)
VALUES
  (1, 'C-2025-007', '2025-05-25', 'Cassandra Marie D. Gatchalian', '2011-02-19',
   'Ramon D. Gatchalian', 'Lourdes D. Gatchalian', 'Veronica D. Gatchalian',
   'Most Rev. Reynaldo G. Evangelista, D.D.', 4, NULL, 'C-2', '07', '2', NULL),
  (2, 'C-2026-002', '2026-03-29', 'Jonas Rafael B. Aquino', '2009-07-30',
   'Nestor B. Aquino', 'Fe B. Aquino', 'Ricardo B. Aquino',
   'Most Rev. Reynaldo G. Evangelista, D.D.', NULL, NULL, 'C-2', '11', '4', NULL);

-- --- First Communion ---------------------------------------------------
INSERT IGNORE INTO first_communion_records
  (id, entry_no, communion_date, communicant_full_name,
   father_name, mother_name, minister_name, baptismal_record_id,
   remarks, book_no, page_no, line_no, encoded_by)
VALUES
  (1, 'FC-2026-001', '2026-03-14', 'Mateo Rafael A. De Guzman',
   'Ramon A. De Guzman', 'Elena V. De Guzman', 'Rev. Fr. Jose M. Villanueva', 1,
   NULL, 'FC-1', '05', '1', NULL),
  (2, 'FC-2025-009', '2025-11-30', 'Gianna Louise T. Navarro',
   'Ramon T. Navarro', 'Marites L. Navarro', 'Rev. Fr. Eduardo S. Ramos', NULL,
   NULL, 'FC-1', '03', '2', NULL);

-- --- Marriage ----------------------------------------------------------
INSERT IGNORE INTO marriage_records
  (id, entry_no, marriage_date, husband_full_name, wife_maiden_name,
   husband_birthdate, husband_birthplace, wife_birthdate, wife_birthplace,
   officiating_minister, remarks, book_no, page_no, line_no, encoded_by)
VALUES
  (1, 'M-2025-021', '2025-12-13', 'Juan Miguel P. Dela Cruz', 'Maria Teresa S. Santos',
   '1990-03-15', 'Gapan, Nueva Ecija', '1993-01-07', 'Dingalan, Aurora',
   'Rev. Fr. Jose M. Villanueva', NULL, 'M-3', '18', '1', NULL),
  (2, 'M-2026-004', '2026-02-07', 'Paolo Angelo D. Ramirez', 'Katrina Mae C. Lopez',
   '1992-11-18', 'San Luis, Aurora', '1994-06-02', 'Baler, Aurora',
   'Rev. Fr. Eduardo S. Ramos', NULL, 'M-3', '22', '4', NULL);

INSERT IGNORE INTO marriage_witnesses (id, marriage_record_id, witness_name, witness_residence) VALUES
  (1, 1, 'Ramon A. De Guzman', 'Brgy. Poblacion, Dingalan, Aurora'),
  (2, 1, 'Karen P. Reyes', 'Brgy. Tanawan, Dingalan, Aurora'),
  (3, 2, 'Carlo D. Ramirez', 'Baler, Aurora');

-- =====================================================================
-- 4. CERTIFICATE REQUESTS (shared table + one extension per type)
--    A small queue at different statuses so the secretary can walk the
--    full workflow: pending -> under_review -> approved -> ready_for_pickup
--    -> released.
-- =====================================================================
INSERT IGNORE INTO certificate_requests
  (id, tracking_number, sacrament_type, requester_name, requester_relationship,
   requester_relationship_other, requester_email, requester_contact_number,
   purpose, purpose_other, num_copies, notes, status,
   date_submitted, date_processed, processed_by)
VALUES
  (1, 'SPPO-2026-000031', 'baptismal', 'Elena V. De Guzman', 'parent', NULL,
   'elena.deguzman@example.com', '0917 111 2233',
   'school', NULL, 2, 'Requested for Kinder school enrollment. Original plus one photocopy.',
   'pending', '2026-09-01 09:12:00', NULL, NULL),
  (2, 'SPPO-2026-000032', 'confirmation', 'Fe B. Aquino', 'parent', NULL,
   'fe.aquino@example.com', '0918 222 3344',
   'employment', NULL, 1, NULL,
   'under_review', '2026-08-28 14:30:00', '2026-08-30 10:00:00', NULL),
  (3, 'SPPO-2026-000033', 'marriage', 'Juan Miguel P. Dela Cruz', 'self', NULL,
   'juan.delacruz@example.com', '0919 333 4455',
   'civil_registration', NULL, 1, 'For the civil registration copy of the marriage.',
   'approved', '2026-08-20 09:00:00', '2026-08-22 11:00:00', NULL),
  (4, 'SPPO-2026-000034', 'first_communion', 'Marites L. Navarro', 'parent', NULL,
   'marites.navarro@example.com', '0920 444 5566',
   'school', NULL, 2, NULL,
   'ready_for_pickup', '2026-08-10 08:45:00', '2026-08-15 14:00:00', NULL),
  (5, 'SPPO-2026-000035', 'baptismal', 'Andres V. Reyes', 'parent', NULL,
   'andres.reyes@example.com', '0921 555 6677',
   'travel', NULL, 1, NULL,
   'released', '2026-07-20 10:20:00', '2026-07-25 09:30:00', NULL),
  (6, 'SPPO-2026-000036', 'baptismal', 'Lorna C. Alvarez', 'guardian', NULL,
   'lorna.alvarez@example.com', '0922 666 7788',
   'other', 'Family record for the Alvarez family archive', 1, NULL,
   'pending', '2026-09-04 16:05:00', NULL, NULL);

INSERT IGNORE INTO baptismal_certificate_requests
  (id, request_id, child_full_name, father_full_name, mother_full_name,
   birthplace, birthdate, date_of_baptism, officiating_minister,
   sponsors_text, book_no, page_no, line_no, matched_record_id)
VALUES
  (1, 1, 'Mateo Rafael A. De Guzman', 'Ramon A. De Guzman', 'Elena V. De Guzman',
   'Dingalan, Aurora', '2025-11-20', '2026-02-15', 'Rev. Fr. Jose M. Villanueva',
   'Antonio V. De Guzman; Marlene B. Cruz', 'B-1', '12', '3', 1),
  (2, 5, 'Sofia Isabel V. Reyes', 'Andres V. Reyes', 'Karen P. Reyes',
   'Dingalan, Aurora', '2025-12-01', '2026-03-08', 'Rev. Fr. Jose M. Villanueva',
   'Josefina P. Reyes', 'B-1', '14', '5', 2),
  (3, 6, 'Miguel Emmanuel C. Alvarez', 'Rogelio C. Alvarez', 'Lorna C. Alvarez',
   'Dingalan, Aurora', '2020-07-14', '2020-09-20', 'Rev. Fr. Eduardo S. Ramos',
   NULL, 'B-2', '07', '2', NULL);

INSERT IGNORE INTO confirmation_certificate_requests
  (id, request_id, confirmand_full_name, father_full_name, mother_full_name,
   date_of_confirmation, officiating_minister, sponsor_name,
   book_no, page_no, line_no, matched_record_id)
VALUES
  (1, 2, 'Jonas Rafael B. Aquino', 'Nestor B. Aquino', 'Fe B. Aquino',
   '2026-03-29', 'Most Rev. Reynaldo G. Evangelista, D.D.', 'Ricardo B. Aquino',
   'C-2', '11', '4', 2);

INSERT IGNORE INTO first_communion_certificate_requests
  (id, request_id, communicant_full_name, father_full_name, mother_full_name,
   date_of_first_communion, officiating_minister,
   book_no, page_no, line_no, matched_record_id)
VALUES
  (1, 4, 'Gianna Louise T. Navarro', 'Ramon T. Navarro', 'Marites L. Navarro',
   '2025-11-30', 'Rev. Fr. Eduardo S. Ramos',
   'FC-1', '03', '2', 2);

INSERT IGNORE INTO marriage_certificate_requests
  (id, request_id, husband_full_name, wife_maiden_name, date_of_marriage,
   officiating_minister, book_no, page_no, line_no, matched_record_id)
VALUES
  (1, 3, 'Juan Miguel P. Dela Cruz', 'Maria Teresa S. Santos', '2025-12-13',
   'Rev. Fr. Jose M. Villanueva', 'M-3', '18', '1', 1);

-- =====================================================================
-- 5. SACRAMENT APPLICATIONS (shared table + one extension per type)
-- =====================================================================
INSERT IGNORE INTO sacrament_applications
  (id, tracking_number, sacrament_type, status, date_submitted, date_processed,
   processed_by, notes)
VALUES
  (1, 'SPPO-APP-2026-000011', 'baptismal', 'submitted', '2026-09-02 09:30:00', NULL, NULL,
   'Requesting to have their newborn baptized. Preferred date in October when the seminar is offered.'),
  (2, 'SPPO-APP-2026-000012', 'confirmation', 'ready_for_approval', '2026-08-15 13:15:00', '2026-08-20 10:00:00', NULL,
   'Documentary requirements complete. Awaiting parish priest approval.'),
  (3, 'SPPO-APP-2026-000013', 'marriage', 'scheduled', '2026-07-30 15:40:00', '2026-08-18 09:00:00', NULL,
   'Pre-Cana completed. Wedding scheduled for December 19.'),
  (4, 'SPPO-APP-2026-000014', 'first_communion', 'completed', '2026-05-10 11:00:00', '2026-05-20 10:00:00', NULL,
   'First Communion received June 2026.');

INSERT IGNORE INTO baptismal_applications
  (id, application_id, child_full_name, child_birthdate, child_birthplace,
   father_full_name, mother_full_name, parent_guardian_name,
   contact_number, email, preferred_date, notes)
VALUES
  (1, 1, 'Ethan Marco D. Villanueva', '2026-07-19', 'Dingalan, Aurora',
   'Robert D. Villanueva', 'Annie S. Villanueva', 'Annie S. Villanueva',
   '0917 777 8899', 'annie.villanueva@example.com', '2026-10-10',
   'Please advise on the pre-baptism seminar schedule.');

INSERT IGNORE INTO baptismal_application_godparents (id, baptismal_application_id, godparent_name, godparent_residence) VALUES
  (1, 1, 'Miguel D. Villanueva', 'Brgy. Poblacion, Dingalan, Aurora'),
  (2, 1, 'Grace S. Banatao', 'Maria Aurora, Aurora');

INSERT IGNORE INTO confirmation_applications
  (id, application_id, applicant_full_name, applicant_birthdate,
   parent_guardian_name, contact_number, email, baptized_here,
   baptismal_record_id, baptismal_cert_status, preferred_batch, sponsor_name,
   age_eligibility_flag)
VALUES
  (1, 2, 'Rafael Luis M. Tolentino', '2011-05-12',
   'Mercy M. Tolentino', '0918 888 9900', 'mercy.tolentino@example.com', 1,
   NULL, 'pending', 'Batch - June 2026', 'Ramon M. Tolentino',
   0);

INSERT IGNORE INTO first_communion_applications
  (id, application_id, applicant_full_name, applicant_birthdate,
   parent_guardian_name, contact_number, email, baptized_here,
   baptismal_record_id, baptismal_cert_status, preferred_batch,
   age_eligibility_flag)
VALUES
  (1, 4, 'Francine Nicole A. Ramos', '2015-09-25',
   'Liza A. Ramos', '0919 999 0011', 'liza.ramos@example.com', 1,
   NULL, 'pending', 'Batch - June 2026',
   0);

INSERT IGNORE INTO marriage_applications
  (id, application_id,
   groom_full_name, groom_birthdate, groom_birthplace, groom_contact_number, groom_email,
   groom_marital_status, groom_baptized_here, groom_baptismal_record_id,
   groom_baptismal_cert_status, groom_confirmation_cert_status,
   bride_full_name, bride_birthdate, bride_birthplace, bride_contact_number, bride_email,
   bride_marital_status, bride_baptized_here, bride_baptismal_record_id,
   bride_baptismal_cert_status, bride_confirmation_cert_status,
   preferred_wedding_date, pre_cana_seminar_completed, pre_cana_completion_date,
   canonical_interview_completed, canonical_interview_date,
   banns_date_1, banns_date_2, banns_date_3, assigned_priest_name)
VALUES
  (1, 3,
   'Marco Antonio D. Bautista', '1992-08-18', 'Cabanatuan City, Nueva Ecija', '0917 121 3141', 'marco.bautista@example.com',
   'single', 0, NULL, 'pending', 'pending',
   'Camille Joyce R. Garcia', '1995-03-27', 'Dingalan, Aurora', '0918 222 1112', 'camille.garcia@example.com',
   'single', 1, NULL, 'pending', 'pending',
   '2026-12-19', 1, '2026-07-10', 1, '2026-08-05',
   '2026-09-20', '2026-09-27', '2026-10-04', 'Rev. Fr. Jose M. Villanueva');

INSERT IGNORE INTO marriage_principal_sponsors (id, marriage_application_id, sponsor_name, sponsor_residence) VALUES
  (1, 1, 'Paolo Angelo D. Ramirez', 'Dingalan, Aurora'),
  (2, 1, 'Katrina Mae C. Lopez', 'Baler, Aurora');

-- =====================================================================
-- 6. SCHEDULE, CALENDAR, AND ANNOUNCEMENTS
-- =====================================================================
INSERT IGNORE INTO schedule_events
  (id, event_type, title, event_date, start_time, end_time,
   related_request_type, related_request_id, notes, created_by)
VALUES
  (1, 'mass', 'Sunday Mass', '2026-09-06', '08:00:00', '09:30:00',
   NULL, NULL, 'Regular Sunday Mass.', NULL),
  (2, 'wedding', 'Bautista-Garcia Wedding', '2026-12-19', '15:00:00', '17:00:00',
   'marriage_application', 3, 'Nuptial Mass for the Bautista-Garcia couple.', NULL),
  (3, 'baptism', 'October Baptism Batch', '2026-10-10', '09:00:00', '11:00:00',
   'other', 1, 'Monthly baptism batch; families arrive 30 minutes early.', NULL),
  (4, 'other', 'Parish Pastoral Council Meeting', '2026-09-14', '14:00:00', '16:00:00',
   NULL, NULL, 'Monthly PPC meeting at the parish hall.', NULL);

INSERT IGNORE INTO announcements (id, title, content, event_date, is_published, posted_by) VALUES
  (1, 'September Baptism Seminar', 'Parents and godparents of children to be baptized must attend the pre-baptism seminar on the first Saturday of the month at 9:00 AM in the Parish Hall.', '2026-10-03', 1, NULL),
  (2, 'Advent Confessions', 'Confessions with the parish priest every Friday of Advent after the 6:00 PM Mass.', '2026-12-04', 1, NULL);

-- =====================================================================
-- 7. CERTIFICATE GENERATION LOG (generate / preview / print / pickup)
--    One already-printed entry so the Print/Pickup History page has data.
-- =====================================================================
INSERT IGNORE INTO certificate_generation_log
  (id, certificate_request_id, generated_by, generated_at, print_status, pickup_date)
VALUES
  (1, 4, NULL, '2026-08-16 09:00:00', 'printed', NULL);

-- =====================================================================
-- 8. AUDIT LOG (activity logging -- a few rows so the page has context)
-- =====================================================================
INSERT IGNORE INTO audit_logs (id, user_id, action, target_table, target_id, description, created_at) VALUES
  (1, NULL, 'demo_seed', 'certificate_requests', 1, 'Demo data seed: certificate request SPPO-2026-000031', '2026-09-01 09:13:00'),
  (2, NULL, 'demo_seed', 'certificate_generation_log', 1, 'Demo data seed: printed SPPO-2026-000034', '2026-08-16 09:01:00'),
  (3, NULL, 'demo_seed', 'marriage_applications', 1, 'Demo data seed: marriage application SPPO-APP-2026-000013', '2026-07-30 15:41:00');