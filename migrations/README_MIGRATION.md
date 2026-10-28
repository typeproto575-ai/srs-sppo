# Database setup, upgrades, and historical migrations

## The short version

- **New, empty installation:** import `database/srs_sppo_schema.sql`. This is
  the canonical schema for a fresh database. Do not import every file in
  `migrations/`.
- **Existing installation:** do not guess which SQL files to run. First back
  up the database, inspect its current schema, and apply only the upgrade
  scripts required to bring that specific database to the version expected by
  the application. For the supported compatibility updates, use the
  authenticated **System → Database Upgrades** page; it checks the actual
  schema and records completed updates.
- **Demo data:** `database/seed_demo_data.sql` is only for a disposable local
  database. It deletes existing sacramental, request, application, and person
  rows before inserting demo data. Never run it against a real parish or
  hosted database.
- **Quick process demo:** `database/seed_process_demo.sql` adds a small set of
  fictional, clearly labeled records without clearing existing data. Use it
  only on a local/demo database. Existing databases need the relevant schema
  updates, including migrations 039, 045, and 046. It can be run repeatedly
  without resetting statuses.
- **Defense walkthrough:** `database/seed_defense_demo.sql` is a broader,
  additive fictional dataset for local presentations. It does not create staff
  accounts or reset existing statuses. See `docs/DEMO_WALKTHROUGH.md` for the
  current routes, supported status names, and safe import steps. Do not use any
  demo seed on a live parish database.

The application does not run updates automatically during ordinary requests.
The protected upgrade page supports only the named compatibility updates
shown there; it does not replay every historical file. Uploading the
application does not itself update the database, and filenames do not provide
a safe universal run order. Some numeric prefixes are reused
(including 017, 023, 025, 026, 027, 028, and 038); identify scripts by their
full filenames and purpose, not by number alone.

## What the SQL files are for

### Canonical fresh-install schema

`database/srs_sppo_schema.sql` defines the current tables and columns for a
fresh installation, including the person registry, workflow features,
retention fields, and the upgrade-history table. This schema—not the
collection of historical migrations—is the starting point for a new database.

`database/srs_sppo_workbench_erd.sql` is an optional Workbench-oriented DDL
reference for reverse engineering an ER diagram. It is not a migration or seed
file; `database/srs_sppo_schema.sql` is authoritative if the copies differ.

### Historical and incremental migrations

Files in `migrations/` record changes made as the application evolved. Some
are one-time data operations; others upgrade an existing database for a
feature. They are not all intended to be run on every installation.

The person-registry sequence documents the original rollout:

1. `001_add_persons_and_person_id.sql` created `persons`, nullable links, and
   link lookup indexes.
2. `002_populate_persons.sql` created best-effort person stubs from existing
   sacramental-record names.
3. `003_map_person_ids.sql` attempted to link records by exact name. A name
   match alone is not reliable proof of identity.
4. `003a_validate_person_identity_links.sql` is a read-only report for
   repeated names, missing links, and orphan links.
5. `004_harden_person_identity_links.sql` adds foreign keys and search indexes
   for the person links. The canonical fresh-install schema includes these
   constraints while allowing legacy rows to remain unlinked. On an existing
   database, validate orphan links and check for the constraints before
   applying 004; do not run it blindly or duplicate its constraints.
6. `039_master_person_profile.sql` adds reusable parent-name fields to
   `persons` and fills only blank profile values from already linked register
   rows. It deliberately does not link records by name. New staff-encoded
   register entries create a master person profile when no existing identity
   is selected; online submissions are not automatically made into profiles
   before staff review.
7. `045_link_sacrament_applications_to_persons.sql` adds nullable, staff-
   confirmed person links to sacrament applications. The second link supports
   the other party in a marriage application. Existing applications remain
   unlinked until reviewed; the migration does not infer identities from names.
8. `046_confirmation_application_profile_fields.sql` adds optional identity,
   family, and preparation fields to existing Confirmation applications to
   match the canonical schema.

The protected upgrade page handles the supported compatibility updates for
public page settings (007), request review fields (012), and retention support
(038). Other files remain as historical upgrade records; an older database
may need them, but they are not blindly executed by the application. For an
existing database, compare its schema with the required application version;
do not rerun historical scripts just because their files are present.

`database/migration_notifications.sql` is a legacy one-off copy of a table
definition already present in the canonical schema. It is not needed for a
fresh installation.

`database/alter_live_db_sync.sql` is an older compatibility bundle for a
particular live-schema state. It overlaps individual migrations and the
canonical schema; it is not a general upgrade script. Do not run it without
first checking every statement against the target database.

### Seed and test data

- `database/seed_demo_data.sql` intentionally clears existing demo-related
  rows and repopulates a larger coherent sample dataset. Use only with a
  disposable local database after importing the canonical schema.
- `scripts/setup-test-db.php` creates and drops a specifically configured
  integration-test database, then loads the canonical schema. It is test
  tooling, not a production deployment command. Never point its test database
  setting at application data.

## Safe upgrade checklist

1. Identify whether this is a new database, a disposable test database, or an
   existing database containing parish records.
2. Make a verified backup before changing an existing database.
3. Compare the existing schema with `database/srs_sppo_schema.sql` and the
   application features being deployed.
4. For updates supported by the protected upgrade page, review its preflight
   status. Older, unsupported migration files require separate schema review;
   do not run them from a bulk import.
5. Test on a staging copy and verify the application before applying to
   production.
6. The application records updates applied through its upgrade page. Keep a
   separate deployment record for any older manual migration.

If the current hosted database's history is unknown, stop and inspect it
before running any migration or seed script. Never use the demo seeds to
"synchronize" a production database.
