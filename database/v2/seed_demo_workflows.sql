-- =====================================================================
-- SRS-SPPO V2 WHIMSICAL WORKFLOW DEMO (additive, repeatable, demo only)
-- =====================================================================
-- Fictional, original fantasy-inspired parish scenarios. All sample
-- names, contact details, and events are invented. This seed adds sample
-- certificate requests, sacrament applications, calendar events, and
-- published announcements. Every public item is clearly labeled DEMO.
--
-- Run only against the hosted V2 demo database. Safe to run again:
-- inserts use reserved DMO-* request numbers and DEMO: event titles.
-- It does not create people, staff accounts, notifications, or audit rows.
-- =====================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- Fictional public intake examples. Advanced application statuses are
-- sample workflow states only; no staff account or action is attributed.
INSERT INTO sacrament_applications
  (request_no, channel, sacrament_type, applicant_name, applicant_email,
   applicant_contact, preferred_date, preferred_time, status, details,
   requirements, notes, privacy_consent_at)
SELECT d.request_no, 'online', d.sacrament_type, d.applicant_name,
       d.applicant_email, d.applicant_contact, d.preferred_date,
       d.preferred_time, d.status, d.details, d.requirements,
       'DEMO ONLY: original fictional fantasy-inspired sample application.',
       NOW()
