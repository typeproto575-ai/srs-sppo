-- Assign one stable public reference to each master person profile.
-- Back up first and run on staging before applying to a live database.

ALTER TABLE persons
  ADD COLUMN IF NOT EXISTS public_reference VARCHAR(48) NULL AFTER email,
  ADD UNIQUE KEY uq_persons_public_reference (public_reference);

UPDATE persons
SET public_reference = CONCAT('SPPO-P-', UPPER(REPLACE(UUID(), '-', '')))
WHERE public_reference IS NULL OR public_reference = '';
