-- =====================================================================
-- 032: Add verification token for public certificate validation
-- =====================================================================
-- The certificate generation and verify pages expect each certificate_request
-- to carry a unique verification_code token, which is embedded in the QR code
-- and printed on the certificate. Existing databases created before this field
-- existed will miss it; adding it here keeps the live app and the canonical
-- schema aligned.
-- =====================================================================

ALTER TABLE certificate_requests
  ADD COLUMN IF NOT EXISTS verification_code VARCHAR(64) NULL UNIQUE AFTER date_processed;