FROM (
  SELECT
    'DMO-A-BAP-001' AS request_no, 'baptism' AS sacrament_type,
    'Mira Moonwhistle' AS applicant_name, 'mira.moonwhistle@demo.test' AS applicant_email,
    '09170000101' AS applicant_contact, DATE_ADD(CURDATE(), INTERVAL 28 DAY) AS preferred_date,
    '09:00:00' AS preferred_time, 'new' AS status,
    JSON_OBJECT(
      'child_name', 'Pip Moonwhistle',
      'child_birth_date', '2023-02-14',
      'child_birth_place', 'Dingalan, Aurora',
      'father_name', 'Tobin Moonwhistle',
      'mother_maiden_name', 'Mira Fernbrook',
      'parents_union_type', 'legitimate',
      'parent_guardian_name', 'Mira Moonwhistle',
      'godparents', JSON_ARRAY(
        JSON_OBJECT('role', 'godfather', 'full_name', 'Rowan Starling'),
        JSON_OBJECT('role', 'godmother', 'full_name', 'Elowen Wren')
      )
    ) AS details,
    JSON_OBJECT(
      'psa_birth_certificate',
      JSON_OBJECT('status', 'not_received', 'note', 'DEMO sample checklist')
    ) AS requirements
  UNION ALL
  SELECT
    'DMO-A-BAP-002', 'baptism', 'Rowan Silverfern',
    'rowan.silverfern@demo.test', '09170000102',
    DATE_ADD(CURDATE(), INTERVAL 35 DAY), '10:00:00', 'scheduled',
    JSON_OBJECT(
      'child_name', 'Tansy Silverfern',
      'child_birth_date', '2024-06-08',
      'child_birth_place', 'Barangay San Isidro, Dingalan',
      'father_name', 'Rowan Silverfern',
      'mother_maiden_name', 'Lark Willowmere',
      'parents_union_type', 'legitimate',
      'parent_guardian_name', 'Rowan Silverfern',
      'godparents', JSON_ARRAY(
        JSON_OBJECT('role', 'godfather', 'full_name', 'Cassian Brookspell'),
        JSON_OBJECT('role', 'godmother', 'full_name', 'Mira Moonwhistle')
      )
    ),
    JSON_OBJECT(
      'psa_birth_certificate',
      JSON_OBJECT('status', 'verified', 'note', 'DEMO ONLY: sample workflow state')
    )
  UNION ALL
  SELECT
    'DMO-A-FC-001', 'first_communion', 'Elowen Wren',
    'elowen.wren@demo.test', '09170000103',
    DATE_ADD(CURDATE(), INTERVAL 50 DAY), NULL, 'requirements_pending',
    JSON_OBJECT(
      'candidate_name', 'Orin Starling',
      'candidate_birth_date', '2018-03-19',
      'father_name', 'Rowan Starling',
      'mother_maiden_name', 'Elowen Wren',
      'parent_guardian_name', 'Elowen Wren',
      'baptized_here', 'elsewhere',
      'confession_completed', FALSE,
      'parental_consent', TRUE
    ),
    JSON_OBJECT(
      'origin_baptismal_certificate',
      JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY: outside-parish sample'),
      'catechism_completion',
      JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY: sample checklist')
    )
  UNION ALL
  SELECT
    'DMO-A-CON-001', 'confirmation', 'Mira Moonwhistle',
    'mira.moonwhistle@demo.test', '09170000104',
    DATE_ADD(CURDATE(), INTERVAL 42 DAY), NULL, 'reviewing',
    JSON_OBJECT(
      'applicant_full_name', 'Sera Bramblewick',
      'birth_date', '2011-09-04',
      'father_name', 'Tobin Bramblewick',
      'mother_maiden_name', 'Nell Starling',
      'parent_guardian_name', 'Nell Starling',
      'baptized_here', 'elsewhere',
      'sponsor_name', 'Cassian Brookspell'
    ),
    JSON_OBJECT(
      'origin_baptismal_certificate',
      JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY: outside-parish sample'),
      'confirmation_sponsor_details',
      JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY: sample checklist')
    )
  UNION ALL
  SELECT
    'DMO-A-CON-002', 'confirmation', 'Lark Willowmere',
    'lark.willowmere@demo.test', '09170000105',
    DATE_ADD(CURDATE(), INTERVAL 56 DAY), '08:30:00', 'scheduled',
    JSON_OBJECT(
      'applicant_full_name', 'Nell Moonwhistle',
      'birth_date', '2010-05-22',
      'father_name', 'Tobin Moonwhistle',
      'mother_maiden_name', 'Mira Fernbrook',
      'parent_guardian_name', 'Mira Moonwhistle',
      'baptized_here', 'this_parish',
      'sponsor_name', 'Isabel Silverfern'
    ),
    JSON_OBJECT(
      'origin_baptismal_certificate',
      JSON_OBJECT('status', 'not_applicable', 'note', ''),
      'confirmation_sponsor_details',
      JSON_OBJECT('status', 'verified', 'note', 'DEMO ONLY: sample workflow state')
    )
  UNION ALL
  SELECT
    'DMO-A-MAR-001', 'marriage', 'Tobin Moonwhistle',
    'tobin.moonwhistle@demo.test', '09170000106',
    DATE_ADD(CURDATE(), INTERVAL 75 DAY), '14:00:00', 'requirements_pending',
    JSON_OBJECT(
      'groom', JSON_OBJECT(
        'full_name', 'Tobin Moonwhistle', 'birth_date', '1993-04-12',
        'birthplace', 'Dingalan, Aurora', 'current_address', 'Poblacion, Dingalan',
        'email', 'tobin.moonwhistle@demo.test', 'marital_status', 'single',
        'baptized', 'yes', 'confirmed', 'yes', 'previous_marriage', 'no'
      ),
      'bride', JSON_OBJECT(
        'full_name', 'Lark Willowmere', 'birth_date', '1995-08-03',
        'birthplace', 'Dingalan, Aurora', 'current_address', 'Barangay San Isidro',
        'email', 'lark.willowmere@demo.test', 'marital_status', 'single',
        'baptized', 'yes', 'confirmed', 'yes', 'previous_marriage', 'no'
      ),
      'preferred_wedding_date', DATE_ADD(CURDATE(), INTERVAL 75 DAY),
      'preferred_wedding_time', '14:00',
      'preferred_wedding_venue', 'church',
      'sponsors', JSON_ARRAY('Cassian Brookspell', 'Elowen Wren')
    ),
    JSON_OBJECT(
      'psa_cenomar_groom', JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY'),
      'psa_cenomar_bride', JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY'),
      'origin_baptismal_certificate_groom', JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY'),
      'origin_baptismal_certificate_bride', JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY'),
      'transfer_letter', JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY'),
      'pre_cana_seminar', JSON_OBJECT('status', 'not_received', 'note', 'DEMO ONLY')
    )
  UNION ALL
  SELECT
    'DMO-A-MAR-002', 'marriage', 'Cassian Brookspell',
    'cassian.brookspell@demo.test', '09170000107',
    DATE_ADD(CURDATE(), INTERVAL 84 DAY), '15:00:00', 'scheduled',
    JSON_OBJECT(
      'groom', JSON_OBJECT(
        'full_name', 'Cassian Brookspell', 'birth_date', '1992-11-18',
        'birthplace', 'Dingalan, Aurora', 'current_address', 'Barangay Sta. Lucia',
        'email', 'cassian.brookspell@demo.test', 'marital_status', 'single',
        'baptized', 'yes', 'confirmed', 'yes', 'previous_marriage', 'no'
      ),
      'bride', JSON_OBJECT(
        'full_name', 'Elowen Wren', 'birth_date', '1994-02-09',
        'birthplace', 'Dingalan, Aurora', 'current_address', 'Poblacion, Dingalan',
        'email', 'elowen.wren@demo.test', 'marital_status', 'single',
        'baptized', 'yes', 'confirmed', 'yes', 'previous_marriage', 'no'
      ),
      'preferred_wedding_date', DATE_ADD(CURDATE(), INTERVAL 84 DAY),
      'preferred_wedding_time', '15:00',
      'preferred_wedding_venue', 'church',
      'sponsors', JSON_ARRAY('Mira Moonwhistle', 'Rowan Starling')
    ),
    JSON_OBJECT(
      'psa_cenomar_groom', JSON_OBJECT('status', 'verified', 'note', 'DEMO ONLY'),
      'psa_cenomar_bride', JSON_OBJECT('status', 'verified', 'note', 'DEMO ONLY'),
      'origin_baptismal_certificate_groom', JSON_OBJECT('status', 'verified', 'note', 'DEMO ONLY'),
      'origin_baptismal_certificate_bride', JSON_OBJECT('status', 'verified', 'note', 'DEMO ONLY'),
      'transfer_letter', JSON_OBJECT('status', 'verified', 'note', 'DEMO ONLY'),
      'pre_cana_seminar', JSON_OBJECT('status', 'verified', 'note', 'DEMO ONLY')
    )
) d
WHERE NOT EXISTS (
  SELECT 1 FROM sacrament_applications a WHERE a.request_no = d.request_no
);

