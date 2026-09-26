# SRS-SPPO — Demo & Defense Guide

A short, practical guide for demonstrating the **Schedule and Record System of
St. Patrick's Parish Office** and for answering common questions during the
capstone defense. The design authority is **`PROJECT_CONTEXT.md`** at the
project root; read it for the full rationale behind every decision below.

---

## 1. What the system is

SRS-SPPO is a plain-PHP (no framework) parish office tool for one user: the
**Parish Secretary**. It digitises the church's sacramental records (Baptism,
Confirmation, First Communion, Marriage) and runs the office workflow around
them:

1. **Parishioners** submit certificate requests or Baptismal/Marriage
   sacrament applications through public forms and receive a **tracking number**.
2. The **Secretary** processes those in the admin queues, updates statuses,
   encodes walk-in requests, and manages the digital logbooks.
3. The **Secretary** matches each approved request to a digital record and
   prints the certificate at the office (identity is verified **in person** at
   pickup).
4. Public **tracking lookup** lets the parishioner check the status with their
   tracking number + email.
5. **Reports, calendar, audit log, and backup** support the office admin side.

## 2. Scope decisions (and why)

These are *deliberate removals/choices* — good defense talking points, not gaps.

| Decision | Reason |
|---|---|
| **No priest system user / portal** | The parish priest is not a login user. Canonical clearance (banns, pre-Cana, interview) is recorded by the Secretary after confirming with the priest in person. Keeps the model to one trained operator. |
| **Death/Burial = status flag only** | Kept as a simplified alive/deceased status page for the secretary (admin/records/death-burial.php). No certificate issuance, no public form, and none of the full Liber Defunctorum register fields (cause of death, burial place, spouse, etc.) - an intentional, documented scope limitation. |
| **No online ID upload** | Identity is verified in person at pickup. Avoids collecting sensitive ID scans, simplifying **RA 10173 (Data Privacy Act)** compliance. |
| **No payment/fees anywhere** | Fees are paid in person at pickup. No monetary fields or logic exist by design. |
| **No parishioner accounts** | Tracking is via tracking number + email, not a login. Only the Secretary has an account. |
| **Eligibility checks flag, never auto-block** | Age/prerequisite checks set a warning flag for the Secretary's review; they never silently reject a submission. |
| **Email is disabled by default** | `MAIL_ENABLED` is `false` in `config/mail.php`. The workflow remains usable through the tracking number and email lookup, while delivery attempts are logged when configured. |
| **Locally hosted (XAMPP)** | Intended as a demonstration system "as if for a real parish", not yet production-deployed. |

## 3. System map

```
public/                     parishioner side (no login)
  index.php                 landing, calendar, announcements
  certificate-request.php   hub -> 4 certificate request forms
   sacrament-applications/   baptism • marriage (live public flows)
  track-request.php         lookup by tracking number + email
admin/                      Secretary only
  dashboard.php             counts + registry summary
  requests/                 certificate queue, applications queue,
                            walk-in encode, marriage detail
  records/                  digital logbooks per sacrament
  certificates/             generate/preview/print, history
  schedule/                 calendar, booking conflicts, announcements
  reports/                  monthly / yearly summaries
  system/                   backup/export, accounts, audit log
database/srs_sppo_schema.sql full schema
database/seed_demo_data.sql demo dataset (see below)
assets/css + assets/js      plain CSS/JS, responsive to device size
```

## 4. Fresh demo setup (5 minutes)

1. Start Apache + MySQL in XAMPP.
2. Import `database/srs_sppo_schema.sql` (creates `srs_sppo_db`).
3. Import `database/seed_demo_data.sql` (idempotent demo dataset).
4. Open `http://localhost/srs-sppo/admin/login.php`.
5. Log in with **`secretary` / `SPPOsecretary2026`** (change it afterwards).

## 5. Demo walkthrough (≈7 minutes)

A single narrative walkthrough that touches every major feature. With seed data
loaded, follow this order:

**A. Public side (no login) — 1 min**
1. Open `http://localhost/srs-sppo/public/index.php`. Point out the responsive
   header, the calendar, and the announcements.
2. Open any certificate form (e.g. Baptismal) and the **Track My Request**
   page — show the truthful copy ("keep this tracking number…").
3. Optional: submit a real request, note its tracking number, then look it up
   on Track My Request (status `pending`).

**B. Log in — 30 sec**
4. `admin/login.php` → **`secretary` / `SPPOsecretary2026`**.
   Mention the security details: generic failure message, CSRF, session
   regeneration on login.

