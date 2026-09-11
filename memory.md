# SRS-SPPO Memory

Tracks progress of the "upgrade and comment every PHP file" task.

## Plan
- Review every PHP file for missing comments and add clear `//` or `/* */` explanations of each feature/purpose.
- Only HTML, CSS, PHP, JS — plain stack, no framework.

## Completed
- [x] `config/database.php` — fully commented (PDO connection, error handling, XAMPP defaults).
- [x] `includes/functions.php` — fully commented (base_path, redirect_to, generate_tracking_number, calculate_age, is_outside_eligibility, log_activity, e(), handle_id_upload).
- [x] `includes/auth.php` — fully commented (session guard, current_user, require_login, require_role, role hierarchy, session data).

## In Progress
- Reading remaining files to verify which already have comments and which need more.

## Files already well-commented (no changes needed)
- public/index.php
- public/certificate-request.php
- public/how-it-works.php
- public/track-request.php
- public/certificate-requests/baptismal.php
- public/certificate-requests/confirmation.php
- public/certificate-requests/first-communion.php
- public/certificate-requests/marriage.php
- public/certificate-requests/death-burial.php (deleted stub)
- public/sacrament-applications/baptismal.php
- public/sacrament-applications/marriage.php
- includes/header.php
- includes/footer.php
- includes/admin-header.php
- includes/admin-footer.php
- includes/view-upload.php (deleted stub)
- admin/dashboard.php
- admin/login.php
- admin/logout.php

## Files to review next
- admin/requests/queue.php
- admin/requests/applications-queue.php
- admin/requests/walk-in-encode.php
- admin/requests/walk-in-application.php
- admin/records/baptismal.php
- admin/records/confirmation.php
- admin/records/first-communion.php
- admin/records/marriage.php
- admin/records/death-burial.php
- admin/certificates/generate.php
- admin/certificates/history.php
- admin/schedule/calendar.php
- admin/schedule/booking-conflicts.php
- admin/schedule/announcements.php
- admin/reports/monthly.php
- admin/reports/yearly.php
- admin/system/backup.php
- admin/system/accounts.php
- admin/system/audit-log.php
- admin/system/person-mapper.php
- priest/dashboard.php
- priest/marriage-approval.php
- priest/schedule.php
- priest/reports.php
- assets/css/style.css
- assets/css/admin.css
- assets/js/main.js
