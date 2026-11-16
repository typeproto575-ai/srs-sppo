-- =====================================================================
-- 038: Consolidate staff access into the parish secretary role
-- =====================================================================
-- Parish secretaries now have the complete administrative workspace.
-- Convert existing administrator accounts and audit snapshots before
-- narrowing both ENUMs so the migration is safe in strict MySQL/MariaDB.

UPDATE users
SET role = 'secretary'
WHERE role = 'parish_admin';

UPDATE audit_logs
SET role_snapshot = 'secretary'
WHERE role_snapshot = 'parish_admin';

ALTER TABLE users
  MODIFY role ENUM('secretary') NOT NULL DEFAULT 'secretary';

ALTER TABLE audit_logs
  MODIFY role_snapshot ENUM('secretary') DEFAULT NULL;
