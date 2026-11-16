-- =====================================================================
-- 032: Add verification token column for public certificate validation
-- =====================================================================
-- Historical: each certificate_request carried a unique verification_code
-- token, embedded in a QR code and printed on the certificate for a public
-- verification page. The QR / public verification feature has since been
-- removed, so the column is no longer written or read by the app. The column
-- is kept in place (and in the canonical schema) so existing databases and
-- fresh installs stay aligned - do not drop it here.
-- =====================================================================

ALTER TABLE certificate_requests
  ADD COLUMN IF NOT EXISTS verification_code VARCHAR(64) NULL UNIQUE AFTER date_processed;
