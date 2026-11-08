# PROJECT_CONTEXT.md — SRS-SPPO

Read this before generating any code. It is the current operating model of the running PHP + MySQL app, not a history of every design pass. If a request conflicts with something here, flag it rather than silently overriding it.

Older markdown in `docs/`, `README.md`, `TASK_PROGRESS.md`, and `memory.md` may be stale. Prefer this file plus the PHP pages and `database/v2/schema.sql`.

---

## What this is

A web-based **Schedule and Record System** for **Saint Patrick's Parish Office**, Dingalan, Aurora (Philippines) — a capstone project.

It is a **digital index and office workflow** for sacramental records that still live in physical logbooks. The database is a single MySQL database with a centralized `persons` master identity table; Baptism, Confirmation, First Communion, and Marriage tables store event/register-specific details and link to that shared identity. They are not separate databases. It is not a parishioner account portal, not a payment system, and not a replacement for the handwritten registers.

**Stack:** Plain PHP (no framework), MySQL/MariaDB, XAMPP. No Composer packages unless explicitly added.

**One-sentence product rule:** A parishioner may submit an **online pre-application** (no account). The secretary may also encode a **walk-in**. Nothing becomes an official register entry or a released certificate until staff review, match, print, and release **in person**.

---

## Non-negotiable rules

1. **No monetary/fee/currency data anywhere.** The system never takes money or stores amounts. Payment is made outside the system and recorded only as a manual status (`paid_at` + `paid_by`). The secretary may match and pre-verify an online certificate request before payment; the online processing gate and certificate release require payment to be recorded. Legacy `awaiting_payment` requests remain supported. Sacrament applications in `awaiting_payment` cannot proceed until payment is recorded.
2. **Identity verification is in person.** No public form collects a photo or scan of a valid ID. The claimant presents ID at the office. This keeps ID images out of the database (RA 10173).
3. **Eligibility/prerequisite checks flag, never auto-block.** Age ranges and prior-sacrament checks warn the secretary. They must not silently reject a submission. See `is_outside_eligibility()` in `includes/functions.php`.
4. **No parishioner accounts.** Visitors never log in. The parish office contacts them using the contact details they provided.
5. **No priest login.** Canonical clearance (banns, pre-Cana, interview) is data the secretary records after confirming with the priest outside the system. There is no `/priest/` app. (`audit_logs.role_snapshot` may still contain a leftover `'priest'` enum value from older rows; do not revive a priest user role.)
6. **Certificates are printed and picked up in person.** No public download of the finished certificate, and no public certificate-verification page: a certificate carries no QR code or verification token.
7. **`legitimacy` on `sacrament_records` is internal-only.** Never put it on public queries, certificates, or reports. New encodes store `NULL`.
8. **Physical logbooks remain canonical.** Digital rows must keep `book_no` / `page_no` / `line_no` on Baptism, Confirmation, First Communion, and Marriage.
9. **An online submission is not an official record.** Public forms write `submission_channel = 'online'`. Walk-in encoders write `'onsite'`. The secretary still verifies requirements and identity before processing or release.
10. **Staff register entries use the master person index.** When encoding a new sacramental register entry, staff search for and select an existing person when one matches. If there is no match, the save creates a linked person profile. Matching by name alone never merges records. Historical rows may remain unlinked until reviewed. Selecting a known profile fills missing biographical details while keeping register-specific source data in its sacrament table.

---

## Operating model (current)

```
Parishioner (optional)          Parish office
  online pre-application   OR   walk-in encoding
           \                   /
            reference number
                    |
            secretary review
            (requirements, register match)
                     |
             payment recorded by hand (manual; no amount)
                     |
            print certificate at office
                    |
            in-person ID check + pickup
```

- **Certificate request** = proof of a sacrament that already happened.
- **Sacrament pre-application** = a preliminary request to begin preparation to receive a sacrament; it is not approval or an official register entry.
- Requirements are shown in person at the office only. Gmail is the outgoing notification relay (status emails) — staff never ask for documents by email or Messenger, and the system never receives or stores documents. Email contact does not by itself create a system row; the secretary encodes or reviews the transaction in the app.
- Contact on forms is **email-first**, with a required contact number on the current public pre-application and certificate-request forms. Do not remove the required contact number or add ID-upload fields unless the parish explicitly changes this.

