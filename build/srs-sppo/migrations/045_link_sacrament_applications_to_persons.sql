-- Preserve staff-confirmed identity matches on application intake records.
-- Marriage applications can link both applicants; the secondary link is
-- unused for the other sacrament types.
ALTER TABLE sacrament_applications
  ADD COLUMN person_id INT UNSIGNED NULL AFTER processed_by,
  ADD COLUMN spouse_person_id INT UNSIGNED NULL AFTER person_id,
  ADD KEY idx_sacr_person (person_id),
  ADD KEY idx_sacr_spouse_person (spouse_person_id),
  ADD CONSTRAINT fk_sacr_person
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_sacr_spouse_person
    FOREIGN KEY (spouse_person_id) REFERENCES persons(id) ON DELETE SET NULL;
