Migration scripts overview

Files added:
- migrations/001_add_persons_and_person_id.sql
  - Creates `persons` table (if missing) and adds nullable `person_id` bridging columns.
- migrations/002_populate_persons.sql
  - Inserts distinct name stubs into `persons` from existing sacrament tables (best-effort).
- migrations/003_map_person_ids.sql
  - Attempts exact-match mapping from legacy name columns to `persons.id` and populates bridging columns.
- scripts/export_unmatched_queries.sql
  - SELECT queries you can run to list rows that remain unmapped (for manual review).
- scripts/run_migration_stage1.ps1
  - PowerShell helper to run the SQL files against a staging DB and export unmatched rows to CSV.

Important notes and recommended usage

1) Run in staging first
- Create a staging DB (for example `sppo_is_migration_test`) and import a copy of your current production data.
- Run the PowerShell script against staging to test the entire flow.

2) Backups
- Always dump your production DB before running any migration in production.
  Example:

```powershell
mysqldump -u root -p --routines --triggers --events sppo_is_db > C:\backups\sppo_is_db_pre_migration.sql
```

3) Understand the limitations
- The population step stores the original full name into the `first_name` column as a stub to be cleaned later.
- Exact-match mapping will miss typos and formatting differences. Use the exported unmatched CSVs for manual reconciliation.

4) Manual resolution options
- Create `persons` entries manually for ambiguous names and update the relevant `person_id` values.
- Or request me to create an interactive PHP admin page to help link unmatched rows to `persons`.

5) Foreign keys
- The migration adds nullable columns; foreign keys are commented out in `001_...sql`. Add FK constraints only after mapping and verification.

6) Next steps (I can do these for you)
- Provide the interactive mapping admin page (PHP) to speed manual matching.
- Create a more advanced name-parsing script to split full names into first/middle/last automatically (best-effort).
- Run the staging migration here and produce the unmatched CSV exports for you to review.

If you want me to run the staging migration now, reply with the staging DB name and MySQL credentials (or confirm I'll run it using `root` and prompt for password). Otherwise tell me which next deliverable to prepare.