There was a Phase 4 “office-only public site” (legacy helper: `includes/public-office-only.php`). **That is not the live product.** Live public certificate-request and pre-application forms accept POST. Do not redirect those forms through `public-office-only.php`.

---

## Two distinct request types — do not merge them

- **Certificate Request** — “give me a copy of my Baptismal / Confirmation / First Communion / Marriage certificate.”
  - Table: `certificate_requests`, shared by all four sacrament types. Request details are held in `subject_details` JSON.
- **Sacrament Application** — “we want this child baptized” / “we want to marry here.”
  - Live forms (public + walk-in): **all four sacraments (Baptism, Confirmation, First Communion, Marriage)**.
  - Table: `sacrament_applications`, shared by all four sacrament types. Sacrament-specific answers and requirement checklists are stored in `details` and `requirements` JSON.
  - Public pre-application forms: `public/sacrament-applications/baptismal.php`, `confirmation.php`, `first-communion.php`, `marriage.php`. Walk-in encoding: `admin/requests/walk-in-application.php` encodes all four sacraments.

The register uses the shared `sacrament_records` table, with `sacrament_type` distinguishing the four sacraments. Do not add legacy per-sacrament record or request tables.

---

## Per-sacrament specifics

**Baptismal** — no age prerequisite. Public certificate requests use the unified form at `public/certificate-request.php` and are stored in the shared v2 `certificate_requests` table. Public pre-application (`public/sacrament-applications/baptismal.php`) collects parent/guardian, godparents, preferred date. Baptismal certificates can print onto parish **blank stock** via `admin/certificates/baptismal-certificate.php` + coordinates in `admin/certificates/print-coordinates.php`.

**Confirmation** — certificate request and online pre-application (`public/sacrament-applications/confirmation.php`) plus walk-in encoding exist. Receiving the sacrament is also encoded as a **register row**. Prerequisite: baptized (flag, do not auto-reject). Age range in `settings` is a **placeholder (12–18)** until the priest confirms it.

**First Communion** — same pattern as Confirmation: online pre-application (`public/sacrament-applications/first-communion.php`) plus walk-in encoding, and a one-time received record, not per-Mass attendance. Age placeholder **7–12**, unconfirmed.

**Marriage** — most complex. The secretary records canonical preparation and verifies the parties' sacramental documents with the priest. Applicants do not self-certify those steps on the public form. Baptismal and Confirmation certificates requested for marriage are marked “For Marriage Purposes.” The project does not define an expiry period; follow the Parish Office's current instructions.

---

## Roles (staff only)

`users.role` is `secretary`. There is no priest or separate administrator role in login.

- **Secretary** — day-to-day operator: queues, records, certificates, schedule, reports, walk-in encode, own password.
- **Parish Secretary** — the single staff role with access to operational pages and the complete System section, including accounts, backup/export, archive/restore, email delivery log, audit log, retention/purge, and public-page content settings.

Rules already in `admin/system/accounts.php`:

- You cannot deactivate or delete your own signed-in account.
- You cannot remove the last active staff account.
- You cannot remove the last active staff account.
- Prefer deactivate over delete when history still references the user.
- Staff passwords: `validate_password_strength()` — 12+ characters, upper, lower, digit.

Auth: `require_login()` / `require_role()` in `includes/auth.php`. Idle timeout 30 minutes. Periodic re-check of `is_active` and `auth_version`. Login and password-reset are rate-limited.

Password recovery: `admin/forgot-password.php` + `admin/reset-password.php`. Tokens hashed (SHA-256), 30 minutes, one use. Successful reset increments `auth_version` (kills other sessions). Public copy must **not** reveal whether the email exists.

---

## Public pages (current)

| Path | Purpose |
|------|---------|
| `public/index.php` | Home, calendar, announcements |
| `public/how-it-works.php` | Pre-application vs office process |
| `public/requirements.php` | Documentary requirements |
| `public/terms.php` | Terms |
| `public/certificate-request.php` | Hub for four certificate forms |
| `public/certificate-request.php` | Unified online certificate intake for all four sacraments |
| `public/sacrament-applications/baptismal.php` | Online Baptism pre-application |
| `public/sacrament-applications/marriage.php` | Online Marriage pre-application |
| `public/sacrament-applications/confirmation.php` | Online Confirmation pre-application |
| `public/sacrament-applications/first-communion.php` | Online First Communion pre-application |

Public navigation is rendered by `includes/header.php`; do not add links to pages that are not present in the repository.

---

## Staff pages (current)

