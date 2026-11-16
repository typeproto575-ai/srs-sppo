-- SRS-SPPO: Secretary-only password recovery
-- Tokens are stored as SHA-256 hashes; raw tokens are sent only by email.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  token_hash        CHAR(64) NOT NULL UNIQUE,
  requested_ip      VARCHAR(45) NOT NULL,
  expires_at        DATETIME NOT NULL,
  used_at           DATETIME DEFAULT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_password_reset_user_created (user_id, created_at),
  INDEX idx_password_reset_expiry (expires_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
