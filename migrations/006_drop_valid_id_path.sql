-- Migration 006: Drop certificate_requests.valid_id_path
-- Run in staging first, verify, then on production in maintenance window.

-- =====================================================================
-- DROP certificate_requests.valid_id_path
-- The online ID-upload feature was already removed from all PHP code
-- (identity is verified in person at pickup per the design doc), but this
-- column was left behind in the schema. It is no longer read or written
-- by any code, so it is removed here.
-- =====================================================================

ALTER TABLE certificate_requests
  DROP COLUMN valid_id_path;

-- =====================================================================
-- No other schema changes in this migration.
--   - No monetary/fee columns added.
--   - death_burial_records untouched.
--   - No new tables or features.
-- =====================================================================

COMMIT;