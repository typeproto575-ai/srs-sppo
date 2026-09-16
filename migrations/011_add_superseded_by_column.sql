-- 011: superseded_by column for generation-log regeneration tracking
--
-- Records which staff member performed a regeneration (replacing a prior
-- generation log via supersede). The mark_generation_superseded() helper
-- in includes/functions.php writes this column.
--
-- schema.sql documents this column inline for fresh installs; this
-- migration catches up existing databases.

ALTER TABLE certificate_generation_log
  ADD COLUMN IF NOT EXISTS superseded_by INT UNSIGNED NULL AFTER superseded_at;

-- FK (safe to re-run: duplicate-key error is accepted by the runner).
ALTER TABLE certificate_generation_log
  ADD CONSTRAINT fk_cgl_superseded_by
  FOREIGN KEY (superseded_by) REFERENCES users(id) ON DELETE SET NULL;
