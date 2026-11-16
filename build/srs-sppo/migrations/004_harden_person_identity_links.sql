-- Migration 004: complete and harden sacramental identity links.
-- Run after migrations 001-003. Existing links remain nullable so records
-- from an external parish can be stored before a local person match exists.

ALTER TABLE baptismal_records
  ADD CONSTRAINT fk_baptismal_person
  FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL;

ALTER TABLE confirmation_records
  ADD CONSTRAINT fk_confirmation_person
  FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL;

ALTER TABLE first_communion_records
  ADD CONSTRAINT fk_first_communion_person
  FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL;

ALTER TABLE marriage_records
  ADD CONSTRAINT fk_marriage_husband_person
  FOREIGN KEY (husband_person_id) REFERENCES persons(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_marriage_wife_person
  FOREIGN KEY (wife_person_id) REFERENCES persons(id) ON DELETE SET NULL;

CREATE INDEX idx_persons_name_birth
  ON persons (last_name, first_name, birth_date);
CREATE INDEX idx_persons_maiden_name
  ON persons (maiden_name);
-- Review these before assigning them. Do not force ambiguous matches.
SELECT 'unmatched_baptismal' AS issue, COUNT(*) AS total
FROM baptismal_records WHERE person_id IS NULL
UNION ALL
SELECT 'unmatched_confirmation', COUNT(*)
FROM confirmation_records WHERE person_id IS NULL
UNION ALL
SELECT 'unmatched_first_communion', COUNT(*)
FROM first_communion_records WHERE person_id IS NULL
UNION ALL
SELECT 'unmatched_marriage_husband', COUNT(*)
FROM marriage_records WHERE husband_person_id IS NULL
UNION ALL
SELECT 'unmatched_marriage_wife', COUNT(*)
FROM marriage_records WHERE wife_person_id IS NULL