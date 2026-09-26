# SRS-SPPO — Documentation and Maintenance Checklist

The live behavior is defined by `PROJECT_CONTEXT.md`. This checklist tracks
documentation and maintenance work only; it must not describe legacy pages as
active features. A file is considered documented when its opening comment
states its purpose, access boundary, and important side effects. Individual
HTML inputs do not need narration when their names and labels are clear.

## Verified baseline
- [x] README and defense guide agree with the live public scope and mail setting
- [x] Legacy Confirmation/First Communion application tables are labeled as compatibility data
- [x] Non-existent priest pages removed from this checklist; there is no priest portal
- [ ] Run syntax validation with the XAMPP PHP binary (`C:\xampp\php\php.exe`)
- [ ] Perform a browser smoke test for public intake, tracking, admin login, and certificate preview

## Phase 1: Core infrastructure (includes/)
- [x] includes/functions.php — already well-commented, add missing pieces
- [x] includes/auth.php — already well-commented
- [ ] includes/header.php — add header comment, comment each section
- [ ] includes/footer.php — add header comment, comment each section
- [ ] includes/admin-header.php — add header comment, comment each section
- [ ] includes/admin-footer.php — add header comment, comment each section
- [ ] includes/application-tabs.php — add header comment
- [ ] includes/record-filters.php — add header comment (or verify existing)

## Phase 2: Config and database
- [x] config/database.php — already commented
- [ ] database/srs_sppo_schema.sql — add comment blocks per table
- [ ] migrations/ files — add header comments

## Phase 3: Public-facing pages
- [ ] public/index.php — add header comment
- [ ] public/certificate-request.php — add header comment
- [ ] public/how-it-works.php — add header comment, comment sections
- [ ] public/track-request.php — add header comment, comment sections
- [ ] public/certificate-requests/baptismal.php — add header comment, comment sections
- [ ] public/certificate-requests/confirmation.php — add header comment, fix $motherName bug
- [ ] public/certificate-requests/first-communion.php — add header comment, fix $motherName bug
- [ ] public/certificate-requests/marriage.php — add header comment, comment sections
- [ ] public/certificate-requests/death-burial.php — add header comment, comment sections
- [ ] public/sacrament-applications/baptismal.php — add header comment, comment sections
- [ ] public/sacrament-applications/confirmation.php — add header comment, comment sections
- [ ] public/sacrament-applications/first-communion.php — add header comment, comment sections
- [ ] public/sacrament-applications/marriage.php — add header comment, comment sections

## Phase 4: Admin pages
- [ ] admin/login.php — add header comment, comment sections
- [ ] admin/logout.php — add header comment, comment sections
- [ ] admin/dashboard.php — add header comment, comment sections
- [ ] admin/requests/queue.php — add header comment, comment sections
- [ ] admin/requests/applications-queue.php — add header comment, comment sections
- [ ] admin/requests/walk-in-encode.php — add header comment, comment sections
- [ ] admin/requests/walk-in-application.php — add header comment, comment sections
- [ ] admin/records/baptismal.php — add header comment, comment sections
- [ ] admin/records/confirmation.php — add header comment, comment sections
- [ ] admin/records/first-communion.php — add header comment, comment sections
- [ ] admin/records/marriage.php — add header comment, comment sections
- [ ] admin/records/death-burial.php — add header comment, comment sections
- [ ] admin/certificates/generate.php — add header comment, comment sections
- [ ] admin/certificates/history.php — add header comment, comment sections
- [ ] admin/schedule/calendar.php — add header comment, comment sections
- [ ] admin/schedule/announcements.php — add header comment, comment sections
- [ ] admin/schedule/booking-conflicts.php — add header comment, comment sections
- [ ] admin/reports/monthly.php — add header comment, comment sections
- [ ] admin/reports/yearly.php — add header comment, comment sections
- [ ] admin/system/accounts.php — add header comment, comment sections
- [ ] admin/system/backup.php — add header comment, comment sections
- [ ] admin/system/audit-log.php — add header comment, comment sections
- [ ] admin/system/person-mapper.php — add header comment, comment sections

## Phase 5: Removed scope
- [x] Priest portal — intentionally not part of the live system; canonical clearance is recorded by the Secretary

## Phase 6: Assets
- [ ] assets/css/style.css — add section header comments
- [ ] assets/css/admin.css — add section header comments
- [ ] assets/js/main.js — add header comment, comment each function

## Phase 7: Final verification
- [ ] Run PHP syntax check on all .php files with XAMPP PHP
- [ ] Verify all executable PHP/CSS/JS entry points have a useful header or section comment
- [ ] Check for remaining stale links, undefined variables, and references to removed workflows