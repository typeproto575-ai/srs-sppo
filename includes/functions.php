<?php
/**
 * =====================================================================
 * SRS-SPPO SHARED HELPER FUNCTIONS
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

// Deployment switches (HTTPS enforcement, etc.) — before anything renders.
require_once __DIR__ . '/../config/environment.php';

/**
 * Generate a unique tracking number, e.g. SPPO-2026-000123
 */
function generate_tracking_number(PDO $pdo, string $table = 'certificate_requests'): string
{
    $year  = date('Y');

    // Derive the next number from the current MAX sequence instead of a
    // COUNT, so deleting the newest request of a year never makes the next
    // submission collide with a previously issued (now-deleted) number.
    // The prefix is taken from the newest existing row so each table keeps
    // its own style (certificates: SPPO-2026-000001, applications seeded
    // as SPPO-APP-2026-000001).
    $stmt = $pdo->prepare(
        "SELECT tracking_number FROM {$table} WHERE tracking_number LIKE ? ORDER BY tracking_number DESC LIMIT 1"
    );
    $stmt->execute(['%-' . $year . '-%']);
    $last = $stmt->fetchColumn();

    $prefix = 'SPPO-' . $year . '-';
    $next   = 1;
    if ($last !== false && preg_match('/^(.*)-(\d{6})$/', (string) $last, $m)) {
        $prefix = $m[1] . '-';
        $next   = ((int) $m[2]) + 1;
    }

    return sprintf('%s%06d', $prefix, $next);
}

/**
 * True when an exception is a MySQL duplicate-key error (driver code 1062).
 * That is the signal that a generated tracking number lost a race with a
 * concurrent submission, so the insert should be retried with a fresh number.
 */
function is_duplicate_key_error(Exception $e): bool
{
    if (!($e instanceof PDOException)) {
        return false;
    }
    // MariaDB/MySQL reports duplicate-key violations with driver code 1062
    // in the message text (e.g. "SQLSTATE[23000]: ... 1062 Duplicate entry...").
    return strpos($e->getMessage(), '1062') !== false;
}

/**
 * Run a full insert wrapped in ONE transaction, retrying the whole body
 * when a duplicate-key error is raised (typically a tracking-number race).
 *
 * The body closure must NOT call beginTransaction/commit/rollBack itself:
 * this helper owns the transaction so a failed attempt can be rolled back
 * cleanly and the body re-run with a freshly generated number.
 *
 * @param PDO      $pdo         Database connection
 * @param callable $body        Closure performing the inserts (returns the
 *                              tracking number, or any value, on success)
 * @param int      $maxAttempts Total attempts including the first (default 5)
 * @return mixed                Whatever the body returned on success
 * @throws Exception            The last error if all attempts collided, or
 *                              any non-duplicate error on the first attempt
 */
function retry_on_duplicate(PDO $pdo, callable $body, int $maxAttempts = 5): mixed
{
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $pdo->beginTransaction();
            $result = $body();
            $pdo->commit();
            return $result;
        } catch (Exception $e) {
            // The failed statement leaves the transaction open — close it
            // before retrying (ignore the case where nothing was started).
            try {
                $pdo->rollBack();
            } catch (Exception $rollbackError) {
                // no active transaction; nothing to roll back
            }

            if (!is_duplicate_key_error($e)) {
                throw $e; // not a tracking-number collision — surface it
            }

            // Collision on the unique tracking_number — loop again with a
            // freshly generated number.
            $lastError = $e;
        }
    }

    // Every attempt collided — surface the final duplicate-key error.
    throw $lastError ?? new Exception('Duplicate tracking number retries exhausted.');
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
 * Record a failed login/tracking attempt for rate limiting.
 * Uses the audit_logs table (action = 'rate_limit_attempt') so no new table is needed.
 */
function record_attempt(PDO $pdo, string $identifier): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, target_table, target_id, description)
         VALUES (NULL, 'rate_limit_attempt', 'login_attempts', NULL, ?)"
    );
    $stmt->execute([$identifier]);
}

