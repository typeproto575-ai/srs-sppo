-- =====================================================================
-- SRS-SPPO: Schedule and Record System of Saint Patrick's Parish Office
-- Database Schema (MySQL / MariaDB, for XAMPP)
-- No monetary/fee fields anywhere by design.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS srs_sppo_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE srs_sppo_db;

-- =====================================================================
-- 1. USERS (Parish Secretary staff accounts)
-- =====================================================================

CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name       VARCHAR(150) NOT NULL,
  username        VARCHAR(50)  NOT NULL UNIQUE,
  email           VARCHAR(150) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('secretary') NOT NULL DEFAULT 'secretary',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  auth_version    INT UNSIGNED NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
-- 2. DIGITAL RECORD INDEXES (mirror the physical logbooks)
-- =====================================================================

-- Shared identity index: one row per person appearing in the record
-- logbooks. The Person Mapper (admin/system/person-mapper.php) links
-- record rows to persons through the nullable *_person_id bridge
-- columns below (folded in from migrations/001).
CREATE TABLE persons (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name       VARCHAR(150) NOT NULL,
  middle_name      VARCHAR(100) DEFAULT NULL,
  last_name        VARCHAR(100) DEFAULT NULL,
  suffix           VARCHAR(20) DEFAULT NULL,
  maiden_name      VARCHAR(150) DEFAULT NULL,
  sex              ENUM('male','female') DEFAULT NULL,
  birth_date       DATE DEFAULT NULL,
  birth_place      VARCHAR(150) DEFAULT NULL,
  address          VARCHAR(255) DEFAULT NULL,
  contact_number   VARCHAR(30) DEFAULT NULL,
  email            VARCHAR(150) DEFAULT NULL,
  baptismal_status ENUM('baptized_catholic','baptized_non_catholic','unbaptized') DEFAULT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --- Baptismal -------------------------------------------------------
CREATE TABLE baptismal_records (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no          VARCHAR(20),
  baptism_date      DATE NOT NULL,
  baptism_place     VARCHAR(150),          -- Locus Baptismi (per Baptismal Register)
  child_birthplace  VARCHAR(150),          -- Child's place of birth (printed on certificates)
  child_full_name   VARCHAR(150) NOT NULL,
  child_first_name  VARCHAR(100),
  child_middle_name VARCHAR(100),
  child_last_name   VARCHAR(100),
  child_suffix      VARCHAR(20),
  birth_date        DATE,
  legitimacy        ENUM('legitimate', 'illegitimate') NULL,  -- internal only, never exposed on outputs
  union_type        ENUM('legitimate', 'civil', 'natural') NULL,  -- Legitimate=Church Marriage, Civil=Civil Marriage, Natural=Not Married
  father_name       VARCHAR(150),
  father_birthplace VARCHAR(150),          -- Father's birthplace (per parish form)
  mother_name       VARCHAR(150),
  mother_maiden_name VARCHAR(150),         -- Mother's maiden name (per parish form)
  mother_birthplace VARCHAR(150),          -- Mother's birthplace (per parish form)
  parents_origin    VARCHAR(150),
  parents_residence VARCHAR(150),
  minister_name     VARCHAR(150),
  proxy_name        VARCHAR(150) DEFAULT NULL,  -- for cases where a proxy stands in for an absent godparent
  remarks           TEXT,
  book_no           VARCHAR(20),
  page_no           VARCHAR(20),
  line_no           VARCHAR(20),
  date_encoded      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by        INT UNSIGNED,
  certificate_request_id INT UNSIGNED,  -- reverse link: approved cert request that cited this record (set on match)
  person_id         INT UNSIGNED,        -- bridge to persons.id (Person Mapper)
  mapper_skipped_at DATETIME NULL,
  book_verified_by  INT UNSIGNED,        -- secretary who confirmed this entry vs. the physical register (031)
  book_verified_at  DATETIME NULL,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (book_verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE baptismal_sponsors (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baptismal_record_id INT UNSIGNED NOT NULL,
  sponsor_name        VARCHAR(150) NOT NULL,
  sponsor_residence   VARCHAR(150),
  sponsor_type        ENUM('godfather', 'godmother') NOT NULL DEFAULT 'godfather',  -- Ninong (godfather) or Ninang (godmother)
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Confirmation ------------------------------------------------------
CREATE TABLE confirmation_records (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no            VARCHAR(20),
  confirmation_date   DATE NOT NULL,
  confirmand_full_name VARCHAR(150) NOT NULL,
  confirmand_first_name VARCHAR(100),
  confirmand_middle_name VARCHAR(100),
  confirmand_last_name VARCHAR(100),
  confirmand_suffix VARCHAR(20),
  birthdate           DATE,
  birthdate_not_recorded TINYINT(1) NOT NULL DEFAULT 0,
  father_name         VARCHAR(150),
  father_name_not_recorded TINYINT(1) NOT NULL DEFAULT 0,
  mother_maiden_name  VARCHAR(150),          -- renamed from mother_name for consistency with baptismal_records
  mother_maiden_name_not_recorded TINYINT(1) NOT NULL DEFAULT 0,
  sponsor_name        VARCHAR(150),
  minister_name       VARCHAR(150),
  baptismal_record_id INT UNSIGNED,          -- prerequisite link
  remarks             TEXT,
  book_no             VARCHAR(20),
  page_no             VARCHAR(20),
  line_no             VARCHAR(20),
  date_encoded        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by          INT UNSIGNED,
  certificate_request_id INT UNSIGNED, -- reverse link: approved cert request (set on match)
  person_id           INT UNSIGNED,      -- bridge to persons.id (Person Mapper)
  mapper_skipped_at   DATETIME NULL,
  book_verified_by    INT UNSIGNED,      -- secretary who confirmed this entry vs. the physical register (031)
  book_verified_at    DATETIME NULL,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (book_verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --- First Communion ---------------------------------------------------
CREATE TABLE first_communion_records (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no              VARCHAR(20),
  communion_date        DATE NOT NULL,
  communicant_full_name VARCHAR(150) NOT NULL,
  communicant_first_name VARCHAR(100),
  communicant_middle_name VARCHAR(100),
  communicant_last_name VARCHAR(100),
  communicant_suffix VARCHAR(20),
  domicile              VARCHAR(255),      -- address per Book of First Communicants
  father_name           VARCHAR(150),
  mother_maiden_name    VARCHAR(150),        -- renamed from mother_name for consistency with baptismal_records
  minister_name         VARCHAR(150),
  baptismal_record_id   INT UNSIGNED,        -- prerequisite link
  remarks               TEXT,
  book_no               VARCHAR(20),
  page_no               VARCHAR(20),
  line_no               VARCHAR(20),
  date_encoded          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by            INT UNSIGNED,
  certificate_request_id INT UNSIGNED, -- reverse link: approved cert request (set on match)
  person_id             INT UNSIGNED,    -- bridge to persons.id (Person Mapper)
  mapper_skipped_at     DATETIME NULL,
  book_verified_by      INT UNSIGNED,    -- secretary who confirmed this entry vs. the physical register (031)
  book_verified_at      DATETIME NULL,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (book_verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --- Marriage ------------------------------------------------------------
CREATE TABLE marriage_records (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no            VARCHAR(20),
  marriage_date       DATE NOT NULL,
  husband_full_name   VARCHAR(150) NOT NULL,
  husband_first_name  VARCHAR(100),
  husband_middle_name VARCHAR(100),
  husband_last_name   VARCHAR(100),
  husband_suffix      VARCHAR(20),
  wife_maiden_name    VARCHAR(150) NOT NULL,
  wife_first_name     VARCHAR(100),
  wife_middle_name    VARCHAR(100),
  wife_last_name      VARCHAR(100),
  wife_suffix         VARCHAR(20),
  husband_birthdate   DATE,
  husband_birthplace  VARCHAR(150),
  husband_civil_status VARCHAR(50),
  husband_age         INT UNSIGNED,
  husband_origin      VARCHAR(150),
  husband_residence   VARCHAR(255),
  husband_parents_name VARCHAR(255),
  wife_birthdate      DATE,
  wife_birthplace     VARCHAR(150),
  wife_civil_status   VARCHAR(50),
  wife_age            INT UNSIGNED,
  wife_origin         VARCHAR(150),
  wife_residence      VARCHAR(255),
  wife_parents_name   VARCHAR(255),
  officiating_minister VARCHAR(150),
  remarks             TEXT,
  book_no             VARCHAR(20),
  page_no             VARCHAR(20),
  line_no             VARCHAR(20),
  date_encoded        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by          INT UNSIGNED,
  certificate_request_id INT UNSIGNED, -- reverse link: approved cert request (set on match)
  husband_person_id   INT UNSIGNED,      -- bridge to persons.id (Person Mapper)
  wife_person_id      INT UNSIGNED,      -- bridge to persons.id (Person Mapper)
  husband_mapper_skipped_at DATETIME NULL,
  wife_mapper_skipped_at    DATETIME NULL,
  book_verified_by     INT UNSIGNED,      -- secretary who confirmed this entry vs. the physical register (031)
  book_verified_at     DATETIME NULL,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (book_verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE marriage_witnesses (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  marriage_record_id INT UNSIGNED NOT NULL,
  witness_name       VARCHAR(150) NOT NULL,
  witness_residence  VARCHAR(150),
  FOREIGN KEY (marriage_record_id) REFERENCES marriage_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- 3. CERTIFICATE REQUESTS (shared table + one extension table per type)
-- =====================================================================

CREATE TABLE certificate_requests (
  id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_number             VARCHAR(30) NOT NULL UNIQUE,
  submission_channel          ENUM('online','onsite') NOT NULL DEFAULT 'onsite',
  sacrament_type              ENUM('baptismal', 'confirmation', 'first_communion', 'marriage') NOT NULL,
  requester_name              VARCHAR(150) NOT NULL,
  requester_relationship      ENUM('self', 'parent', 'guardian', 'spouse', 'sibling', 'child', 'other') NOT NULL,
  requester_relationship_other VARCHAR(100),
  requester_email             VARCHAR(150) NOT NULL,
  requester_contact_number    VARCHAR(30),
  requester_mailing_address   VARCHAR(500) NULL,
  purpose                     VARCHAR(40) NOT NULL,  -- legacy public-form purpose; new requests use 'unspecified'
  purpose_other               VARCHAR(150),
  notes                       TEXT,
  review_notes                TEXT NULL,
  rejection_reason            TEXT NULL,
  status                      ENUM('pending','submitted','under_review','requirements_pending','requirements_incomplete','awaiting_onsite_verification','awaiting_payment','approved','processing','ready_for_pickup','released','rejected','cancelled') NOT NULL DEFAULT 'pending',
  date_submitted               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_processed               DATETIME,
  anonymized_at                DATETIME NULL,
  verification_code            VARCHAR(64) NULL UNIQUE,
  processed_by                 INT UNSIGNED,
  paid_at                      DATETIME NULL,        -- secretary marks payment received by hand (migration 035); no amount stored
  paid_by                      INT UNSIGNED NULL,
  match_confirmed_at           DATETIME NULL,        -- secretary confirms the register match (migration 037); gates public payment prompt
  match_confirmed_by           INT UNSIGNED NULL,
  KEY idx_cert_purge (status, date_processed, date_submitted, anonymized_at),
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE baptismal_certificate_requests (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id           INT UNSIGNED NOT NULL UNIQUE,
  child_full_name      VARCHAR(150) NOT NULL,
  father_full_name     VARCHAR(150) NOT NULL,
  mother_full_name     VARCHAR(150) NOT NULL,
  birthplace           VARCHAR(150),
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
  date_of_baptism       DATE,              -- per parish form: baptism provenance
  place_of_baptism      VARCHAR(150),      -- per parish form
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
  id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id                      INT UNSIGNED NOT NULL UNIQUE,

  -- Party A (per parish "Notification of Marriage" form)
  party_a_full_name               VARCHAR(150) NOT NULL,
  party_a_father_name             VARCHAR(150),
  party_a_mother_name             VARCHAR(150),
  party_a_baptized_in_church_of   VARCHAR(150),
  party_a_date_of_baptism         DATE,
  party_a_book_no                 VARCHAR(20),
  party_a_page_no                 VARCHAR(20),
  party_a_line_no                 VARCHAR(20),

  -- Party B (per parish "Notification of Marriage" form)
  party_b_full_name               VARCHAR(150) NOT NULL,
  party_b_father_name             VARCHAR(150),
  party_b_mother_name             VARCHAR(150),
  party_b_baptized_in_church_of   VARCHAR(150),
  party_b_date_of_baptism         DATE,
  party_b_book_no                 VARCHAR(20),
  party_b_page_no                 VARCHAR(20),
  party_b_line_no                 VARCHAR(20),

  -- Contracted Marriage detail
  marriage_date                   DATE,
  officiating_minister            VARCHAR(150),

  matched_record_id               INT UNSIGNED,
  FOREIGN KEY (request_id) REFERENCES certificate_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (matched_record_id) REFERENCES marriage_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- =====================================================================
-- 4. SACRAMENT APPLICATIONS (shared table + one extension per type)
--    Baptism and Marriage have forms; Confirmation and First Communion are
--    status flags on records (legacy extension tables kept for old data).
-- =====================================================================

CREATE TABLE sacrament_applications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_number VARCHAR(30) NOT NULL UNIQUE,
  submission_channel ENUM('online','onsite') NOT NULL DEFAULT 'onsite',
  sacrament_type  ENUM('baptismal', 'confirmation', 'first_communion', 'marriage') NOT NULL,
  status          ENUM('submitted','requirements_pending','requirements_incomplete','awaiting_onsite_verification','documents_pending','interview_scheduled','banns_in_progress','seminar_pending','ready_for_approval','awaiting_payment','approved','scheduled','completed','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  date_submitted  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_processed  DATETIME,
  anonymized_at   DATETIME NULL,
  processed_by    INT UNSIGNED,
  paid_at         DATETIME NULL,             -- secretary marks payment received by hand (migration 035); no amount stored
  paid_by         INT UNSIGNED NULL,
  notes           TEXT,
  KEY idx_sacr_purge (status, date_processed, date_submitted, anonymized_at),
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE baptismal_applications (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id        INT UNSIGNED NOT NULL UNIQUE,
  child_full_name       VARCHAR(150) NOT NULL,
  child_birthdate       DATE NOT NULL,
  child_birthplace      VARCHAR(150) NOT NULL,
  father_full_name      VARCHAR(150),
  father_birthplace     VARCHAR(150),          -- legacy application data
  mother_maiden_name    VARCHAR(150),          -- per parish form (maiden name)
  mother_birthplace     VARCHAR(150),          -- legacy application data
  parents_address       VARCHAR(255),          -- legacy application data
  parents_union_type    ENUM('legitimate', 'civil', 'natural') DEFAULT 'legitimate', -- new applications set NULL; legacy default retained
  parent_guardian_name  VARCHAR(150) NOT NULL, -- whoever is submitting/vouching for the child
  contact_number        VARCHAR(30),
  email                 VARCHAR(150) NOT NULL,
  preferred_date         DATE,
  delegated_minister     VARCHAR(150),          -- per parish form
  book_no                VARCHAR(20),
  page_no                VARCHAR(20),
  line_no                VARCHAR(20),
  certificate_done       TINYINT(1) NOT NULL DEFAULT 0, -- per parish form
  baptism_date           DATE,                 -- per parish form (Date of Baptism)
  notes                 TEXT,
  documents_verified    TINYINT(1) NOT NULL DEFAULT 0, -- secretary: required docs presented (013)
  seminar_attended      TINYINT(1) NOT NULL DEFAULT 0, -- godparents/parents seminar done (013)
  FOREIGN KEY (application_id) REFERENCES sacrament_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Godparents table with type separation (godfather=ninong, godmother=ninang)
CREATE TABLE baptismal_application_godparents (
  id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baptismal_application_id INT UNSIGNED NOT NULL,
  godparent_name           VARCHAR(150) NOT NULL,
  godparent_residence      VARCHAR(150),
  godparent_type           ENUM('godfather', 'godmother') NOT NULL DEFAULT 'godfather', -- Ninong (godfather) or Ninang (godmother)
  FOREIGN KEY (baptismal_application_id) REFERENCES baptismal_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Optional profile and baptism-identification fields are retained for
-- legacy rows; current public and walk-in intake forms do not collect them.
CREATE TABLE confirmation_applications (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id        INT UNSIGNED NOT NULL UNIQUE,
  applicant_full_name   VARCHAR(150) NOT NULL,
  applicant_birthdate   DATE NOT NULL,
  applicant_gender      VARCHAR(20),
  applicant_birthplace  VARCHAR(150),
  current_address       VARCHAR(255),
  school_name           VARCHAR(150),
  grade_level_section   VARCHAR(100),
  adviser_name          VARCHAR(150),
  parent_guardian_name  VARCHAR(150) NOT NULL,
  father_full_name      VARCHAR(150),
  mother_maiden_name    VARCHAR(150),
  contact_number        VARCHAR(30),
  email                 VARCHAR(150) NOT NULL,
  parents_catholic_married VARCHAR(30),
  baptized_here         TINYINT(1) NOT NULL DEFAULT 0,
  baptism_date          DATE,
  baptism_parish_name   VARCHAR(150),
  baptism_parish_address VARCHAR(255),
  baptismal_record_id   INT UNSIGNED,
  baptismal_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',
  psa_birth_certificate_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  catechetical_formation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  confession_completed  TINYINT(1) NOT NULL DEFAULT 0,
  parental_consent      TINYINT(1) NOT NULL DEFAULT 0,
  preferred_batch       VARCHAR(50),
  sponsor_name          VARCHAR(150),
  age_eligibility_flag  TINYINT(1) NOT NULL DEFAULT 0,
  seminar_attended      TINYINT(1) NOT NULL DEFAULT 0, -- seminar completed (013)
  FOREIGN KEY (application_id) REFERENCES sacrament_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Optional profile and baptism-identification fields are retained for
-- legacy rows; current public and walk-in intake forms do not collect them.
CREATE TABLE first_communion_applications (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id        INT UNSIGNED NOT NULL UNIQUE,
  applicant_full_name   VARCHAR(150) NOT NULL,
  applicant_birthdate   DATE NOT NULL,
  parent_guardian_name  VARCHAR(150) NOT NULL,
  contact_number        VARCHAR(30),
  email                 VARCHAR(150) NOT NULL,
  baptized_here         TINYINT(1) NOT NULL DEFAULT 0,
  baptismal_record_id   INT UNSIGNED,
  baptismal_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',
  preferred_batch       VARCHAR(50),
  age_eligibility_flag  TINYINT(1) NOT NULL DEFAULT 0,
  seminar_attended      TINYINT(1) NOT NULL DEFAULT 0, -- seminar completed (013)
  FOREIGN KEY (application_id) REFERENCES sacrament_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE marriage_applications (
  id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id              INT UNSIGNED NOT NULL UNIQUE,

  groom_full_name             VARCHAR(150) NOT NULL,
  groom_birthdate             DATE NOT NULL,
  groom_birthplace            VARCHAR(150) NOT NULL,
  groom_current_address       VARCHAR(255),
  groom_address_length        VARCHAR(80),
  groom_contact_number        VARCHAR(30),
  groom_email                 VARCHAR(150) NOT NULL,
  groom_religion              VARCHAR(100),
  groom_occupation            VARCHAR(150),
  groom_father_name           VARCHAR(150),
  groom_mother_maiden_name    VARCHAR(150),
  groom_marital_status        ENUM('single', 'widowed', 'annulled', 'married') NOT NULL,
  groom_baptized_here         TINYINT(1) NOT NULL DEFAULT 0,
  groom_confirmed              TINYINT(1) NOT NULL DEFAULT 0,
  groom_previous_marriage     VARCHAR(30),
  groom_baptismal_record_id   INT UNSIGNED,
  groom_baptismal_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',
  groom_confirmation_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',

  bride_full_name             VARCHAR(150) NOT NULL,
  bride_birthdate             DATE NOT NULL,
  bride_birthplace            VARCHAR(150) NOT NULL,
  bride_current_address       VARCHAR(255),
  bride_address_length        VARCHAR(80),
  bride_contact_number        VARCHAR(30),
  bride_email                 VARCHAR(150) NOT NULL,
  bride_religion              VARCHAR(100),
  bride_occupation            VARCHAR(150),
  bride_father_name           VARCHAR(150),
  bride_mother_maiden_name    VARCHAR(150),
  bride_marital_status        ENUM('single', 'widowed', 'annulled', 'married') NOT NULL,
  bride_baptized_here         TINYINT(1) NOT NULL DEFAULT 0,
  bride_confirmed              TINYINT(1) NOT NULL DEFAULT 0,
  bride_previous_marriage     VARCHAR(30),
  bride_baptismal_record_id   INT UNSIGNED,
  bride_baptismal_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',
  bride_confirmation_cert_status ENUM('submitted', 'pending') NOT NULL DEFAULT 'pending',

  preferred_wedding_date      DATE NOT NULL,
  preferred_wedding_time      TIME,
  preferred_wedding_venue     ENUM('church','barangay_chapel') NOT NULL DEFAULT 'church',  -- Kasal venue
  assigned_priest_name        VARCHAR(150),
  civilly_married_to_each_other TINYINT(1) NOT NULL DEFAULT 0,
  civil_marriage_date         DATE,
  civil_marriage_place        VARCHAR(150),
  venue_chapel_name           VARCHAR(150) DEFAULT NULL,  -- chapel name when preferred_wedding_venue = 'barangay_chapel'
  marriage_license_ref      VARCHAR(100),
  groom_cenomar_status        VARCHAR(20) NOT NULL DEFAULT 'pending',
  bride_cenomar_status        VARCHAR(20) NOT NULL DEFAULT 'pending',
  marriage_license_status     VARCHAR(20) NOT NULL DEFAULT 'pending',
  psa_marriage_contract_status VARCHAR(20) NOT NULL DEFAULT 'not_applicable',
  groom_baptismal_annotated   TINYINT(1) NOT NULL DEFAULT 0,
  bride_baptismal_annotated   TINYINT(1) NOT NULL DEFAULT 0,
  groom_confirmation_annotated TINYINT(1) NOT NULL DEFAULT 0,
  bride_confirmation_annotated TINYINT(1) NOT NULL DEFAULT 0,
  -- Marriage license reference number (text field, not upload)
  pre_cana_seminar_completed  TINYINT(1) NOT NULL DEFAULT 0,
  pre_cana_completion_date    DATE,
  canonical_interview_completed TINYINT(1) NOT NULL DEFAULT 0,
  canonical_interview_date    DATE,
  banns_date_1                DATE,
  banns_date_2                DATE,
  banns_date_3                DATE,
  groom_banns_status           VARCHAR(30) NOT NULL DEFAULT 'pending',
  bride_banns_status           VARCHAR(30) NOT NULL DEFAULT 'pending',

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
-- 5. ELIGIBILITY SETTINGS (configurable age ranges, no hardcoding)
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
-- 6. SCHEDULE, CALENDAR, AND ANNOUNCEMENTS
-- =====================================================================

CREATE TABLE schedule_events (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type      ENUM('mass', 'wedding', 'baptism', 'other') NOT NULL,
  title           VARCHAR(150) NOT NULL,
  public_details  TEXT,
  event_date      DATE NOT NULL,
  start_time      TIME,
  end_time        TIME,
  related_request_type ENUM('marriage_application', 'baptismal_certificate_request', 'other'),
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
-- 7. CERTIFICATE GENERATION LOG (generate / preview / print / pickup)
-- =====================================================================

CREATE TABLE certificate_generation_log (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  certificate_request_id INT UNSIGNED NOT NULL,
  generated_by           INT UNSIGNED,
  printed_by             INT UNSIGNED,            -- staff who confirmed physical printing (see 009)
  generated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  printed_at             DATETIME,                -- when printing was confirmed (see 009)
  print_status           ENUM('generated', 'printed', 'released') NOT NULL DEFAULT 'generated',
  pickup_date            DATETIME,
  claimant_name          VARCHAR(255),            -- who picked up the certificate (see 010)
  claimant_relationship  VARCHAR(120),            -- relationship to the subject (see 010)
  claimant_notes         TEXT,                    -- optional release notes (see 010)
  id_verified            TINYINT(1) NOT NULL DEFAULT 0, -- whether valid ID was verified (see 010)
  superseded_at          DATETIME,                -- set when replaced by a newer regeneration (see 009)
  superseded_by          INT UNSIGNED,            -- staff who performed the regeneration (see 009)
  KEY idx_cgl_request_active (certificate_request_id, superseded_at),
  FOREIGN KEY (certificate_request_id) REFERENCES certificate_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (printed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (superseded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 8. CERTIFICATE CORRECTION REQUESTS
-- =====================================================================

CREATE TABLE correction_requests (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_number    VARCHAR(50) NOT NULL,
  requester_name     VARCHAR(255) NOT NULL,
  requester_email    VARCHAR(255) NOT NULL,
  certificate_id     INT UNSIGNED NOT NULL,
  requested_changes  TEXT NOT NULL,
  document_path      VARCHAR(255),
  admin_note         TEXT,
  status             ENUM('pending','processing','completed','rejected') NOT NULL DEFAULT 'pending',
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  anonymized_at      DATETIME NULL,
  KEY idx_correction_tracking (tracking_number),
  KEY idx_correction_status_created (status, created_at),
  KEY idx_corr_purge (status, updated_at, anonymized_at)
) ENGINE=InnoDB;

-- =====================================================================
-- 9. AUDIT LOG (activity logging, per your admin dashboard's Audit Log page)
-- =====================================================================

CREATE TABLE audit_logs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED,
  role_snapshot ENUM('secretary') DEFAULT NULL,
  action       VARCHAR(150) NOT NULL,
  target_table VARCHAR(100),
  target_id    INT UNSIGNED,
  description  TEXT,
  ip_address   VARCHAR(45) DEFAULT NULL,
  user_agent   VARCHAR(500) DEFAULT NULL,
  reason       VARCHAR(500) DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created_action (created_at, action),
  KEY idx_audit_role (role_snapshot, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 9. PUBLIC SUBMISSION RATE LIMITS (abuse guard for the no-login forms)
-- One row per successful public submission; powers the rolling 24h caps
-- of 5 per email address and 20 per client IP enforced in
-- includes/functions.php (see migrations/018_add_submission_rate_limits.sql).
-- Rows are disposable -- safe to purge whenever.
-- =====================================================================

CREATE TABLE submission_rate_limits (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(150) NOT NULL,
  ip_address   VARCHAR(45) NOT NULL,
  form_type    VARCHAR(50) NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_time (email, submitted_at),
  KEY idx_ip_time    (ip_address, submitted_at),
  KEY idx_ratelimit_purge (submitted_at)
) ENGINE=InnoDB;

-- =====================================================================
-- Seed defaults for eligibility settings (placeholders -- confirm with priest)
-- =====================================================================

INSERT INTO sacrament_eligibility_settings (sacrament_type, min_age, max_age) VALUES
  ('first_communion', 7, 12),
  ('confirmation', 12, 18);

-- =====================================================================
-- 8. SITE SETTINGS (secretary-editable public page content)
-- Key/value store backing Admin > Website > Public Page Content. Seeds
-- mirror the values previously hardcoded on public/index.php.
-- =====================================================================
CREATE TABLE site_settings (
  setting_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key   VARCHAR(64)  NOT NULL UNIQUE,
  setting_value TEXT         NOT NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by    INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (setting_key, setting_value) VALUES
('hero_eyebrow_text', 'Parish Office Online'),
('hero_subtitle_text', 'Dingalan, Aurora - the parish''s sacrament records and certificates, kept faithfully for generations, now easier to reach.'),
('office_hours_text', CONCAT('Monday - Saturday: 8:00 AM - 12:00 NN', CHAR(10), 'Monday - Saturday: 2:00 PM - 5:00 PM', CHAR(10), 'Sunday: Closed')),
('office_location_text', CONCAT('St. Patrick''s Parish Office', CHAR(10), 'Dingalan, Aurora, Philippines'));

-- =====================================================================
-- ADMIN NOTIFICATIONS (global bell in the admin header)
-- =====================================================================
-- One shared stream for all staff: every row is visible to whoever is
-- logged in, and a single is_read flag marks it handled. The link column
-- stores an APP-RELATIVE path (e.g. /admin/requests/queue.php) — the API
-- endpoint prepends base_path() so it works in subfolder installs too.
CREATE TABLE notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        ENUM('request_new','application_new','request_ready','event_reminder','conflict',
                   'request_under_review','request_approved','request_rejected',
                   'application_submitted','application_awaiting_payment','application_documents_pending','application_interview_scheduled',
                   'application_banns_in_progress','application_seminar_pending','application_ready_for_approval',
                   'application_approved','application_scheduled','application_completed','application_rejected',
                   'certificate_picked_up') NOT NULL,
  title       VARCHAR(150) NOT NULL,
  message     VARCHAR(255) NOT NULL,
  link        VARCHAR(200) NOT NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at     DATETIME NULL,
  INDEX idx_notif_unread (is_read, created_at),
  INDEX idx_notif_purge (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 11. EMAIL DELIVERY LOG
-- Records sent, failed, disabled, and invalid email attempts.
-- =====================================================================
CREATE TABLE email_delivery_log (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient       VARCHAR(150) NOT NULL,
  subject_line    VARCHAR(255) NOT NULL,
  event_type      VARCHAR(80) NOT NULL,
  entity_type     VARCHAR(80) DEFAULT NULL,
  entity_id       INT UNSIGNED DEFAULT NULL,
  tracking_number VARCHAR(40) DEFAULT NULL,
  delivery_status ENUM('sent','failed','disabled','invalid') NOT NULL,
  error_message   VARCHAR(500) DEFAULT NULL,
  sent_by         INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_delivery_entity (entity_type, entity_id),
  KEY idx_email_delivery_status (delivery_status, created_at),
  KEY idx_email_delivery_recipient (recipient, created_at),
  KEY idx_delivery_purge (created_at),
  FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 10. WORKFLOW REQUIREMENTS CHECKLIST
-- Tracks documentary review without storing uploaded documents.
-- =====================================================================
CREATE TABLE workflow_requirements (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type     ENUM('certificate_request','sacrament_application') NOT NULL,
  entity_id       INT UNSIGNED NOT NULL,
  requirement_key VARCHAR(80) NOT NULL,
  requirement_label VARCHAR(180) NOT NULL,
  status          ENUM('not_received','received','under_review','incomplete','verified','not_applicable') NOT NULL DEFAULT 'not_received',
  notes           VARCHAR(500) DEFAULT NULL,
  reviewed_by     INT UNSIGNED DEFAULT NULL,
  reviewed_at     DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_workflow_requirement (entity_type, entity_id, requirement_key),
  KEY idx_workflow_requirement_entity (entity_type, entity_id),
  KEY idx_workflow_requirement_status (status),
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- =====================================================================
-- 11. PHASE 4 WORKFLOW CONTROLS
-- Existing deployments use migrations/022_phase4_workflow_controls.sql.
-- Fresh installs apply the same fields after the base tables are created.
-- =====================================================================
ALTER TABLE certificate_requests
  ADD COLUMN requirements_source ENUM('not_yet_received','physical','official_gmail','physical_and_gmail') NOT NULL DEFAULT 'not_yet_received',
  ADD COLUMN requirements_reviewed_at DATETIME NULL,
  ADD COLUMN requirements_reviewed_by INT UNSIGNED NULL,
  ADD COLUMN missing_requirements_note VARCHAR(500) NULL,
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN cancelled_by INT UNSIGNED NULL,
  ADD COLUMN cancellation_reason VARCHAR(500) NULL;
ALTER TABLE sacrament_applications
  ADD COLUMN requirements_source ENUM('not_yet_received','physical','official_gmail','physical_and_gmail') NOT NULL DEFAULT 'not_yet_received',
  ADD COLUMN requirements_reviewed_at DATETIME NULL,
  ADD COLUMN requirements_reviewed_by INT UNSIGNED NULL,
  ADD COLUMN missing_requirements_note VARCHAR(500) NULL;
ALTER TABLE certificate_generation_log
  ADD COLUMN finalized_by INT UNSIGNED NULL,
  ADD COLUMN finalized_at DATETIME NULL,
  ADD COLUMN cancelled_by INT UNSIGNED NULL,
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN cancellation_reason VARCHAR(500) NULL,
  ADD COLUMN reprint_reason VARCHAR(500) NULL;
ALTER TABLE certificate_requests
  MODIFY status ENUM('pending','submitted','under_review','requirements_pending','requirements_incomplete','awaiting_onsite_verification','awaiting_payment','approved','processing','ready_for_pickup','released','rejected','cancelled') NOT NULL DEFAULT 'pending';
ALTER TABLE baptismal_records ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT UNSIGNED NULL, ADD COLUMN archive_reason VARCHAR(500) NULL;
ALTER TABLE confirmation_records ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT UNSIGNED NULL, ADD COLUMN archive_reason VARCHAR(500) NULL;
ALTER TABLE first_communion_records ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT UNSIGNED NULL, ADD COLUMN archive_reason VARCHAR(500) NULL;
ALTER TABLE marriage_records ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT UNSIGNED NULL, ADD COLUMN archive_reason VARCHAR(500) NULL;

-- --- Secretary password recovery --------------------------------------
CREATE TABLE password_reset_tokens (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  token_hash        CHAR(64) NOT NULL UNIQUE,
  requested_ip      VARCHAR(45) NOT NULL,
  expires_at        DATETIME NOT NULL,
  used_at           DATETIME DEFAULT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_password_reset_user_created (user_id, created_at),
  INDEX idx_password_reset_expiry (expires_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Applied upgrade history for the protected System > Database Upgrades page.
CREATE TABLE srs_schema_migrations (
  migration_key VARCHAR(80) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_by INT UNSIGNED NULL
) ENGINE=InnoDB;

INSERT INTO srs_schema_migrations (migration_key) VALUES
  ('007_site_settings'),
  ('012_request_review_fields'),
  ('038_data_retention_support');
