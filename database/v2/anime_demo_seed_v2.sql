-- Fictional staging dataset for the current unified-person V2 schema.
-- Import only after schema.sql into a new, empty database selected in phpMyAdmin.
-- Names and scenarios are original examples, not real parish records.
-- This file is additive and creates no staff account or default password.
SET NAMES utf8mb4;

INSERT INTO persons
    (id, first_name, last_name, sex, birth_date, birth_place, father_name,
     mother_maiden_name, father_person_id, mother_person_id, address,
     contact_number, email, baptismal_status)
VALUES
    (1, 'Haruto', 'Akiyama', 'male', '1982-03-14', 'Sakura Ward',
     'Fumio Akiyama', 'Keiko Mori', NULL, NULL, 'Demo District',
     '+81-90-5555-0101', 'haruto@example.com', 'baptized_catholic'),
    (2, 'Emi', 'Takahashi', 'female', '1984-07-22', 'River Town',
     'Hiroshi Takahashi', 'Natsumi Ito', NULL, NULL, 'Demo District',
     '+81-90-5555-0102', 'emi@example.com', 'baptized_catholic'),
    (5, 'Sora', 'Nakamura', 'female', '1994-02-11', 'Maple City',
     'Akira Nakamura', 'Yoko Sato', NULL, NULL, 'North Demo Ward',
     '+81-90-5555-0105', 'sora@example.com', 'baptized_catholic'),
    (6, 'Renji', 'Kato', 'male', '1993-09-03', 'Harbor Town',
     'Takeshi Kato', 'Mika Endo', NULL, NULL, 'Harbor Demo Ward',
     '+81-90-5555-0106', 'renji@example.com', 'baptized_catholic');

INSERT INTO persons
    (id, first_name, last_name, sex, birth_date, birth_place, father_name,
     mother_maiden_name, father_person_id, mother_person_id, address,
     email, baptismal_status)
VALUES
    (3, 'Ren', 'Akiyama', 'male', '2012-05-08', 'Sakura Ward',
     'Haruto Akiyama', 'Emi Takahashi', 1, 2, 'Demo District',
     'ren@example.com', 'baptized_catholic'),
    (4, 'Yui', 'Akiyama', 'female', '2015-11-19', 'Sakura Ward',
     'Haruto Akiyama', 'Emi Takahashi', 1, 2, 'Demo District',
     'yui@example.com', 'baptized_catholic'),
    (7, 'Miu', 'Akiyama', 'female', '2020-06-02', 'Sakura Ward',
     'Haruto Akiyama', 'Emi Takahashi', 1, 2, 'Demo District',
     'miu@example.com', 'baptized_catholic');

INSERT INTO sacrament_records
    (id, sacrament_type, person_id, spouse_person_id, source, event_date,
     place, minister_name, entry_no, book_no, page_no, line_no,
     parents_union_type, legitimacy, details)
VALUES
    (1, 'baptism', 1, NULL, 'this_parish', '1982-04-04',
     'St. Patrick Parish', 'Fr. Mateo Reyes', 'B-1982-014', '1', '12', '4',
     'canonical', NULL, '{"parents_residence":"Demo District"}'),
    (2, 'first_communion', 1, NULL, 'this_parish', '1990-05-20',
     'St. Patrick Parish', 'Fr. Mateo Reyes', 'FC-1990-008', '1', '8', '2',
     NULL, NULL, '{"domicile":"Demo District"}'),
    (3, 'confirmation', 1, NULL, 'this_parish', '1995-10-08',
     'St. Patrick Parish', 'Bishop Elias Navarro', 'C-1995-021', '2', '5', '7',
     NULL, NULL, NULL),
    (4, 'baptism', 2, NULL, 'this_parish', '1984-08-12',
     'St. Patrick Parish', 'Fr. Mateo Reyes', 'B-1984-031', '1', '18', '6',
     'canonical', NULL, '{"parents_residence":"River Town"}'),
    (5, 'first_communion', 2, NULL, 'this_parish', '1992-05-24',
     'St. Patrick Parish', 'Fr. Mateo Reyes', 'FC-1992-013', '1', '11', '3',
     NULL, NULL, '{"domicile":"River Town"}'),
    (6, 'confirmation', 2, NULL, 'this_parish', '1997-09-14',
     'St. Patrick Parish', 'Bishop Elias Navarro', 'C-1997-026', '2', '9', '1',
     NULL, NULL, NULL),
    (7, 'marriage', 1, 2, 'this_parish', '2008-05-17',
     'St. Patrick Parish', 'Fr. Mateo Reyes', 'M-2008-006', '3', '2', '6',
     NULL, NULL,
     '{"groom":{"civil_status":"single"},"bride":{"civil_status":"single"},"license_ref":"DEMO-2008-006"}'),
    (8, 'baptism', 3, NULL, 'this_parish', '2012-06-10',
     'St. Patrick Parish', 'Fr. Tomas Villanueva', 'B-2012-019', '4', '3', '5',
     'canonical', NULL, '{"parents_residence":"Demo District"}'),
    (9, 'baptism', 4, NULL, 'this_parish', '2015-12-13',
     'St. Patrick Parish', 'Fr. Tomas Villanueva', 'B-2015-044', '4', '15', '2',
     'canonical', NULL, '{"parents_residence":"Demo District"}'),
    (10, 'baptism', 5, NULL, 'other_parish', '1994-03-20',
     'Maple City Parish', 'Fr. Kenji Watanabe', 'EXT-1994-011', 'A', '4', '3',
     'canonical', NULL, '{"origin_parish":"Maple City Parish"}'),
    (11, 'baptism', 6, NULL, 'this_parish', '1993-10-10',
     'St. Patrick Parish', 'Fr. Mateo Reyes', 'B-1993-037', '2', '14', '8',
     'canonical', NULL, '{"parents_residence":"Harbor Town"}'),
    (12, 'marriage', 6, 5, 'this_parish', '2021-06-12',
     'St. Patrick Parish', 'Fr. Tomas Villanueva', 'M-2021-004', '5', '2', '3',
     NULL, NULL,
     '{"groom":{"civil_status":"single"},"bride":{"civil_status":"single"},"license_ref":"DEMO-2021-004"}');

