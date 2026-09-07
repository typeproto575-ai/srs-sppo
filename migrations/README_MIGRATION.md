# Migrations — historical record

These scripts were one-time database migrations applied when the Person
Registry (`persons`) was introduced. They are kept for documentation and
history only.

- `001_add_persons_and_person_id.sql` — created the `persons` table and added
  the nullable `person_id` / `husband_person_id` / `wife_person_id` bridge
  columns to the record tables.
- `002_populate_persons.sql` — inserted one best-effort name stub per distinct
  person name found in the existing record tables.
- `003_map_person_ids.sql` — linked existing records to `persons` rows by
  exact name match; leftovers were reconciled manually through the Person
  Mapper (admin/system/person-mapper.php).

**Fresh installs do not need any of these.** The equivalent of migration 001
is now part of the canonical schema (`database/sppo_is_schema.sql`), and the
demo dataset (`database/seed_demo_data.sql`) populates `persons` for
demonstration. Migrations 002 and 003 were one-time data operations against
the pre-existing parish database and have already been applied there.

Note: the bridge columns intentionally have no foreign-key constraints (same
as the live database); referential integrity is managed by the application.