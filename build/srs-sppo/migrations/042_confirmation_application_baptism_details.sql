-- Store the baptism information collected by the walk-in Confirmation form.
ALTER TABLE confirmation_applications
  ADD COLUMN IF NOT EXISTS baptism_date DATE NULL,
  ADD COLUMN IF NOT EXISTS baptism_parish_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS baptism_parish_address VARCHAR(255) NULL;
