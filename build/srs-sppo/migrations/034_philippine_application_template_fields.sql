-- =====================================================================
-- 034: Philippine parish application template fields
-- =====================================================================
-- Extends First Communion and Holy Matrimony applications with the
-- biographical, canonical, legal, and office-clearance fields in the
-- parish sacramental application templates. Existing rows remain valid.
-- =====================================================================

ALTER TABLE first_communion_applications
  ADD COLUMN IF NOT EXISTS applicant_gender VARCHAR(20),
  ADD COLUMN IF NOT EXISTS applicant_birthplace VARCHAR(150),
  ADD COLUMN IF NOT EXISTS current_address VARCHAR(255),
  ADD COLUMN IF NOT EXISTS school_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS grade_level_section VARCHAR(100),
  ADD COLUMN IF NOT EXISTS adviser_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS father_full_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS mother_maiden_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS parents_catholic_married VARCHAR(30),
  ADD COLUMN IF NOT EXISTS baptism_date DATE,
  ADD COLUMN IF NOT EXISTS baptism_parish_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS baptism_parish_address VARCHAR(255),
  ADD COLUMN IF NOT EXISTS psa_birth_certificate_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS catechetical_formation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS confession_completed TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS parental_consent TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE marriage_applications
  ADD COLUMN IF NOT EXISTS groom_current_address VARCHAR(255),
  ADD COLUMN IF NOT EXISTS groom_address_length VARCHAR(80),
  ADD COLUMN IF NOT EXISTS groom_religion VARCHAR(100),
  ADD COLUMN IF NOT EXISTS groom_occupation VARCHAR(150),
  ADD COLUMN IF NOT EXISTS groom_father_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS groom_mother_maiden_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS groom_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS groom_previous_marriage VARCHAR(30),
  ADD COLUMN IF NOT EXISTS bride_current_address VARCHAR(255),
  ADD COLUMN IF NOT EXISTS bride_address_length VARCHAR(80),
  ADD COLUMN IF NOT EXISTS bride_religion VARCHAR(100),
  ADD COLUMN IF NOT EXISTS bride_occupation VARCHAR(150),
  ADD COLUMN IF NOT EXISTS bride_father_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS bride_mother_maiden_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS bride_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS bride_previous_marriage VARCHAR(30),
  ADD COLUMN IF NOT EXISTS assigned_priest_name VARCHAR(150),
  ADD COLUMN IF NOT EXISTS civilly_married_to_each_other TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS civil_marriage_date DATE,
  ADD COLUMN IF NOT EXISTS civil_marriage_place VARCHAR(150),
  ADD COLUMN IF NOT EXISTS groom_cenomar_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS bride_cenomar_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS marriage_license_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS psa_marriage_contract_status VARCHAR(20) NOT NULL DEFAULT 'not_applicable',
  ADD COLUMN IF NOT EXISTS groom_baptismal_annotated TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS bride_baptismal_annotated TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS groom_confirmation_annotated TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS bride_confirmation_annotated TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS groom_banns_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS bride_banns_status VARCHAR(30) NOT NULL DEFAULT 'pending';
