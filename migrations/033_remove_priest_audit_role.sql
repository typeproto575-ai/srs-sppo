-- =====================================================================
-- 033: Remove retired priest role from audit log snapshots
-- =====================================================================
-- The live product has no priest staff role. Older rows may still have
-- role_snapshot = 'priest' in audit_logs from legacy testing or seed data.
-- Convert those legacy snapshots before narrowing the ENUM; doing the ALTER
-- first can fail in strict MySQL/MariaDB mode when legacy rows still exist.
-- Safe to run against a database that has no legacy priest rows.
-- =====================================================================

UPDATE audit_logs
SET role_snapshot = 'secretary'
WHERE role_snapshot = 'priest';

ALTER TABLE audit_logs
  MODIFY role_snapshot ENUM('secretary','parish_admin') DEFAULT NULL;
