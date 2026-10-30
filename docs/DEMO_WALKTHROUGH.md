# SRS-SPPO Demo Dataset and Walkthrough

This guide is the project-specific, runnable version of the supplied demo
walkthrough. It uses the routes, tables, and status values that exist in the
current codebase.

## Safety and setup

1. Use a disposable local/demo database. Back it up first if it already
   contains anything you need.
2. Import the current canonical schema in
   [`database/srs_sppo_schema.sql`](../database/srs_sppo_schema.sql). On an
   existing installation, complete the applicable migrations first, including
   `039_master_person_profile.sql` and
   `045_link_sacrament_applications_to_persons.sql` and
   `046_confirmation_application_profile_fields.sql`.
3. Import [`database/seed_defense_demo.sql`](../database/seed_defense_demo.sql)
   through phpMyAdmin or the MySQL command line. The seed is additive and
   repeatable: it does not delete data, reset workflow statuses, or replace
   existing site copy. It can also be used alongside
   [`database/seed_process_demo.sql`](../database/seed_process_demo.sql).
4. Create staff logins separately with `php scripts/create-admin.php`.
   Repeat the command to create a second secretary. The script prompts for a
   password and stores a password hash; use local-only credentials of at least
   12 characters. The current schema supports the `secretary` role only, not a
   separate `admin` role. The SQL seed intentionally creates no accounts or
   reusable passwords.
5. Sign in at `/admin/login.php`. Open the public portal at `/public/index.php`.

All seed identities, audit examples, announcements, and events are fictional.
The demonstration audit rows are explicitly labeled as seed samples; actions
you perform while signed in are logged as actual staff actions. Do not load the
dataset into a live parish database.

> `database/seed_demo_data.sql` is a separate reset-style seed for disposable
> databases. It clears targeted tables. Do not use it as a repeatable,
> additive import.

## What the defense seed contains

- 11 master person profiles and linked official register entries:
  - 5 Baptisms, with sponsors;
  - 4 First Communions;
  - 3 Confirmations;
  - 2 Marriages, with witnesses.
- 4 public sacrament applications, including a new-child intake, a Confirmation
  and First Communion application ready for review/encoding, and a Marriage
  application with two separate people to match.
- 5 certificate requests across submission, payment, review, pickup-ready, and
  released states, with extension details and generation/pickup history.
- Document checklist examples, 6 upcoming calendar events, 3 published
  announcements, missing default public-site settings, and clearly marked
  sample audit rows.

The sample tracking numbers are:

| Tracking number | Sample |
|---|---|
| `SPPO-APP-2026-BAPDEFENSEABCDEF` | New-child Baptism application |
| `SPPO-APP-2026-CNFDEFENSABCDEFG` | Confirmation application |
| `SPPO-APP-2026-FCDEFENSABCDEFGH` | First Communion application |
| `SPPO-APP-2026-MARRDEFENSABCDEF` | Marriage application |
| `SPPO-2026-CERTDEFENSABCDEF` | Certificate submitted for initial review |
| `SPPO-2026-CERTDEFENSABCDEG` | Certificate awaiting payment |
| `SPPO-2026-CERTDEFENSABCDEH` | Certificate under review |
| `SPPO-2026-CERTDEFENSABCDEJ` | Certificate ready for pickup |
| `SPPO-2026-CERTDEFENSABCDEK` | Sample released certificate |

Use **Eva Demo Santos**, born `2014-06-15`, to find the cross-register profile.
Other useful matches include **Marco Demo Reyes** (`1997-04-20`),
**Nina Demo Cruz** (`1998-09-08`), **Emma Demo Reyes** (`2012-02-11`), and
**Sofia Demo Garcia** (`2016-09-17`).

## Status terminology used by the application

The supplied guide used phrases such as “Under Review” and “Ready for
Processing” as if they were stored status values. They are not values in the
current database enum. Use the actual labels/statuses below when describing
the system:

- Certificate requests use values including `submitted`, `under_review`,
  `awaiting_payment`, `approved`, `processing`, `ready_for_pickup`, and
  `released`.
- Sacrament applications use values including `submitted`,
  `requirements_pending`, `awaiting_payment`, `documents_pending`,
  `interview_scheduled`, `ready_for_approval`, `approved`, `scheduled`, and
  `completed`. Marriage has additional interview and banns stages.
- Payment is recorded manually by staff; no amount is stored. Recording
  payment does not automatically approve a request. Staff must use the
  allowed status transitions and meet the relevant prerequisite checks.
- Official register entries are separate from online intake. A submitted
  application is not automatically an official sacrament record.

## Adviser walkthrough

### 1. Public experience

