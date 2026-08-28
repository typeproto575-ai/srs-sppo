# PROJECT_CONTEXT.md — SPPO-IS

Read this before generating any code for this project. It captures decisions made during design that aren't obvious from the code alone. If a request conflicts with something here, flag it rather than silently overriding it.

---

## What this is

A web-based **Schedule and Record System** for **Saint Patrick's Parish Office**, Dingalan, Aurora (Philippines) — a capstone project. It digitizes sacrament records and certificate requests for a small Catholic parish currently run entirely on handwritten logbooks and a single secretary.

**Stack:** Plain PHP (no framework), MySQL/MariaDB, XAMPP. No Composer packages assumed unless explicitly added.

---

## Non-negotiable rules

1. **No monetary/fee/currency data anywhere.** No fields, no tables, no logic touching payment. Certificate fees are paid in person at pickup — completely outside this system's scope.
2. **Identity verification happens in person at pickup, not online.** No public form collects a photo/scan of a valid ID. The parishioner brings a valid ID to the office when picking up the certificate. This keeps sensitive ID data out of the system entirely, simplifying Data Privacy Act (RA 10173) compliance.
3. **Eligibility/prerequisite checks flag, never auto-block.** Age ranges (First Communion, Confirmation), prior-sacrament requirements (e.g. Baptism before Confirmation), etc. should set a flag for secretary manual review — never silently reject a submission. See `is_outside_eligibility()` in `includes/functions.php`.
4. **No parishioner accounts.** Parishioners never log in. They submit a request/application and get a tracking number + email; they check status by tracking number + email on `public/track-request.php`. Only the Secretary has a login account (`users` table, `role` = `secretary`).
5. **No priest system user.** The parish priest is not a system user. The priest's canonical clearance for Marriage (banns, pre-Cana, canonical interview) is handled as data the Secretary records after confirming with the priest outside the system, not as a login-based approval step.
6. **Certificates are picked up in person, printed.** No digital download of the final certificate. Payment happens at the same pickup visit.
7. **The `legitimacy` column in `baptismal_records` is internal-only.** Never expose it in any public query, certificate output, or report. It exists only because the physical logbook records it and nothing should be lost when digitizing.
8. **Physical logbooks remain the canonical record.** The digital system is an index for fast retrieval, not a replacement — this framing matters for the capstone paper, and functionally means the system should always allow linking back to `book_no` / `page_no` / `line_no`.

---

## Two distinct request types — don't merge these

- **Certificate Request** — requesting proof of a sacrament that already happened (e.g. "give me a copy of my Baptismal Certificate"). Tables: `certificate_requests` + one extension table per sacrament (`baptismal_certificate_requests`, `confirmation_certificate_requests`, `first_communion_certificate_requests`, `marriage_certificate_requests`).
- **Sacrament Application** — applying to *receive* a sacrament (e.g. having an infant baptized, enrolling a child for Confirmation, applying to get married here). Exists for **Baptism, Confirmation, First Communion, and Marriage**. Tables: `sacrament_applications` + `baptismal_applications` / `confirmation_applications` / `first_communion_applications` / `marriage_applications`.

Every sacrament type follows a **shared table + extension table** pattern. When adding a new sacrament-specific field, it goes in the extension table, never bloats the shared table.

---

## Per-sacrament specifics

**Baptismal** — no prerequisites. Certificate request is fully built (`public/certificate-requests/baptismal.php`) and remains the reference pattern for validation style, DB insert pattern, tracking number generation, and page structure. A Baptismal **Application** (`public/sacrament-applications/baptismal.php`) also exists — parents/guardians applying to have an infant or child baptized. No age-eligibility flag (no fixed age range for Baptism in this parish's practice), but it does collect parent/guardian + godparent info and a preferred date.

**Confirmation** — prerequisite: must be baptized (flag if not confirmed). Age-eligibility flagged via `sacrament_eligibility_settings` (currently placeholder values 12–18, **not yet confirmed with the actual priest** — don't treat these as final).

**First Communion** — prerequisite: must be baptized. Tracked as a one-time received/not-received flag on the record, **not** per-Mass attendance. Age-eligibility placeholder currently 7–12, also unconfirmed.

**Marriage** — the most complex. Requires: canonical interview, banns published on three separate dates (`banns_date_1/2/3`), pre-Cana/marriage seminar completion, and Baptismal + Confirmation certificate status for both parties. The Secretary records all canonical clearance data after confirming with the priest outside the system. The Secretary gives final approval on marriage applications. "For marriage purposes" certificates are typically valid 6 months in real parish practice — not yet enforced in the schema.

---

## Roles: Secretary Only

- **Secretary**: full admin access — all requests, all records, certificate generation, schedule, reports, system tools, and marriage approval. The Secretary is the sole system user with a login account.
- There is no priest login. The priest confirms canonical clearance verbally/in person; the Secretary records it in the system.
- Auth guard pattern: `require_role('secretary')` from `includes/auth.php`.

---

## Conventions already in use — follow these

- **File pattern for public forms**: validate required fields server-side → wrap insert in a PDO transaction (insert into shared table, get `lastInsertId()`, insert into extension table) → `log_activity()` to `audit_logs` → show tracking number on success, re-render form with errors on failure. Copy this from `baptismal.php`.
- **Tracking numbers**: `generate_tracking_number($pdo, $table)` → format `SPPO-YYYY-NNNNNN`.
- **Escaping output**: always use the `e()` helper, never raw `echo` of user input.
- **Admin pages**: require the correct role at the very top, before any output.
- **Styling**: `assets/css/style.css` for public pages, `assets/css/admin.css` for the dashboard layout. Keep new pages consistent with the existing class names (`.btn`, `.alert`, `fieldset`/`legend` form structure, `.admin-layout`/`.admin-sidebar`/`.admin-content`) rather than introducing a new system.
- **Booking conflicts**: `schedule_events` is the source of truth for the calendar. Preferred-date fields on applications (e.g. `preferred_wedding_date`) are checked against it, but conflict resolution is manual (secretary confirms) — not an automated reservation/locking system.
- **Marriage clearance**: pre-Cana, canonical interview, and banns dates are recorded by the Secretary on the Marriage detail page (`admin/requests/marriage-detail.php`) after confirming with the priest outside the system. They are NOT self-reported by the applicant on the public form.

---

## Current build status

**Done:** DB schema, config/includes scaffolding, public landing page with FullCalendar.js calendar, request-type selector, all four certificate request forms (Baptismal, Confirmation, First Communion, Marriage), all four sacrament application forms, staff login, secretary dashboard, full admin queue/records/certificates/schedule/reports/system pages, Marriage clearance detail page, walk-in encoding, audit logging, backup/export.

**Removed per updated design doc:** Death/Burial (all forms, records, tables, and references), priest as system user (all priest pages, role, and references), online ID collection (all file upload handling removed from public forms).

**Not yet implemented:** actual email sending (every form has a `// TODO: send confirmation email` marker where SMTP config would go — no mail server is configured yet). CSRF token protection across forms. Real certificate HTML/CSS templates (current preview is a raw database dump).

**Known open questions:** real First Communion/Confirmation age ranges (to be confirmed with the actual priest).