/**
 * Check whether an identifier has too many recent failed attempts.
 *
 * @param PDO    $pdo           Database connection
 * @param string $identifier    Username (login) or tracking+email (track-request) or IP
 * @param int    $maxAttempts   Maximum allowed attempts within the window
 * @param int    $windowMinutes Time window in minutes
 * @return bool True if the identifier is rate-limited (too many attempts)
 */
function too_many_attempts(PDO $pdo, string $identifier, int $maxAttempts = 8, int $windowMinutes = 15): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c FROM audit_logs
         WHERE action = 'rate_limit_attempt'
           AND description = ?
           AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$identifier, $windowMinutes]);
    return ((int) $stmt->fetch()['c']) >= $maxAttempts;
}

/**
 * Validation helpers for public forms.
 */



/**
 * Basic HTML-escaping shorthand for output.
 *
 * Purpose: Converts special characters to HTML entities to prevent
 *          Cross-Site Scripting (XSS) attacks when displaying user-supplied
 *          data in HTML templates.
 *
 * What it escapes:
 *   &  → &amp;   (ampersand)
 *   "  → &quot;  (double quote)
 *   '  → &#039;  (single quote, ENT_QUOTES mode)
 *   <  → &lt;    (less than)
 *   >  → &gt;    (greater than)
 *
 * Why ENT_QUOTES: Both single and double quotes are escaped, which is
 * important because attribute values can be quoted with either type.
 *
 * UTF-8: Ensures multi-byte characters (e.g. accented letters, emojis)
 * are handled correctly and not corrupted.
 *
 * Null handling: If $value is NULL, it returns an empty string instead of
 * "NULL" or causing a notice, so templates can safely call e($var) without
 * checking for null first.
 *
 * Usage (in every HTML template throughout the app):
 *   <input value="<?= e($userName) ?>">
 *   <p><?= e($userComment) ?></p>
 *   <td><?= e($record['child_full_name']) ?></td>
 *
 * IMPORTANT: Never use this for SQL (use prepared statements instead).
 * This is ONLY for HTML output escaping.
 *
 * @param string|null $value  The string to escape (NULL allowed, returns '')
 * @return string             The HTML-escaped string
 */