**C. Dashboard — 30 sec**
5. Highlight the **live counts**: pending certificate requests, new sacrament
   applications, and the **registry summary** (real `COUNT(*)` per logbook).

**D. Process a certificate request — 2 min**
6. **Certificates → Certificate Requests** (queue). Open the pending Baptismal
   request (SPPO-2026-000031) and update its status to `under_review`, then
   `approved`.
7. **Certificates → Generate / Preview**. An approved request is listed. Open
   one → a proper printable **certificate**
   shows (matched record → register data). Click **Print Certificate** and show
   the print-only paper; **Mark as Generated**.
8. Show **Print / Pickup History** now contains the entry.

**E. Application workflow — 1 min**
9. **Requests → Sacrament Applications**. Open the Baptismal application
   (submitted) and move it forward (e.g. `interview_scheduled`).
10. **Requests → Walk-in Request** — encode one request on behalf of a visitor;
    show the tracking number it generates.

**F. Records & Person Mapper — 1 min**
11. **Records → Baptismal** — filter/search the logbook, view the encode form.
12. **Records → Person Mapper** — show unmatched records and the assign
    existing/new person flow.

**G. Schedule & Reports & System — 1 min**
13. **Schedule → Calendar** (FullCalendar with events), **Booking Conflicts**.
14. **Reports → Monthly/Yearly** — summaries by sacrament/status.
15. **System → Audit Log** (the actions you just performed are recorded),
    **Backup / Export**, **Accounts**.

## 6. Likely defense questions (with short answers)

**Q: Why didn't you include the priest / a Death/Burial module?**
The updated design (`PROJECT_CONTEXT.md`) removed the priest role and
Death/Burial. The priest's canonical steps are recorded as data by the
Secretary (banns, pre-Cana, interview) instead of a priest login. Death/Burial
has no module, no UI, and no tables in the canonical schema.

**Q: Why no ID upload on the forms?**
Data privacy (RA 10173). Identity is verified **in person** at pickup; the
system never stores a copy of a valid ID, so it holds less sensitive data.

**Q: How is security handled?**
CSRF tokens on every POST (checked server-side), password hashes
(`password_hash`/bcrypt), session regeneration on login, generic login errors,
prepared statements everywhere, output escaping (`e()`), a strict role guard,
hardened session cookies (HttpOnly/SameSite), and email validation on public
forms. The audit log records who did what.

**Q: What if two bookings want the same date/time?**
`schedule_events` is the source of truth for the calendar; the application
forms and the Booking Conflicts page flag overlaps for the Secretary, who
resolves manually — no automated reservation lock.

**Q: Why might an email not arrive?**
Email delivery is disabled by default and depends on the deployment's SMTP
relay. The system never makes delivery a prerequisite for submission: the
requester can always use the tracking number plus submission email on the
tracking page, and staff can inspect delivery attempts in the admin log.

**Q: Why no framework?**
A plain PHP/MySQL app is easier to deploy (any XAMPP machine), easier for a
parish office to maintain, and the study's design-doc artifacts (UI components,
design elements) were built against this exact stack.

**Q: How do the eligibility checks work?**
Age ranges live in `sacrament_eligibility_settings`. When an application falls
outside the range, `is_outside_eligibility()` sets a **flag** for review — the
submission is never silently blocked.

## 7. Known limitations (be ready to say these)

- Email delivery is disabled by default and requires deployment-specific SMTP configuration.
- Real certificate templates depend on the parish's official layout; the
  current print-ready certificate uses a clean parish-style template.
- First Communion/Confirmation age ranges are placeholders pending the parish
  priest's confirmation.
- Local single-machine hosting (XAMPP); no SSL terminator in the demo.

## 8. Pre-defense checklist

- [ ] Import schema + seed into a fresh `srs_sppo_db`.
- [ ] Log in as `secretary` / `SPPOsecretary2026`.
- [ ] Run the sections A–G of the walkthrough above.
- [ ] Check the app on a phone / responsive DevTools mode (dashboard, tables
      scroll internally, sidebar becomes a swipe strip).
- [ ] Reset the DB if the demo actually mutated data (re-import the schema
      then the seed).
- [ ] Keep project backup zipped off-machine before the defense.

> **One-screen summary:** SRS-SPPO = public request intake + secretary records &
> queue management + certificate generation + tracking + reports, on a plain
> PHP/MySQL stack, responsive, secure, and audit-logged.
