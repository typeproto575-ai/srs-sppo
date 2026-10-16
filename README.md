# SRS-SPPO — Schedule and Record System of Saint Patrick's Parish Office

Plain PHP (no framework), MySQL/MariaDB, built for XAMPP.

## Setup

1. Place this whole `srs-sppo` folder inside `htdocs/` in your XAMPP installation.
2. Start Apache and MySQL from the XAMPP control panel.
3. Open phpMyAdmin and import `database/srs_sppo_schema.sql` (or run it via the `mysql` CLI).
4. Configure database credentials through environment variables, or create the
   untracked local file `config/local.php`:
   ```php
   <?php
   return [
       'DB_USER' => 'root',
       'DB_PASS' => '', // Explicit XAMPP-local choice only; use a real password outside local development.
   ];
   ```
   The application refuses to connect when `DB_USER` is missing or when
   `DB_PASS` is not present in the environment or this file. For production,
   use `SRS_DB_USER` and `SRS_DB_PASS` environment variables instead.
5. Import `database/seed_demo_data.sql` only if you want sample records. It
   does not create a login. Create the first administrator from the project
   root with `php scripts/create-admin.php`; the CLI requires a password of at
   least 12 characters.
6. Visit `http://localhost/srs-sppo/public/index.php` for the public site, and
   `http://localhost/srs-sppo/admin/login.php` for staff login.
7. Set `SRS_FORCE_HTTPS=true` in production. Leave it unset or false for local
   XAMPP HTTP development. When enabled, HTTP requests redirect to HTTPS and
   HSTS is sent. Set the public base URL used inside the certificate QR code: in
   `config/environment.php`, `SRS_APP_URL` defaults to
   `http://localhost/srs-sppo` for XAMPP. On the real parish server, set the
   `SRS_APP_URL` environment variable to the public address (e.g.
   `https://parish.example.org`). A QR that points to localhost scans only
   on that same computer — the admin preview warns in that case.

## Folder structure

```
srs-sppo/
├── config/
│   └── database.php          PDO connection
├── includes/
│   ├── header.php / footer.php          shared public-facing layout
│   ├── admin-header.php / admin-footer.php  shared staff layout (top bar + sidebar)
│   ├── application-tabs.php             tab nav between sacrament application forms
│   ├── functions.php                    helpers: base_path/redirect_to, CSRF, tracking
│   │                                    numbers, age/eligibility check, audit logging, e()
│   └── auth.php                         session guard (require_login / require_role)
├── database/
│   ├── srs_sppo_schema.sql        full DB schema
│   └── seed_demo_data.sql        optional demo dataset
├── public/                       parishioner-facing, no login required
│   ├── index.php                 landing page with FullCalendar.js calendar + announcements
│   ├── certificate-request.php   step 1: choose request type + sacrament
│   ├── certificate-requests/     one form per sacrament (Baptismal, Confirmation, First Communion, Marriage)
│   ├── sacrament-applications/   Baptism, Confirmation, First Communion, Marriage
│   ├── how-it-works.php          explained workflow for parishioners
│   ├── requirements.php          documentary requirements per sacrament
│   └── track-request.php         look up status by tracking number + email
├── admin/                        secretary only, login required
│   ├── login.php / logout.php / dashboard.php
│   ├── requests/                 queues + walk-in encoding + marriage detail page
│   ├── records/                  per-sacrament record management (Baptismal, Confirmation, First Communion, Marriage)
│   ├── certificates/             generate/preview/print history
│   ├── schedule/                 calendar, booking conflicts, announcements
│   ├── reports/                  monthly/yearly
│   └── system/                   backup/export, accounts, audit log
├── docs/                         defense guide + archived design-process artifacts
└── assets/
    ├── css/style.css             public site styles
    ├── css/admin.css             admin dashboard layout
    └── js/main.js
```

## What's built

- **Live public intake:** Four certificate-request forms and four sacrament-application forms: Baptismal, Confirmation, First Communion, and Marriage.
- **Live office workflow:** Secretary dashboard, certificate and application queues, walk-in encoding, sacramental records, person mapping, Marriage clearance details, certificate generation/preview, print and pickup history, schedule, reports, tracking/status lookup, correction requests, and the shared System tools. Parish Administrators retain the additional role protections for Parish Administrator account changes.
- **Email delivery:** Disabled by default (`MAIL_ENABLED = false`). The application records delivery attempts and safely continues when mail is unavailable. To enable it, configure the server's SMTP/sendmail relay, `MAIL_FROM_ADDRESS`, and `MAIL_SITE_URL` in the environment; never commit credentials.
- Certificate generation renders a **print-ready certificate** (parish header, certifying text, curated per-sacrament details, signature line) using the shared `.cert-paper` styles. The template still needs to be aligned with the parish's official certificate stock before production use.

## Design decisions (see PROJECT_CONTEXT.md for full details)

- No monetary/fee/amount fields anywhere — the system never takes money and has no payment feature. Payment is made **outside the system, before processing**: at the parish office, by contacting the parish (email, phone, or the parish Facebook page/Messenger), or through someone near the parish. It is recorded only as a **manual status** — the secretary marks a request/application "paid" by hand (`paid_at` / `paid_by`; no amount stored). Online rows pause at `awaiting_payment` until then.
- Identity verification happens **in person at pickup** — no public form collects ID scans. The Data Privacy Act still applies to all personal data handled by the system.
- The parish priest is **not a system user**. Canonical clearance for Marriage is recorded by the Secretary after confirming with the priest outside the system.
- Marriage clearance (pre-Cana, canonical interview, banns) is recorded by the Secretary on the Marriage detail page, not self-reported by the applicant.
- Age eligibility (First Communion / Confirmation) is flagged, never auto-blocked.
- The `legitimacy` column in `baptismal_records` is internal-only and never appears on certificates or reports.
- Sacrament Applications exist for Baptism, Confirmation, First Communion, and Marriage.

## Deployment note

Always deploy an **exported copy** of the project — never the live working directory:

- Use `git archive` to create a clean zip/tar without the `.git/` folder, or export from your Git hosting provider.
- **Never place the `.git/` directory inside `htdocs/`.** It contains the full commit history and is reachable from the web unless blocked by the provided root `.htaccess`.
- The database backup feature (`admin/system/backup.php`) exports all sacramental records and hashed account credentials. Store exported `.sql` files **encrypted and offline** — never on shared drives or sent via email.
- The first administrator is created only with `php scripts/create-admin.php`;
  do not put administrator passwords in seed files or documentation.