function e(?string $value): string
{
    // htmlspecialchars handles the 5 special characters (&, ", ', <, >).
    // ENT_QUOTES tells it to escape both single AND double quotes.
    // 'UTF-8' ensures proper handling of multi-byte / Unicode characters.
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Return the project's base URL path, used for all internal links and asset URLs.
 *
 * Purpose: Ensures that every link, CSS file, JS file, and form action URL
 * works correctly whether the application is installed at the web root (/)
 * or in a subfolder (e.g. /srs-sppo).
 *
 * How it works:
 *   - Returns '/srs-sppo' as a hardcoded constant.
 *   - Every page that needs a URL uses this function (or the $basePath variable
 * base_path() is defined below (auto-detecting version).
 * It returns the URL path prefix for the project root, e.g. "/srs-sppo".
 *
 * Usage in templates:
 *   <a href="<?= base_path() ?>/public/index.php">Home</a>
 *   <link rel="stylesheet" href="<?= base_path() ?>/assets/css/style.css">
 */

/**
 * NOTE: The valid-ID upload feature was REMOVED per the updated design doc
 * (PROJECT_CONTEXT.md): applicants no longer upload IDs online — identity
 * is verified in person when the certificate is picked up. The former
 * handle_id_upload() helper was therefore deleted, and its columns were
 * removed from the schema.
 */

/**
 * Return the URL path prefix for the project root, e.g. "/srs-sppo".
 *
 * Derived once per request by comparing the project directory (parent of
 * /includes) against the web server's DOCUMENT_ROOT. This keeps every link
 * and redirect working whether the app lives at the domain root or in a
 * subfolder (the typical XAMPP setup: http://localhost/srs-sppo/).
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

/* =====================================================================
 * LOGBOOK NUMBERING HELPERS (records pages)
 * =====================================================================
 * The physical registers number entries sequentially, and the digital
 * index mirrors that habit:
 *   - register_next_logbook_numbers() suggests the next Entry No. (plus
 *     the last Book/Page/Line) so the encode dialog opens pre-filled;
 *   - register_shift_entry_numbers() makes room for an entry inserted in
 *     the middle of a sequence by bumping every later entry with the same
 *     prefix up by one — it MUST run inside the caller's transaction so
 *     the shift lands together with the new row.
 */

/**
 * Split an entry number into prefix + trailing number, e.g.
 * "B-2015-011" -> prefix "B-2015-0", number 11, pad 3. Plain numbers keep
 * an empty prefix. Returns null when there is no trailing number.
 */
function register_parse_entry_no(string $entryNo): ?array
{
    $entryNo = trim($entryNo);
    if ($entryNo === '' || !preg_match('/^(.*?)(\d+)$/', $entryNo, $m)) return null;
    return ['prefix' => $m[1], 'number' => (int) $m[2], 'pad' => strlen($m[2])];
}

/** Rebuild an entry number with the same prefix and zero-padding. */
function register_build_entry_no(array $parts, int $number): string
{
    return $parts['prefix'] . str_pad((string) $number, $parts['pad'], '0', STR_PAD_LEFT);
}

/**
 * Suggest the next logbook numbers from the most recently encoded row:
 * Entry No. = last + 1 (same prefix and zero-padding), Book/Page copied
 * as-is, Line + 1 when the last line is purely numeric. Every field stays
 * editable in the dialog — these are only suggestions.
 *
 * @param string $table Register table name — page-authored, never user input.
 */
function register_next_logbook_numbers(PDO $pdo, string $table): array
{
    $row = $pdo->query("SELECT entry_no, book_no, page_no, line_no FROM {$table} ORDER BY id DESC LIMIT 1")->fetch();
    if (!$row) return ['entry_no' => '', 'book_no' => '', 'page_no' => '', 'line_no' => ''];

    $entryNo = '';
    $parts = register_parse_entry_no((string) ($row['entry_no'] ?? ''));
    if ($parts) $entryNo = register_build_entry_no($parts, $parts['number'] + 1);

    $lineNo = (string) ($row['line_no'] ?? '');
    if ($lineNo !== '' && ctype_digit($lineNo)) $lineNo = (string) ((int) $lineNo + 1);

    return [
        'entry_no' => $entryNo,
        'book_no'  => (string) ($row['book_no'] ?? ''),
        'page_no'  => (string) ($row['page_no'] ?? ''),
        'line_no'  => $lineNo,
    ];
}

/**
 * Make room for $entryNo: every row in $table whose entry number shares the
 * same prefix and whose number is >= $entryNo's gets bumped up by one, so
 * the new entry can take its number. Rows with a different numbering
 * format are left untouched. Returns how many rows were renumbered.
 *
 * @param string $table Register table name — page-authored, never user input.
 */
function register_shift_entry_numbers(PDO $pdo, string $table, string $entryNo): int
{
    $incoming = register_parse_entry_no($entryNo);
    if (!$incoming) return 0;

    $stmt = $pdo->prepare("SELECT id, entry_no FROM {$table} WHERE entry_no LIKE ?");
    $stmt->execute([$incoming['prefix'] . '%']);
    $upd = $pdo->prepare("UPDATE {$table} SET entry_no = ? WHERE id = ?");

    $shifted = 0;
    foreach ($stmt->fetchAll() as $row) {
        $parts = register_parse_entry_no((string) $row['entry_no']);
        if (!$parts || $parts['prefix'] !== $incoming['prefix'] || $parts['number'] < $incoming['number']) continue;
        $upd->execute([register_build_entry_no($parts, $parts['number'] + 1), (int) $row['id']]);
        $shifted++;
    }
    return $shifted;
}

// ---------------------------------------------------------------------
// VALIDATION HELPERS
// ---------------------------------------------------------------------

/**
 * Validate a Philippine phone number format.
 * Accepts formats: 09XXXXXXXXX, +639XXXXXXXXX, 09XX-XXX-XXXX
 * Returns true if the number matches a valid pattern.
 */
function is_valid_phone_number(string $phone): bool
{
    // Remove all non-digit characters except leading +
    $phone = preg_replace('/[^\d+]/', '', $phone);
    
    // Check patterns: 09XXXXXXXXX (11 digits) or +639XXXXXXXXX (13 digits with +)
    if (preg_match('/^09\d{9}$/', $phone)) {
        return true;
    }
    if (preg_match('/^\+639\d{9}$/', $phone)) {
        return true;
    }
    return false;
}

/**
 * Check if a date string is in the future (after today).
 * Used to validate preferred dates for sacraments.
 * Returns true if the date is today or in the future.
 */
function is_future_date(string $date): bool
{
    try {
        $inputDate = new DateTime($date);
        $today = new DateTime('today');
        return $inputDate >= $today;
    } catch (Exception $e) {
        return false; // invalid date format
    }
}

/**
 * Read all secretary-editable site content settings (Admin > Website >
 * Public Page Content) in one query, merged over sane defaults. Falls back
 * to the defaults when the table does not exist yet (pre-migration DB), so
 * public pages never break.
 *
 * @param PDO   $pdo      Database connection
 * @param array $defaults key => default value map
 * @return array key => resolved value (never empty string when default exists)
 */
function site_settings_all(PDO $pdo, array $defaults): array
{
    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $rows = []; // table not migrated yet
    }
    $out = [];
    foreach ($defaults as $key => $default) {
        $value = isset($rows[$key]) ? trim((string) $rows[$key]) : '';
        $out[$key] = ($value === '') ? $default : $value;
    }
    return $out;
}

// ---------------------------------------------------------------------
// SECURITY HELPERS
// ---------------------------------------------------------------------

/**
 * Best-effort client IP for rate limiting and audit context.
 * Deliberately ignores X-Forwarded-For and similar headers — those are
 * trivially spoofable by the client. REMOTE_ADDR is what the web server
 * actually saw, which is the only trustworthy value here.
 */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Validate password strength for staff accounts.
 * Returns NULL when the password is acceptable, otherwise a human-readable
 * reason it was rejected (shown in the accounts dialog).
 *
 * Policy: at least 12 characters with an uppercase letter, a lowercase
 * letter, and a digit. Symbol use is encouraged but not required.
 */
function validate_password_strength(string $password): ?string
{
    if (strlen($password) < 12) {
        return 'Passwords must be at least 12 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Passwords must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Passwords must include at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Passwords must include at least one number.';
    }
    return null;
}

/**
 * Send conservative security headers before any page output.
 * Called once at include time (bottom of functions.php) so every page —
 * public and admin — gets them without per-page edits.
 *
 * Content-Security-Policy: allowlists only this site and the FullCalendar
 * CDN (cdnjs.cloudflare.com). 'unsafe-inline' is required by the app's
 * existing inline <script>/<style> blocks (calendar init, inline SVG/JS);
 * it still blocks scripts/styles from any other origin. Tighten by moving
 * the inline scripts to .js files and dropping 'unsafe-inline' later.
 *
 * HTTPS: when APP_FORCE_HTTPS is enabled (config/environment.php), plain
 * HTTP GET/HEAD requests are 301-redirected to HTTPS and a one-year HSTS
 * header is sent. Off by default so the local XAMPP HTTP setup keeps
 * working unchanged.
 */
function security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    // Optional HTTPS enforcement — see config/environment.php.
    if (defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS && PHP_SAPI !== 'cli') {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
        if (!$isHttps && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            // 301 + exit before any DB work or output happens.
            header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
        // Sent only once the connection is (forced to be) HTTPS.
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self'");
}

// Fire-and-forget: applied to every request that loads this file (which is
// every page in the app), before any HTML output has been sent.
security_headers();

// ---------------------------------------------------------------------
// ADMIN NOTIFICATIONS
// ---------------------------------------------------------------------

/**
 * Record an admin notification (best-effort — NEVER throws).
 *
 * Appends a row to the shared `notifications` stream shown in the admin
 * header bell. Deliberately swallows every failure (missing table, DB
 * hiccup, transaction issues) and only writes to the error log: a broken
 * notification must never break the request that triggered it — e.g. a
 * parishioner's certificate submission must still go through.
 *
 * Call sites run inside transactions in some forms; the INSERT simply
 * joins that transaction, so a later rollback also rolls the
 * notification back (correct: no notification for a request that
 * failed to save).
 *
 * @param PDO    $pdo     Database connection
 * @param string $type    One of the notifications.type enum values
 *                        (request_new, application_new, request_ready,
 *                        event_reminder, conflict)
 * @param string $title   Short heading shown in the bell dropdown
 * @param string $message One-line detail (who/what/which tracking no.)
 * @param string $link    APP-RELATIVE admin path the item opens
 *                        (e.g. /admin/requests/queue.php) — the API
 *                        endpoint prepends base_path().
 */
function notify(PDO $pdo, string $type, string $title, string $message, string $link): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (type, title, message, link) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$type, $title, $message, $link]);
        $insertedId = (int) $pdo->lastInsertId();

        // Post-INSERT verification: with non-strict sql_mode, invalid ENUM values
        // are silently truncated to '' instead of throwing. Detect that here.
        $verifyStmt = $pdo->prepare('SELECT type FROM notifications WHERE id = ?');
        $verifyStmt->execute([$insertedId]);
        $stored = $verifyStmt->fetchColumn();

        if ($stored !== $type) {
            // Silent truncation detected: attempted '$type' -> stored '$stored'.
            error_log(
                'SRS-SPPO notify ENUM truncation: attempted type="' . $type .
                '" stored as="' . ($stored === false || $stored === null ? '(empty)' : $stored) .
                '" for notification id ' . $insertedId .
                ' (title: ' . $title . ')'
            );
        }
    } catch (Throwable $e) {
        error_log('SRS-SPPO notify failed (' . $type . '): ' . $e->getMessage());
    }
}

