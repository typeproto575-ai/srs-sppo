-- Add a person-level Observanda note, separate from sacrament register remarks.
-- Safe to apply after confirming the target database uses the V2 persons table.
ALTER TABLE persons
  ADD COLUMN IF NOT EXISTS observanda TEXT NULL AFTER contact_number;
