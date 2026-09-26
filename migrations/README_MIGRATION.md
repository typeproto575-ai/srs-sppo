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
is now part of the canonical schema (`database/srs_sppo_schema.sql`), and the
demo dataset (`database/seed_demo_data.sql`) populates `persons` for
demonstration. Migrations 002 and 003 were one-time data operations against
the pre-existing parish database and have already been applied there.

Note: the bridge columns intentionally have no foreign-key constraints (same
as the live database); referential integrity is managed by the application.


## Current workflow migrations

- `019_add_workflow_requirements_checklist.sql` — creates the `workflow_requirements` table used by the secretary-side requirements checklist for certificate requests and sacrament applications. It stores only checklist status and notes; it does not store uploaded documents. Apply this migration before using the checklist in an existing database.
- `020_add_email_delivery_logs.sql` — adds `email_delivery_log` for sent/failed/disabled/invalid delivery attempts and expands the notification event enum so all certificate and application status alerts are preserved.
Migration `021_admin_role_audit_metadata.sql` adds the `parish_admin` role, enriches append-only audit entries with role, IP, user-agent, and reason fields, and adds audit indexes for filtering and export. Existing staff remain secretaries until a Parish Administrator performs or completes the controlled first-time promotion.

- `022_phase4_workflow_controls.sql` — adds requirements source/review metadata, certificate cancellation/finalization fields, and archive metadata for sacramental records. Existing direct public submission routes now redirect to the onsite requirements workflow through `includes/public-office-only.php`.
- `023_phase5a_online_intake.sql` — adds the online certificate intake fields and submission-channel metadata used by the public certificate request flow.
- `024_secretary_password_recovery.sql` — adds account authentication versions and one-time password-reset tokens for active secretary and parish administrator accounts.
- `028_make_baptismal_certificate_birthplace_optional.sql` — allows certificate requesters to leave birthplace blank when the child’s name, birthdate, and parent names are available for lookup.
