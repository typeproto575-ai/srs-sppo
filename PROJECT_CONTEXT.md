# PROJECT_CONTEXT.md — SRS-SPPO

Read this before generating any code. It is the current operating model of the running PHP + MySQL app, not a history of every design pass. If a request conflicts with something here, flag it rather than silently overriding it.

Older markdown in `docs/`, `README.md`, `TASK_PROGRESS.md`, and `memory.md` may be stale. Prefer this file plus the PHP pages and `database/srs_sppo_schema.sql`.

---

## What this is

A web-based **Schedule and Record System** for **Saint Patrick's Parish Office**, Dingalan, Aurora (Philippines) — a capstone project.

It is a **digital index and office workflow** for sacramental records that still live in physical logbooks. It is not a parishioner account portal, not a payment system, and not a replacement for the handwritten registers.

**Stack:** Plain PHP (no framework), MySQL/MariaDB, XAMPP. No Composer packages unless explicitly added.

**One-sentence product rule:** A parishioner may submit an **online pre-application** (no account). The secretary may also encode a **walk-in**. Nothing becomes an official register entry or a released certificate until staff review, match, print, and release **in person**.

---

## Non-negotiable rules

1. **No monetary/fee/currency data anywhere.** No fields, tables, or logic for payment. Fees are paid in person at pickup, outside this system.
2. **Identity verification is in person.** No public form collects a photo or scan of a valid ID. The claimant presents ID at the office. This keeps ID images out of the database (RA 10173).
3. **Eligibility/prerequisite checks flag, never auto-block.** Age ranges and prior-sacrament checks warn the secretary. They must not silently reject a submission. See `is_outside_eligibility()` in `includes/functions.php`.
4. **No parishioner accounts.** Visitors never log in. They receive a tracking number and use that **plus the recorded email** on `public/track-request.php`.
5. **No priest login.** Canonical clearance (banns, pre-Cana, interview) is data the secretary records after confirming with the priest outside the system. There is no `/priest/` app. (`audit_logs.role_snapshot` may still contain a leftover `'priest'` enum value from older rows; do not revive a priest user role.)
6. **Certificates are printed and picked up in person.** No public download of the finished certificate. A public **verification** page (`public/verify.php`) may confirm that a printed token/QR is known to the parish; that is not a download of the certificate.
7. **`legitimacy` on `baptismal_records` is internal-only.** Never put it on public queries, certificates, or reports. New encodes store `NULL`; legacy rows may still have a value, editable only in the baptismal record form.
8. **Physical logbooks remain canonical.** Digital rows must keep `book_no` / `page_no` / `line_no` on Baptism, Confirmation, First Communion, and Marriage. Death/Burial is the exception (status table only; no register numbering).
9. **An online submission is not an official record.** Public forms write `submission_channel = 'online'`. Walk-in encoders write `'onsite'`. The secretary still verifies requirements and identity before processing or release.

---

## Operating model (current)

```
Parishioner (optional)          Parish office
  online pre-application   OR   walk-in encoding
           \                   /
            tracking number
                    |
            secretary review
            (requirements, register match)
                    |
            print certificate at office
                    |
            in-person ID check + pickup
```

- **Certificate request** = proof of a sacrament that already happened.
- **Sacrament application** = applying to *receive* a sacrament (Baptism or Marriage only).
- Requirements may be brought to the office or sent to the parish Gmail as an **office procedure**. Sending email does not by itself create a system row; the secretary encodes or reviews the transaction in the app.
- Contact on forms is **email-first**. Do not reintroduce required phone or ID-upload fields unless the parish explicitly changes this.

There was a Phase 4 “office-only public site” (legacy helper: `includes/public-office-only.php`). **That is not the live product.** Live public certificate and application forms accept POST. Do not redirect those forms through `public-office-only.php`.

---

## Two distinct request types — do not merge them

- **Certificate Request** — “give me a copy of my Baptismal / Confirmation / First Communion / Marriage certificate.”
  - Tables: `certificate_requests` + one extension table (`baptismal_certificate_requests`, `confirmation_certificate_requests`, `first_communion_certificate_requests`, `marriage_certificate_requests`).
- **Sacrament Application** — “we want this child baptized” / “we want to marry here.”
  - Live forms (public + walk-in): **Baptism and Marriage only**.
  - Tables: `sacrament_applications` + `baptismal_applications` / `marriage_applications`.
  - `confirmation_applications` and `first_communion_applications` are **legacy**. Do not add new public or walk-in forms for them. Confirmation and First Communion are recorded on the register as received records, not as public “apply to receive” flows.

Every sacrament type uses **shared table + extension table**. New sacrament-specific fields go in the extension table.

---

## Per-sacrament specifics

**Baptismal** — no age prerequisite. Public certificate form (`public/certificate-requests/baptismal.php`) is the reference pattern for validation, transaction insert, tracking numbers, rate limits, and success panel. Public application (`public/sacrament-applications/baptismal.php`) collects parent/guardian, godparents, preferred date. Baptismal certificates can print onto parish **blank stock** via `admin/certificates/baptismal-certificate.php` + coordinates in `admin/certificates/print-coordinates.php`.

