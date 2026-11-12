# SRS-SPPO Local V2 Demo and Walkthrough

This guide covers the unified V2 schema and the current XAMPP workflow.

## Safety and setup

1. Use a disposable local/demo database. Do not import a schema or demo seed
   into the hosted parish database or any database containing data you need.
2. The current schema is
   [`database/v2/schema.sql`](../database/v2/schema.sql). For a clean local
   demonstration database, import that schema through phpMyAdmin, then import
   [`database/v2/seed_demo.sql`](../database/v2/seed_demo.sql).
3. The V2 demo seed contains fictional records and a local-only demo account
   (`secretary` / `demo1234`). Use it only on a disposable local database.
   Never use that account on the hosted site.
4. Optional V2 seeds add a larger fictional register dataset or extra
   application/request/calendar examples; see
   [`database/v2/seed_many_demo.sql`](../database/v2/seed_many_demo.sql) and
   [`database/v2/seed_demo_workflows.sql`](../database/v2/seed_demo_workflows.sql).
   Do not load optional seeds into a live parish database.
5. Sign in at `/admin/login.php`. Open the public portal at `/public/index.php`.

To run database integration tests with XAMPP, set the test database variables
to a **dedicated disposable database name** before running the setup script.
The setup script drops and recreates that named database using the V2 schema:

```powershell
$env:SRS_TEST_DB_NAME = 'srs_sppo_test'
$env:SRS_TEST_DB_DSN = 'mysql:host=127.0.0.1;dbname=srs_sppo_test;charset=utf8mb4'
$env:SRS_TEST_DB_HOST = '127.0.0.1'
$env:SRS_TEST_DB_USER = 'root'
$env:SRS_TEST_DB_PASS = ''
composer test:setup-db
composer test:integration
```

Do not set `SRS_TEST_DB_NAME` to the application's database. The test runner
also verifies that the DSN selects exactly the configured test database.

All seed identities and public sample entries are fictional. Do not load demo
data into a live parish database.

## V2 sample request numbers

| Reference number | Sample |
|---|---|
| `SA-2026-0001` | New Baptism pre-application |
| `SA-2026-0002` | Marriage pre-application |
| `SA-2026-0003` | First Communion pre-application |
| `CR-2026-0001` | Released Baptism certificate request |
| `CR-2026-0002` | Ready for pickup |
| `CR-2026-0003` | Released First Communion request |
| `CR-2026-0004` | New Confirmation request |
| `CR-2026-0005` | Waiting for priest signature |
| `CR-2026-0006` | Rejected: record belongs to another parish |

## V2 status terminology

- Certificate requests use `new`, `matched`, `for_signature`, `ready`,
  `released`, or `rejected`.
- Sacrament applications use `new`, `reviewing`, `requirements_pending`,
  `approved`, `scheduled`, `completed`, or `rejected`. Marriage interview,
  banns, and document checks are recorded in the application details and
  checklist, not as separate status values.
- Payment is made outside the system and recorded by staff only after receipt.
  The system stores no amount. Payment does not itself approve a request.
- Online applications are intake records, not official sacrament-register
  entries. Staff review and record the eventual sacrament separately.

## Adviser walkthrough

### 1. Public experience

1. Open `/public/index.php` and point out the public announcements, office
   details, certificate requests, and sacrament pre-applications.
2. Open `/public/certificate-request.php?type=baptism` to see the unified
   certificate form with Baptism selected. Other supported types are
   `first_communion`, `confirmation`, and `marriage`. Show its displayed
   requirements before submission.
3. Open one of the forms under `/public/sacrament-applications/`. Explain that public
   submissions are stored as pre-application intake records for staff review, separate
   from the official registers.

### 2. Staff review and person matching

1. Sign in at `/admin/login.php`.
2. Open **Age Eligibility Review** from the dashboard or staff navigation.
   Show the separate First Communion and Confirmation lists, and explain that
   they are advisory minimum-age reminders for secretary review—not approval
   or confirmation that someone is ready to receive the sacrament. Filter by a
   preset age group or choose a custom minimum and maximum; these filters only
   narrow the displayed list.
3. Open **Requests → Sacrament Pre-Applications**
   (`/admin/requests/applications-queue.php`).
