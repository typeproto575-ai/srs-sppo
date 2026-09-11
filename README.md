# SRS-SPPO — Schedule and Record System of Saint Patrick's Parish Office

Plain PHP (no framework), MySQL/MariaDB, built for XAMPP.

## Setup

1. Place this whole `srs-sppo` folder inside `htdocs/` in your XAMPP installation.
2. Start Apache and MySQL from the XAMPP control panel.
3. Open phpMyAdmin and import `database/srs_sppo_schema.sql` (or run it via the `mysql` CLI).
4. Adjust `config/database.php` if your MySQL credentials differ from the XAMPP defaults.
5. Create the secretary account **or** use the demo dataset:
   - **Recommended (demo):** after importing the schema, also import
     `database/seed_demo_data.sql`. It resets the sacramental records,
    certificate requests, and sacrament applications, then seeds 21 people
    linked across all five record categories: Baptismal, Confirmation, First
    Communion, Marriage, and Death/Burial. It also seeds the secretary account
     **`secretary`** / **`SPPOsecretary2026`** — change the password after
     first login. Re-running the seed intentionally replaces this demo data.
   - **Manual:** create one account by hand:
     ```sql
     INSERT INTO users (full_name, username, email, password_hash, role)
     VALUES ('Test Secretary', 'secretary', 'secretary@example.com',
             '$2y$10$replace_with_a_real_password_hash', 'secretary');
     ```
     Generate the hash with `password_hash('yourpassword', PASSWORD_DEFAULT)`
     in a quick PHP script.
6. Visit `http://localhost/srs-sppo/public/index.php` for the public site, and
   `http://localhost/srs-sppo/admin/login.php` for staff login.

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
│   └── seed_demo_data.sql        optional demo dataset + secretary account
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
│   ├── records/                  per-sacrament record management (Baptismal, Confirmation, First Communion, Marriage, Death/Burial status)
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

- **Fully built:** All public forms (4 certificate requests + 2 sacrament applications (Baptismal and Marriage)), secretary dashboard, all admin queues/records/certificates/schedule/reports/system pages, Marriage clearance detail page, walk-in encoding, tracking/status-check, public calendar, audit logging, database backup/export.
- **Not implemented:** SMTP emission is **built (wired into all public forms + secretary status changes) but off by default** — `config/mail.php` has `MAIL_ENABLED = false` for the XAMPP demo (no mail server). Enable it there and set `MAIL_FROM_*` / `MAIL_SITE_URL` to go live; `includes/mail.php` is a safe no-op meanwhile.
- Certificate generation renders a **print-ready certificate** (parish header, certifying text, curated per-sacrament details, signature line) using the shared `.cert-paper` styles — layout mirrors a typical parish certificate and can be restyled to the parish's official format in `assets/css/admin.css`.

## Design decisions (see PROJECT_CONTEXT.md for full details)

- No monetary/fee fields anywhere — payment is handled entirely in person at pickup.
- Identity verification happens **in person at pickup** — no public form collects ID scans. This simplifies RA 10173 (Data Privacy Act) compliance.
- The parish priest is **not a system user**. Canonical clearance for Marriage is recorded by the Secretary after confirming with the priest outside the system.
- Marriage clearance (pre-Cana, canonical interview, banns) is recorded by the Secretary on the Marriage detail page, not self-reported by the applicant.
- Age eligibility (First Communion / Confirmation) is flagged, never auto-blocked.
- The `legitimacy` column in `baptismal_records` is internal-only and never appears on certificates or reports.
- Sacrament Applications exist for Baptism, Confirmation, First Communion, and Marriage (not Death/Burial).

## Deployment note

Always deploy an **exported copy** of the project — never the live working directory:

- Use `git archive` to create a clean zip/tar without the `.git/` folder, or export from your Git hosting provider.
- **Never place the `.git/` directory inside `htdocs/`.** It contains the full commit history and is reachable from the web unless blocked by the provided root `.htaccess`.
- The database backup feature (`admin/system/backup.php`) exports all sacramental records and hashed account credentials. Store exported `.sql` files **encrypted and offline** — never on shared drives or sent via email.
- After first login, **change the demo secretary password** immediately (the dashboard will remind you).
