-- Add the biographical and preparation fields present in the canonical
-- Confirmation application schema to existing installations.
ALTER TABLE confirmation_applications
  ADD COLUMN IF NOT EXISTS applicant_gender VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS applicant_birthplace VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS current_address VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS school_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS grade_level_section VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS adviser_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS father_full_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS mother_maiden_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS parents_catholic_married VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS psa_birth_certificate_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS catechetical_formation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS confession_completed TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS parental_consent TINYINT(1) NOT NULL DEFAULT 0;
