-- Add staff ownership to sacrament pre-applications.
-- Assignment changes are recorded in audit_logs by the application.
ALTER TABLE sacrament_applications
  ADD COLUMN assigned_to INT UNSIGNED NULL AFTER processed_by,
  ADD INDEX idx_sa_assigned (assigned_to),
  ADD CONSTRAINT fk_sa_assigned
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL;
