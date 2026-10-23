-- Migration 001: Add `persons` table (if missing) and bridging person_id columns.
-- Run in staging first, verify, then on production in maintenance window.
-- Safe hardening is intentionally delayed until after backfill and review so
-- existing data is not rejected by new constraints during deployment.

CREATE TABLE IF NOT EXISTS persons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(150) NOT NULL,
  middle_name VARCHAR(100) DEFAULT NULL,
  last_name VARCHAR(100) DEFAULT NULL,
  suffix VARCHAR(20) DEFAULT NULL,
  maiden_name VARCHAR(150) DEFAULT NULL,
  sex ENUM('male','female') DEFAULT NULL,
  birth_date DATE DEFAULT NULL,
  birth_place VARCHAR(150) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  contact_number VARCHAR(30) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  baptismal_status ENUM('baptized_catholic','baptized_non_catholic','unbaptized') DEFAULT 'unbaptized',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Add nullable person_id bridging columns to sacrament tables (non-destructive)
ALTER TABLE IF EXISTS baptismal_records
  ADD COLUMN IF NOT EXISTS person_id INT UNSIGNED NULL;

ALTER TABLE IF EXISTS confirmation_records
  ADD COLUMN IF NOT EXISTS person_id INT UNSIGNED NULL;

ALTER TABLE IF EXISTS first_communion_records
  ADD COLUMN IF NOT EXISTS person_id INT UNSIGNED NULL;

ALTER TABLE IF EXISTS marriage_records
  ADD COLUMN IF NOT EXISTS husband_person_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS wife_person_id INT UNSIGNED NULL;

-- Add lookup indexes for person links only when their table and index exist
-- and the index is not already present.
SELECT COUNT(*) INTO @baptismal_table_exists
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'baptismal_records';

SELECT COUNT(*) INTO @baptismal_person_idx_exists
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'baptismal_records'
  AND index_name = 'idx_baptismal_records_person_id';

SET @baptismal_person_idx_sql = IF(@baptismal_table_exists > 0 AND @baptismal_person_idx_exists = 0,
  'CREATE INDEX idx_baptismal_records_person_id ON baptismal_records (person_id)',
  'SELECT 1');
PREPARE stmt_baptismal_person_idx FROM @baptismal_person_idx_sql;
EXECUTE stmt_baptismal_person_idx;
DEALLOCATE PREPARE stmt_baptismal_person_idx;

SELECT COUNT(*) INTO @confirmation_table_exists
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'confirmation_records';

SELECT COUNT(*) INTO @confirmation_person_idx_exists
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'confirmation_records'
  AND index_name = 'idx_confirmation_records_person_id';

SET @confirmation_person_idx_sql = IF(@confirmation_table_exists > 0 AND @confirmation_person_idx_exists = 0,
  'CREATE INDEX idx_confirmation_records_person_id ON confirmation_records (person_id)',
  'SELECT 1');
PREPARE stmt_confirmation_person_idx FROM @confirmation_person_idx_sql;
EXECUTE stmt_confirmation_person_idx;
DEALLOCATE PREPARE stmt_confirmation_person_idx;

SELECT COUNT(*) INTO @first_communion_table_exists
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'first_communion_records';

SELECT COUNT(*) INTO @first_communion_person_idx_exists
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'first_communion_records'
  AND index_name = 'idx_first_communion_records_person_id';

SET @first_communion_person_idx_sql = IF(@first_communion_table_exists > 0 AND @first_communion_person_idx_exists = 0,
  'CREATE INDEX idx_first_communion_records_person_id ON first_communion_records (person_id)',
  'SELECT 1');
PREPARE stmt_first_communion_person_idx FROM @first_communion_person_idx_sql;
EXECUTE stmt_first_communion_person_idx;
DEALLOCATE PREPARE stmt_first_communion_person_idx;

SELECT COUNT(*) INTO @marriage_table_exists
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'marriage_records';

SELECT COUNT(*) INTO @marriage_husband_idx_exists
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'marriage_records'
  AND index_name = 'idx_marriage_records_husband_person_id';

SET @marriage_husband_idx_sql = IF(@marriage_table_exists > 0 AND @marriage_husband_idx_exists = 0,
  'CREATE INDEX idx_marriage_records_husband_person_id ON marriage_records (husband_person_id)',
  'SELECT 1');
PREPARE stmt_marriage_husband_idx FROM @marriage_husband_idx_sql;
EXECUTE stmt_marriage_husband_idx;
DEALLOCATE PREPARE stmt_marriage_husband_idx;

SELECT COUNT(*) INTO @marriage_wife_idx_exists
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'marriage_records'
  AND index_name = 'idx_marriage_records_wife_person_id';

SET @marriage_wife_idx_sql = IF(@marriage_table_exists > 0 AND @marriage_wife_idx_exists = 0,
  'CREATE INDEX idx_marriage_records_wife_person_id ON marriage_records (wife_person_id)',
  'SELECT 1');
PREPARE stmt_marriage_wife_idx FROM @marriage_wife_idx_sql;
EXECUTE stmt_marriage_wife_idx;
DEALLOCATE PREPARE stmt_marriage_wife_idx;

-- Add foreign keys only after backfill and validation in a later migration.
-- This prevents live data from failing during deployment when historical records
-- do not yet have clean person matches.

-- Commit
COMMIT;