-- New, unpaid certificate requests across all four sacrament types.
-- They intentionally have no person/record match until a staff demo action.
INSERT INTO certificate_requests
  (request_no, channel, sacrament_type, subject_name, subject_details,
   requester_name, requester_relationship, requester_email,
   requester_contact_number, purpose, privacy_consent_at, status, notes)
SELECT d.request_no, 'online', d.sacrament_type, d.subject_name,
       d.subject_details, d.requester_name, d.requester_relationship,
       d.requester_email, d.requester_contact, d.purpose, NOW(), 'new',
       'DEMO ONLY: fictional certificate request; no real parishioner or record.'
FROM (
  SELECT 'DMO-CR-BAP-001' AS request_no, 'baptism' AS sacrament_type,
         'Pip Moonwhistle' AS subject_name,
         JSON_OBJECT('birth_date', '2023-02-14', 'father_name', 'Tobin Moonwhistle',
                     'mother_maiden_name', 'Mira Fernbrook') AS subject_details,
         'Mira Moonwhistle' AS requester_name, 'parent' AS requester_relationship,
         'mira.moonwhistle@demo.test' AS requester_email, '09170000101' AS requester_contact,
         'personal' AS purpose
  UNION ALL
  SELECT 'DMO-CR-BAP-002', 'baptism', 'Tansy Silverfern',
         JSON_OBJECT('birth_date', '2024-06-08', 'father_name', 'Rowan Silverfern',
                     'mother_maiden_name', 'Lark Willowmere'),
         'Rowan Silverfern', 'parent', 'rowan.silverfern@demo.test', '09170000102', 'school'
  UNION ALL
  SELECT 'DMO-CR-FC-001', 'first_communion', 'Orin Starling',
         JSON_OBJECT('birth_date', '2018-03-19', 'father_name', 'Rowan Starling',
                     'mother_maiden_name', 'Elowen Wren'),
         'Elowen Wren', 'parent', 'elowen.wren@demo.test', '09170000103', 'school'
  UNION ALL
  SELECT 'DMO-CR-FC-002', 'first_communion', 'Tansy Silverfern',
         JSON_OBJECT('birth_date', '2016-05-11', 'father_name', 'Rowan Silverfern',
                     'mother_maiden_name', 'Lark Willowmere'),
         'Lark Willowmere', 'parent', 'lark.willowmere@demo.test', '09170000105', 'personal'
  UNION ALL
  SELECT 'DMO-CR-CON-001', 'confirmation', 'Sera Bramblewick',
         JSON_OBJECT('birth_date', '2011-09-04', 'father_name', 'Tobin Bramblewick',
                     'mother_maiden_name', 'Nell Starling'),
         'Sera Bramblewick', 'self', 'sera.bramblewick@demo.test', '09170000108', 'personal'
  UNION ALL
  SELECT 'DMO-CR-CON-002', 'confirmation', 'Nell Moonwhistle',
         JSON_OBJECT('birth_date', '2010-05-22', 'father_name', 'Tobin Moonwhistle',
                     'mother_maiden_name', 'Mira Fernbrook'),
         'Mira Moonwhistle', 'parent', 'mira.moonwhistle@demo.test', '09170000104', 'school'
  UNION ALL
  SELECT 'DMO-CR-MAR-001', 'marriage', 'Tobin Moonwhistle',
         JSON_OBJECT('spouse_name', 'Lark Willowmere',
                     'marriage_date', DATE_ADD(CURDATE(), INTERVAL 75 DAY)),
         'Tobin Moonwhistle', 'self', 'tobin.moonwhistle@demo.test', '09170000106', 'marriage'
  UNION ALL
  SELECT 'DMO-CR-MAR-002', 'marriage', 'Cassian Brookspell',
         JSON_OBJECT('spouse_name', 'Elowen Wren',
                     'marriage_date', DATE_ADD(CURDATE(), INTERVAL 84 DAY)),
         'Elowen Wren', 'spouse', 'elowen.wren@demo.test', '09170000107', 'marriage'
) d
WHERE NOT EXISTS (
  SELECT 1 FROM certificate_requests r WHERE r.request_no = d.request_no
);

