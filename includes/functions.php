<?php
/**
 * =====================================================================
 * SPPO-IS SHARED HELPER FUNCTIONS
 * =====================================================================
 * Purpose: One place for small utilities used across the whole system:
 *   - generate_tracking_number() : unique request tracking codes
 *   - calculate_age()            : age from a birthdate
 *   - is_outside_eligibility()   : age-range flagging for sacraments
 *   - log_activity()             : audit-trail writer
 *   - e()                        : HTML-escaping shorthand for output
 *   - base_path()                : project URL prefix (subfolder-safe)
 *   - redirect_to()              : safe 302 redirects within the app
 *   - csrf_token()/csrf_field()/csrf_verify() : CSRF protection
 * Every page includes this file (directly or via includes/auth.php).
 * =====================================================================
 */

/**
 * Generate a unique tracking number, e.g. SPPO-2026-000123
 */
function generate_tracking_number(PDO $pdo, string $table = 'certificate_requests'): string
{
    $year  = date('Y');
    $stmt  = $pdo->query("SELECT COUNT(*) AS total FROM {$table} WHERE YEAR(date_submitted) = {$year}");
    $count = (int) $stmt->fetch()['total'] + 1;

    return sprintf('SPPO-%s-%06d', $year, $count);
}

/**
 * Calculate age in years from a birthdate (Y-m-d).
 */
function calculate_age(string $birthdate): int
{
    $birth = new DateTime($birthdate);
    $today = new DateTime('today');
    return $birth->diff($today)->y;
}

/**
 * Check whether an age falls outside a sacrament's configured eligibility range.
 * Returns true if it SHOULD be flagged (not blocked) for secretary/priest review.
 */
function is_outside_eligibility(PDO $pdo, string $sacramentType, int $age): bool
{
    $stmt = $pdo->prepare(
        "SELECT min_age, max_age FROM sacrament_eligibility_settings WHERE sacrament_type = ?"
    );
    $stmt->execute([$sacramentType]);
    $row = $stmt->fetch();

    if (!$row) {
        return false; // no rule configured yet -- don't flag
    }

    if ($age < (int) $row['min_age']) {
        return true;
    }
    if ($row['max_age'] !== null && $age > (int) $row['max_age']) {
        return true;
    }
    return false;
}

/**
 * Write an entry to the audit log.
 */
function log_activity(PDO $pdo, ?int $userId, string $action, ?string $targetTable = null, ?int $targetId = null, ?string $description = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, target_table, target_id, description)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$userId, $action, $targetTable, $targetId, $description]);
}

/**
 * Basic HTML-escaping shorthand for output.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * NOTE: The valid-ID upload feature was REMOVED per the updated design doc
 * (PROJECT_CONTEXT.md): applicants no longer upload IDs online — identity
 * is verified in person when the certificate is picked up. The former
 * handle_id_upload() helper was therefore deleted, and its columns were
 * removed from the schema.
 */

/**
 * Return the URL path prefix for the project root, e.g. "/sppo-is".
 *
 * Derived once per request by comparing the project directory (parent of
 * /includes) against the web server's DOCUMENT_ROOT. This keeps every link
 * and redirect working whether the app lives at the domain root or in a
 * subfolder (the typical XAMPP setup: http://localhost/sppo-is/).
 */
function base_path(): string
{
    static $path = null; // compute only once per request
    if ($path !== null) {
        return $path;
    }

    $path = '';
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appDir  = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..') ?: dirname(__DIR__)), '/');
    $appName = basename($appDir);

    // Standard case: project sits beneath DOCUMENT_ROOT (common XAMPP setup).
    if ($docRoot !== '' && strpos($appDir, $docRoot) === 0) {
        $path = substr($appDir, strlen($docRoot));
    }

    // Fallback for environments where DOCUMENT_ROOT is missing or the app is
    // mounted under a URL subfolder without a standard server layout.
    if ($path === '') {
        $candidates = [
            $_SERVER['SCRIPT_NAME'] ?? '',
            $_SERVER['PHP_SELF'] ?? '',
            $_SERVER['REQUEST_URI'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $normalized = '/' . trim((string) $candidate, '/');
            if ($normalized === '/' || $normalized === '') {
                continue;
            }

            $uriPath = parse_url($normalized, PHP_URL_PATH) ?: $normalized;
            $needle  = '/' . $appName;
            $pos     = strpos($uriPath, $needle);

            if ($pos !== false) {
                $path = substr($uriPath, 0, $pos + strlen($needle));
                break;
            }
        }
    }

    return $path;
}

/**
 * Redirect to an app-root-relative path (e.g. '/admin/login.php') and stop.
 * Always use this instead of header('Location: /...') so the subfolder
 * prefix from base_path() is applied automatically.
 */
function redirect_to(string $appPath): void
{
    header('Location: ' . base_path() . $appPath);
    exit;
}

/**
 * Start (or reuse) the PHP session with hardened cookie parameters.
 *
 * Always prefer this to a raw session_start() call. It sets:
 *   - HttpOnly     : the session id stays out of client-side JavaScript
 *   - SameSite=Lax : blocks most cross-site cookie-sending CSRF attempts
 *   - Secure       : enabled automatically when the site is served over HTTPS
 *   - scoped path  : the app's base path (or / when installed at the root)
 */
function secure_session_start(): void
{
    // If a session is already active, cookie parameters are locked in — do nothing.
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,                                  // browser-session cookie
        'path'     => base_path() ?: '/',                 // scope to this app
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),          // HTTPS-only when available
        'httponly' => true,                               // JS cannot read the session id
        'samesite' => 'Lax',                              // mitigates CSRF via the cookie
    ]);

    session_start();
}

/**
 * Get (or lazily create) the per-session CSRF token.
 * A random 32-byte hex value stored in the session and echoed into forms.
 */
function csrf_token(): string
{
    // Public form pages never log in, so make sure a session exists
    // before touching $_SESSION (idempotent if one is already active).
    secure_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden <input> to drop into every POST form (pairs with csrf_verify()).
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the CSRF token sent with a POST request.
 *
 * @param string|null $token Optional token override (defaults to $_POST).
 * @return bool True when the submitted token matches the session token.
 */
function csrf_verify(?string $token = null): bool
{
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    // hash_equals() compares in constant time to avoid timing attacks.
    return is_string($token)
        && $token !== ''
        && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
