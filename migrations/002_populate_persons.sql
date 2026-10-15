-- Migration 002: Populate `persons` with unique name stubs from existing sacrament tables.
-- This is best-effort and stores full original name strings into `first_name` for manual cleaning later.

-- Baptismal children
INSERT IGNORE INTO persons (first_name, created_at)
SELECT DISTINCT TRIM(child_full_name), NOW()
FROM baptismal_records
WHERE child_full_name IS NOT NULL AND TRIM(child_full_name) <> '';

-- Confirmation confirmands
INSERT IGNORE INTO persons (first_name, created_at)
SELECT DISTINCT TRIM(confirmand_full_name), NOW()
FROM confirmation_records
WHERE confirmand_full_name IS NOT NULL AND TRIM(confirmand_full_name) <> '';

-- First communion communicants
INSERT IGNORE INTO persons (first_name, created_at)
SELECT DISTINCT TRIM(communicant_full_name), NOW()
FROM first_communion_records
WHERE communicant_full_name IS NOT NULL AND TRIM(communicant_full_name) <> '';

-- Marriage parties (husband/wife)
INSERT IGNORE INTO persons (first_name, created_at)
SELECT DISTINCT TRIM(husband_full_name), NOW()
FROM marriage_records
WHERE husband_full_name IS NOT NULL AND TRIM(husband_full_name) <> '';

INSERT IGNORE INTO persons (first_name, created_at)
SELECT DISTINCT TRIM(wife_maiden_name), NOW()
FROM marriage_records
WHERE wife_maiden_name IS NOT NULL AND TRIM(wife_maiden_name) <> '';

COMMIT;