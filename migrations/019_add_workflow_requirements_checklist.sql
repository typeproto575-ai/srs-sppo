-- =====================================================================
-- 019: Reusable requirements checklist for office workflows
-- =====================================================================
-- Stores checklist rows separately from request/application data so the
-- secretary can track documentary review without changing the transaction
-- schema or storing uploaded documents.

CREATE TABLE IF NOT EXISTS workflow_requirements (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type   ENUM('certificate_request','sacrament_application') NOT NULL,
  entity_id     INT UNSIGNED NOT NULL,
  requirement_key VARCHAR(80) NOT NULL,
  requirement_label VARCHAR(180) NOT NULL,
  status        ENUM('not_received','received','under_review','incomplete','verified','not_applicable') NOT NULL DEFAULT 'not_received',
  notes         VARCHAR(500) DEFAULT NULL,
  reviewed_by   INT UNSIGNED DEFAULT NULL,
  reviewed_at   DATETIME DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_workflow_requirement (entity_type, entity_id, requirement_key),
  KEY idx_workflow_requirement_entity (entity_type, entity_id),
  KEY idx_workflow_requirement_status (status),
  CONSTRAINT fk_workflow_requirement_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
