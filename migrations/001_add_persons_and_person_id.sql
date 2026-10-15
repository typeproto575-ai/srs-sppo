-- Migration 001: Add `persons` table (if missing) and bridging person_id columns.
-- Run in staging first, verify, then on production in maintenance window.

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

-- Add foreign keys (can be enabled after population to avoid FK constraint issues)
-- ALTER TABLE baptismal_records ADD CONSTRAINT fk_bap_person FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL;
-- ALTER TABLE confirmation_records ADD CONSTRAINT fk_conf_person FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL;
-- ALTER TABLE first_communion_records ADD CONSTRAINT fk_fc_person FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL;
-- ALTER TABLE marriage_records ADD CONSTRAINT fk_mar_husband_person FOREIGN KEY (husband_person_id) REFERENCES persons(id) ON DELETE SET NULL;
-- ALTER TABLE marriage_records ADD CONSTRAINT fk_mar_wife_person FOREIGN KEY (wife_person_id) REFERENCES persons(id) ON DELETE SET NULL;

-- Commit
COMMIT;