**Confirmation** — certificate request exists. Receiving the sacrament is encoded as a **register row**, not an application form. Prerequisite: baptized (flag, do not auto-reject). Age range in `sacrament_eligibility_settings` is a **placeholder (12–18)** until the priest confirms it.

**First Communion** — same pattern as Confirmation. One-time received record, not per-Mass attendance. Age placeholder **7–12**, unconfirmed.

**Marriage** — most complex. Canonical interview, three banns dates (`banns_date_1/2/3`), pre-Cana, baptismal + confirmation certificate status for both parties. The secretary records clearance on `admin/requests/marriage-detail.php` after confirming with the priest **outside** the system. Applicants do not self-certify those steps on the public form. “For marriage purposes” certificates are often valid ~6 months in parish practice — **not enforced in the schema**.

**Death / Burial** — intentionally thin: name, date of death, alive/deceased flag, `person_id`, archive columns. No death certificate issuance, no Liber Defunctorum fields. `admin/records/death-burial.php` still exists but is **not in the admin sidebar**; hitting the old dashboard link can show it as unavailable. Do not rebuild a full death register unless the parish asks.

---

## Roles (staff only)

`users.role` is `secretary` or `parish_admin`. There is no priest role in login.

- **Secretary** — day-to-day operator: queues, records, certificates, schedule, reports, walk-in encode, own password.
- **Parish Administrator** — same operational pages (`require_role('secretary')` also allows `parish_admin`) plus the ability to manage staff accounts and protected account-role changes. Secretaries and Parish Administrators both have access to the System section, including accounts, backup/export, archive/restore, email delivery log, audit log, and public-page content settings.

Rules already in `admin/system/accounts.php`:

- You cannot deactivate or delete your own signed-in account.
- You cannot remove the last active staff account.
- You cannot remove the last active `parish_admin`.
- Only a parish admin (or a one-time first-setup self-promotion when zero admins exist) may assign `parish_admin`.
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
| `public/certificate-requests/*.php` | Online certificate intake |
| `public/sacrament-application.php` | Hub for Baptism + Marriage applications |
| `public/sacrament-applications/baptismal.php` | Online Baptism application |
| `public/sacrament-applications/marriage.php` | Online Marriage application |
| `public/track-request.php` | Status by tracking number + email |
| `public/correction-request.php` | Report an error on an issued certificate |
| `public/verify.php` | Verify a printed certificate token |

Public nav: Home, How It Works, Requirements, Office Services, Track My Request, Request Correction.

---

## Staff pages (current)

Admin layout: `includes/admin-header.php` / `admin-footer.php`.

**Requests:** online submissions, certificate queue + detail pages, applications queue + detail pages, walk-in request, walk-in application.

**Records:** all-records index, baptismal, confirmation, first communion, marriage, person mapper, person profile, record view. (Death/Burial page exists, not in sidebar.)

**Certificates:** generate/preview, print/pickup history, correction list/detail.

**Schedule:** calendar, booking conflicts, announcements.

**Reports:** `admin/reports/index.php` (monthly/yearly URLs are redirects).

**System:** accounts, backup/export, archive/restore, email delivery log, audit log.

Also: dashboard, notifications, login/logout, forgot/reset password. `admin/system/site-settings.php` can edit public home copy (`site_settings`) but is **not linked in the sidebar**.

---

## Persons and linking

`persons` is an **internal identity index**, not a public account.

Register tables have nullable `person_id` (marriage: `husband_person_id` / `wife_person_id`). Encoding a record does **not** auto-create or auto-link a person. The secretary uses `admin/system/person-mapper.php`. Until mapped, the same human can exist as duplicate name strings across tables. Do not assume a connected “person timeline” unless `person_id` is set.

Structured name parts (`child_first_name`, etc.) were added for records; keep `*_full_name` in sync when you change either.

---

## Certificate workflow

1. Request created (online or walk-in).
2. Secretary reviews requirements (`workflow_requirements` checklist is status/notes only — **no file storage**).
3. Match to a register row (`matched_record_id` on the extension table; reverse `certificate_request_id` on the record). Match name mismatch is **warn-only**, never a hard block.
4. Generate/preview in `admin/certificates/generate.php`.
5. Print lifecycle on `certificate_generation_log`: generated → printed → released. Claimant name/relationship/notes and ID-verified flag are recorded at the counter (`migrations/009`, `010`). Regenerating can supersede an older log row.
6. Pickup is in person. Status moves through the certificate enum (includes review, requirements, onsite verification, processing, ready, released, rejected, cancelled).

Corrections: public form writes `correction_requests`; staff process them under Certificates. This is not a public edit of the register.

---

## Conventions — follow these

