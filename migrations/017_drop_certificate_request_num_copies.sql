-- =====================================================================
-- 017. DROP num_copies FROM certificate_requests
-- =====================================================================
-- The field was a historical request metadata column with:
--   TINYINT UNSIGNED NOT NULL DEFAULT 1
-- The app now assumes exactly one certificate per request, so the field is
-- redundant and removed from both the canonical schema and the app logic.
--
-- This migration is safe to rerun because it uses DROP COLUMN IF EXISTS.
-- =====================================================================

ALTER TABLE certificate_requests
  DROP COLUMN IF EXISTS num_copies;