INSERT INTO sacrament_participants (record_id, role, full_name, residence, sort_order)
VALUES
    (1, 'godfather', 'Kenta Mori', 'Sakura Ward', 1),
    (1, 'godmother', 'Aiko Fujita', 'Sakura Ward', 2),
    (7, 'principal_sponsor', 'Naoki Sato', 'Demo District', 1),
    (12, 'witness', 'Mina Kobayashi', 'Harbor Demo Ward', 1);

INSERT INTO sacrament_batches (sacrament_type, grade_level, school_year, batch_name)
VALUES
    ('first_communion', '3', '2026-2027', 'Demo First Communion Group'),
    ('confirmation', '6', '2026-2027', 'Demo Confirmation Group');

INSERT INTO sacrament_applications
    (request_no, channel, sacrament_type, person_id, spouse_person_id,
     applicant_name, applicant_email, applicant_contact, preferred_date,
     preferred_time, status, details, requirements, privacy_consent_at)
VALUES
    ('SA-DEMO-001', 'online', 'baptism', 7, NULL,
     'Haruto Akiyama', 'haruto@example.com', '+81-90-5555-0101',
     '2026-12-06', '10:00:00', 'new',
     '{"child_name":"Miu Akiyama","child_birth_date":"2020-06-02","father_name":"Haruto Akiyama","mother_maiden_name":"Emi Takahashi"}',
     '{"psa_birth_certificate":{"status":"not_received","note":""}}',
     '2026-11-01 09:00:00'),
    ('SA-DEMO-002', 'online', 'marriage', 5, 6,
     'Sora Nakamura', 'sora@example.com', '+81-90-5555-0105',
     '2027-03-20', '14:00:00', 'reviewing',
     '{"groom":{"full_name":"Renji Kato","baptism_status":"this_parish"},"bride":{"full_name":"Sora Nakamura","baptism_status":"other_parish"},"venue":"St. Patrick Parish"}',
     '{"psa_cenomar":{"status":"not_received","note":""},"origin_baptismal_certificate":{"status":"received","note":"Demo copy"},"transfer_letter":{"status":"not_received","note":""}}',
     '2026-11-02 10:30:00'),
    ('SA-DEMO-003', 'online', 'first_communion', 3, NULL,
     'Haruto Akiyama', 'haruto@example.com', '+81-90-5555-0101',
     '2027-05-23', '09:00:00', 'requirements_pending',
     '{"candidate_name":"Ren Akiyama","baptism_status":"this_parish","catechism_batch":"2026-2027"}',
     '{"origin_baptismal_certificate":{"status":"not_applicable","note":"Baptized in this parish"},"catechism_completion":{"status":"not_received","note":""}}',
     '2026-11-03 11:15:00');

INSERT INTO certificate_requests
    (request_no, channel, sacrament_type, subject_name, subject_details,
     requester_name, requester_relationship, requester_email,
     requester_contact_number, purpose, privacy_consent_at, person_id,
     record_id, status)
VALUES
    ('CR-DEMO-001', 'online', 'baptism', 'Haruto Akiyama',
     '{"birth_date":"1982-03-14","father_name":"Fumio Akiyama","mother_maiden_name":"Keiko Mori"}',
     'Haruto Akiyama', 'self', 'haruto@example.com', '+81-90-5555-0101',
     'personal', '2026-11-04 08:45:00', 1, 1, 'new');

INSERT INTO schedule_events
    (event_type, title, public_details, event_date, start_time, end_time)
VALUES
    ('baptism', 'Demo Baptism Celebration',
     'Fictional staging calendar entry for system testing.', '2026-12-06', '10:00:00', '11:00:00'),
    ('wedding', 'Demo Wedding Celebration',
     'Fictional staging calendar entry for system testing.', '2027-03-20', '14:00:00', '15:00:00');

INSERT INTO announcements (title, content, event_date, is_published)
VALUES
    ('STAGING DEMO: Fictional sample records',
     'This is a staging demonstration with original fictional sample data. Do not treat these names or events as parish records. Do not submit real requests here.',
     '2026-11-17', 1);

INSERT INTO settings (setting_key, setting_value)
VALUES
    ('parish_name', 'SRS-SPPO Staging Demo'),
    ('first_communion_min_age', '8'),
    ('confirmation_min_age', '12');