- **Public form insert:** validate server-side → `public_submission_allowed()` (Marriage: groom **and** bride emails) → one PDO transaction (shared row, `lastInsertId()`, extension rows) → `log_activity()` → `record_public_submission()` before commit → `retry_on_duplicate()` for tracking-number races. Copy `public/certificate-requests/baptismal.php`. Set `submission_channel` to `'online'` on public forms and `'onsite'` on walk-in encoders.
- **Tracking numbers:** `generate_tracking_number($pdo, $table)` → `SPPO-YYYY-NNNNNN` (applications may use a distinct prefix already present in that table).
- **Success panel:** `includes/submission-success.php`. Print there = confirmation page only, never a certificate.
- **CSRF:** every POST uses `csrf_field()` / `csrf_verify()`.
- **SQL:** PDO native prepared statements. Output: `e()`.
- **Admin pages:** `require_role(...)` before any output.
- **CSS:** `assets/css/style.css` public, `assets/css/admin.css` staff. Reuse `.btn`, `.alert`, `fieldset`/`legend`, `.admin-layout`, `.hub-card`. Do not start a new design system.
- **Record lists:** `includes/record-filters.php` (`rf_parse`, `rf_render_bar`, `rf_render_pagination`). Copy `$rfConfig` from `admin/records/baptismal.php`. Column names in config are from the page, never user input.
- **Logbook numbering:** `register_next_logbook_numbers()` — line 1..10 per page, page 1..500 per book, then next Roman book (`I`, `II`, …). Suggestions only; fields stay editable. Mid-register insert: “Shift later entries” uses `register_shift_entry_numbers()` inside the same transaction; “Save as entered” may duplicate. Do not UNIQUE `entry_no` without rewriting the shift to descending order.
- **Calendar:** `schedule_events` is source of truth. Preferred dates on applications are checked for conflicts; the secretary resolves them manually (no automatic lock).
- **Archive:** registers have `is_archived` / `archived_at` / `archived_by` / `archive_reason`. Lists exclude archived rows. Use `admin/system/archive-record.php` rather than hard-deleting history.
- **Rate limits (public):** 3 successful submissions per email / 24h and 20 per IP / 24h (`submission_rate_limits`). Fail **open** if the table is missing. This is spam control, not eligibility. `client_ip()` uses `REMOTE_ADDR` only.
- **Mail:** `includes/mail.php` + `config/mail.php`. Failed send must never block a form. **`MAIL_ENABLED` is currently `false`** (silent no-op). Credentials stay in the server environment / XAMPP `sendmail.ini`, never in the repo. Set `MAIL_FROM_ADDRESS` and `MAIL_SITE_URL` when enabling. Delivery attempts log to `email_delivery_log`.

---

## Security and deployment notes

- DB credentials: `SRS_DB_HOST` / `SRS_DB_NAME` / `SRS_DB_USER` / `SRS_DB_PASS` with XAMPP fallbacks in `config/database.php`. Connection failures log the real error and show a generic 503.
- HTTPS: `APP_FORCE_HTTPS` in `config/environment.php` is **false** for local XAMPP. Turn on only with a real TLS cert.
- Root `.htaccess` blocks dotfiles, `_*` helpers, `.sql` / `.md` / `.txt` / `.bat`, and common debug script names. `Options -Indexes`. Do not deploy `.git/` inside a public `htdocs` copy.
- Demo login in seed data: `secretary` / `SPPOsecretary2026`. Dashboard warns if that password is still in use. Change it before any real parish use.
- Backup (`admin/system/backup.php`) dumps **all tables including password hashes**. Store exports offline. Never email the dump or leave it in the web root.
- Certificate QR currently loads an **external** image URL (`api.qrserver.com`). Treat that as a known dependency, not as storing parish data on that host beyond the verify URL.

---

## Schema source of truth

Canonical create script: `database/srs_sppo_schema.sql`. Fresh installs use that, then seed if needed (`database/seed_demo_data.sql`).

`migrations/` is the historical path for existing databases. Some numbers were reused (`017_*` twice, `025_*` twice) — apply by filename, not by assuming a clean 001–029 sequence. Helper scripts `migrations/_run007.php` and `migrations/_test_suggest.php` are not part of the product UI.

If PHP expects a column that is missing from the CREATE TABLE block (example: `certificate_requests.verification_code` used by generate / history / verify), treat that as a **schema gap**: add a migration and fold it into the canonical schema; do not assume the column exists on every copy.

---

## Intentionally not in the product

- Parishioner registration or login
- Online payment or fee tracking
- Public upload of IDs or documentary scans
- Public download of the official certificate PDF/image
- Priest portal
- New Confirmation / First Communion *application* forms
- Full death register / death certificates
- Automatic person linking on encode
- Automatic booking locks

Leftover files that are **not** the live path: `includes/public-office-only.php`, `admin/requests/certificate-detail.tmp`, `cookie_test_online.txt`. Do not wire new features through them.

---

## Known open questions

- Confirm First Communion and Confirmation age ranges with the parish priest (placeholders 7–12 and 12–18).
- Whether to enforce a 6-month validity window on “for marriage purposes” certificates.
- Whether Death/Burial stays hidden or returns to the sidebar as a status tool only.
- Whether `site-settings.php` should be linked in System nav.
- Production mail relay, `MAIL_SITE_URL`, and HTTPS when leaving localhost.
