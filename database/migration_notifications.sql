-- =====================================================================
-- MIGRATION: admin notifications table (notification bell feature)
-- =====================================================================
-- Legacy one-off copy. New installs already have this table in
-- srs_sppo_schema.sql; check the target schema before using on an old database.
-- Run once on an existing database. New installs get this table from
-- srs_sppo_schema.sql automatically.
CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        ENUM('request_new', 'application_new', 'request_ready', 'event_reminder', 'conflict') NOT NULL,
  title       VARCHAR(150) NOT NULL,
  message     VARCHAR(255) NOT NULL,
  link        VARCHAR(200) NOT NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at     DATETIME NULL,
  INDEX idx_notif_unread (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;