-- Public calendar samples. Dates are relative to import day and stay future.
INSERT INTO schedule_events
  (event_type, title, public_details, event_date, start_time, end_time,
   application_id, notes)
SELECT 'baptism', 'DEMO: Moonlit Garden Baptism',
       'Fictional demonstration event. Not an actual parish service.',
       DATE_ADD(CURDATE(), INTERVAL 35 DAY), '10:00:00', '11:00:00',
       a.id, 'DEMO ONLY: linked fictional application.'
FROM sacrament_applications a
WHERE a.request_no = 'DMO-A-BAP-002'
  AND NOT EXISTS (SELECT 1 FROM schedule_events e WHERE e.title = 'DEMO: Moonlit Garden Baptism');

INSERT INTO schedule_events
  (event_type, title, public_details, event_date, start_time, end_time,
   application_id, notes)
SELECT 'other', 'DEMO: Starlight Confirmation Gathering',
       'Fictional demonstration event. Not an actual parish service.',
       DATE_ADD(CURDATE(), INTERVAL 56 DAY), '08:30:00', '11:00:00',
       a.id, 'DEMO ONLY: linked fictional application.'
FROM sacrament_applications a
WHERE a.request_no = 'DMO-A-CON-002'
  AND NOT EXISTS (SELECT 1 FROM schedule_events e WHERE e.title = 'DEMO: Starlight Confirmation Gathering');

INSERT INTO schedule_events
  (event_type, title, public_details, event_date, start_time, end_time,
   application_id, notes)
SELECT 'wedding', 'DEMO: Cassian and Elowen - Storybook Wedding',
       'Fictional demonstration event. Not an actual parish booking.',
       DATE_ADD(CURDATE(), INTERVAL 84 DAY), '15:00:00', '16:30:00',
       a.id, 'DEMO ONLY: linked fictional application.'
