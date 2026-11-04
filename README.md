# SRS-SPPO — Schedule and Record System of Saint Patrick's Parish Office

Plain PHP (no framework), MySQL/MariaDB, built for XAMPP.

## Setup

1. Place this whole `srs-sppo` folder inside `htdocs/` in your XAMPP installation.
2. Start Apache and MySQL from the XAMPP control panel.
3. From the project root, run `composer install` to install the PHP runtime dependencies.
4. Open phpMyAdmin and import `database/srs_sppo_schema.sql` (or run it via the `mysql` CLI).
5. Configure database credentials through environment variables, or create the
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
6. Only for a **disposable local database**, import
   `database/seed_demo_data.sql` if you want sample records. It deletes all
   rows from the sacramental-record, request, application, and persons tables
   it targets before reseeding; never run it on a populated, real, or hosted
   database. For a small, non-resetting walkthrough dataset instead, import
   `database/seed_process_demo.sql` after the current schema is installed.
   It adds clearly labeled fictional records and is safe to rerun without
   resetting their workflow statuses. Its reference numbers are
   `DEMO-BAP-001`, `DEMO-CONF-001`, `DEMO-FC-001`, `DEMO-MARR-001`, and
   `DEMO-CERT-001`. Match Eva Demo Santos using birth date `2014-06-15`.
   For a comprehensive, still-additive defense dataset, import
   `database/seed_defense_demo.sql` and follow
   [`docs/DEMO_WALKTHROUGH.md`](docs/DEMO_WALKTHROUGH.md). Both seeds are only
   for a local/demo database. Existing databases must have the required schema
   updates (including migrations 039, 045, and 046) before importing. Neither seed
   creates a login. Create a secretary account from the project root with
   `php scripts/create-admin.php`; the CLI requires a password of at least 12
   characters. The current schema has a `secretary` role, not a separate
   `admin` role.
7. Visit `http://localhost/srs-sppo/public/index.php` for the public site, and
   `http://localhost/srs-sppo/admin/login.php` for staff login.
8. For an existing database, sign in as secretary and open
   **System → Database Setup** to review supported compatibility changes.
   The app does not run schema changes automatically on normal requests. Back
   up the database before applying any update; the page checks schema objects
   but cannot guarantee a backup or undo MySQL DDL.
9. Set `SRS_APP_URL` to the public HTTPS address of this installation (for
   example, `https://parish.example.org` or
   `https://example.org/srs-sppo`). It can be set in the server environment or
   in server-only `config/local.php`. A non-local host automatically enables
   HTTPS enforcement.
10. Email delivery is disabled unless explicitly configured. To use Gmail SMTP,
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

## Scheduled encrypted Google Drive backups

The optional CLI job creates one compressed, encrypted **full database export**
per month. It uploads the file to a dedicated Google Drive folder and, only
after a successful upload, keeps the six newest monthly files matching its
backup filename pattern. Re-running the job in the same month does not create
a duplicate. This is not an incremental backup and does not back up application
files or server configuration.

The job requires PHP CLI with PDO MySQL, cURL, OpenSSL, and zlib, plus a host
that permits scheduled tasks and outbound HTTPS. It uses Google OAuth with the
`drive.file` scope, so the setup script creates the app-owned backup folder.
Create a Google Cloud project, enable the Google Drive API, and create OAuth
credentials for the account that will own the backups. A refresh token is
required for unattended operation; keep it private and revoke it if exposed.
For a personal Google Drive account, one way to obtain that token is to
register `https://developers.google.com/oauthplayground` as the OAuth client's
redirect URI, then use Google OAuth Playground's **Use your own OAuth
credentials** option with the scope
`https://www.googleapis.com/auth/drive.file`. Authorize the dedicated account,
exchange the authorization code for tokens, and copy the refresh token directly
to the server configuration. Configure the consent screen for unattended use:
refresh tokens issued while an External app is in Testing expire after a short
period. Never paste OAuth secrets or tokens into source control, support
requests, or chat.

Add these values to server environment variables or the untracked,
server-only `config/local.php` array. Use a dedicated Google account where
possible. Generate `SRS_BACKUP_PASSPHRASE` as a random secret of at least 32
characters; store a recovery copy in the parish-approved password manager,
separately from Google Drive and the server. The system cannot recover this
secret if it is lost. Scheduled backups are disabled unless
`SRS_SCHEDULED_BACKUPS_ENABLED` is explicitly set to `true`; disabling the
scheduled job does not disable manual backups from the admin page.

```php
'SRS_SCHEDULED_BACKUPS_ENABLED' => 'true',
'SRS_GOOGLE_CLIENT_ID' => 'YOUR_GOOGLE_OAUTH_CLIENT_ID',
'SRS_GOOGLE_CLIENT_SECRET' => 'YOUR_GOOGLE_OAUTH_CLIENT_SECRET',
'SRS_GOOGLE_REFRESH_TOKEN' => 'YOUR_GOOGLE_OAUTH_REFRESH_TOKEN',
'SRS_BACKUP_PASSPHRASE' => 'YOUR_RANDOM_32_OR_MORE_CHARACTER_SECRET',
// Set after running the folder setup command:
'SRS_GOOGLE_DRIVE_FOLDER_ID' => 'FOLDER_ID_FROM_SETUP',
```

Run the one-time setup command from the project root to create (or find) the
app-owned `SRS-SPPO Encrypted Backups` folder and print its ID:

```text
php scripts/setup-google-drive-backups.php
```

