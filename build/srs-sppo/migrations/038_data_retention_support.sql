-- =====================================================================
-- Migration 038: Data Retention & PII Anonymization Support
-- Adds anonymized_at columns and optimization indexes for the purge engine.
-- =====================================================================

-- 1. Anonymization Tracking Columns
ALTER TABLE certificate_requests ADD COLUMN anonymized_at DATETIME NULL;
ALTER TABLE sacrament_applications ADD COLUMN anonymized_at DATETIME NULL;
ALTER TABLE correction_requests ADD COLUMN anonymized_at DATETIME NULL;

-- 2. Optimization Indexes for Purge Filters
-- Certificate Requests: filtered by status, processed/submitted dates, and anonymized_at
CREATE INDEX idx_cert_purge ON certificate_requests (status, date_processed, date_submitted, anonymized_at);

-- Sacrament Applications: filtered by status, processed/submitted dates, and anonymized_at
CREATE INDEX idx_sacr_purge ON sacrament_applications (status, date_processed, date_submitted, anonymized_at);

-- Correction Requests: filtered by status, updated_at, and anonymized_at
CREATE INDEX idx_corr_purge ON correction_requests (status, updated_at, anonymized_at);

-- General cleanup indexes for high-volume logs
CREATE INDEX idx_audit_purge ON audit_logs (created_at);
CREATE INDEX idx_delivery_purge ON email_delivery_log (created_at);
CREATE INDEX idx_notif_purge ON notifications (created_at);
CREATE INDEX idx_ratelimit_purge ON submission_rate_limits (submitted_at);
