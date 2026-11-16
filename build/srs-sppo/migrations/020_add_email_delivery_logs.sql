-- =====================================================================
-- 020: Email delivery history and complete notification event types
-- =====================================================================

CREATE TABLE IF NOT EXISTS email_delivery_log (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient      VARCHAR(150) NOT NULL,
  subject_line   VARCHAR(255) NOT NULL,
  event_type     VARCHAR(80) NOT NULL,
  entity_type    VARCHAR(80) DEFAULT NULL,
  entity_id      INT UNSIGNED DEFAULT NULL,
  tracking_number VARCHAR(40) DEFAULT NULL,
  delivery_status ENUM('sent','failed','disabled','invalid') NOT NULL,
  error_message  VARCHAR(500) DEFAULT NULL,
  sent_by        INT UNSIGNED DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_delivery_entity (entity_type, entity_id),
  KEY idx_email_delivery_status (delivery_status, created_at),
  KEY idx_email_delivery_recipient (recipient, created_at),
  FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE notifications
  MODIFY type ENUM('request_new','application_new','request_ready','event_reminder','conflict',
                   'request_under_review','request_approved','request_rejected',
                   'application_submitted','application_documents_pending','application_interview_scheduled',
                   'application_banns_in_progress','application_seminar_pending','application_ready_for_approval',
                   'application_approved','application_scheduled','application_completed','application_rejected',
                   'certificate_picked_up') NOT NULL;
