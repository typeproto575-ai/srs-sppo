-- Migration 005: Rename mother_name → mother_maiden_name for consistency,
-- add proxy_name to baptismal_records, add venue_chapel_name to marriage_applications.
-- Run in staging first, verify, then on production in maintenance window.

-- =====================================================================
-- 1. RENAME mother_name → mother_maiden_name
--    baptismal_records and baptismal_applications already use mother_maiden_name,
--    so confirmation_records and first_communion_records should match.
-- =====================================================================

ALTER TABLE confirmation_records
  CHANGE mother_name mother_maiden_name VARCHAR(150);

ALTER TABLE first_communion_records
  CHANGE mother_name mother_maiden_name VARCHAR(150);

-- =====================================================================
-- 2. ADD proxy_name to baptismal_records
--    For cases where a proxy stands in for an absent godparent.
-- =====================================================================

ALTER TABLE baptismal_records
  ADD COLUMN proxy_name VARCHAR(150) DEFAULT NULL;

-- =====================================================================
-- 3. ADD venue_chapel_name to marriage_applications
--    Text field for the chapel name when preferred_wedding_venue = 'barangay_chapel'.
-- =====================================================================

ALTER TABLE marriage_applications
  ADD COLUMN venue_chapel_name VARCHAR(150) DEFAULT NULL;

-- =====================================================================
-- NOT CHANGED (per instruction):
--   - marriage_certificate_requests.party_a_mother_name / party_b_mother_name
--     are requester-entered fields on a request form, not verified maiden names.
--   - No monetary/fee columns added.
--   - No new tables, no banns automation, no canonical dispensation tracking.
-- =====================================================================

COMMIT;