Set the printed folder ID as `SRS_GOOGLE_DRIVE_FOLDER_ID`. Then test the job
manually from the command line and confirm the encrypted file appears in
Google Drive:

```text
php scripts/scheduled-backup.php
```

Configure the host scheduler to run once a month. For a Linux cron host, the
example below runs on the first day of each month at 2:00 AM in the host's
local time. The job uses the Philippine calendar month for the backup
filename. If a scheduled run fails, run it manually after resolving the
problem; re-running within the same month will not create a duplicate after a
successful upload. Keep the log outside the public web directory and review it
regularly.

```cron
0 2 1 * * cd /path/to/srs-sppo && /usr/bin/php scripts/scheduled-backup.php >> /path/outside/webroot/srs-sppo-backup.log 2>&1
```

If the host does not support scheduled jobs or outbound Google API access,
or `SRS_SCHEDULED_BACKUPS_ENABLED` is not set to `true`, the automatic backup
is **not active**. The manual **System → Backup** download remains available.
A scheduled run reports errors with a non-zero exit status; configure the host
to alert the maintainer on failed jobs and periodically perform an isolated
restore drill. Never import a backup into the live database as a test.

To decrypt a scheduled `.sql.gz.enc` file, set `SRS_BACKUP_PASSPHRASE` through
the trusted recovery machine's secure environment/secret manager and run:

```text
php scripts/decrypt-backup.php path/to/srs-sppo-monthly-YYYY-MM.sql.gz.enc
```

The utility verifies the encrypted payload and writes a decompressed `.sql`
file beside it. Treat that SQL file as sensitive plaintext, keep it out of the
web root, and securely remove it after the isolated recovery drill. Never put
the passphrase directly in a shell command or commit it to a script.

## Quick process demo

After importing `database/seed_process_demo.sql` on a local/demo database:

1. Open **Admin → Requests → Online Submissions** to see all five `DEMO-*`
   reference numbers.
2. In the application queue, match `DEMO-CONF-001` and `DEMO-FC-001` to
   **Eva Demo Santos**, born `2014-06-15`.
3. Match both applicants separately on `DEMO-MARR-001`: **Marco Demo Reyes**
   (born `1997-04-20`) and **Nina Demo Cruz** (born `1998-09-08`).
4. Open Eva's person profile from **System → Persons**. Her Baptism record is
   linked; the other sacrament actions show how an existing identity can be
   reused instead of re-entered.
5. Open `DEMO-CERT-001` in the certificate queue and match it to her
   `DEMO-B-001` Baptism register entry.

All records are fictional and start as reviewable submissions. The seed does
not create staff login credentials or complete any workflow automatically.

## Folder structure

```
srs-sppo/
├── config/
│   ├── database.php          PDO connection
│   └── local.php             optional server-only settings (create during setup)
├── includes/
│   ├── header.php / footer.php          shared public-facing layout
│   ├── admin-header.php / admin-footer.php  shared staff layout (top bar + sidebar)
│   ├── application-tabs.php             tab nav between sacrament application forms
│   ├── functions.php                    helpers: base_path/redirect_to, CSRF, reference
│   │                                    numbers, age/eligibility check, audit logging, e()
│   └── auth.php                         session guard (require_login / require_role)
├── database/
│   ├── srs_sppo_schema.sql        full DB schema
│   ├── seed_process_demo.sql      quick, non-resetting walkthrough data
│   └── seed_demo_data.sql         larger destructive demo reset
├── migrations/                    reviewed database upgrades
├── scripts/                       setup and maintenance commands
├── tests/                         automated tests
├── public/                       parishioner-facing, no login required
│   ├── index.php                 landing page with FullCalendar.js calendar + announcements
│   ├── certificate-request.php   step 1: choose request type + sacrament
│   ├── sacrament-applications/   Baptism, Confirmation, First Communion, Marriage
│   ├── how-it-works.php          explained workflow for parishioners
│   └── requirements.php          documentary requirements per sacrament
├── admin/                        secretary only, login required
│   ├── login.php / logout.php / dashboard.php
│   ├── requests/                 queues + walk-in encoding + marriage detail page
│   ├── records/                  per-sacrament record management (Baptismal, Confirmation, First Communion, Marriage)
│   ├── certificates/             generate/preview/print history
│   ├── schedule/                 calendar, booking conflicts, announcements
│   ├── reports/                  monthly/yearly
│   └── system/                   backup/export, accounts, audit log
└── assets/
    ├── css/style.css             public site styles
    ├── css/admin.css             admin dashboard layout
    └── js/main.js
```

## What's built

- **Live public intake:** One unified certificate-request form and four sacrament-application forms: Baptismal, Confirmation, First Communion, and Marriage.
- **Live office workflow:** Parish Secretary dashboard, certificate and application queues, walk-in encoding, sacramental records, person mapping, Marriage clearance details, certificate generation/preview, print and pickup history, schedule, reports, request status management, and the shared System tools. Applicants receive updates by email when available or contact the Parish Office with their reference number. All staff accounts use the same parish secretary access level.
- **Email delivery:** Disabled by default (`MAIL_ENABLED = false`). The application sends through PHPMailer SMTP, records delivery attempts, and safely continues when mail is unavailable. Configure the server-only mail settings in `config/local.php`; never commit credentials. `MAIL_SITE_URL` defaults to the origin derived from `SRS_APP_URL`.
- Certificate generation renders a **print-ready certificate** (parish header, certifying text, curated per-sacrament details, signature line) using the shared `.cert-paper` styles. The template still needs to be aligned with the parish's official certificate stock before production use.

## Design decisions

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
