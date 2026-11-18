-- Add person-level Profile Notes (observanda), separate from sacrament Remarks.
-- Safe to apply after confirming the target database uses the V2 persons table.
ALTER TABLE persons
  ADD COLUMN IF NOT EXISTS observanda TEXT NULL AFTER contact_number;
