-- =====================================================================
-- 037: register-match confirmation flag (certificate requests)
-- Purpose: The public flow never tells the visitor whether a register
--          record exists. An online request enters `awaiting_payment`
--          internally when the search matches; the tracking page reveals
--          the payment step only AFTER the secretary confirms the match.
-- Safe to re-run: ADD COLUMN IF NOT EXISTS skips existing columns.
-- =====================================================================

ALTER TABLE certificate_requests
  ADD COLUMN IF NOT EXISTS match_confirmed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS match_confirmed_by INT UNSIGNED NULL;
