# SPPO-IS UI Component Inventory

This document maps the requested UI surfaces to the current application implementation paths.

## 1. Homepage
- UI item: Homepage / public landing page
- Path: `public/index.php`
- Supporting layout: `includes/header.php`, `includes/footer.php`
- CSS layer: `assets/css/style.css`
- Notes: This page loads the public layout shell and serves the public home/landing content.

## 2. Login Page
- UI item: Staff authentication screen
- Path: `admin/login.php`
- Supporting CSS: `assets/css/style.css`
- Login-specific styling: `.login-page`, `.login-panel`, `.login-form`, `.password-toggle`
- Notes: The login screen is separate from the admin dashboard shell and is intentionally minimized.

## 3. Dashboard
- UI item: Dashboard surface for Secretary/Priest system entry
- Path: `admin/dashboard.php` and `priest/dashboard.php`
- Shared shell: `includes/admin-header.php`
- Shared dashboard classes: `.admin-hero`, `.stat-cards`, `.panel-card`, `.panel-grid`, `.quick-actions`
- CSS layer: `assets/css/admin.css`
- Notes: The live dashboard content is rendered inside the common admin system layout.

## 4. Navigation Menu
- UI item: Shared system navigation
- Path: `includes/admin-header.php`
- Header utility navigation: `header.site-master-header` and `.master-utilities`
- Sidebar navigation: `<nav class="admin-sidebar">`
- Labels and menu entries are rendered in the same include to all admin pages.
- CSS layer: `assets/css/admin.css`
- Notes: The sidebar organizes Requests, Records, and Schedule navigation.

## 5. Forms
- UI item: Data-entry forms for the system
- Path examples:
  - `admin/system/accounts.php` — account create/edit form
  - `public/certificate-request.php` — public certificate request entry point
  - `public/sacrament-applications/*.php` — public sacrament application forms
  - `public/certificate-requests/*.php` — public certificate request detail forms
- Shared form styling: `assets/css/style.css` and `assets/css/admin.css`
- Notes: Form fields are standardized with shared input, textarea, select, and label styling.

## 6. Tables
- UI item: System data tables
- Path examples:
  - `admin/system/accounts.php` — account listing table
  - `priest/marriage-approval.php` — priest table-driven approval data
  - Reports and record pages make use of the `.admin-table` style
- Shared class: `.admin-table`
- CSS layer: `assets/css/admin.css`
- Notes: The table styling uses alternating/compatible visual treatment, padding, borders and status styling.

## 7. Reports
- UI item: Monthly / yearly reports and priest report interfaces
- Path: `admin/reports/monthly.php`, `admin/reports/yearly.php`, and `priest/reports.php`
- Shared shell: `includes/admin-header.php`
- CSS layer: `assets/css/admin.css` and `assets/css/style.css`
- Notes: Reports are page-level content surfaces loaded through the same admin/header shell.

## 8. Buttons
- UI item: Shared button styling for actions
- Path examples:
  - button classes are used in page markup under the admin and public pages
  - `assets/css/style.css` provides `.btn` and `.btn-secondary`
  - `assets/css/admin.css` provides `.btn-small` and header logout button styling
- CSS layer: `assets/css/style.css` and `assets/css/admin.css`
- Notes: Buttons include primary action buttons, secondary buttons, small table buttons, and utility logout button styling.

## 9. Cards
- UI item: Dashboard and system content cards
- Path examples:
  - `admin/dashboard.php` includes `admin-hero`, `stat-card`, `panel-card`, and `panel-grid`
  - `admin/system/accounts.php` uses `panel-card` for the account form and account list
- CSS layer: `assets/css/admin.css`
- Shared card components: `.admin-hero`, `.panel-card`, `.stat-card`, `.panel-grid`
- Notes: The card system is part of the shared layout vocabulary across system pages.

## 10. Footer
- UI item: Shared footer area
- Path: `includes/admin-footer.php` and `includes/footer.php`
- CSS: `assets/css/style.css` — `.site-footer`
- Notes: The footer sits after the main content and is shared across public and admin page shells.

## 11. Other Interface Components
- UI item: Additional visual components used throughout the system
- Path examples:
  - Header: `includes/admin-header.php`
  - Sidebar: same admin header file
  - Status badges: `assets/css/admin.css` (`.status-badge` and `.status-approved`, etc.)
  - Quick actions: `admin/dashboard.php` and `assets/css/admin.css`
  - Alerts: `assets/css/style.css` (`.alert`, `.alert-success`, `.alert-error`, `.alert-warning`)
- CSS layer: `assets/css/style.css` and `assets/css/admin.css`
- Notes: These are shared UI tokens and utility-level constructs that keep the system visually continuous.
