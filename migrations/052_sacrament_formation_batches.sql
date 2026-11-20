CREATE TABLE sacrament_batches (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sacrament_type  ENUM('first_communion','confirmation') NOT NULL,
  grade_level     VARCHAR(32) NOT NULL,
  school_year     CHAR(9) NOT NULL,
  batch_name      VARCHAR(150) NOT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sb_type_year_grade (sacrament_type, school_year, grade_level),
  CONSTRAINT fk_sb_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE sacrament_applications
  ADD COLUMN batch_id INT UNSIGNED NULL AFTER person_id,
  ADD INDEX idx_sa_batch (batch_id),
  ADD CONSTRAINT fk_sa_batch
    FOREIGN KEY (batch_id) REFERENCES sacrament_batches(id) ON DELETE SET NULL;

ALTER TABLE schedule_events
  ADD COLUMN batch_id INT UNSIGNED NULL AFTER application_id,
  ADD UNIQUE INDEX uq_se_batch (batch_id),
  ADD CONSTRAINT fk_se_batch
    FOREIGN KEY (batch_id) REFERENCES sacrament_batches(id) ON DELETE SET NULL;