/**
 * Notify the bell dropdown that a sacrament application changed status.
 *
 * Single shared entry point for all sacrament-application transitions so the
 * notification 'type' values stay consistent (see notifications.type ENUM —
 * an unlisted type is silently truncated to '' in this env's non-strict sql_mode).
 *
 * @param PDO    $pdo          Active DB connection.
 * @param string $newStatus    The status just written (must be in the notifications.type ENUM).
 * @param string $sacramentType   'baptismal', 'confirmation', 'first_communion', 'marriage'.
 * @param string $trackingNumber   application tracking_number (from sa.*).
 * @param string $extra        Optional suffix appended after the first sentence.
 */
function notify_sacrament_application_status(
    PDO $pdo,
    string $newStatus,
    string $sacramentType,
    string $trackingNumber,
    string $extra = ''
): void {
    $kind = ucwords(str_replace('_', ' ', $sacramentType));
    $state = ucwords(str_replace('_', ' ', $newStatus));
    notify(
        $pdo,
        'application_' . $newStatus,
        'Application ' . $state,
        "{$kind} application ({$trackingNumber}) is now {$state}" . ($extra !== '' ? '. ' . $extra : ''),
        '/admin/requests/applications-queue.php?status=' . urlencode($newStatus)
    );
}

// ---------------------------------------------------------------------
// CERTIFICATE LIFECYCLE helpers (generation log + request status transitions)
// ---------------------------------------------------------------------
// These operate on the certificate_generation_log lifecycle:
//   generated -> printed -> released
//
// print_status ENUM already includes these values; "printed" is a new
// explicit step (previously nothing ever set it — the workflow jumped
// straight from "generated" to "released"). "Mark Printed" is now a
// first-class action on the generate/preview page.
//
// Superseded logs: when a request is regenerated, the prior generation-log
// row is marked superseded (superseded_at) so only ONE active log exists
// per request at any time. Only the current (non-superseded) log is
// printable or releasable.
//
// Reuse cert_request_transition_valid() in generate.php and history.php
// rather than duplicating status rules inline.

