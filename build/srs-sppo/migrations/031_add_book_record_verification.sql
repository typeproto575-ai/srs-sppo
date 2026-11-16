-- =====================================================================
-- 031: Book-record verification (confirm digitized entry vs. register)
-- =====================================================================
-- Adds a per-record flag: has the parish secretary checked this digitized
-- register entry against the physical logbook? This is a property of the
-- RECORD, not of any one certificate request -- once verified, it stays
-- verified for every future request that cites the same entry.
--
-- The request detail flow will not let a certificate be marked
-- "Generated" for a matched request until the matched record carries this
-- flag. A request with no matched record (manual-search path) is unaffected
-- -- there is no digital entry to check yet.
--
-- Safe to re-run: ADD COLUMN IF NOT EXISTS is a no-op on a rerun.
-- =====================================================================

ALTER TABLE baptismal_records
  ADD COLUMN IF NOT EXISTS book_verified_by INT UNSIGNED NULL AFTER person_id,
  ADD COLUMN IF NOT EXISTS book_verified_at DATETIME NULL AFTER book_verified_by;

ALTER TABLE confirmation_records
  ADD COLUMN IF NOT EXISTS book_verified_by INT UNSIGNED NULL AFTER person_id,
  ADD COLUMN IF NOT EXISTS book_verified_at DATETIME NULL AFTER book_verified_by;

ALTER TABLE first_communion_records
  ADD COLUMN IF NOT EXISTS book_verified_by INT UNSIGNED NULL AFTER person_id,
  ADD COLUMN IF NOT EXISTS book_verified_at DATETIME NULL AFTER book_verified_by;

ALTER TABLE marriage_records
  ADD COLUMN IF NOT EXISTS book_verified_by INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS book_verified_at DATETIME NULL;

-- Foreign keys are added separately so a rerun (columns already present)
-- doesn't fail trying to re-add them. MySQL/MariaDB have no
-- "ADD CONSTRAINT IF NOT EXISTS", so this checks information_schema first.
SET @fk_bap := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'baptismal_records'
                   AND CONSTRAINT_NAME = 'fk_baptismal_records_book_verified_by');
SET @sql := IF(@fk_bap = 0,
  'ALTER TABLE baptismal_records ADD CONSTRAINT fk_baptismal_records_book_verified_by FOREIGN KEY (book_verified_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_conf := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'confirmation_records'
                    AND CONSTRAINT_NAME = 'fk_confirmation_records_book_verified_by');
SET @sql := IF(@fk_conf = 0,
  'ALTER TABLE confirmation_records ADD CONSTRAINT fk_confirmation_records_book_verified_by FOREIGN KEY (book_verified_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_fc := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'first_communion_records'
                 AND CONSTRAINT_NAME = 'fk_first_communion_records_book_verified_by');
SET @sql := IF(@fk_fc = 0,
  'ALTER TABLE first_communion_records ADD CONSTRAINT fk_first_communion_records_book_verified_by FOREIGN KEY (book_verified_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_mar := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'marriage_records'
                   AND CONSTRAINT_NAME = 'fk_marriage_records_book_verified_by');
SET @sql := IF(@fk_mar = 0,
  'ALTER TABLE marriage_records ADD CONSTRAINT fk_marriage_records_book_verified_by FOREIGN KEY (book_verified_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
