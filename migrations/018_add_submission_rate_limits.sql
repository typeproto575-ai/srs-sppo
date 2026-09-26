-- =====================================================================
-- 018: Abuse guard for public submissions (rate limiting store)
-- =====================================================================
-- Public certificate-request and sacrament-application forms have no login,
-- so nothing stopped one person (or a script) from flooding the queue with
-- hundreds of submissions. This table records one row per SUCCESSFUL public
-- submission so includes/functions.php can enforce a rolling 24-hour cap:
--
--   * 3 submissions per email address per 24h
--   * 20 submissions per client IP per 24h
--
-- Only successes are recorded, so a visitor re-submitting an invalid form
-- never burns quota. The 24h window is rolling (NOW() - INTERVAL 1 DAY),
-- not calendar-day, which keeps the check timezone-safe.
--
-- Why a dedicated table instead of audit_logs: audit_logs has no IP column
-- and its rows are the parish's accountability record -- they should not be
-- pruned or counted for anti-spam purposes. Rows here are disposable and
-- safe to purge at any time, e.g.:
--   DELETE FROM submission_rate_limits WHERE submitted_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
--
-- Idempotent: CREATE TABLE IF NOT EXISTS, so re-running is a no-op.
--
-- NOTE: the equivalent table is also part of the canonical schema
-- (database/srs_sppo_schema.sql) for fresh installs.
-- =====================================================================

CREATE TABLE IF NOT EXISTS submission_rate_limits (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(150) NOT NULL,           -- lowercased/trimmed submitter email
  ip_address   VARCHAR(45) NOT NULL,            -- client_ip(), IPv6-safe length
  form_type    VARCHAR(50) NOT NULL,            -- e.g. 'certificate_baptismal' (diagnostics only)
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_time (email, submitted_at),     -- email cap lookup
  KEY idx_ip_time    (ip_address, submitted_at) -- IP cap lookup
) ENGINE=InnoDB;
