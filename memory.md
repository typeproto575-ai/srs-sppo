# SRS-SPPO Maintenance Notes

`PROJECT_CONTEXT.md` is the source of truth for live behavior. The repository
uses plain PHP, MySQL/MariaDB, CSS, and JavaScript; there is no priest portal.

## Documentation status

- Core helpers, authentication, public pages, admin pages, and certificate
	generation have purpose comments.
- API endpoints, password recovery pages, email delivery, storage protection,
	and the remaining uncommented migrations now have opening comments.
- Comments describe purpose, access boundaries, and side effects. They do not
	narrate every obvious HTML field.

## Validation commands

- PHP syntax: `C:\xampp\php\php.exe -l path\to\file.php`
- Full PHP syntax pass: run the XAMPP PHP binary against every `*.php` file.
- Browser smoke test: public intake, tracking, admin login, certificate
	preview, and pickup history.

## Known intentional boundaries

- Email delivery is disabled until deployment SMTP settings are supplied.
- Confirmation and First Communion application tables are retained for legacy
	data compatibility; new live intake is register encoding.
- Death/Burial remains a simplified status tool.
- Certificates are printed and released in person; no public certificate
	download is provided.
