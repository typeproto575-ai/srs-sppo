-- 012: Review notes + rejection_reason for certificate_requests
--
-- review_notes: staff-only notes (distinct from the requester-facing
--     notes column). Used on the detail/review page.
-- rejection_reason: populated when the secretary rejects a request.

ALTER TABLE certificate_requests
  ADD COLUMN IF NOT EXISTS review_notes     TEXT NULL AFTER notes,
  ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL AFTER review_notes;
