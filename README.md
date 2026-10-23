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
       'DB_HOST' => 'localhost',
       'DB_NAME' => 'srs_sppo_db',
       'DB_USER' => 'root',
       'DB_PASS' => '', // Explicit XAMPP-local choice only; use a real password outside local development.
       'SRS_APP_URL' => 'http://localhost/srs-sppo',
   ];
   ```
   Environment variables (`SRS_DB_HOST`, `SRS_DB_NAME`, `SRS_DB_USER`,
   `SRS_DB_PASS`, `SRS_APP_URL`) take precedence over these local settings.
   The application refuses to connect when `DB_USER` is missing or when
   `DB_PASS` is not present in the environment or this file. On shared hosting,
   use the database host, database name, username, and password shown in that
   host's database panel. Never commit or upload your local `config/local.php`;
   create a separate server-only copy on the host.
5. Only for a **disposable local database**, import
   `database/seed_demo_data.sql` if you want sample records. It deletes all
   rows from the sacramental-record, request, application, and persons tables
   it targets before reseeding; never run it on a populated, real, or hosted
   database. It does not create a login. Create the first administrator from
   the project root with `php scripts/create-admin.php`; the CLI requires a
   password of at least 12 characters.
6. Visit `http://localhost/srs-sppo/public/index.php` for the public site, and
   `http://localhost/srs-sppo/admin/login.php` for staff login.
7. For an existing database, sign in as secretary and open
   **System → Database Setup** to review supported compatibility changes.
   The app does not run schema changes automatically on normal requests. Back
   up the database before applying any update; the page checks schema objects
   but cannot guarantee a backup or undo MySQL DDL.
8. Set `SRS_APP_URL` to the public HTTPS address of this installation (for
   example, `https://parish.example.org` or
   `https://example.org/srs-sppo`). It can be set in the server environment or
   in server-only `config/local.php`. A non-local host automatically enables
   HTTPS enforcement.
9. Email delivery is disabled unless explicitly configured. To use Gmail SMTP,
   enable 2-Step Verification on the sending account and create an App Password.
   Add the settings below to the server-only `config/local.php`; do not commit
   that file or share the App Password. The displayed App Password spaces should
   be omitted. The app URL should use HTTPS, and the deployment must include
   `vendor/` because PHPMailer is a runtime dependency.

   ```php
   'MAIL_ENABLED' => true,
   'MAIL_FROM_ADDRESS' => 'julyanworking@gmail.com',
   'MAIL_FROM_NAME' => "St. Patrick's Parish Office",
   'MAIL_SITE_URL' => 'https://stpatricksparish.me',
   'MAIL_SMTP_HOST' => 'smtp.gmail.com',
   'MAIL_SMTP_PORT' => 587,
   'MAIL_SMTP_USER' => 'julyanworking@gmail.com',
   'MAIL_SMTP_PASS' => 'YOUR_GOOGLE_APP_PASSWORD',
   'MAIL_SMTP_ENCRYPT' => 'tls',
   ```

   Add these entries to the array in the server-only `config/local.php`, keeping
   its database settings. On InfinityFree, external SMTP is supported; outgoing
   PHP `mail()` is restricted. Test delivery to an address you control and
   check **System → Email Log** before relying on notifications.

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

The current ERD, normalization analysis, use-case diagram, and actor-based
DFD are documented in [docs/SYSTEM_ANALYSIS_DIAGRAMS.md](docs/SYSTEM_ANALYSIS_DIAGRAMS.md).

## What's built

- **Live public intake:** Four certificate-request forms and four sacrament-application forms: Baptismal, Confirmation, First Communion, and Marriage.
- **Live office workflow:** Parish Secretary dashboard, certificate and application queues, walk-in encoding, sacramental records, person mapping, Marriage clearance details, certificate generation/preview, print and pickup history, schedule, reports, tracking/status lookup, correction requests, and the shared System tools. All staff accounts use the same parish secretary access level.
- **Email delivery:** Disabled by default (`MAIL_ENABLED = false`). The application sends through PHPMailer SMTP, records delivery attempts, and safely continues when mail is unavailable. Configure the server-only mail settings in `config/local.php`; never commit credentials. `MAIL_SITE_URL` defaults to the origin derived from `SRS_APP_URL`.
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