/**
 * Shared status-transition validator for certificate_requests lifecycle.
 * Used by generate.php and history.php so both pages enforce the exact
 * same rules — never hand-code a status comparison in a page.
 *
 * Flow: pending -> under_review -> approved -> ready_for_pickup -> released
 * (printing does NOT change the request status; it only advances the
 *  generation log's print_status generated -> printed).
 *
 * @param string $from Current status value.
 * @param string $to   Target status value.
 * @return bool Whether the transition is permitted.
 */
function cert_request_transition_valid(string $from, string $to): bool
{
    static $map = [
        'pending'          => ['under_review', 'rejected'],
        'under_review'     => ['approved', 'rejected'],
        'approved'         => ['ready_for_pickup'],
        'ready_for_pickup' => ['released'],
        'released'         => [],
        'rejected'         => [],
    ];
    return isset($map[$from]) && in_array($to, $map[$from], true);
}

/**
 * The CURRENT generation log for a certificate request: the most recent
 * row that has not been superseded by a regeneration. Returns null when
 * the request has never been generated (or every row was superseded —
 * impossible in practice since regeneration always creates a new row).
 *
 * @param PDO    $pdo       Active database connection.
 * @param int    $requestId certificate_requests.id.
 * @return array|null       The row, or null when none is current.
 */
