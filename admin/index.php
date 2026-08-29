<?php
/**
 * =====================================================================
 * SPPO-IS ADMIN ENTRY POINT (/admin/)
 * =====================================================================
 * Purpose: Visiting /sppo-is/admin/ forwards staff to the dashboard;
 *          require_login() bounces unauthenticated visitors to the
 *          login page first.
 * =====================================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

redirect_to('/admin/dashboard.php');