4. Open `SA-2026-0002` to review the marriage application and its two linked
   people. Explain that person linking is a staff confirmation, not an
   automatic name-only merge.
5. Open `SA-2026-0001` to demonstrate a new Baptism intake, then review the
   application details, requirements checklist, and status controls.

### 3. Certificate review, requirements, and pickup

1. Open **Requests → Certificate Requests**
   (`/admin/requests/queue.php`) and locate `CR-2026-0004` or
   `CR-2026-0002`. This queue tracks certificate-copy requests; **Records**
   contains completed sacrament entries from the parish register.
2. Open the request details. Review the submitted information and match the
   exact parish record. Confirm the matched record against the physical book
   with **Verify book entry** before printing.
3. For an online request, record payment only after it has actually been
   received. Use **Print certificate** to preview and print. After printing,
   mark the request **For Signature**, then **Ready** after the priest has
   signed it.
4. At pickup, verify the claimant's ID and record the release. A
   representative must also provide the signed authorization letter. The
   sample `CR-2026-0001` is already released and should only be viewed.

### 4. One-click register prefill

1. Use an eligible application in the queue, link it to the correct master
   person, and select the register-encoding action.
2. Verify the prefilled identity and sacrament details against the source
   information and physical book, then save only when demonstrating a new
   official register entry.

The placeholder `<application-id>` is the numeric ID shown in the staff
application queue or database; it is not the reference number. Do not create a
second official record merely to show the prefilled form.

From the dashboard, search the master person index by name or birth year and
open the matching profile. If a name search does not match, try fewer name
parts; use **Records** to search the register entries themselves. For an exact
register lookup, search `b12 p1 l4` (or `book 12 page 1 line 4`); `b12`, `p1`,
and `l4` can also search one register field at a time. Ordinary name and record
ID searches remain available. On an
in-parish entry under **Sacramental history**, choose **Start certificate
request**. The request form is prefilled from that register entry. Complete
the requester and purpose details, submit, and record payment only when it is
actually received. The request appears in the certificate queue with an audit
entry. Verify the physical register, print from request details, mark it for
the priest's signature, then mark it ready and record release after pickup.

## Large register/search dataset

The optional V2 dataset in `database/v2/seed_many_demo.sql` provides a larger
group of fictional parishioners for person search, profile lookup, and register
browsing. It adds 100 profiles, 100 Baptisms, 20 First Communions, 40
Confirmations, and 10 Marriages, with linked person and prerequisite records.
Sample email addresses use `@demo.test`; the SQL does not create certificate
requests, pre-applications, staff accounts, or audit history. Import it only into
a disposable V2 demo database, never a live parish database.

The optional `database/v2/seed_demo_workflows.sql` seed adds eight fictional
certificate requests, seven sacrament pre-applications, six future calendar events,
and four published announcements. Names and places are original whimsical
storybook inventions; every public entry is labeled as demo/fictional, and no
staff activity, payment, notification, or official sacramental record is
fabricated by this seed. It is repeatable and inserts no duplicates.

### 5. Master profile and linked records

1. Open a person profile such as
   `/admin/system/person-profile.php?id=<person-id>` from the person search.
2. Show the profile-level Observanda field and save a person-wide note directly
   from the profile. Changes are recorded in Correction History.
3. Show the linked sacramental history and register references. Each register
   card also displays its own Observanda, kept separate from the profile note.
4. Explain that matching is staff-confirmed and that the physical register
   remains the authority for historical details.

### 6. Schedule, announcements, and audit history

1. Open **Schedule → Calendar** (`/admin/schedule/calendar.php`) to review
   demo events and show that overlaps are flagged for staff review.
2. Open **Schedule → Announcements**
   (`/admin/schedule/announcements.php`) to manage public notices.
3. Open **Reports** or **System → Audit Log**
   (`/admin/reports/index.php`, `/admin/system/audit-log.php`) to review
   period summaries and recorded staff actions.

## Notes and limitations

- The base V2 seed provides a demo secretary account only for local testing.
  Do not use it on the hosted site.
- Certificate lifecycle data lives on `certificate_requests`; V2 does not use
  a separate certificate-generation log or a `workflow_requirements` table.
- Do not treat seeded “released” certificate or sample audit rows as evidence
  of real parish transactions.
- Import demo data only after confirming that the selected database is
  disposable and its schema is current.
