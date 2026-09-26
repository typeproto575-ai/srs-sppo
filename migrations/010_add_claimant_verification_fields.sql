-- 010: Claimant verification fields for certificate release
--
-- These fields live on certificate_generation_log so the release action
-- records who picked up the certificate and the verification context in
-- one place (alongside print_status, pickup_date, and the superseded
-- tracking). Used by admin/certificates/history.php.
--
-- complaint_notes is omitted — the existing notes capture counter-side
-- context; claimant_notes is for the release form only.

ALTER TABLE certificate_generation_log
  ADD COLUMN IF NOT EXISTS claimant_name         VARCHAR(255) NULL AFTER pickup_date,
  ADD COLUMN IF NOT EXISTS claimant_relationship VARCHAR(120) NULL AFTER claimant_name,
  ADD COLUMN IF NOT EXISTS claimant_notes        TEXT         NULL AFTER claimant_relationship,
  ADD COLUMN IF NOT EXISTS id_verified           TINYINT(1)  NOT NULL DEFAULT 0 AFTER claimant_notes;
