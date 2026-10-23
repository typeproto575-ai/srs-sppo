-- Migration 003A: Validate person identity links before optional hardening.
-- This script is read-only and intentionally does not change data.
-- Run after migration 003 and before migration 004, if hardening is planned.
--
-- Purpose:
-- 1) surface repeated names for review (same name does not prove same identity),
-- 2) detect records still without a person match,
-- 3) detect orphaned person_id values that would violate a foreign key.

SELECT 'repeated_person_names_review' AS issue,
       first_name,
       COUNT(*) AS total
FROM persons
WHERE first_name IS NOT NULL
  AND TRIM(first_name) <> ''
GROUP BY first_name
HAVING COUNT(*) > 1

UNION ALL

SELECT 'unmatched_baptismal_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM baptismal_records
WHERE person_id IS NULL

UNION ALL

SELECT 'unmatched_confirmation_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM confirmation_records
WHERE person_id IS NULL

UNION ALL

SELECT 'unmatched_first_communion_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM first_communion_records
WHERE person_id IS NULL

UNION ALL

SELECT 'unmatched_marriage_husband_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM marriage_records
WHERE husband_person_id IS NULL

UNION ALL

SELECT 'unmatched_marriage_wife_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM marriage_records
WHERE wife_person_id IS NULL

UNION ALL

SELECT 'orphan_baptismal_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM baptismal_records b
LEFT JOIN persons p ON p.id = b.person_id
WHERE b.person_id IS NOT NULL
  AND p.id IS NULL

UNION ALL

SELECT 'orphan_confirmation_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM confirmation_records c
LEFT JOIN persons p ON p.id = c.person_id
WHERE c.person_id IS NOT NULL
  AND p.id IS NULL

UNION ALL

SELECT 'orphan_first_communion_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM first_communion_records f
LEFT JOIN persons p ON p.id = f.person_id
WHERE f.person_id IS NOT NULL
  AND p.id IS NULL

UNION ALL

SELECT 'orphan_marriage_husband_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM marriage_records m
LEFT JOIN persons p ON p.id = m.husband_person_id
WHERE m.husband_person_id IS NOT NULL
  AND p.id IS NULL

UNION ALL

SELECT 'orphan_marriage_wife_records' AS issue,
       NULL AS first_name,
       COUNT(*) AS total
FROM marriage_records m
LEFT JOIN persons p ON p.id = m.wife_person_id
WHERE m.wife_person_id IS NOT NULL
  AND p.id IS NULL

ORDER BY issue, first_name;

-- Review repeated names as candidates only; do not merge people based on name alone.
-- Unmatched rows are NULL and remain valid with nullable foreign keys. Review them
-- separately if the business requires every record to have a person link.
-- Orphan counts must be zero before adding foreign keys.
--
-- If these checks show rows > 0, resolve them in staging first. Do not enable
-- the foreign keys in production until orphaned links are corrected.
