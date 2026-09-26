-- 009: Generation-log print lifecycle + supersede support
--
-- printed_by / printed_at record the staff confirmation of physical
-- printing ("Mark Printed" step on the generate/preview page).
-- superseded_at marks a generation-log row replaced by a newer
-- regeneration, so only ONE active log exists per certificate request
-- at any time; only the active row is printable/releasable.
--
-- Run against an existing database to bring certificate_generation_log
-- up to date. The base schema file (srs_sppo_schema.sql) already defines
-- these columns inline for fresh installs; this migration is for catching
-- up databases created before this lifecycle was added.
--
-- The IF NOT EXISTS clauses on columns/indexes make those steps idempotent.
-- The FK additions will throw a duplicate-key error if the constraint
-- already exists; that is handled by the runner (caught per-statement)
-- so the migration is safe to re-run. If already applied, nothing changes.

-- 1. Columns
ALTER TABLE certificate_generation_log
  ADD COLUMN IF NOT EXISTS printed_by INT UNSIGNED NULL AFTER generated_by,
  ADD COLUMN IF NOT EXISTS printed_at DATETIME NULL AFTER printed_by,
  ADD COLUMN IF NOT EXISTS superseded_at DATETIME NULL AFTER pickup_date;

-- 2. Index for finding the active log per request (most recent non-superseded).
ALTER TABLE certificate_generation_log
  ADD INDEX IF NOT EXISTS idx_cgl_request_active (certificate_request_id, superseded_at);

-- 3. Foreign key for printed_by (guards referential integrity on print confirmation).
ALTER TABLE certificate_generation_log
  ADD CONSTRAINT fk_cgl_printed_by
  FOREIGN KEY (printed_by) REFERENCES users(id) ON DELETE SET NULL;

-- 4. Foreign key for generated_by (schema defines this; existing databases
--    created before this migration may lack it).
ALTER TABLE certificate_generation_log
  ADD CONSTRAINT fk_cgl_generated_by
  FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL;