FROM sacrament_applications a
WHERE a.request_no = 'DMO-A-MAR-002'
  AND NOT EXISTS (SELECT 1 FROM schedule_events e WHERE e.title = 'DEMO: Cassian and Elowen - Storybook Wedding');

INSERT INTO schedule_events
  (event_type, title, public_details, event_date, start_time, end_time, notes)
SELECT 'other', 'DEMO: Moonspire Lantern-Making Workshop',
       'A whimsical fictional craft session for the public-calendar demo.',
       DATE_ADD(CURDATE(), INTERVAL 18 DAY), '14:00:00', '16:00:00',
       'DEMO ONLY: fictional event; not a real parish activity.'
WHERE NOT EXISTS (
  SELECT 1 FROM schedule_events WHERE title = 'DEMO: Moonspire Lantern-Making Workshop'
);

INSERT INTO schedule_events
  (event_type, title, public_details, event_date, start_time, end_time, notes)
SELECT 'other', 'DEMO: Moonlit Story Circle',
       'A fictional family story hour featuring original imaginary characters.',
       DATE_ADD(CURDATE(), INTERVAL 25 DAY), '16:00:00', '17:00:00',
       'DEMO ONLY: fictional event; not a real parish activity.'
WHERE NOT EXISTS (
  SELECT 1 FROM schedule_events WHERE title = 'DEMO: Moonlit Story Circle'
);

INSERT INTO schedule_events
  (event_type, title, public_details, event_date, start_time, end_time, notes)
SELECT 'other', 'DEMO: Firefly Garden Music Evening',
       'A whimsical fictional music gathering for calendar demonstration.',
       DATE_ADD(CURDATE(), INTERVAL 40 DAY), '17:30:00', '19:00:00',
       'DEMO ONLY: fictional event; not a real parish activity.'
WHERE NOT EXISTS (
  SELECT 1 FROM schedule_events WHERE title = 'DEMO: Firefly Garden Music Evening'
);

-- Published on purpose for the public-site demo; each notice says it is fiction.
INSERT INTO announcements (title, content, event_date, is_published)
SELECT 'DEMO: A Moonspire Lantern Evening',
       'FICTIONAL DEMO ANNOUNCEMENT: Join Mira Moonwhistle and friends for a storybook lantern evening. This is imaginary sample content, not a real parish event or schedule.',
       DATE_ADD(CURDATE(), INTERVAL 18 DAY), 1
WHERE NOT EXISTS (SELECT 1 FROM announcements WHERE title = 'DEMO: A Moonspire Lantern Evening');

INSERT INTO announcements (title, content, event_date, is_published)
SELECT 'DEMO: The Starlight Story Circle',
       'FICTIONAL DEMO ANNOUNCEMENT: Meet original imaginary characters Orin Starling and Sera Bramblewick at a make-believe family story hour. Not a real parish activity.',
       DATE_ADD(CURDATE(), INTERVAL 25 DAY), 1
WHERE NOT EXISTS (SELECT 1 FROM announcements WHERE title = 'DEMO: The Starlight Story Circle');

INSERT INTO announcements (title, content, event_date, is_published)
SELECT 'DEMO: A Garden Full of Fireflies',
       'FICTIONAL DEMO ANNOUNCEMENT: The Moonspire friends invite you to an imaginary evening of music, paper lanterns, and firefly tales. This is sample content only.',
       DATE_ADD(CURDATE(), INTERVAL 40 DAY), 1
WHERE NOT EXISTS (SELECT 1 FROM announcements WHERE title = 'DEMO: A Garden Full of Fireflies');

INSERT INTO announcements (title, content, event_date, is_published)
SELECT 'DEMO: Whimsical Sacrament Application Walkthrough',
       'FICTIONAL DEMO NOTICE: This notice and its sample requests use invented people and imaginary storybook names. No real applications, bookings, or parish schedules are represented.',
       NULL, 1
WHERE NOT EXISTS (
  SELECT 1 FROM announcements WHERE title = 'DEMO: Whimsical Sacrament Application Walkthrough'
);

COMMIT;
