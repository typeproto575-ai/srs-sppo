-- Migration 003: Attempt automated exact-match mapping from legacy name columns to `persons.id`.
-- Run after 001 and 002. This sets bridging person_id columns where exact string match exists.

-- Baptismal
UPDATE baptismal_records b
JOIN persons p ON TRIM(b.child_full_name) = TRIM(p.first_name)
SET b.person_id = p.id
WHERE b.child_full_name IS NOT NULL AND TRIM(b.child_full_name) <> '';

-- Confirmation
UPDATE confirmation_records c
JOIN persons p ON TRIM(c.confirmand_full_name) = TRIM(p.first_name)
SET c.person_id = p.id
WHERE c.confirmand_full_name IS NOT NULL AND TRIM(c.confirmand_full_name) <> '';

-- First Communion
UPDATE first_communion_records f
JOIN persons p ON TRIM(f.communicant_full_name) = TRIM(p.first_name)
SET f.person_id = p.id
WHERE f.communicant_full_name IS NOT NULL AND TRIM(f.communicant_full_name) <> '';

-- Marriage (husband/wife)
UPDATE marriage_records m
JOIN persons p ON TRIM(m.husband_full_name) = TRIM(p.first_name)
SET m.husband_person_id = p.id
WHERE m.husband_full_name IS NOT NULL AND TRIM(m.husband_full_name) <> '';

UPDATE marriage_records m
JOIN persons p ON TRIM(m.wife_maiden_name) = TRIM(p.first_name)
SET m.wife_person_id = p.id
WHERE m.wife_maiden_name IS NOT NULL AND TRIM(m.wife_maiden_name) <> '';

-- Death / burial (handled by migration runner if table exists)
-- UPDATE death_burial_records d
-- JOIN persons p ON TRIM(d.deceased_full_name) = TRIM(p.first_name)
-- SET d.person_id = p.id
-- WHERE d.deceased_full_name IS NOT NULL AND TRIM(d.deceased_full_name) <> '';

-- After running, generate reports of unmatched rows (see scripts/export_unmatched_queries.sql)
COMMIT;