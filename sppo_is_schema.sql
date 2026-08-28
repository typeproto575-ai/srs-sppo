-- =====================================================================
-- SPPO-IS: Saint Patrick's Parish Office Information System
-- Database Schema (MySQL / MariaDB, for XAMPP)
--
-- v2: Person-centric model, canon-law-aligned fields, structured marginal
-- notations, and logbook scan support -- based on Code of Canon Law /
-- CBCP Updated Complementary Norms.
--
-- SCOPE NOTE: Death Register and Catechumen/RCIA Register are included
-- in this schema for canonical completeness and future-proofing, but
-- are NOT part of the approved capstone UI scope. No public form, admin
-- page, or certificate type should be built against death_records or
-- catechumen_records without explicit confirmation -- they exist here
-- only so the data model doesn't need another rebuild later.
--
-- No monetary/fee fields anywhere by design.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS sppo_is_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE sppo_is_db;

-- =====================================================================
-- 1. USERS (Secretary accounts -- sole system user)
-- =====================================================================

CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name       VARCHAR(150) NOT NULL,
  username        VARCHAR(50)  NOT NULL UNIQUE,
  email           VARCHAR(150) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('secretary') NOT NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
-- 2. PERSONS -- master record. Every sacrament recipient is a person;
--    every canonical register links back here instead of storing its
--    own copy of name/birth data.
-- =====================================================================

