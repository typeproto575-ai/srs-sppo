<?php
/**
 * =====================================================================
 * SRS-SPPO ENTRY POINT (project root)
 * =====================================================================
 * Purpose: Visiting the project root (e.g. http://localhost/srs-sppo/)
 *          forwards to the public home page instead of showing an
 *          Apache directory listing or a 403.
 * =====================================================================
 */

require_once __DIR__ . '/includes/functions.php';
redirect_to('/public/index.php');