function cert_current_generation_log(PDO $pdo, int $requestId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM certificate_generation_log
          WHERE certificate_request_id = ? AND superseded_at IS NULL
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Marks a generation-log row as superseded (Regenerated), recording the
 * regeneration staff member and timestamp.
 *
 * Use this when a new generation log is created for a request that already
 * has an active (non-superseded) log — i.e. the user explicitly chose to
 * regenerate.
 *
 * @param PDO    $pdo      Active database connection.
 * @param int    $logId    certificate_generation_log.id to mark superseded.
 * @param int    $staffId  Staff user who performed the regeneration.
 * @return int             Number of rows affected (1 on success).
 */
function mark_generation_superseded(PDO $pdo, int $logId, int $staffId): int
{
    $stmt = $pdo->prepare(
        'UPDATE certificate_generation_log
         SET superseded_at = NOW(), superseded_by = ?
         WHERE id = ? AND superseded_at IS NULL'
    );
    $stmt->execute([$staffId, $logId]);
    return $stmt->rowCount();
}


/**
 * Marks the current generation log for a request as "printed".
 *
 * Records the staff member and timestamp of the physical-printing
 * confirmation. Only the current (non-superseded) log can be marked
 * printed; the print_status must be 'generated'.
 *
 * @param PDO    $pdo       Active database connection.
 * @param int    $requestId certificate_requests.id.
 * @param int    $staffId   Staff user performing the print confirmation.
 * @return array            ['ok' => true] on success, or
 *                          ['ok' => false, 'reason' => ...] on failure.
 */
function log_certificate_printed(PDO $pdo, int $requestId, int $staffId): array
{
    $current = cert_current_generation_log($pdo, $requestId);
    if (!$current) {
        return ['ok' => false, 'reason' => 'No current generation log for this request.'];
    }
    if ($current['print_status'] !== 'generated') {
        return [
            'ok' => false,
            'reason' => 'This certificate has already been ' . $current['print_status'] . ' (only a "generated" certificate can be marked printed).',
        ];
    }

    $stmt = $pdo->prepare(
        'UPDATE certificate_generation_log
         SET print_status = "printed", printed_by = ?, printed_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$staffId, $current['id']]);
    return ['ok' => true];
}


/**
 * Releases a certificate for pickup, recording claimant verification.
 *
 * Before releasing, verifies:
 *   - the certificate_requests.status is currently 'ready_for_pickup'
 *     (using cert_request_transition_valid)
 *   - the generation log being released is the current, non-superseded one
 *     for that request
 *
 * All updates (generation log, request status, audit log, notification)
 * run in a single DB transaction so they can't partially apply.
 *
 * @param PDO    $pdo            Active database connection.
 * @param int    $requestId      certificate_requests.id.
 * @param int    $logId          certificate_generation_log.id to release.
 * @param int    $staffId        Staff user performing the release.
 * @param string $claimantName   Name of the person picking up the certificate.
 * @param string $claimantRelation Relationship to the subject (e.g. "Parent", "Self").
 * @param string $claimantNotes   Optional notes (e.g. "Left at parish office").
 * @param bool   $idVerified     Whether valid ID was verified in person at the counter.
 * @return array                 ['ok' => true] on success, or
 *                               ['ok' => false, 'reason' => ...] on failure.
 */
