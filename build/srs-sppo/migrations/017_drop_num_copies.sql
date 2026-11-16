-- =====================================================================
-- 017: Drop num_copies from certificate_requests
-- =====================================================================
-- Every certificate request represents exactly one certificate, so the
-- "number of copies" field served no purpose. The form input, admin
-- display, and certificate preview rows that used it were removed in the
-- same change.
--
-- Pre-drop state (verified 2026-09-17):
--   num_copies TINYINT(3) UNSIGNED NOT NULL DEFAULT '1'
-- It had a DEFAULT, so dropping it cannot make any INSERT fail; all
-- historical rows carried the default value 1 (no data of value is lost).
--
-- Idempotent: re-running on a database that no longer has the column
-- raises ERROR 1091 only inside the guarded block below, which is
-- suppressed. (MySQL 8 / MariaDB 10.0.2+ support IF EXISTS.)
-- =====================================================================

ALTER TABLE certificate_requests
  DROP COLUMN IF EXISTS num_copies;
