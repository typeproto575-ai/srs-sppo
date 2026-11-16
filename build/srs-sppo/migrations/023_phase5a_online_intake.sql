-- SRS-SPPO Phase 5A: online intake provenance and workflow statuses
-- Existing rows remain onsite for compatibility; public forms explicitly write online.

ALTER TABLE certificate_requests
  ADD COLUMN IF NOT EXISTS submission_channel ENUM('online','onsite') NOT NULL DEFAULT 'onsite' AFTER tracking_number,
  MODIFY status ENUM('pending','submitted','under_review','requirements_pending','requirements_incomplete','awaiting_onsite_verification','approved','processing','ready_for_pickup','released','rejected','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE sacrament_applications
  ADD COLUMN IF NOT EXISTS submission_channel ENUM('online','onsite') NOT NULL DEFAULT 'onsite' AFTER tracking_number,
  MODIFY status ENUM('submitted','requirements_pending','requirements_incomplete','awaiting_onsite_verification','documents_pending','interview_scheduled','banns_in_progress','seminar_pending','ready_for_approval','approved','scheduled','completed','rejected','cancelled') NOT NULL DEFAULT 'submitted';

ALTER TABLE certificate_requests
  ADD INDEX IF NOT EXISTS idx_cert_req_submission_channel (submission_channel, date_submitted);

ALTER TABLE sacrament_applications
  ADD INDEX IF NOT EXISTS idx_sacrament_app_submission_channel (submission_channel, date_submitted);
