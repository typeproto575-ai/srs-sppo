-- Add an explicit closed status and opt-in retention start for new online
-- certificate requests. Existing requests stay outside the retention workflow.
ALTER TABLE certificate_requests
  MODIFY status ENUM('new','matched','for_signature','ready','released','rejected','cancelled') NOT NULL DEFAULT 'new',
  ADD COLUMN retention_eligible_at DATETIME NULL AFTER anonymized_at,
  ADD INDEX idx_cr_retention (status, channel, retention_eligible_at, anonymized_at);
