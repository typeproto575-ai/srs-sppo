-- =====================================================================
-- 035: Payment status (awaiting payment) + manual paid marker
-- Purpose: An online certificate request pauses at "awaiting payment"
--          once a register match is found. Payment happens OUTSIDE the
--          system (at the parish office, by contacting the parish, or
--          through someone near the parish). The secretary marks a
--          transaction paid by hand; the system records only WHO and
--          WHEN -- never any amount.
--
-- Paid marker is a timestamp+user (paid_at / paid_by), not a second
-- status: "paid" is a fact about the awaiting_payment stage, so it
-- adds no node to either lifecycle map. paid_at IS NOT NULL => paid.
--
-- Safe to re-run: MODIFY to the same definition is a no-op;
-- ADD COLUMN IF NOT EXISTS skips existing columns. Legacy status
-- values are preserved; none are removed or renamed.
-- =====================================================================

-- --- certificate_requests ---
ALTER TABLE certificate_requests
  MODIFY status ENUM(
    'pending','submitted','under_review','requirements_pending',
    'requirements_incomplete','awaiting_onsite_verification',
    'awaiting_payment','approved','processing','ready_for_pickup',
    'released','rejected','cancelled'
  ) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS paid_by INT UNSIGNED NULL;

-- --- sacrament_applications ---
ALTER TABLE sacrament_applications
  MODIFY status ENUM(
    'submitted','requirements_pending','requirements_incomplete',
    'awaiting_onsite_verification','documents_pending',
    'interview_scheduled','banns_in_progress','seminar_pending',
    'ready_for_approval','awaiting_payment','approved','scheduled',
    'completed','rejected','cancelled'
  ) NOT NULL DEFAULT 'submitted',
  ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS paid_by INT UNSIGNED NULL;
