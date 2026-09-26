-- Keep the child's birthplace separate from the baptism location.
ALTER TABLE baptismal_records
  ADD COLUMN child_birthplace VARCHAR(150) NULL AFTER baptism_place;

-- Preserve the value used by older records until staff can verify the two
-- distinct places against the physical register.
UPDATE baptismal_records
SET child_birthplace = baptism_place
WHERE child_birthplace IS NULL AND baptism_place IS NOT NULL AND baptism_place <> '';