function log_certificate_released(
    PDO $pdo,
    int $requestId,
    int $logId,
    int $staffId,
    string $claimantName,
    string $claimantRelation,
    string $claimantNotes,
    bool $idVerified
): array {
    // --- Validate the request status transition -------------------------
    $requestStmt = $pdo->prepare(
        'SELECT status FROM certificate_requests WHERE id = ?'
    );
    $requestStmt->execute([$requestId]);
    $requestRow = $requestStmt->fetch();
    if (!$requestRow) {
        return ['ok' => false, 'reason' => 'Request not found.'];
    }
    if (!cert_request_transition_valid($requestRow['status'], 'released')) {
        return [
            'ok' => false,
            'reason' => "Cannot release: certificate request status is '{$requestRow['status']}' (expected 'ready_for_pickup').",
        ];
    }

    // --- Validate the generation log is current & not superseded --------
    $current = cert_current_generation_log($pdo, $requestId);
    if (!$current) {
        return ['ok' => false, 'reason' => 'No current generation log for this request.'];
    }
    if ((int) $current['id'] !== $logId) {
        return [
            'ok' => false,
            'reason' => 'The generation log being released is not the current active log for this request (it may have been superseded by a regeneration).',
        ];
    }
    if ($current['print_status'] !== 'printed') {
        return [
            'ok' => false,
            'reason' => 'This certificate has already been released (or is not yet marked printed).',
        ];
    }

    // --- Apply within a transaction ------------------------------------
    try {
        $pdo->beginTransaction();

        // Mark the generation log as released.
        $releaseStmt = $pdo->prepare(
            'UPDATE certificate_generation_log
             SET print_status = "released", pickup_date = NOW()
             WHERE id = ?'
        );
        $releaseStmt->execute([$logId]);

        // Flip the request status to released.
        $reqUp = $pdo->prepare(
            'UPDATE certificate_requests
             SET status = "released", date_processed = NOW(), processed_by = ?
             WHERE id = ?'
        );
        $reqUp->execute([$staffId, $requestId]);

        // Audit-log the release with the claimant verification details.
        $releaseNote = sprintf(
            'Certificate released to %s (%s)%s. ID verified in person: %s.',
            $claimantName,
            $claimantRelation,
            $claimantNotes !== '' ? ' Notes: ' . $claimantNotes : '',
            $idVerified ? 'Yes' : 'No'
        );
        log_activity(
            $pdo,
            $staffId,
            'certificate_released',
            'certificate_requests',
            $requestId,
            $releaseNote
        );

        // Notify relevant parties.
        $kind = ucwords(str_replace('_', ' ', (string) ($requestRow['sacrament_type'] ?? ''))) . ' Certificate';
        notify(
            $pdo,
            'certificate_picked_up',
            'Certificate picked up',
            $kind . ' picked up by ' . $claimantName,
            '/admin/requests/queue.php?status=released'
        );

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Certificate release failed: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'An error occurred while releasing the certificate. Please try again.'];
    }
}

/* SACRAMENT APPLICATION TRANSITIONS + APPROVAL (own map, not merged). */