CREATE TABLE persons (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name        VARCHAR(100) NOT NULL,
  middle_name       VARCHAR(100),
  last_name         VARCHAR(100) NOT NULL,
  suffix            VARCHAR(20),
  maiden_name       VARCHAR(150),   -- used for women per canonical register convention
  sex               ENUM('male', 'female') NOT NULL,
  birth_date        DATE,
  birth_place       VARCHAR(150),
  address           VARCHAR(255),
  contact_number    VARCHAR(30),
  email             VARCHAR(150),
  baptismal_status  ENUM('baptized_catholic', 'baptized_non_catholic', 'unbaptized') NOT NULL DEFAULT 'unbaptized',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
-- 3. CANONICAL REGISTERS (digital indexes mirroring the physical logbooks)
-- =====================================================================

-- --- Baptismal -------------------------------------------------------
CREATE TABLE baptismal_records (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id         INT UNSIGNED NOT NULL,
  entry_no          VARCHAR(20),
  baptism_date      DATE NOT NULL,
  legitimacy        ENUM('legitimate', 'illegitimate') NULL,  -- internal only, never exposed on outputs
  is_adopted        TINYINT(1) NOT NULL DEFAULT 0,            -- internal only; secret-archive annotation flag per CBCP norms
  father_name       VARCHAR(150),
  mother_name       VARCHAR(150),   -- maiden name
  parents_origin    VARCHAR(150),
  parents_residence VARCHAR(150),
  minister_name     VARCHAR(150),
  minister_title    ENUM('priest', 'deacon') DEFAULT 'priest',
  remarks           TEXT,
  book_no           VARCHAR(20),
  page_no           VARCHAR(20),
  line_no           VARCHAR(20),
  date_encoded      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by        INT UNSIGNED,
  FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE baptismal_sponsors (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baptismal_record_id INT UNSIGNED NOT NULL,
  sponsor_name        VARCHAR(150) NOT NULL,
  sponsor_residence    VARCHAR(150),
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Confirmation ------------------------------------------------------
CREATE TABLE confirmation_records (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id           INT UNSIGNED NOT NULL,
  entry_no            VARCHAR(20),
  confirmation_date   DATE NOT NULL,
  minister_name       VARCHAR(150),
  minister_title      ENUM('bishop', 'priest') DEFAULT 'bishop',
  sponsor_name        VARCHAR(150),
  baptismal_record_id INT UNSIGNED,          -- prerequisite link
  remarks             TEXT,
  book_no             VARCHAR(20),
  page_no             VARCHAR(20),
  line_no             VARCHAR(20),
  date_encoded        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by          INT UNSIGNED,
  FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --- First Communion ---------------------------------------------------
CREATE TABLE first_communion_records (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id             INT UNSIGNED NOT NULL,
  entry_no              VARCHAR(20),
  communion_date        DATE NOT NULL,
  minister_name         VARCHAR(150),
  baptismal_record_id   INT UNSIGNED,        -- prerequisite link
  confirmation_record_id INT UNSIGNED,       -- optional, if already received beforehand
  remarks               TEXT,
  book_no               VARCHAR(20),
  page_no               VARCHAR(20),
  line_no               VARCHAR(20),
  date_encoded          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by            INT UNSIGNED,
  FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL,
  FOREIGN KEY (confirmation_record_id) REFERENCES confirmation_records(id) ON DELETE SET NULL,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --- Marriage ------------------------------------------------------------
CREATE TABLE marriage_records (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  husband_person_id    INT UNSIGNED NOT NULL,
  wife_person_id       INT UNSIGNED NOT NULL,
  entry_no             VARCHAR(20),
  marriage_date        DATE NOT NULL,
  officiating_minister VARCHAR(150),
  minister_title       ENUM('priest', 'deacon') DEFAULT 'priest',
  marriage_type        ENUM('ordinary_canonical', 'mixed_marriage', 'disparity_of_worship',
                             'convalidation', 'radical_sanation', 'other') NOT NULL DEFAULT 'ordinary_canonical',
  remarks              TEXT,
  book_no              VARCHAR(20),
  page_no              VARCHAR(20),
  line_no              VARCHAR(20),
  date_encoded         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by           INT UNSIGNED,
  FOREIGN KEY (husband_person_id) REFERENCES persons(id) ON DELETE CASCADE,
  FOREIGN KEY (wife_person_id) REFERENCES persons(id) ON DELETE CASCADE,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE marriage_witnesses (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  marriage_record_id INT UNSIGNED NOT NULL,
  witness_name       VARCHAR(150) NOT NULL,
  witness_residence  VARCHAR(150),
  FOREIGN KEY (marriage_record_id) REFERENCES marriage_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Death (schema only -- NOT wired into any UI yet) -------------------
CREATE TABLE death_records (
  id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id                       INT UNSIGNED NOT NULL,
  entry_no                        VARCHAR(20),
  date_of_death                   DATE,
  date_of_burial                  DATE,
  place_of_burial                 VARCHAR(150),
  presider_name                   VARCHAR(150),
  presider_title                  ENUM('priest', 'deacon') DEFAULT 'priest',
  age_at_death                    INT UNSIGNED,
  cause_of_death                  VARCHAR(150),
  sacraments_received_near_death  TEXT,
  remarks                         TEXT,
  book_no                         VARCHAR(20),
  page_no                         VARCHAR(20),
  line_no                         VARCHAR(20),
  date_encoded                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by                      INT UNSIGNED,
  FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --- Catechumens / RCIA-OCIA (schema only -- NOT wired into any UI yet) --
CREATE TABLE catechumen_records (
  id                                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id                         INT UNSIGNED NOT NULL,
  baptismal_status_at_entry         ENUM('unbaptized', 'baptized_non_catholic') NOT NULL,
  prior_baptism_date                DATE,
  prior_baptism_place               VARCHAR(150),
  sponsor_name                      VARCHAR(150),
  rite_of_acceptance_date           DATE,
  rite_of_election_date             DATE,
  reception_date                    DATE,   -- full communion / initiation date
  initiation_baptismal_record_id    INT UNSIGNED,
  initiation_confirmation_record_id INT UNSIGNED,
  initiation_first_communion_record_id INT UNSIGNED,
  coordinator_name                  VARCHAR(150),
  status                            ENUM('in_formation', 'initiated', 'transferred', 'discontinued') NOT NULL DEFAULT 'in_formation',
  remarks                           TEXT,
  date_encoded                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by                        INT UNSIGNED,
  FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
  FOREIGN KEY (initiation_baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL,
  FOREIGN KEY (initiation_confirmation_record_id) REFERENCES confirmation_records(id) ON DELETE SET NULL,
  FOREIGN KEY (initiation_first_communion_record_id) REFERENCES first_communion_records(id) ON DELETE SET NULL,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 4. MARGINAL NOTATIONS -- structured, not free text. Traditionally
--    recorded on the baptismal record whenever another sacrament or
--    life event happens to that person.
-- =====================================================================

CREATE TABLE sacrament_notations (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baptismal_record_id   INT UNSIGNED NOT NULL,
  notation_type         ENUM('confirmation', 'first_communion', 'marriage', 'death',
                              'religious_profession', 'ordination', 'other') NOT NULL,
  related_record_type   VARCHAR(50),   -- e.g. 'confirmation_records', 'marriage_records'
  related_record_id     INT UNSIGNED,
  note_text             TEXT,
  date_entered          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  entered_by            INT UNSIGNED,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE CASCADE,
  FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 5. LOGBOOK DIGITIZATION -- scanned images/PDFs of physical register
--    pages, linked to their encoded record for side-by-side verification.
-- =====================================================================

CREATE TABLE record_scans (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_type  ENUM('baptismal', 'confirmation', 'first_communion', 'marriage', 'death', 'catechumen') NOT NULL,
  record_id    INT UNSIGNED NOT NULL,
  file_path    VARCHAR(255) NOT NULL,
  book_no      VARCHAR(20),
  page_no      VARCHAR(20),
  line_no      VARCHAR(20),
  uploaded_by  INT UNSIGNED,
  uploaded_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 6. CERTIFICATE REQUESTS (shared table + one extension table per type)
--    These capture what the REQUESTER states, independent of the persons
--    table, until the secretary matches the request to a canonical record.
-- =====================================================================

CREATE TABLE certificate_requests (
  id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_number             VARCHAR(30) NOT NULL UNIQUE,
  sacrament_type              ENUM('baptismal', 'confirmation', 'first_communion', 'marriage') NOT NULL,
  requester_name              VARCHAR(150) NOT NULL,
  requester_relationship      ENUM('self', 'parent', 'guardian', 'spouse', 'sibling', 'child', 'other') NOT NULL,
  requester_relationship_other VARCHAR(100),
  requester_email             VARCHAR(150) NOT NULL,
  requester_contact_number    VARCHAR(30) NOT NULL,
  purpose                     ENUM('marriage', 'school', 'employment', 'travel', 'civil_registration', 'immigration', 'legal', 'other') NOT NULL,
  purpose_other               VARCHAR(150),
  num_copies                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  notes                       TEXT,
  status                      ENUM('pending', 'under_review', 'approved', 'ready_for_pickup', 'released', 'rejected') NOT NULL DEFAULT 'pending',
  date_submitted               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_processed               DATETIME,
  processed_by                 INT UNSIGNED,
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE baptismal_certificate_requests (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id           INT UNSIGNED NOT NULL UNIQUE,
  child_full_name      VARCHAR(150) NOT NULL,
  father_full_name     VARCHAR(150) NOT NULL,
  mother_full_name     VARCHAR(150) NOT NULL,
  birthplace           VARCHAR(150) NOT NULL,
  birthdate            DATE NOT NULL,
  date_of_baptism      DATE,
  officiating_minister VARCHAR(150),
  sponsors_text        TEXT,
  book_no              VARCHAR(20),
  page_no              VARCHAR(20),
  line_no              VARCHAR(20),
  matched_record_id    INT UNSIGNED,
  FOREIGN KEY (request_id) REFERENCES certificate_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (matched_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE confirmation_certificate_requests (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id            INT UNSIGNED NOT NULL UNIQUE,
  confirmand_full_name  VARCHAR(150) NOT NULL,
  father_full_name      VARCHAR(150) NOT NULL,
  mother_full_name      VARCHAR(150) NOT NULL,
  date_of_confirmation  DATE,
  officiating_minister  VARCHAR(150),
  sponsor_name          VARCHAR(150),
  book_no               VARCHAR(20),
  page_no               VARCHAR(20),
  line_no               VARCHAR(20),
  matched_record_id     INT UNSIGNED,
  FOREIGN KEY (request_id) REFERENCES certificate_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (matched_record_id) REFERENCES confirmation_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE first_communion_certificate_requests (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id              INT UNSIGNED NOT NULL UNIQUE,
  communicant_full_name   VARCHAR(150) NOT NULL,
  father_full_name        VARCHAR(150) NOT NULL,
  mother_full_name        VARCHAR(150) NOT NULL,
  date_of_first_communion DATE,
  officiating_minister    VARCHAR(150),
  book_no                 VARCHAR(20),
  page_no                 VARCHAR(20),
  line_no                 VARCHAR(20),
  matched_record_id       INT UNSIGNED,
  FOREIGN KEY (request_id) REFERENCES certificate_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (matched_record_id) REFERENCES first_communion_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE marriage_certificate_requests (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id           INT UNSIGNED NOT NULL UNIQUE,
  husband_full_name    VARCHAR(150) NOT NULL,
  wife_maiden_name     VARCHAR(150) NOT NULL,
  date_of_marriage     DATE,
  officiating_minister VARCHAR(150),
  book_no              VARCHAR(20),
  page_no              VARCHAR(20),
  line_no              VARCHAR(20),
  matched_record_id    INT UNSIGNED,
  FOREIGN KEY (request_id) REFERENCES certificate_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (matched_record_id) REFERENCES marriage_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 7. SACRAMENT APPLICATIONS (shared table + one extension per type)
--    Baptismal, Confirmation, First Communion, Marriage -- NOT Death
--    (no one applies in advance to be buried) and NOT Catechumen yet
--    (RCIA intake isn't wired into the UI at all -- see note above).
-- =====================================================================

CREATE TABLE sacrament_applications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_number VARCHAR(30) NOT NULL UNIQUE,
  sacrament_type  ENUM('baptismal', 'confirmation', 'first_communion', 'marriage') NOT NULL,
  status          ENUM('submitted', 'documents_pending', 'interview_scheduled', 'banns_in_progress',
                        'seminar_pending', 'ready_for_approval', 'approved', 'scheduled',
                        'completed', 'rejected') NOT NULL DEFAULT 'submitted',
  date_submitted  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_processed  DATETIME,
  processed_by    INT UNSIGNED,
  notes           TEXT,
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Baptism has a real application step in Philippine parish practice:
-- a pre-baptism interview/seminar ("no seminar, no baptism") before the
-- date is confirmed. This is within already-approved Baptismal scope.
CREATE TABLE baptismal_applications (
  id                           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id               INT UNSIGNED NOT NULL UNIQUE,
  child_full_name              VARCHAR(150) NOT NULL,
  birthdate                    DATE NOT NULL,
  birthplace                   VARCHAR(150) NOT NULL,
  father_full_name             VARCHAR(150) NOT NULL,
  mother_full_name             VARCHAR(150) NOT NULL,
  sponsor_names                TEXT,
  contact_number               VARCHAR(30) NOT NULL,
  email                        VARCHAR(150) NOT NULL,
  preferred_baptism_date       DATE,
  pre_baptism_seminar_completed TINYINT(1) NOT NULL DEFAULT 0,
  pre_baptism_seminar_date     DATE,
  FOREIGN KEY (application_id) REFERENCES sacrament_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE confirmation_applications (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id        INT UNSIGNED NOT NULL UNIQUE,
  applicant_full_name   VARCHAR(150) NOT NULL,
  applicant_birthdate   DATE NOT NULL,
  parent_guardian_name  VARCHAR(150) NOT NULL,
  contact_number        VARCHAR(30) NOT NULL,
  email                 VARCHAR(150) NOT NULL,
  baptized_here         TINYINT(1) NOT NULL DEFAULT 0,
  baptismal_record_id   INT UNSIGNED,
  baptismal_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',
  preferred_batch       VARCHAR(50),
  sponsor_name          VARCHAR(150),
  age_eligibility_flag  TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (application_id) REFERENCES sacrament_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE first_communion_applications (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id        INT UNSIGNED NOT NULL UNIQUE,
  applicant_full_name   VARCHAR(150) NOT NULL,
  applicant_birthdate   DATE NOT NULL,
  parent_guardian_name  VARCHAR(150) NOT NULL,
  contact_number        VARCHAR(30) NOT NULL,
  email                 VARCHAR(150) NOT NULL,
  baptized_here         TINYINT(1) NOT NULL DEFAULT 0,
  baptismal_record_id   INT UNSIGNED,
  baptismal_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',
  preferred_batch       VARCHAR(50),
  age_eligibility_flag  TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (application_id) REFERENCES sacrament_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE marriage_applications (
  id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id              INT UNSIGNED NOT NULL UNIQUE,

  groom_full_name             VARCHAR(150) NOT NULL,
  groom_birthdate             DATE NOT NULL,
  groom_birthplace            VARCHAR(150) NOT NULL,
  groom_contact_number        VARCHAR(30) NOT NULL,
  groom_email                 VARCHAR(150) NOT NULL,
  groom_marital_status        ENUM('single', 'widowed', 'annulled') NOT NULL,
  groom_baptized_here         TINYINT(1) NOT NULL DEFAULT 0,
  groom_baptismal_record_id   INT UNSIGNED,
  groom_baptismal_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',
  groom_confirmation_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',

  bride_full_name             VARCHAR(150) NOT NULL,
  bride_birthdate             DATE NOT NULL,
  bride_birthplace            VARCHAR(150) NOT NULL,
  bride_contact_number        VARCHAR(30) NOT NULL,
  bride_email                 VARCHAR(150) NOT NULL,
  bride_marital_status        ENUM('single', 'widowed', 'annulled') NOT NULL,
  bride_baptized_here         TINYINT(1) NOT NULL DEFAULT 0,
  bride_baptismal_record_id   INT UNSIGNED,
  bride_baptismal_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',
  bride_confirmation_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',

  preferred_wedding_date      DATE NOT NULL,
  pre_cana_seminar_completed  TINYINT(1) NOT NULL DEFAULT 0,
  pre_cana_completion_date    DATE,
  canonical_interview_completed TINYINT(1) NOT NULL DEFAULT 0,
  canonical_interview_date    DATE,
  banns_date_1                DATE,
  banns_date_2                DATE,
  banns_date_3                DATE,

  FOREIGN KEY (application_id) REFERENCES sacrament_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (groom_baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL,
  FOREIGN KEY (bride_baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE marriage_principal_sponsors (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  marriage_application_id INT UNSIGNED NOT NULL,
  sponsor_name           VARCHAR(150) NOT NULL,
  sponsor_residence      VARCHAR(150),
  FOREIGN KEY (marriage_application_id) REFERENCES marriage_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- 8. ELIGIBILITY SETTINGS (configurable age ranges, no hardcoding)
-- =====================================================================

CREATE TABLE sacrament_eligibility_settings (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sacrament_type ENUM('first_communion', 'confirmation') NOT NULL UNIQUE,
  min_age        TINYINT UNSIGNED NOT NULL,
  max_age        TINYINT UNSIGNED,
  updated_by     INT UNSIGNED,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 9. SCHEDULE, CALENDAR, AND ANNOUNCEMENTS
-- =====================================================================

CREATE TABLE schedule_events (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type      ENUM('mass', 'wedding', 'baptism', 'other') NOT NULL,
  title           VARCHAR(150) NOT NULL,
  event_date      DATE NOT NULL,
  start_time      TIME,
  end_time        TIME,
  celebrant_name  VARCHAR(150),
  venue           VARCHAR(150),
  related_request_type ENUM('marriage_application', 'baptismal_application', 'other'),
  related_request_id   INT UNSIGNED,
  notes           TEXT,
  created_by      INT UNSIGNED,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE announcements (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(150) NOT NULL,
  content       TEXT NOT NULL,
  event_date    DATE,
  is_published  TINYINT(1) NOT NULL DEFAULT 1,
  posted_by     INT UNSIGNED,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 10. CERTIFICATE GENERATION LOG (generate / preview / print / pickup)
-- =====================================================================

CREATE TABLE certificate_generation_log (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  certificate_request_id INT UNSIGNED NOT NULL,
  generated_by           INT UNSIGNED,
  generated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  print_status           ENUM('generated', 'printed', 'released') NOT NULL DEFAULT 'generated',
  pickup_date            DATETIME,
  FOREIGN KEY (certificate_request_id) REFERENCES certificate_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 11. AUDIT LOG
-- =====================================================================

CREATE TABLE audit_logs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED,
  action       VARCHAR(150) NOT NULL,
  target_table VARCHAR(100),
  target_id    INT UNSIGNED,
  description  TEXT,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- Seed defaults for eligibility settings (placeholders -- confirm with the actual parish priest)
-- =====================================================================

INSERT INTO sacrament_eligibility_settings (sacrament_type, min_age, max_age) VALUES
  ('first_communion', 7, 12),
  ('confirmation', 12, 18);

-- =====================================================================
-- Seed the Secretary account (sole system user)
-- Username: secretary   Password: SPPOsecretary2026
-- CHANGE THIS PASSWORD after first login.
-- =====================================================================

INSERT INTO users (full_name, username, email, password_hash, role) VALUES
  ('Parish Secretary', 'secretary', 'secretary@stpatrickdingalan.example',
   '$2b$10$PCV9F/Uyvr7yQOOFXKihK.4t3o/Fa52ibLDTa17JqvY6hrlZdpnfO', 'secretary');