Admin layout: `includes/admin-header.php` / `admin-footer.php`.

**Requests:** online submissions, certificate queue + detail pages, pre-application queue + detail pages, walk-in request, walk-in pre-application.

**Records:** all-records index, baptismal, confirmation, first communion, marriage, person mapper, person profile, record view.

**Certificates:** generate/preview and print/pickup history.

**Schedule:** calendar, booking conflicts, announcements.

**Reports:** `admin/reports/index.php` (monthly/yearly URLs are redirects).

**System:** accounts, backup/export, archive/restore, email delivery log, audit log.

Also: dashboard, age-eligibility review, notifications, login/logout, forgot/reset password.

Home page text is fixed in `public/index.php`; changing it requires a code edit.

---

## Persons and linking

`persons` is the internal master identity index, not a public account. All four sacrament types are stored in `sacrament_records` and link to `persons` through `person_id`; marriage uses `spouse_person_id` for the other party. Record forms search for existing profiles and create a linked profile when no match is selected. Historical records may still have no person link; the secretary can review and map them through the current person-mapping/profile tools.

Identity and family details belong in `persons`; sacrament-specific register data belongs in `sacrament_records.details` and `sacrament_participants`. Do not reintroduce legacy per-sacrament tables or columns such as `husband_person_id` / `wife_person_id`.

When a register row is linked, its birth date fills the person profile only if that profile has no birth date yet. Confirmation birth date and parent-name fields are captured from the source register. Baptismal certificate lookup uses the linked person identity and the register entry.

---

## Certificate workflow

**Requests → Certificate Requests** is the queue for copies of existing sacramental records; **Records** is the index of events already entered in the parish registers. A secretary can start an in-person certificate request from the dashboard, records index, request queue, or a verified record's profile. A record-based request is unavailable until its in-parish book entry is verified against the physical register.

1. Request created online or at the office in `certificate_requests`.
2. The secretary reviews the request, checks requirements in person, and records the outcome; the system stores no uploaded documents.
3. Match the request to a `sacrament_records` row by setting `certificate_requests.person_id` and `record_id`. If an online search does not find a match, the applicant can ask the secretary to search the physical register; an online miss alone does not establish that the sacrament did not occur.
4. Record payment manually in `paid_at` / `paid_by` before processing. No monetary amount is stored.
5. Print, sign, and release using `certificate_requests.printed_at`, `signed_at`, and `released_at` plus the claimant/ID-verification fields. Reprints and other staff actions are audited in `audit_logs`; there is no `certificate_generation_log` table in the current schema.

Corrections follow Parish Office procedures. Staff record edits append an audit event containing before-and-after snapshots of changed record, person, and participant data. The register row itself is updated in place; the audit event preserves the prior values. There is no public correction form or self-service correction workflow.

---

## Conventions — follow these

- **Public certificate intake:** `public/certificate-request.php` validates the request and inserts one row into the v2 `certificate_requests` table. The legacy per-sacrament URLs redirect to this form with a supported type preselected.
- **Reference numbers:** `generate_tracking_number($pdo, $table)` → new certificate requests use `SPPO-YYYY-` plus a random 16-character token; sacrament pre-applications use `SPPO-APP-YYYY-` plus the token so records can be distinguished. Tokens use an ambiguity-reduced alphabet. Previously issued sequential numbers remain valid.
- **Success panel:** `includes/submission-success.php`. Print there = confirmation page only, never a certificate.
- **CSRF:** every POST uses `csrf_field()` / `csrf_verify()`.
- **SQL:** PDO native prepared statements. Output: `e()`.
- **Admin pages:** `require_role(...)` before any output.
- **CSS:** `assets/css/style.css` public, `assets/css/admin.css` staff. Reuse `.btn`, `.alert`, `fieldset`/`legend`, `.admin-layout`, `.hub-card`. Do not start a new design system.
- **Record lists:** `includes/record-filters.php` (`rf_parse`, `rf_render_bar`, `rf_render_pagination`). Copy `$rfConfig` from `admin/records/baptismal.php`. Column names in config are from the page, never user input.
- **Logbook numbering:** `register_next_logbook_numbers()` — line 1..10 per page, page 1..500 per book, then next Roman book (`I`, `II`, …). Suggestions only; fields stay editable. Mid-register insert: “Shift later entries” uses `register_shift_entry_numbers()` inside the same transaction; “Save as entered” may duplicate. Do not UNIQUE `entry_no` without rewriting the shift to descending order.
- **Calendar:** `schedule_events` is source of truth. Preferred dates on pre-applications are checked for conflicts; the secretary resolves them manually (no automatic lock).
- **Archive:** registers have `is_archived` / `archived_at` / `archived_by` / `archive_reason`. Lists exclude archived rows. Archive history instead of hard-deleting it.
- **Rate limits (public):** up to 5 successful submissions per email / 24h and 20 per IP / 24h. Hashed email identifiers and client IPs are recorded in `audit_logs`; no separate rate-limit table is required. Submission and lookup checks fail closed if audit logging is unavailable. This is spam control, not eligibility. `client_ip()` uses `REMOTE_ADDR` only.
- **Mail:** `includes/mail.php` + `config/mail.php` uses PHPMailer SMTP. Failed send must never block a form. **`MAIL_ENABLED` defaults to `false`** until explicitly set in server environment or ignored `config/local.php`. SMTP credentials stay out of tracked files. Set sender, SMTP settings, and HTTPS `MAIL_SITE_URL` when enabling. Delivery attempts log to `email_delivery_log`.

