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

-- 6) Migration 005: Rename mother_name → mother_maiden_name for consistency
--    (matches baptismal_records and baptismal_applications, already renamed).
ALTER TABLE confirmation_records
  CHANGE COLUMN IF EXISTS mother_name mother_maiden_name VARCHAR(150) NULL;

ALTER TABLE first_communion_records
  CHANGE COLUMN IF EXISTS mother_name mother_maiden_name VARCHAR(150) NULL;

-- 7) Migration 005: Add proxy_name to baptismal_records
--    For cases where a proxy stands in for an absent godparent.
ALTER TABLE baptismal_records
  ADD COLUMN IF NOT EXISTS proxy_name VARCHAR(150) DEFAULT NULL;

-- 8) Migration 005: Add venue_chapel_name to marriage_applications
--    Text field for the chapel name when preferred_wedding_venue = 'barangay_chapel'.
ALTER TABLE marriage_applications
  ADD COLUMN IF NOT EXISTS venue_chapel_name VARCHAR(150) DEFAULT NULL AFTER marriage_license_ref;

-- 9) Certificate requests: drop num_copies from the shared request table.
--    The field was TINYINT UNSIGNED NOT NULL DEFAULT 1, so it is treated as
--    a required historical field that every request had by default; all current
--    app code now assumes a single certificate per request and stores no copy
--    count. This is safe on the live DB because the field is no longer used.
ALTER TABLE certificate_requests
  DROP COLUMN IF EXISTS num_copies;

-- 10) Legacy ID columns: the live database already dropped them; nothing to relax.
