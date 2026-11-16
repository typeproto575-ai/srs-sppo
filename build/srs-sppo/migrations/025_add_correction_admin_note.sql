-- Store the secretary's internal outcome note for certificate corrections.
ALTER TABLE correction_requests
  ADD COLUMN admin_note TEXT NULL AFTER document_path;