1. Open `/public/index.php` and point out the public announcements, office
   details, certificate requests, and sacrament applications.
2. Open `/public/certificate-request.php`, then choose a certificate type.
   The actual Baptism form is `/public/certificate-requests/baptismal.php`.
   Show its displayed requirements before submission.
3. Open `/public/sacrament-application.php` and follow a link to one of the
   forms under `/public/sacrament-applications/`. Explain that public
   submissions are stored as intake applications for staff review, separate
   from the official registers.

### 2. Staff review and person matching

1. Sign in at `/admin/login.php`.
2. Open **Requests → Online Submissions**
   (`/admin/requests/online-requests.php`) or **Requests → Sacrament
   Applications** (`/admin/requests/applications-queue.php`).
3. Find `SPPO-APP-2026-CNFDEFENSABCDEFG` and use the person matcher to search for **Emma Demo
   Reyes**, birth date `2012-02-11`; select the matching master person. For
   `SPPO-APP-2026-MARRDEFENSABCDEF`, match groom **Marco Demo Reyes** and bride **Nina Demo
   Cruz** separately. Explain that matching is a staff confirmation, not an
   automatic name-only merge.
4. Review the application’s existing prerequisites and status controls. For
   Confirmation and First Communion, the approved/scheduled examples can be
   used to demonstrate the register prefill after matching.

### 3. Certificate review, requirements, and pickup

1. Open **Requests → Certificate Requests**
   (`/admin/requests/queue.php`) and locate
   `SPPO-2026-CERTDEFENSABCDEG` or `SPPO-2026-CERTDEFENSABCDEJ`.
2. Open the request detail page. The certificate requirements checklist is
   displayed there (`/admin/requests/certificate-detail.php`); mark document
   states only for the fictional sample. The general checklist table supports
   application rows too, but sacrament application screens currently use
   their own prerequisite fields rather than this generic checklist UI.
3. Use `SPPO-2026-CERTDEFENSABCDEJ` to show the ready-for-pickup generation
   history. Open
   **Certificates → Print / Pickup History**
   (`/admin/certificates/history.php`) and record a fictional claimant plus ID
   verification if you want to demonstrate release. The released sample
   `SPPO-2026-CERTDEFENSABCDEK` is available for viewing without changing it.
4. Certificate preview/generation is at
   `/admin/certificates/generate.php`. Staff must complete the request review
   and approval prerequisites before a certificate is eligible for
   generation.

### 4. One-click register prefill

1. Use the row-level person matcher to link `SPPO-APP-2026-CNFDEFENSABCDEFG` to **Emma Demo
   Reyes**, then use the Confirmation register page
   `/admin/records/confirmation.php?from_application=<application-id>`.
2. Link `SPPO-APP-2026-FCDEFENSABCDEFGH` to **Sofia Demo Garcia**, then open
   `/admin/records/first-communion.php?from_application=<application-id>`.
3. Confirm the application is approved or scheduled before using the prefill
   URL. Verify the copied identity details, check the physical book/page/line
   reference, then save only if performing a fresh encoding demonstration.

The placeholder `<application-id>` is the numeric ID shown in the staff
application queue or database; it is not the tracking number. Do not create a
second official record merely to show the prefilled form.

### 5. Master profile and linked records

1. Open **Records → Persons**
   (`/admin/system/persons.php`) and find **Eva Demo Santos**, born
   `2014-06-15`.
2. Open her profile at `/admin/system/person-profile.php?id=<person-id>`.
   Show the linked Baptism, First Communion, and Confirmation entries.
3. Point out the available action for a missing sacrament and how it can start
   an application/encoding flow with the existing person identity attached.

### 6. Schedule, announcements, and audit history

1. Open **Schedule → Calendar** (`/admin/schedule/calendar.php`) to show the
   six upcoming demo events.
2. Open **Schedule → Announcements**
   (`/admin/schedule/announcements.php`) and point out the three published
   demo notices. Their titles start with `DEMO:`.
3. Open **System → Audit Log** (`/admin/system/audit-log.php`). Identify the
   explicitly labeled seed samples, then perform a person match or status
   change and show the new real audit entry under the signed-in staff user.

## Notes and limitations

- The seed does not create staff users or passwords; create those locally
  using the CLI account tool above.
- The generic `workflow_requirements` UI is currently part of certificate
  request detail. Application-specific document review uses the existing
  sacrament-specific fields and status workflow.
- Do not treat seeded “released” certificate or sample audit rows as evidence
  of real parish transactions.
- The seed has not been applied to any database by this task. Import it only
  after confirming that the selected database is a disposable local/demo
  database and that its schema is current.