---

## Security and deployment notes

- DB credentials: `SRS_DB_HOST` / `SRS_DB_NAME` / `SRS_DB_USER` / `SRS_DB_PASS` with XAMPP fallbacks in `config/database.php`. Connection failures log the real error and show a generic 503.
- HTTPS: `APP_FORCE_HTTPS` in `config/environment.php` is **false** for local XAMPP. Turn on only with a real TLS cert.
- Root `.htaccess` blocks dotfiles, `_*` helpers, `.sql` / `.md` / `.txt` / `.bat`, and common debug script names. `Options -Indexes`. Do not deploy `.git/` inside a public `htdocs` copy.
- The seed data contains no login. Create the first administrator with `scripts/create-admin.php`.
- Backup (`admin/system/backup.php`) dumps **all tables including password hashes**. Store exports offline. Never email the dump or leave it in the web root.
- Certificates carry **no QR code and no verification token**. There is no public certificate-verification page; authenticity is confirmed in person at the counter against the parish register.

---

## Schema source of truth

Canonical create script: `database/v2/schema.sql`. Fresh installs use that, then seed if needed (`database/v2/seed_demo.sql`).

Existing databases can review supported compatibility updates at **System → Database Setup**. Updates run only after an authenticated secretary explicitly submits them; normal page requests never alter the schema. The page handles public page settings (007), certificate request review fields (012), Philippine sacrament application fields (034), data-retention support (038), the certificate-request mailing address (041), Confirmation application baptism details (042), and Marriage application marital status (043), checking existing tables/columns before applying changes. Person-level Observanda is included in the V2 schema; existing V2 databases need backup and review before applying `migrations/047_add_person_profile_observanda.sql`, which is not handled by this page.

Application and database-generated timestamps use Philippine local time (`Asia/Manila`, UTC+08:00).

`migrations/` remains the historical record for older database versions and is not replayed wholesale by the application. Numeric prefixes are not unique (`017_*`, `023_*`, `025_*`, `026_*`, `027_*`, `028_*`, and `038_*` are reused); identify historical scripts by their full filename and inspect the target database before applying any of them.

The canonical schema already includes `certificate_requests.verification_code`. The column is retained for schema continuity with the historical migration `032_add_certificate_verification_code.sql`, but it is no longer populated or used: certificates carry no verification token.

Historical migration files include parallel changes with duplicate numeric prefixes. Do not reorder or rename them in place; preserve them as upgrade history for older installations.

---

## Intentionally not in the product

- Parishioner registration or login
- Online payment processing or fee/amount tracking (payment is made in person / outside the system and recorded only as a manual `paid_at` / `paid_by` status)
- Public upload of IDs or documentary scans
- Public download of the official certificate PDF/image
- Priest portal
- Automatic person linking on encode
- Automatic booking locks

Leftover files that are **not** the live path: `includes/public-office-only.php`, `admin/requests/certificate-detail.tmp`, `cookie_test_online.txt`. Do not wire new features through them.

---

## Known open questions

- Confirm First Communion and Confirmation age ranges with the parish priest (placeholders 7–12 and 12–18).
- Confirm any separate recency requirement with the Parish Office or receiving institution; the project does not impose certificate expiry dates.
- Production SMTP settings, `MAIL_SITE_URL`, and HTTPS when leaving localhost.
