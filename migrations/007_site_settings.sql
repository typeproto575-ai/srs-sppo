-- Migration 007: site_settings (secretary-editable public page content)
-- Backs the Admin > Website > Public Page Content screen. Seeds the values
-- previously hardcoded on public/index.php so behavior is unchanged.
-- Scope note: presentation content only - no fees, no new sacrament features.

CREATE TABLE IF NOT EXISTS site_settings (
  setting_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key   VARCHAR(64)  NOT NULL UNIQUE,
  setting_value TEXT         NOT NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by    INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- >>> RUN SPLIT <<<

INSERT INTO site_settings (setting_key, setting_value) VALUES
('hero_eyebrow_text', 'Parish Office Online'),
('hero_subtitle_text', 'Dingalan, Aurora - the parish''s sacrament records and certificates, kept faithfully for generations, now easier to reach.'),
('office_hours_text', CONCAT('Monday - Saturday: 8:00 AM - 12:00 PM', CHAR(10), 'Monday - Saturday: 2:00 PM - 5:00 PM', CHAR(10), 'Sunday: Closed')),
('office_location_text', CONCAT('St. Patrick''s Parish Office', CHAR(10), 'Dingalan, Aurora, Philippines'));