function sacrament_application_status_flow(): array
{
    $common = ['submitted', 'documents_pending', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'];
    return [
        'baptismal'       => $common,
        'confirmation'    => $common,
        'first_communion' => $common,
        'marriage'        => ['submitted', 'documents_pending', 'interview_scheduled', 'banns_in_progress', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'],
    ];
}

function sacrament_application_transition_valid(string $from, string $to, ?string $sacramentType = null): bool
{
    if (in_array($from, ['completed', 'rejected'], true) && $from !== $to) {
        return false;
    }
    $all = ['submitted', 'documents_pending', 'interview_scheduled', 'banns_in_progress', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected'];
    if (!in_array($to, $all, true)) {
        return false;
    }
    if ($sacramentType !== null) {
        $flow = sacrament_application_status_flow();
        $stages = $flow[$sacramentType] ?? $all;
        if (!in_array($to, $stages, true) && $from !== $to) {
            return false;
        }
    }
    if ($to === 'rejected') {
        return true;
    }
    if ($from === $to) {
        return true;
    }
    $pipe = ['submitted', 'documents_pending', 'interview_scheduled', 'banns_in_progress', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed'];
    $fp = array_search($from, $pipe, true);
    $tp = array_search($to, $pipe, true);
    if ($fp === false || $tp === false) {
        return false;
    }
    return $tp > $fp;
}

function can_approve_sacrament_application(PDO $pdo, int $applicationId, string $sacramentType): array
{
    $missing = [];
    if ($applicationId <= 0) {
        return ['ok' => false, 'missing' => ['Application not found.']];
    }
    if ($sacramentType === 'marriage') {
        $stmt = $pdo->prepare('SELECT * FROM marriage_applications WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        $ext = $stmt->fetch();
        if (!$ext) {
            return ['ok' => false, 'missing' => ['Marriage application details not found.']];
        }
        if (empty($ext['canonical_interview_completed'])) {
            $missing[] = 'Canonical (pastoral) interview not completed.';
        }
        if (empty($ext['pre_cana_seminar_completed'])) {
            $missing[] = 'Pre-Cana / marriage seminar not completed.';
        }
        if (empty($ext['banns_date_1']) || empty($ext['banns_date_2']) || empty($ext['banns_date_3'])) {
            $missing[] = 'Banns must be posted on all three dates (Tawag sa Kasal).';
        }
        if (($ext['groom_baptismal_cert_status'] ?? '') !== 'submitted') {
            $missing[] = "Groom's baptismal certificate not submitted.";
        }
        if (($ext['bride_baptismal_cert_status'] ?? '') !== 'submitted') {
            $missing[] = "Bride's baptismal certificate not submitted.";
        }
        if (($ext['groom_confirmation_cert_status'] ?? '') !== 'submitted') {
            $missing[] = "Groom's confirmation certificate not submitted.";
        }
        if (($ext['bride_confirmation_cert_status'] ?? '') !== 'submitted') {
            $missing[] = "Bride's confirmation certificate not submitted.";
        }
        return ['ok' => $missing === [], 'missing' => $missing];
    }
    if ($sacramentType === 'baptismal') {
        $stmt = $pdo->prepare('SELECT * FROM baptismal_applications WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        $ext = $stmt->fetch();
        if (!$ext) {
            return ['ok' => false, 'missing' => ['Baptismal application details not found.']];
        }
        if (empty($ext['documents_verified'])) {
            $missing[] = 'Required documents not yet verified.';
        }
        if (empty($ext['seminar_attended'])) {
            $missing[] = 'Parents/godparents seminar not yet completed.';
        }
        $gpStmt = $pdo->prepare("SELECT godparent_type, COUNT(*) AS c FROM baptismal_application_godparents WHERE baptismal_application_id = ? GROUP BY godparent_type");
        $gpStmt->execute([(int) $ext['id']]);
        $counts = ['godfather' => 0, 'godmother' => 0];
        foreach ($gpStmt->fetchAll() as $row) {
            $counts[$row['godparent_type']] = (int) $row['c'];
        }
        if ($counts['godfather'] < 1) {
            $missing[] = 'At least one godfather (ninong) must be recorded.';
        }
        if ($counts['godmother'] < 1) {
            $missing[] = 'At least one godmother (ninang) must be recorded.';
        }
        return ['ok' => $missing === [], 'missing' => $missing];
    }
    if ($sacramentType === 'confirmation' || $sacramentType === 'first_communion') {
        $table = $sacramentType === 'confirmation' ? 'confirmation_applications' : 'first_communion_applications';
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE application_id = ?");
        $stmt->execute([$applicationId]);
        $ext = $stmt->fetch();
        if (!$ext) {
            return ['ok' => false, 'missing' => ['Application details not found.']];
        }
        if (($ext['baptismal_cert_status'] ?? '') !== 'submitted') {
            $missing[] = 'Baptismal certificate not submitted (baptism is required first).';
        }
        if (empty($ext['seminar_attended'])) {
            $missing[] = 'Seminar not yet completed.';
        }
        return ['ok' => $missing === [], 'missing' => $missing];
    }
    return ['ok' => false, 'missing' => ['Unknown sacrament type.']];
}