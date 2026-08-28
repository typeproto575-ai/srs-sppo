-- =====================================================================
-- SPPO-IS: Schedule and Record System of Saint Patrick's Parish Office
-- Database Schema (MySQL / MariaDB, for XAMPP)
-- No monetary/fee fields anywhere by design.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS sppo_is_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE sppo_is_db;

-- =====================================================================
-- 1. USERS (Secretary and Priest accounts)
-- =====================================================================

CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name       VARCHAR(150) NOT NULL,
  username        VARCHAR(50)  NOT NULL UNIQUE,
  email           VARCHAR(150) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('secretary') NOT NULL DEFAULT 'secretary',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
-- 2. DIGITAL RECORD INDEXES (mirror the physical logbooks)
-- =====================================================================

-- --- Baptismal -------------------------------------------------------
CREATE TABLE baptismal_records (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no          VARCHAR(20),
  baptism_date      DATE NOT NULL,
  child_full_name   VARCHAR(150) NOT NULL,
  birth_date        DATE,
  legitimacy        ENUM('legitimate', 'illegitimate') NULL,  -- internal only, never exposed on outputs
  father_name       VARCHAR(150),
  mother_name       VARCHAR(150),
  parents_origin    VARCHAR(150),
  parents_residence VARCHAR(150),
  minister_name     VARCHAR(150),
  remarks           TEXT,
  book_no           VARCHAR(20),
  page_no           VARCHAR(20),
  line_no           VARCHAR(20),
  date_encoded      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by        INT UNSIGNED,
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
  entry_no            VARCHAR(20),
  confirmation_date   DATE NOT NULL,
  confirmand_full_name VARCHAR(150) NOT NULL,
  birthdate           DATE,
  father_name         VARCHAR(150),
  mother_name         VARCHAR(150),
  sponsor_name        VARCHAR(150),
  minister_name       VARCHAR(150),
  baptismal_record_id INT UNSIGNED,          -- prerequisite link
  remarks             TEXT,
  book_no             VARCHAR(20),
  page_no             VARCHAR(20),
  line_no             VARCHAR(20),
  date_encoded        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by          INT UNSIGNED,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --- First Communion ---------------------------------------------------
CREATE TABLE first_communion_records (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no              VARCHAR(20),
  communion_date        DATE NOT NULL,
  communicant_full_name VARCHAR(150) NOT NULL,
  father_name           VARCHAR(150),
  mother_name           VARCHAR(150),
  minister_name         VARCHAR(150),
  baptismal_record_id   INT UNSIGNED,        -- prerequisite link
  remarks               TEXT,
  book_no               VARCHAR(20),
  page_no               VARCHAR(20),
  line_no               VARCHAR(20),
  date_encoded          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by            INT UNSIGNED,
  FOREIGN KEY (baptismal_record_id) REFERENCES baptismal_records(id) ON DELETE SET NULL,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --- Marriage ------------------------------------------------------------
CREATE TABLE marriage_records (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no            VARCHAR(20),
  marriage_date       DATE NOT NULL,
  husband_full_name   VARCHAR(150) NOT NULL,
  wife_maiden_name    VARCHAR(150) NOT NULL,
  husband_birthdate   DATE,
  husband_birthplace  VARCHAR(150),
  wife_birthdate      DATE,
  wife_birthplace     VARCHAR(150),
  officiating_minister VARCHAR(150),
  remarks             TEXT,
  book_no             VARCHAR(20),
  page_no             VARCHAR(20),
  line_no             VARCHAR(20),
  date_encoded        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  encoded_by          INT UNSIGNED,
  FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL
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
  valid_id_path                VARCHAR(255), -- kept nullable; identity verified in person at pickup per design doc
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
-- 4. SACRAMENT APPLICATIONS (shared table + one extension per type)
--    Baptism, Confirmation, First Communion, Marriage -- no Death/Burial
--    applications (no one applies in advance to be buried).
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

CREATE TABLE baptismal_applications (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id        INT UNSIGNED NOT NULL UNIQUE,
  child_full_name       VARCHAR(150) NOT NULL,
  child_birthdate       DATE NOT NULL,
  child_birthplace      VARCHAR(150) NOT NULL,
  father_full_name      VARCHAR(150),
  mother_full_name      VARCHAR(150),
  parent_guardian_name  VARCHAR(150) NOT NULL, -- whoever is submitting/vouching for the child
  contact_number        VARCHAR(30) NOT NULL,
  email                 VARCHAR(150) NOT NULL,
  preferred_date         DATE,
  notes                 TEXT,
  parent_valid_id_path  VARCHAR(255), -- see includes/view-upload.php
  FOREIGN KEY (application_id) REFERENCES sacrament_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE baptismal_application_godparents (
  id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baptismal_application_id INT UNSIGNED NOT NULL,
  godparent_name           VARCHAR(150) NOT NULL,
  godparent_residence      VARCHAR(150),
  FOREIGN KEY (baptismal_application_id) REFERENCES baptismal_applications(id) ON DELETE CASCADE
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
  valid_id_path         VARCHAR(255), -- see includes/view-upload.php
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
  valid_id_path         VARCHAR(255), -- see includes/view-upload.php
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
  groom_valid_id_path         VARCHAR(255), -- see includes/view-upload.php

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
  bride_valid_id_path         VARCHAR(255), -- see includes/view-upload.php

  preferred_wedding_date      DATE NOT NULL,
  pre_cana_seminar_completed  TINYINT(1) NOT NULL DEFAULT 0,
  pre_cana_completion_date    DATE,
  canonical_interview_completed TINYINT(1) NOT NULL DEFAULT 0,
  canonical_interview_date    DATE,
  banns_date_1                DATE,
  banns_date_2                DATE,
  banns_date_3                DATE,
  assigned_priest_name        VARCHAR(150),

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
  generated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  print_status           ENUM('generated', 'printed', 'released') NOT NULL DEFAULT 'generated',
  pickup_date            DATETIME,
  FOREIGN KEY (certificate_request_id) REFERENCES certificate_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 8. AUDIT LOG (activity logging, per your admin dashboard's Audit Log page)
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
-- Seed defaults for eligibility settings (placeholders -- confirm with priest)
-- =====================================================================

INSERT INTO sacrament_eligibility_settings (sacrament_type, min_age, max_age) VALUES
  ('first_communion', 7, 12),
  ('confirmation', 12, 18);
