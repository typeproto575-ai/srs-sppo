-- Migration 040: Track explicit source-register omissions for Confirmation.
-- Existing blank values are treated as "not recorded" so legacy entries remain valid.

ALTER TABLE confirmation_records
  ADD COLUMN birthdate_not_recorded TINYINT(1) NOT NULL DEFAULT 0 AFTER birthdate,
  ADD COLUMN father_name_not_recorded TINYINT(1) NOT NULL DEFAULT 0 AFTER father_name,
  ADD COLUMN mother_maiden_name_not_recorded TINYINT(1) NOT NULL DEFAULT 0 AFTER mother_maiden_name;

UPDATE confirmation_records
SET birthdate_not_recorded = (birthdate IS NULL),
    father_name_not_recorded = (father_name IS NULL OR TRIM(father_name) = ''),
    mother_maiden_name_not_recorded = (mother_maiden_name IS NULL OR TRIM(mother_maiden_name) = '');
