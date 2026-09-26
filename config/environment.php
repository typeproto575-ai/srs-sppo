<?php
/**
 * ============================================================================
 * SRS-SPPO — Environment / deployment switches
 * ============================================================================
 * Loaded by includes/functions.php. Defaults are correct for a local XAMPP
 * demo over plain HTTP; tighten them for a real deployment.
 *
 * APP_FORCE_HTTPS (default false):
 *   true  — every HTTP GET/HEAD is 301-redirected to HTTPS, and a one-year
 *           HSTS header is sent. Enable on the real parish server (requires
 *           a working TLS certificate) — leave false on localhost, where
 *           XAMPP has no TLS configured.
 * ============================================================================
 */

define('APP_FORCE_HTTPS', false);
