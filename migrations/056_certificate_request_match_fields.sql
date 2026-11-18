-- Add the staff-match metadata used by the walk-in certificate request workflow.
-- The hosted database already has record_id, which links the request to its register entry.
ALTER TABLE certificate_requests
  ADD COLUMN matched_by INT UNSIGNED NULL AFTER record_id,
  ADD COLUMN matched_at DATETIME NULL AFTER matched_by,
  ADD CONSTRAINT fk_cr_matched
    FOREIGN KEY (matched_by) REFERENCES users(id) ON DELETE SET NULL;
