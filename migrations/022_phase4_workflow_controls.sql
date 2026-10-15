-- =====================================================================
-- 022: Phase 4 workflow controls
-- Requirements provenance, record archival/correction, and certificate lifecycle.
-- =====================================================================

ALTER TABLE certificate_requests
  ADD COLUMN IF NOT EXISTS requirements_source ENUM('not_yet_received','physical','official_gmail','physical_and_gmail') NOT NULL DEFAULT 'not_yet_received',
  ADD COLUMN IF NOT EXISTS requirements_reviewed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS requirements_reviewed_by INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS missing_requirements_note VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS cancelled_by INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(500) NULL;

ALTER TABLE sacrament_applications
  ADD COLUMN IF NOT EXISTS requirements_source ENUM('not_yet_received','physical','official_gmail','physical_and_gmail') NOT NULL DEFAULT 'not_yet_received',
  ADD COLUMN IF NOT EXISTS requirements_reviewed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS requirements_reviewed_by INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS missing_requirements_note VARCHAR(500) NULL;

ALTER TABLE certificate_generation_log
  ADD COLUMN IF NOT EXISTS finalized_by INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS cancelled_by INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS reprint_reason VARCHAR(500) NULL;

ALTER TABLE certificate_requests
  MODIFY requester_contact_number VARCHAR(30) NULL,
  MODIFY status ENUM('pending','under_review','approved','ready_for_pickup','released','rejected','cancelled') NOT NULL DEFAULT 'pending';
ALTER TABLE baptismal_applications MODIFY contact_number VARCHAR(30) NULL;
ALTER TABLE confirmation_applications MODIFY contact_number VARCHAR(30) NULL;
ALTER TABLE first_communion_applications MODIFY contact_number VARCHAR(30) NULL;
ALTER TABLE marriage_applications MODIFY groom_contact_number VARCHAR(30) NULL, MODIFY bride_contact_number VARCHAR(30) NULL;

ALTER TABLE baptismal_records ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL, ADD COLUMN IF NOT EXISTS archived_by INT UNSIGNED NULL, ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(500) NULL;
ALTER TABLE confirmation_records ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL, ADD COLUMN IF NOT EXISTS archived_by INT UNSIGNED NULL, ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(500) NULL;
ALTER TABLE first_communion_records ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL, ADD COLUMN IF NOT EXISTS archived_by INT UNSIGNED NULL, ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(500) NULL;
ALTER TABLE marriage_records ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL, ADD COLUMN IF NOT EXISTS archived_by INT UNSIGNED NULL, ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(500) NULL;

ALTER TABLE certificate_requests ADD INDEX IF NOT EXISTS idx_cert_req_requirements_source (requirements_source);
ALTER TABLE sacrament_applications ADD INDEX IF NOT EXISTS idx_sacrament_app_requirements_source (requirements_source);
ALTER TABLE baptismal_records ADD INDEX IF NOT EXISTS idx_baptismal_archive (is_archived);
ALTER TABLE confirmation_records ADD INDEX IF NOT EXISTS idx_confirmation_archive (is_archived);
ALTER TABLE first_communion_records ADD INDEX IF NOT EXISTS idx_first_communion_archive (is_archived);
ALTER TABLE marriage_records ADD INDEX IF NOT EXISTS idx_marriage_archive (is_archived);
