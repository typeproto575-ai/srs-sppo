-- =====================================================================
-- SRS-SPPO: Schedule and Record System of Saint Patrick's Parish Office
-- NEW SCHEMA v2  |  MySQL / MariaDB (XAMPP)  |  12 tables
--
-- This single file replaces the old 33-table schema and all 54 migrations.
-- There is no migration runner and no in-app upgrader. To reset a LOCAL
-- test database, run:   DROP DATABASE srs_sppo_db;   then run this file.
--
-- Design rules
--   1. Identity (name, birth, parents, address, contact) lives ONLY in
--      `persons`. No other table stores those fields for a parishioner.
--   2. All four sacraments live in ONE table, `sacrament_records`.
--   3. Online submissions land in `certificate_requests` or
--      `sacrament_applications` and never write to `persons` or
--      `sacrament_records` until the secretary approves them.
--   4. `sacrament_records.legitimacy` is INTERNAL. Never show it on any
--      public page, email, or printed certificate.
--   5. No monetary amounts anywhere. Payment is a manual paid_at stamp.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS srs_sppo_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE srs_sppo_db;

-- ---------------------------------------------------------------------
-- 1. users : the single secretary/administrator account
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name        VARCHAR(150) NOT NULL,
  username         VARCHAR(50)  NOT NULL UNIQUE,
  email            VARCHAR(150) NOT NULL UNIQUE,
  password_hash    VARCHAR(255) NOT NULL,
  role             ENUM('secretary') NOT NULL DEFAULT 'secretary',
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  auth_version     INT UNSIGNED NOT NULL DEFAULT 1,   -- bump to log out all sessions
  reset_token_hash VARCHAR(255) NULL,                 -- forgot-password / handover link (hashed)
  reset_expires_at DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. persons : master identity, one row per parishioner
