-- =====================================================================
-- 008. REVERSE LINK: CERTIFICATE REQUEST -> RECORD
-- =====================================================================
-- Adds a nullable certificate_request_id column to the four core record
-- tables so a register row knows which approved certificate request (if
-- any) points to it. This complements the existing matched_record_id on
-- the *certificate_requests extension tables (the forward link), making
-- the request<->record relationship bidirectional.
--
-- The link is set at match time (admin/certificates/generate.php), NOT at
-- record-encode time, because records are encoded independently from the
-- physical logbook and may have no associated request at all.
-- =====================================================================

ALTER TABLE baptismal_records
  ADD COLUMN certificate_request_id INT UNSIGNED DEFAULT NULL AFTER date_encoded,
  ADD KEY idx_baptismal_cert_req (certificate_request_id);

ALTER TABLE confirmation_records
  ADD COLUMN certificate_request_id INT UNSIGNED DEFAULT NULL AFTER date_encoded,
  ADD KEY idx_confirmation_cert_req (certificate_request_id);

ALTER TABLE first_communion_records
  ADD COLUMN certificate_request_id INT UNSIGNED DEFAULT NULL AFTER date_encoded,
  ADD KEY idx_first_communion_cert_req (certificate_request_id);

ALTER TABLE marriage_records
  ADD COLUMN certificate_request_id INT UNSIGNED DEFAULT NULL AFTER date_encoded,
  ADD KEY idx_marriage_cert_req (certificate_request_id);