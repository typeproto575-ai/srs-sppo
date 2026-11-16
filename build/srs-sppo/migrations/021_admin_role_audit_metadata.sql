-- =====================================================================
-- 021: Parish Administrator role and enriched append-only audit metadata
-- =====================================================================

ALTER TABLE users
  MODIFY role ENUM('secretary','parish_admin') NOT NULL DEFAULT 'secretary';

ALTER TABLE audit_logs
  ADD COLUMN IF NOT EXISTS role_snapshot ENUM('secretary','parish_admin') DEFAULT NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) DEFAULT NULL AFTER description,
  ADD COLUMN IF NOT EXISTS user_agent VARCHAR(500) DEFAULT NULL AFTER ip_address,
  ADD COLUMN IF NOT EXISTS reason VARCHAR(500) DEFAULT NULL AFTER user_agent;

CREATE INDEX idx_audit_created_action ON audit_logs (created_at, action);
CREATE INDEX idx_audit_role ON audit_logs (role_snapshot, created_at);
