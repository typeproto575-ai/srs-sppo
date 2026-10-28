-- 039: Promote persons to the reusable master identity profile.
-- Back up first and run on staging. Existing register rows remain nullable
-- and are not linked by name alone; ambiguous identities must be reviewed.

ALTER TABLE persons
  ADD COLUMN IF NOT EXISTS father_name VARCHAR(150) NULL AFTER birth_place,
  ADD COLUMN IF NOT EXISTS mother_maiden_name VARCHAR(150) NULL AFTER father_name;

-- Preserve the first available parent details from linked register entries.
-- Existing profile values take precedence; source register rows are unchanged.
UPDATE persons p
JOIN (
  SELECT person_id,
         MIN(NULLIF(father_name, '')) AS father_name,
         MIN(NULLIF(mother_maiden_name, '')) AS mother_maiden_name,
         COUNT(DISTINCT NULLIF(father_name, '')) AS father_name_variants,
         COUNT(DISTINCT NULLIF(mother_maiden_name, '')) AS mother_name_variants
  FROM baptismal_records
  WHERE person_id IS NOT NULL
  GROUP BY person_id
) b ON b.person_id = p.id
SET p.father_name = COALESCE(NULLIF(p.father_name, ''), IF(b.father_name_variants = 1, b.father_name, NULL)),
    p.mother_maiden_name = COALESCE(NULLIF(p.mother_maiden_name, ''), IF(b.mother_name_variants = 1, b.mother_maiden_name, NULL))
WHERE p.father_name IS NULL OR p.father_name = ''
   OR p.mother_maiden_name IS NULL OR p.mother_maiden_name = '';

UPDATE persons p
JOIN (
  SELECT person_id,
         MIN(NULLIF(father_name, '')) AS father_name,
         MIN(NULLIF(mother_maiden_name, '')) AS mother_maiden_name,
         COUNT(DISTINCT NULLIF(father_name, '')) AS father_name_variants,
         COUNT(DISTINCT NULLIF(mother_maiden_name, '')) AS mother_name_variants
  FROM confirmation_records
  WHERE person_id IS NOT NULL
  GROUP BY person_id
) c ON c.person_id = p.id
SET p.father_name = COALESCE(NULLIF(p.father_name, ''), IF(c.father_name_variants = 1, c.father_name, NULL)),
    p.mother_maiden_name = COALESCE(NULLIF(p.mother_maiden_name, ''), IF(c.mother_name_variants = 1, c.mother_maiden_name, NULL))
WHERE p.father_name IS NULL OR p.father_name = ''
   OR p.mother_maiden_name IS NULL OR p.mother_maiden_name = '';

UPDATE persons p
JOIN (
  SELECT person_id,
         MIN(NULLIF(father_name, '')) AS father_name,
         MIN(NULLIF(mother_maiden_name, '')) AS mother_maiden_name,
         COUNT(DISTINCT NULLIF(father_name, '')) AS father_name_variants,
         COUNT(DISTINCT NULLIF(mother_maiden_name, '')) AS mother_name_variants
  FROM first_communion_records
  WHERE person_id IS NOT NULL
  GROUP BY person_id
) f ON f.person_id = p.id
SET p.father_name = COALESCE(NULLIF(p.father_name, ''), IF(f.father_name_variants = 1, f.father_name, NULL)),
    p.mother_maiden_name = COALESCE(NULLIF(p.mother_maiden_name, ''), IF(f.mother_name_variants = 1, f.mother_maiden_name, NULL))
WHERE p.father_name IS NULL OR p.father_name = ''
   OR p.mother_maiden_name IS NULL OR p.mother_maiden_name = '';

-- person_id bridges and foreign keys were introduced by migrations 001/004.
-- Check the target database and apply 004 only if its constraints are absent.