--    last_name is always the surname AT BIRTH (a married woman keeps her
--    maiden surname here, as in the church registers).
-- ---------------------------------------------------------------------
CREATE TABLE persons (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name       VARCHAR(150) NOT NULL,
  middle_name      VARCHAR(100) NULL,
  last_name        VARCHAR(100) NOT NULL,
  suffix           VARCHAR(20)  NULL,
  sex              ENUM('male','female') NULL,
  birth_date       DATE NULL,
  birth_place      VARCHAR(150) NULL,
  father_name      VARCHAR(150) NULL,
  mother_maiden_name VARCHAR(150) NULL,
  father_person_id INT UNSIGNED NULL,                 -- optional link when the father also has a profile
  mother_person_id INT UNSIGNED NULL,                 -- optional link when the mother also has a profile
  address          VARCHAR(255) NULL,
  contact_number   VARCHAR(30)  NULL,
  email            VARCHAR(150) NULL,
  baptismal_status ENUM('baptized_catholic','baptized_non_catholic','unbaptized') NULL,
  is_deceased      TINYINT(1) NOT NULL DEFAULT 0,     -- status flag only, no burial details
  death_date       DATE NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_persons_name (last_name, first_name),
  INDEX idx_persons_birth (birth_date),
  CONSTRAINT fk_persons_father FOREIGN KEY (father_person_id) REFERENCES persons(id) ON DELETE SET NULL,
  CONSTRAINT fk_persons_mother FOREIGN KEY (mother_person_id) REFERENCES persons(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. sacrament_records : every sacrament event, one row each
--    person_id        = the recipient (for marriage: the groom)
--    spouse_person_id = the bride (marriage only)
--    A profile's marriages are found with:
--       WHERE person_id = ? OR spouse_person_id = ?
--    source = 'other_parish' means the event happened elsewhere and was
--    entered from an origin certificate. Such rows are shown on the
--    profile but this parish does not print certificates for them.
--    details (JSON) holds the few type-specific fields, see
--    DATA_DICTIONARY.md section "details JSON keys".
-- ---------------------------------------------------------------------
CREATE TABLE sacrament_records (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sacrament_type     ENUM('baptism','first_communion','confirmation','marriage') NOT NULL,
  person_id          INT UNSIGNED NOT NULL,
  spouse_person_id   INT UNSIGNED NULL,
  source             ENUM('this_parish','other_parish') NOT NULL DEFAULT 'this_parish',
  event_date         DATE NOT NULL,
  place              VARCHAR(150) NULL,
  minister_name      VARCHAR(150) NULL,
  entry_no           VARCHAR(20)  NULL,
  book_no            VARCHAR(20)  NULL,
  page_no            VARCHAR(20)  NULL,
  line_no            VARCHAR(20)  NULL,
  parents_union_type ENUM('legitimate','civil','natural') NULL,   -- baptism only (Church / Civil / Natural union)
  legitimacy         ENUM('legitimate','illegitimate') NULL,      -- baptism only. INTERNAL, NEVER DISPLAYED OR PRINTED
  details            JSON NULL,
  remarks            TEXT NULL,
  book_verified_by   INT UNSIGNED NULL,                           -- secretary who checked this entry against the physical register
  book_verified_at   DATETIME NULL,
  encoded_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sr_person (person_id, sacrament_type),
  INDEX idx_sr_spouse (spouse_person_id),
  INDEX idx_sr_type_date (sacrament_type, event_date),
  INDEX idx_sr_book (book_no, page_no, line_no),
  CONSTRAINT chk_sr_spouse CHECK (spouse_person_id IS NULL OR sacrament_type = 'marriage'),
  CONSTRAINT fk_sr_person   FOREIGN KEY (person_id)        REFERENCES persons(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sr_spouse   FOREIGN KEY (spouse_person_id) REFERENCES persons(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sr_verifier FOREIGN KEY (book_verified_by) REFERENCES users(id)   ON DELETE SET NULL,
  CONSTRAINT fk_sr_encoder  FOREIGN KEY (encoded_by)       REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. sacrament_participants : godparents, sponsors, witnesses
-- ---------------------------------------------------------------------
CREATE TABLE sacrament_participants (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_id   INT UNSIGNED NOT NULL,
  role        ENUM('godfather','godmother','sponsor','principal_sponsor','witness') NOT NULL,
  full_name   VARCHAR(150) NOT NULL,
  residence   VARCHAR(150) NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_sp_record (record_id),
  CONSTRAINT fk_sp_record FOREIGN KEY (record_id) REFERENCES sacrament_records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. sacrament_applications : online or walk-in application to RECEIVE a
--    sacrament. Unverified intake. The secretary links it to person(s) and
--    approves it; approval prefills a sacrament_records form.
--    details  (JSON) = the type-specific form answers.
--    requirements (JSON) = checklist the secretary ticks in person.
-- ---------------------------------------------------------------------
CREATE TABLE sacrament_applications (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_no         VARCHAR(20) NOT NULL UNIQUE,         -- internal reference only, not a public tracking feature
  channel            ENUM('online','onsite') NOT NULL DEFAULT 'onsite',
  sacrament_type     ENUM('baptism','first_communion','confirmation','marriage') NOT NULL,
  person_id          INT UNSIGNED NULL,                   -- set when the secretary links an existing profile (marriage: the groom)
  spouse_person_id   INT UNSIGNED NULL,                   -- marriage: the bride
  applicant_name     VARCHAR(150) NOT NULL,
  applicant_email    VARCHAR(150) NOT NULL,
  applicant_contact  VARCHAR(30)  NULL,
  preferred_date     DATE NULL,
  preferred_time     TIME NULL,
  status             ENUM('new','reviewing','requirements_pending','approved','scheduled','completed','rejected') NOT NULL DEFAULT 'new',
  details            JSON NULL,
  requirements       JSON NULL,
  record_id          INT UNSIGNED NULL,                   -- the sacrament_records row created after completion
  notes              TEXT NULL,
  rejection_reason   TEXT NULL,
  paid_at            DATETIME NULL,
  paid_by            INT UNSIGNED NULL,
  processed_by       INT UNSIGNED NULL,
  privacy_consent_at DATETIME NULL,
  anonymized_at      DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sa_status (status),
  INDEX idx_sa_type (sacrament_type, preferred_date),
  INDEX idx_sa_name (applicant_name),
  CONSTRAINT fk_sa_person    FOREIGN KEY (person_id)        REFERENCES persons(id)           ON DELETE SET NULL,
  CONSTRAINT fk_sa_spouse    FOREIGN KEY (spouse_person_id) REFERENCES persons(id)           ON DELETE SET NULL,
  CONSTRAINT fk_sa_record    FOREIGN KEY (record_id)        REFERENCES sacrament_records(id) ON DELETE SET NULL,
  CONSTRAINT fk_sa_paid_by   FOREIGN KEY (paid_by)          REFERENCES users(id)             ON DELETE SET NULL,
  CONSTRAINT fk_sa_processed FOREIGN KEY (processed_by)     REFERENCES users(id)             ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. certificate_requests : one table for the whole certificate life cycle
--    new -> matched -> for_signature -> ready -> released  (or rejected)
--    "for_signature" = printed, waiting for the priest's physical
--    signature. Pickup verification (Stage 2) is the claimant_* columns.
-- ---------------------------------------------------------------------
CREATE TABLE certificate_requests (
  id                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_no                 VARCHAR(20) NOT NULL UNIQUE,   -- internal reference only
  channel                    ENUM('online','onsite') NOT NULL DEFAULT 'onsite',
  sacrament_type             ENUM('baptism','first_communion','confirmation','marriage') NOT NULL,
  -- what the requester typed (unverified snapshot)
  subject_name               VARCHAR(150) NOT NULL,         -- marriage: groom's name
  subject_details            JSON NULL,                     -- birth date, parents, spouse name, marriage date, etc.
  -- requester
  requester_name             VARCHAR(150) NOT NULL,
  requester_relationship     ENUM('self','parent','guardian','spouse','sibling','child','other') NOT NULL DEFAULT 'self',
  requester_relationship_other VARCHAR(100) NULL,
  requester_email            VARCHAR(150) NOT NULL,
  requester_contact_number   VARCHAR(30)  NULL,
  purpose                    VARCHAR(40)  NULL,
  purpose_other              VARCHAR(150) NULL,
  privacy_consent_at         DATETIME NULL,
  -- secretary matching
  person_id                  INT UNSIGNED NULL,
  record_id                  INT UNSIGNED NULL,             -- the sacrament_records row the certificate is printed from
  matched_by                 INT UNSIGNED NULL,
  matched_at                 DATETIME NULL,
  -- life cycle
  status                     ENUM('new','matched','for_signature','ready','released','rejected') NOT NULL DEFAULT 'new',
  certificate_no             VARCHAR(30) NULL,
  printed_by                 INT UNSIGNED NULL,
  printed_at                 DATETIME NULL,
  signed_at                  DATETIME NULL,                 -- priest signed; "ready" email is sent
  -- Stage 2 counter verification at release
  claimant_type              ENUM('self','representative') NULL,
  representative_name        VARCHAR(150) NULL,
  representative_relationship VARCHAR(120) NULL,
  id_verified                TINYINT(1) NOT NULL DEFAULT 0, -- "I verified valid ID at counter"
  claimant_notes             TEXT NULL,
  released_by                INT UNSIGNED NULL,
  released_at                DATETIME NULL,
  paid_at                    DATETIME NULL,                 -- fee collected in person
  paid_by                    INT UNSIGNED NULL,
  notes                      TEXT NULL,
  rejection_reason           TEXT NULL,
  anonymized_at              DATETIME NULL,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cr_status (status),
  INDEX idx_cr_subject (subject_name),
  INDEX idx_cr_person (person_id),
  CONSTRAINT fk_cr_person   FOREIGN KEY (person_id)   REFERENCES persons(id)           ON DELETE SET NULL,
  CONSTRAINT fk_cr_record   FOREIGN KEY (record_id)   REFERENCES sacrament_records(id) ON DELETE SET NULL,
  CONSTRAINT fk_cr_matched  FOREIGN KEY (matched_by)  REFERENCES users(id)             ON DELETE SET NULL,
  CONSTRAINT fk_cr_printed  FOREIGN KEY (printed_by)  REFERENCES users(id)             ON DELETE SET NULL,
  CONSTRAINT fk_cr_released FOREIGN KEY (released_by) REFERENCES users(id)             ON DELETE SET NULL,
  CONSTRAINT fk_cr_paid     FOREIGN KEY (paid_by)     REFERENCES users(id)             ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. correction_requests : request to fix wrong details on a record
-- ---------------------------------------------------------------------
CREATE TABLE correction_requests (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id          INT UNSIGNED NULL,                 -- set by the secretary once the person is found
  sacrament_type     ENUM('baptism','first_communion','confirmation','marriage') NULL,
  subject_name       VARCHAR(150) NOT NULL,             -- who the record is about, as typed
  requester_name     VARCHAR(150) NOT NULL,
  requester_email    VARCHAR(150) NOT NULL,
  requested_changes  TEXT NOT NULL,
  admin_note         TEXT NULL,
  status             ENUM('pending','processing','completed','rejected') NOT NULL DEFAULT 'pending',
  anonymized_at      DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cor_status (status),
  CONSTRAINT fk_cor_person FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. schedule_events : parish calendar (public view shows public_details only)
-- ---------------------------------------------------------------------
CREATE TABLE schedule_events (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type      ENUM('mass','wedding','baptism','other') NOT NULL,
  title           VARCHAR(150) NOT NULL,
  public_details  TEXT NULL,
  event_date      DATE NOT NULL,
  start_time      TIME NULL,
  end_time        TIME NULL,
  application_id  INT UNSIGNED NULL,
  notes           TEXT NULL,                           -- internal only
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_se_date (event_date),
  CONSTRAINT fk_se_application FOREIGN KEY (application_id) REFERENCES sacrament_applications(id) ON DELETE SET NULL,
  CONSTRAINT fk_se_creator     FOREIGN KEY (created_by)     REFERENCES users(id)                 ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. announcements : public landing page notices
-- ---------------------------------------------------------------------
CREATE TABLE announcements (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(150) NOT NULL,
  content       TEXT NOT NULL,
  event_date    DATE NULL,
  is_published  TINYINT(1) NOT NULL DEFAULT 1,
  posted_by     INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_an_poster FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. settings : key/value (site text, parish name, eligibility ages,
--     priest approval email, retention periods ...)
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  setting_key    VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value  TEXT NOT NULL,
  updated_by     INT UNSIGNED NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_set_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. notifications : in-app admin notices AND the email delivery log
--     channel = 'inapp' uses title/message/link/is_read.
--     channel = 'email' uses recipient/delivery_status/error_message.
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel          ENUM('inapp','email') NOT NULL,
  type             VARCHAR(60)  NOT NULL,              -- e.g. request_new, request_ready, event_reminder
  title            VARCHAR(150) NOT NULL,
  message          VARCHAR(255) NULL,
  link             VARCHAR(200) NULL,
  recipient        VARCHAR(150) NULL,
  delivery_status  ENUM('sent','failed','disabled','invalid') NULL,
  error_message    VARCHAR(500) NULL,
  entity_type      VARCHAR(40)  NULL,
  entity_id        INT UNSIGNED NULL,
  is_read          TINYINT(1) NOT NULL DEFAULT 0,
  read_at          DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_no_unread (channel, is_read, created_at),
  INDEX idx_no_entity (entity_type, entity_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 12. audit_logs : who did what, including reprints and handovers
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NULL,
  actor_name    VARCHAR(150) NULL,                     -- name of the account holder at that time
  action        VARCHAR(150) NOT NULL,
  target_table  VARCHAR(100) NULL,
  target_id     INT UNSIGNED NULL,
  description   TEXT NULL,
  reason        VARCHAR(500) NULL,
  ip_address    VARCHAR(45)  NULL,
  user_agent    VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_al_target (target_table, target_id),
  INDEX idx_al_created (created_at),
  CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
