-- 014: Add missing FK on baptismal_application_godparents.baptismal_application_id
--
-- The canonical schema (srs_sppo_schema.sql) declares this FK, but the live
-- table was created without it (only a non-unique index exists on the column).
-- This migration adds the formal constraint so deletes from
-- baptismal_applications cascade to their godparent rows and referential
-- integrity is enforced at the engine level.
--
-- Pre-check before applying (run manually if in doubt):
--   SELECT g.id FROM baptismal_application_godparents g
--   LEFT JOIN baptismal_applications b ON b.id = g.baptismal_application_id
--   WHERE b.id IS NULL;
--   (must return 0 rows — no orphaned godparent rows)
--
-- As of 2026-09-13 the live table has 2 rows, both pointing to
-- baptismal_applications.id = 22 (exists), so this is safe to apply.

ALTER TABLE baptismal_application_godparents
  ADD CONSTRAINT fk_bapt_godparents_application
  FOREIGN KEY (baptismal_application_id) REFERENCES baptismal_applications(id) ON DELETE CASCADE;