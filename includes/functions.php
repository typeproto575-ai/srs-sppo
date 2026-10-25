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
require_once __DIR__ . '/mail.php';

/**
 * Generate a tracking number, e.g. SPPO-2026-000123 or SPPO-APP-2026-000123.
 */
function generate_tracking_number(PDO $pdo, string $table = 'certificate_requests'): string
{
    $year = date('Y');
    $prefix = match ($table) {
        'certificate_requests' => 'SPPO-' . $year . '-',
        'sacrament_applications' => 'SPPO-APP-' . $year . '-',
        default => throw new InvalidArgumentException('Unsupported tracking-number table.'),
    };

    // Derive the next number from the current MAX sequence instead of a
    // COUNT, so deleting the newest request of a year never makes the next
    // submission collide with a previously issued (now-deleted) number.
    // Applications have their own prefix so their numbers cannot shadow a
    // certificate request in the public tracking lookup.
    $stmt = $pdo->prepare(
        "SELECT tracking_number FROM {$table} WHERE tracking_number LIKE ? ORDER BY tracking_number DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $next   = 1;
    if ($last !== false && preg_match('/-(\d{6})$/', (string) $last, $m)) {
        $next   = ((int) $m[1]) + 1;
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

/** Return the configured age range for a sacrament. */
function sacrament_eligibility_range(PDO $pdo, string $sacramentType): array
{
    $stmt = $pdo->prepare(
        "SELECT min_age, max_age FROM sacrament_eligibility_settings WHERE sacrament_type = ?"
    );
    $stmt->execute([$sacramentType]);
    $row = $stmt->fetch();

    $minAge = 0;
    $maxAge = null;

    if ($row) {
        $minAge = (int) $row['min_age'];
        $maxAge = $row['max_age'] !== null ? (int) $row['max_age'] : null;
    }

    return ['min_age' => $minAge, 'max_age' => $maxAge];
}

/** Return a review-friendly age status without blocking the application. */
function sacrament_age_status(PDO $pdo, string $sacramentType, int $age): string
{
    $range = sacrament_eligibility_range($pdo, $sacramentType);
    if ($age < $range['min_age']) return 'Not yet age-eligible';
    if ($range['max_age'] !== null && $age > $range['max_age']) return 'Outside configured range - secretary review';
    return 'Age-eligible';
}

/** Check whether an age falls outside a sacrament's configured range. */
function is_outside_eligibility(PDO $pdo, string $sacramentType, int $age): bool
{
    $status = sacrament_age_status($pdo, $sacramentType, $age);

    return $status !== 'Age-eligible';
}

/**
 * Write an entry to the audit log.
 */
function log_activity(PDO $pdo, ?int $userId, string $action, ?string $targetTable = null, ?int $targetId = null, ?string $description = null, ?string $reason = null): void
{
    $role = null;
    if (function_exists('current_user') && current_user()) {
        $role = current_user()['role'] ?? null;
    }
    $ip = client_ip();
    $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null;
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, role_snapshot, action, target_table, target_id, description, ip_address, user_agent, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$userId, $role, $action, $targetTable, $targetId, $description, $ip, $agent, $reason]);
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

/* =====================================================================
 * PUBLIC SUBMISSION RATE LIMITING (abuse guard for the no-login forms)
 * =====================================================================
 * The four certificate-request forms and the two sacrament-application
 * forms are open to anyone, so without a cap a single person or script can
 * bury the secretary's queue in junk. Two caps are enforced, both over a
 * ROLLING 24-hour window (NOW() - INTERVAL 1 DAY) rather than calendar day,
 * which avoids timezone/edge-of-midnight surprises:
 *
 *   - 3 successful submissions per email address
 *   - 20 successful submissions per client IP
 *
 * Only SUCCESSFUL submissions are counted (recorded just before commit),
 * so a visitor who re-submits a form with validation errors never burns
 * quota, and a failed INSERT leaves no trace.
 *
 * This is spam/abuse prevention, NOT an eligibility rule. It never blocks
 * anyone permanently and never overrides the "flag, don't auto-reject"
 * sacrament-eligibility policy — it only asks the visitor to try again
 * tomorrow or come to the parish office.
 *
 * Marriage applications carry two addresses (groom + bride); a cap breach
 * on EITHER party blocks the submission, which closes the "submit three as
 * the groom, three more as the bride" loophole.
 *
 * Fail-open by design: if the tracking table is missing (migration 018 not
 * yet applied), the helpers allow the submission rather than break every
 * public form. See migrations/018_add_submission_rate_limits.sql.
 * ===================================================================== */

// Tunable caps — change here only; every public form reads these constants.
define('PUBLIC_SUBMISSIONS_PER_EMAIL_PER_DAY', 5);
define('PUBLIC_SUBMISSIONS_PER_IP_PER_DAY', 20);

/**
 * Canonical form of a submitter address for rate-limit comparison.
 * Trim + lowercase, so "Ana@Gmail.com " and "ana@gmail.com" count as one
 * person instead of two.
 */
function normalize_submission_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Would this submission breach the public abuse caps?
 *
 * @param PDO         $pdo    Database connection
 * @param string[]    $emails Submitter address(es) — marriage passes both parties
 * @param string|null $ip     Client IP; defaults to client_ip()
 * @return array{ok: bool, reason: string|null} ok=false with a visitor-facing
 *         reason when a cap is already used up.
 */
function public_submission_allowed(PDO $pdo, array $emails, ?string $ip = null): array
{
    $ip = $ip ?? client_ip();

    // Distinct, non-empty, normalized addresses (marriage may send two).
    $lookup = [];
    foreach ($emails as $email) {
        $email = normalize_submission_email((string) $email);
        if ($email !== '') {
            $lookup[$email] = true;
        }
    }

    try {
        foreach (array_keys($lookup) as $email) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS c FROM submission_rate_limits
                  WHERE email = ? AND submitted_at > DATE_SUB(NOW(), INTERVAL 1 DAY)'
            );
            $stmt->execute([$email]);
            if ((int) $stmt->fetch()['c'] >= PUBLIC_SUBMISSIONS_PER_EMAIL_PER_DAY) {
                return [
                    'ok'     => false,
                    'reason' => 'This email address has already reached the daily submission limit ('
                        . PUBLIC_SUBMISSIONS_PER_EMAIL_PER_DAY . ' per day). '
                        . 'Please try again tomorrow, or visit the parish office to follow up in person.',
                ];
            }
        }

        // 'unknown' comes from client_ip() when REMOTE_ADDR is unavailable
        // (CLI/odd setups) — counting it would lump unrelated visitors
        // together, so it is skipped.
        if ($ip !== '' && $ip !== 'unknown') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS c FROM submission_rate_limits
                  WHERE ip_address = ? AND form_type <> \'record_search\' AND form_type NOT LIKE \'lookup%\'
                    AND submitted_at > DATE_SUB(NOW(), INTERVAL 1 DAY)'
            );
            $stmt->execute([$ip]);
            if ((int) $stmt->fetch()['c'] >= PUBLIC_SUBMISSIONS_PER_IP_PER_DAY) {
                return [
                    'ok'     => false,
                    'reason' => 'Too many submissions have been made from this network today. '
                        . 'Please try again tomorrow, or visit the parish office to submit in person.',
                ];
            }
        }
    } catch (Throwable $e) {
        // Table not migrated yet (or other transient DB issue): let the
        // submission through rather than take the public forms offline.
        return ['ok' => true, 'reason' => null];
    }

    return ['ok' => true, 'reason' => null];
}

/**
 * Record a SUCCESSFUL public submission for both caps.
 * Call this inside the caller's transaction, immediately before commit, so
 * a rolled-back submission never consumes quota.
 *
 * @param PDO         $pdo      Database connection
 * @param string[]    $emails   Submitter address(es) — marriage passes both parties
 * @param string      $formType Short label for diagnostics, e.g. 'certificate_baptismal'
 * @param string|null $ip       Client IP; defaults to client_ip()
 */
function record_public_submission(PDO $pdo, array $emails, string $formType, ?string $ip = null): void
{
    $ip = $ip ?? client_ip();

    $lookup = [];
    foreach ($emails as $email) {
        $email = normalize_submission_email((string) $email);
        if ($email !== '') {
            $lookup[$email] = true;
        }
    }
    if (empty($lookup)) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO submission_rate_limits (email, ip_address, form_type) VALUES (?, ?, ?)'
        );
        foreach (array_keys($lookup) as $email) {
            $stmt->execute([$email, $ip, $formType]);
        }
    } catch (Throwable $e) {
        // Never let bookkeeping failure abort a legitimate submission.
        error_log('SRS-SPPO: could not record submission rate limit: ' . $e->getMessage());
    }
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
 * Public base URL of this installation, e.g. 'http://localhost/srs-sppo'.
 * Read from the SRS_APP_URL environment variable with the config/environment.php
 * fallback. Returns '' when unset.
 */
function app_url(): string
{
    $env = getenv('SRS_APP_URL');
    if ($env !== false && trim((string) $env) !== '') {
        return rtrim(trim((string) $env), '/');
    }
    if (defined('SRS_APP_URL') && trim((string) SRS_APP_URL) !== '') {
        return rtrim(trim((string) SRS_APP_URL), '/');
    }
    return '';
}

/**
 * Validate a browser-supplied "return" URL, so a page can send the user back
 * to the list they actually came from instead of a hardcoded default.
 *
 * Only app-root-relative paths under /admin are accepted, with an optional
 * query string. That keeps the value a safe redirect target: no absolute URLs,
 * no protocol-relative "//host" forms, nothing outside the admin area, and no
 * ".." traversal segments in the path -- a ".." inside the query string is
 * allowed, as it is inert data and is never joined onto a path.
 * Returns null when the value is absent or fails the check, so callers fall
 * back to their own default. The result is already app-relative -- do not run
 * it through base_path() again at the point of use.
 */
function safe_internal_return_url(?string $return): ?string
{
    $value = trim((string) ($return ?? ''));
    if ($value === '') {
        return null;
    }

    if (preg_match('#^/admin(?:/[A-Za-z0-9._-]+)+\.php(?:\?.*)?$#', $value) !== 1) {
        return null;
    }

    // The character class above allows dots, so path segments like ".." slip past
    // it and could climb out of /admin ("/admin/../../evil.php"). Only the path is
    // ever joined onto base_path(), so test just that part -- a ".." in the query
    // string (a search term, say) is inert data. parse_url() leaves dot segments
    // unresolved, so the traversal above is still caught.
    if (strpos((string) parse_url($value, PHP_URL_PATH), '..') !== false) {
        return null;
    }

    return $value;
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
 *   - Secure       : enabled when HTTPS is active or SRS_FORCE_HTTPS is on
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
        'secure'   => is_https_request() || (defined('SRS_FORCE_HTTPS') && SRS_FORCE_HTTPS),
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
 *   - register_next_logbook_numbers() suggests the next Entry No. and
 *     cascades Book/Page/Line: Line runs 1..REGISTER_LINES_PER_PAGE
 *     within a page; past that the Page increments (1..REGISTER_PAGES_PER_BOOK
 *     per book); past the last page the Book increments and is shown in
 *     Roman numerals ("I", "II", ...). Rows that do not fit the scheme
 *     (legacy/demo data) trigger a fresh "I / 1 / 1" suggestion. The
 *     suggestion is only a pre-fill — dialog fields stay editable.
 *   - register_shift_entry_numbers() makes room for an entry inserted in
 *     the middle of a sequence by bumping every later entry with the same
 *     prefix up by one — it MUST run inside the caller's transaction so
 *     the shift lands together with the new row.
 */

// Physical capacity of one register book: 10 lines per page, 500 pages per book.
define('REGISTER_LINES_PER_PAGE', 10);
define('REGISTER_PAGES_PER_BOOK', 500);

/** Integer -> Roman numeral (1..3999); empty string outside that range. */
function register_roman_numeral(int $number): string
{
    if ($number < 1 || $number > 3999) return '';
    $pairs = [
        1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C',
        90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX',
        5 => 'V', 4 => 'IV', 1 => 'I',
    ];
    $out = '';
    foreach ($pairs as $value => $glyph) {
        while ($number >= $value) { $out .= $glyph; $number -= $value; }
    }
    return $out;
}

/** Roman numeral -> integer ("I" -> 1); null when not a valid Roman numeral. */
function register_roman_to_int(string $roman): ?int
{
    $roman = strtoupper(trim($roman));
    if ($roman === '' || !preg_match('/^[IVXLCDM]+$/', $roman)) return null;
    $values = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
    $total = 0;
    $len = strlen($roman);
    for ($i = 0; $i < $len; $i++) {
        $v = $values[$roman[$i]];
        $next = $i + 1 < $len ? $values[$roman[$i + 1]] : 0;
        $total += ($v < $next) ? -$v : $v; // subtractive pair (IV, IX, XL, ...)
    }
    return $total;
}

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
 * Suggest the next logbook numbers from the most recently encoded row,
 * cascading Line -> Page -> Book across the physical limits:
 *   Line 1..REGISTER_LINES_PER_PAGE (overflow: next page, line 1)
 *   Page 1..REGISTER_PAGES_PER_BOOK (overflow: next book in Roman numerals, page 1)
 * When the last row does not fit the scheme (legacy/demo data) the
 * suggestion restarts at Book "I", Page 1, Line 1. Suggestions only —
 * the encode dialog fields remain fully editable.
 *
 * @param string $table Register table name — page-authored, never user input.
 */
function register_next_logbook_numbers(PDO $pdo, string $table, bool $lockRows = false): array
{
    $lock = $lockRows ? ' FOR UPDATE' : '';
    $row = $pdo->query("SELECT entry_no, book_no, page_no, line_no FROM {$table} ORDER BY id DESC LIMIT 1{$lock}")->fetch();
    if (!$row) return ['entry_no' => '', 'book_no' => 'I', 'page_no' => '1', 'line_no' => '1'];

    $entryNo = '';
    $parts = register_parse_entry_no((string) ($row['entry_no'] ?? ''));
    if ($parts) $entryNo = register_build_entry_no($parts, $parts['number'] + 1);

    // --- Book/Page/Line cascade ------------------------------------------
    $bookRaw = trim((string) ($row['book_no'] ?? ''));
    $pageRaw = trim((string) ($row['page_no'] ?? ''));
    $lineRaw = trim((string) ($row['line_no'] ?? ''));

    $bookInt = register_roman_to_int($bookRaw);
    if ($bookInt === null && ctype_digit($bookRaw)) $bookInt = (int) $bookRaw;

    if ($bookInt === null || !ctype_digit($pageRaw) || !ctype_digit($lineRaw)
        || (int) $pageRaw < 1 || (int) $lineRaw < 1) {
        // Legacy/nonconforming numbering (e.g. "B-1" / "17" / "9") — start fresh.
        return ['entry_no' => $entryNo, 'book_no' => 'I', 'page_no' => '1', 'line_no' => '1'];
    }

    $page = (int) $pageRaw;
    $line = (int) $lineRaw + 1;
    if ($line > REGISTER_LINES_PER_PAGE) { $line = 1; $page++; }
    if ($page > REGISTER_PAGES_PER_BOOK) { $page = 1; $bookInt++; }

    return [
        'entry_no' => $entryNo,
        'book_no'  => register_roman_numeral($bookInt),
        'page_no'  => (string) $page,
        'line_no'  => (string) $line,
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

function structured_name_parts(string $fullName): array
{
    $tokens = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
    $suffix = '';
    if ($tokens && in_array(strtolower(end($tokens)), ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'], true)) {
        $suffix = array_pop($tokens);
    }
    $first = array_shift($tokens) ?? '';
    $last = array_pop($tokens) ?? '';
    return [$first, implode(' ', $tokens), $last, $suffix];
}

function compose_structured_name(string $first, string $middle, string $last, string $suffix = ''): string
{
    return trim(implode(' ', array_filter([trim($first), trim($middle), trim($last), trim($suffix)])));
}

/**
 * Validate a "full name" free-text field.
 *
 * The parish form requires the complete first, middle and last name spelled
 * out — initials (e.g. "J.", "D") or a two-part name are rejected because the
 * certificate/register needs the exact full name.
 *
 * Returns NULL when acceptable, otherwise a human-readable error message.
 * Empty strings return NULL (the field's own `required` rule handles that).
 */
function validate_full_name(string $name): ?string
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $parts = preg_split('/\s+/', $name);
    $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));
    if (count($parts) < 3) {
        return 'Please enter the full name (First Name Middle Name Last Name).';
    }

    foreach ($parts as $part) {
        if (preg_match('/^[A-Za-z]\.?$/', $part)) {
            return 'Please spell out every name in full — initials (e.g. "J.", "D") are not allowed.';
        }
    }

    return null;
}

function display_name_with_middle_initial(string $fullName): string
{
    $fullName = trim((string) $fullName);
    if ($fullName === '') {
        return '';
    }

    $tokens = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
    if (count($tokens) === 0) {
        return $fullName;
    }

    $suffix = '';
    if (count($tokens) > 1 && in_array(strtolower((string) end($tokens)), ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'], true)) {
        $suffix = array_pop($tokens);
    }

    $first = array_shift($tokens) ?? '';
    $last = array_pop($tokens) ?? '';
    $middle = implode(' ', $tokens);

    if ($first === '' && $last === '') {
        return $fullName;
    }

    $formatted = trim(implode(' ', array_filter([
        $first,
        $middle !== '' ? ((function_exists('mb_substr') ? mb_substr($middle, 0, 1) : substr($middle, 0, 1))) . '.' : '',
        $last,
    ])));

    if ($suffix !== '') {
        $formatted .= ' ' . $suffix;
    }

    return $formatted !== '' ? $formatted : $fullName;
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

/* =====================================================================
 * LOOKUP / GUESSING THROTTLE (tracking lookup, certificate verification,
 * correction form)
 * =====================================================================
 * Failed or repeated lookups are counted in submission_rate_limits with
 * form_type 'lookup_*' and a HASHED key in the email column, so:
 *   - nothing personal (tracking|email, plain IP lists) is written to
 *     audit_logs, and the audit trail cannot be flooded by outsiders;
 *   - these rows never count toward the 3-per-email / 20-per-IP daily
 *     SUBMISSION caps above;
 *   - the existing retention purge deletes them after
 *     SRS_RETENTION_RATELIMIT_DAYS.
 * Fail-open: if the table is missing, lookups still work.
 * ===================================================================== */

/** Hashed throttle key. Not reversible and never a real email address. */
function lookup_throttle_key(string $key): string
{
    return 'lookup:' . substr(hash('sha256', strtolower($key)), 0, 40);
}

/**
 * Is this visitor (or this key) over the limit for the given lookup form?
 * @param string $form         e.g. 'lookup_track', 'lookup_verify', 'lookup_correction'
 * @param string $key          Optional identifier such as "tracking|email"; '' = IP only
 * @param int    $maxPerKey    Attempts allowed per key in the window (ignored when $key is '')
 * @param int    $maxPerIp     Attempts allowed per client IP in the window
 */
function lookup_is_throttled(PDO $pdo, string $form, string $key = '', int $maxPerKey = 8, int $maxPerIp = 20, int $windowMinutes = 15): bool
{
    try {
        if ($key !== '') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM submission_rate_limits
                  WHERE form_type = ? AND email = ?
                    AND submitted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
            );
            $stmt->execute([$form, lookup_throttle_key($key), $windowMinutes]);
            if ((int) $stmt->fetchColumn() >= $maxPerKey) {
                return true;
            }
        }
        $ip = client_ip();
        if ($ip !== '' && $ip !== 'unknown') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM submission_rate_limits
                  WHERE form_type = ? AND ip_address = ?
                    AND submitted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
            );
            $stmt->execute([$form, $ip, $windowMinutes]);
            if ((int) $stmt->fetchColumn() >= $maxPerIp) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false; // fail open
    }
    return false;
}

/** Count one attempt (call for failures, or for every attempt on forms that send data). */
function lookup_record_attempt(PDO $pdo, string $form, string $key = ''): void
{
    $ip = client_ip();
    try {
        $stmt = $pdo->prepare('INSERT INTO submission_rate_limits (email, ip_address, form_type) VALUES (?, ?, ?)');
        $stmt->execute([lookup_throttle_key($key !== '' ? $key : 'ip:' . $ip), substr($ip, 0, 45), $form]);
    } catch (Throwable $e) {
        error_log('SRS-SPPO: could not record lookup attempt: ' . $e->getMessage());
    }
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
 * HTTPS: SRS_FORCE_HTTPS is enabled automatically for non-local deployments
 * (or explicitly with SRS_FORCE_HTTPS=true). Plain HTTP requests are
 * redirected to HTTPS and a one-year HSTS header is sent. Local XAMPP HTTP
 * remains available unless explicitly forced.
 */
function security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    // Optional HTTPS enforcement — see config/environment.php.
    if (defined('SRS_FORCE_HTTPS') && SRS_FORCE_HTTPS && PHP_SAPI !== 'cli') {
        $isHttps = is_https_request();
        if (!$isHttps) {
            // Preserve the request method while redirecting before any DB work or output.
            $secureHost = parse_url(SRS_APP_URL, PHP_URL_HOST);
            $securePort = parse_url(SRS_APP_URL, PHP_URL_PORT);
            $secureAuthority = $secureHost;
            if ($securePort !== null && (int) $securePort !== 443) {
                $secureAuthority .= ':' . (int) $securePort;
            }
            header('Location: https://' . $secureAuthority . ($_SERVER['REQUEST_URI'] ?? '/'), true, 307);
            exit;
        }
        // Sent only once the connection is HTTPS.
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

function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
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

    // Applications may have one email (Baptism, Confirmation, First Communion)
    // or two emails (Marriage). Delivery is best-effort and is recorded in the
    // email delivery log; a mail failure must never block the status change.
    $emails = [];
    try {
        if ($sacramentType === 'marriage') {
            $stmt = $pdo->prepare('SELECT ma.application_id, groom_email, bride_email FROM marriage_applications ma JOIN sacrament_applications sa ON sa.id = ma.application_id WHERE sa.tracking_number = ?');
            $stmt->execute([$trackingNumber]);
            $row = $stmt->fetch() ?: [];
            $emails = [$row['groom_email'] ?? '', $row['bride_email'] ?? ''];
            $applicationId = (int) ($row['application_id'] ?? 0);
        } else {
            $table = [
                'baptismal' => 'baptismal_applications',
                'confirmation' => 'confirmation_applications',
                'first_communion' => 'first_communion_applications',
            ][$sacramentType] ?? null;
            if ($table) {
                $stmt = $pdo->prepare("SELECT a.application_id, email FROM {$table} a JOIN sacrament_applications sa ON sa.id = a.application_id WHERE sa.tracking_number = ?");
                $stmt->execute([$trackingNumber]);
                $row = $stmt->fetch() ?: [];
                $emails = [($row['email'] ?? '')];
                $applicationId = (int) ($row['application_id'] ?? 0);
            }
        }
        $kind .= ' Application';
        foreach (array_unique(array_filter($emails)) as $email) {
            mail_status_update($email, $trackingNumber, $state, $kind, $pdo, 'sacrament_application', $applicationId ?? null);
        }
    } catch (Throwable $e) {
        error_log('SRS-SPPO application status email failed: ' . $e->getMessage());
    }
}

/** Send a certificate status alert and record the attempt in email_delivery_log. */
function notify_certificate_requester_status(PDO $pdo, int $requestId, string $status): void
{
    try {
        $stmt = $pdo->prepare('SELECT requester_email, tracking_number, sacrament_type FROM certificate_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();
        if (!$row) return;
        $label = ucwords(str_replace('_', ' ', $status));
        $kind = ucwords(str_replace('_', ' ', $row['sacrament_type'])) . ' Certificate';
        mail_status_update($row['requester_email'], $row['tracking_number'], $label, $kind, $pdo, 'certificate_request', $requestId);
    } catch (Throwable $e) {
        error_log('SRS-SPPO certificate status email failed: ' . $e->getMessage());
    }
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
// Regeneration is an IN-PLACE refresh: the existing generation-log row for a
// request is updated rather than superseded-and-replaced, so exactly ONE log
// row exists per request and superseded_at is never set by the application.
// The superseded_at IS NULL guards below therefore always pass; they are kept
// as a cheap invariant check rather than as live workflow state.
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
        'pending'                    => ['under_review', 'awaiting_payment', 'requirements_pending', 'awaiting_onsite_verification', 'rejected', 'cancelled'],
        'submitted'                 => ['under_review', 'awaiting_payment', 'requirements_pending', 'awaiting_onsite_verification', 'rejected', 'cancelled'],
        'under_review'              => ['awaiting_payment', 'requirements_pending', 'requirements_incomplete', 'awaiting_onsite_verification', 'approved', 'rejected', 'cancelled'],
        'requirements_pending'      => ['requirements_incomplete', 'awaiting_onsite_verification', 'approved', 'rejected', 'cancelled'],
        'requirements_incomplete'   => ['under_review', 'requirements_pending', 'awaiting_onsite_verification', 'rejected', 'cancelled'],
        'awaiting_onsite_verification' => ['approved', 'rejected', 'cancelled'],
        'awaiting_payment'          => ['under_review', 'requirements_pending', 'requirements_incomplete', 'awaiting_onsite_verification', 'rejected', 'cancelled'],
        'approved'                  => ['processing', 'ready_for_pickup', 'rejected', 'cancelled'],
        'processing'                => ['ready_for_pickup'],
        'ready_for_pickup'          => ['released'],
        'released'                  => [],
        'rejected'                  => [],
        'cancelled'                 => [],
    ];
    return isset($map[$from]) && in_array($to, $map[$from], true);
}

/**
 * Payment gate for ONLINE certificate requests.
 *
 * Online submissions pause at "awaiting payment" once a register match is
 * found. Payment happens outside the system and is recorded by hand
 * (paid_at / paid_by). Until the secretary marks the payment received, the
 * request may not advance into review, approval, processing or pickup.
 * Reject/cancel stay allowed at any time. Onsite rows and rows that never
 * entered "awaiting payment" are never gated.
 *
 * @param array  $req The current certificate_requests row (needs status,
 *                    submission_channel, paid_at).
 * @param string $to  Target status being attempted.
 * @return array      ['ok' => bool, 'reason' => string]
 */
function cert_request_payment_gate(array $req, string $to): array
{
    if (($req['submission_channel'] ?? 'onsite') !== 'online') {
        return ['ok' => true];
    }
    if (($req['status'] ?? '') !== 'awaiting_payment') {
        return ['ok' => true];
    }
    if (!empty($req['paid_at'])) {
        return ['ok' => true];
    }
    if (in_array($to, ['rejected', 'cancelled'], true)) {
        return ['ok' => true];
    }
    return ['ok' => false, 'reason' => 'This online request is awaiting payment. Record the payment (Mark as Paid) before it can move forward.'];
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
         WHERE id = ? AND print_status = "generated" AND superseded_at IS NULL'
    );
    $stmt->execute([$staffId, $current['id']]);
    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'reason' => 'The certificate changed before printing could be recorded. Refresh and try again.'];
    }
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
        'SELECT status, requester_email, tracking_number, sacrament_type FROM certificate_requests WHERE id = ?'
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
    if (empty($current['finalized_at'])) {
        return [
            'ok' => false,
            'reason' => 'This certificate must be finalized as signed and sealed before it can be released.',
        ];
    }

    // --- Apply within a transaction ------------------------------------
    try {
        $pdo->beginTransaction();

        // Mark the generation log as released.
        $releaseStmt = $pdo->prepare(
            'UPDATE certificate_generation_log
             SET print_status = "released",
                 pickup_date = NOW(),
                 claimant_name = ?,
                 claimant_relationship = ?,
                 claimant_notes = ?,
                 id_verified = ?
             WHERE id = ? AND print_status = "printed" AND finalized_at IS NOT NULL AND superseded_at IS NULL'
        );
        $releaseStmt->execute([
            $claimantName,
            $claimantRelation,
            $claimantNotes !== '' ? $claimantNotes : null,
            $idVerified ? 1 : 0,
            $logId,
        ]);
        if ($releaseStmt->rowCount() !== 1) {
            throw new RuntimeException('The certificate changed before release could be recorded. Refresh and try again.');
        }

        // Flip the request status to released.
        $reqUp = $pdo->prepare(
            'UPDATE certificate_requests
             SET status = "released", date_processed = NOW(), processed_by = ?
             WHERE id = ?'
        );
        $reqUp->execute([$staffId, $requestId]);
        if ($reqUp->rowCount() !== 1) {
            throw new RuntimeException('The request changed before release could be recorded. Refresh and try again.');
        }

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
        if (!empty($requestRow['requester_email'])) {
            mail_status_update(
                $requestRow['requester_email'],
                $requestRow['tracking_number'],
                'Released',
                $kind,
                $pdo,
                'certificate_request',
                $requestId
            );
        }
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
    $common = ['submitted', 'awaiting_payment', 'requirements_pending', 'requirements_incomplete', 'awaiting_onsite_verification', 'documents_pending', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected', 'cancelled'];
    return [
        'baptismal'       => $common,
        'confirmation'    => $common,
        'first_communion' => $common,
        'marriage'       => ['submitted', 'awaiting_payment', 'requirements_pending', 'requirements_incomplete', 'awaiting_onsite_verification', 'documents_pending', 'interview_scheduled', 'banns_in_progress', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected', 'cancelled'],
    ];
}

function sacrament_application_transition_valid(string $from, string $to, ?string $sacramentType = null): bool
{
    if (in_array($from, ['completed', 'rejected', 'cancelled'], true) && $from !== $to) {
        return false;
    }
    $all = ['submitted', 'awaiting_payment', 'requirements_pending', 'requirements_incomplete', 'awaiting_onsite_verification', 'documents_pending', 'interview_scheduled', 'banns_in_progress', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed', 'rejected', 'cancelled'];
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
    if (in_array($to, ['rejected', 'cancelled'], true)) {
        return true;
    }
    if ($from === $to) {
        return true;
    }
    $pipe = ['submitted', 'awaiting_payment', 'requirements_pending', 'requirements_incomplete', 'awaiting_onsite_verification', 'documents_pending', 'interview_scheduled', 'banns_in_progress', 'seminar_pending', 'ready_for_approval', 'approved', 'scheduled', 'completed'];
    $fp = array_search($from, $pipe, true);
    $tp = array_search($to, $pipe, true);
    if ($fp === false || $tp === false) {
        return false;
    }
    return $tp > $fp;
}

/**
 * Payment gate for ONLINE sacrament applications.
 *
 * Online applications enter at "awaiting payment". Payment happens outside
 * the system and is recorded by hand (paid_at / paid_by). Until the secretary
 * marks the payment received, an online application stuck at "awaiting payment"
 * may not advance through the workflow. Reject/cancel stay allowed. Onsite
 * rows and rows past "awaiting payment" are never gated.
 *
 * @param array  $app The current sacrament_applications row (needs status,
 *                    submission_channel, paid_at).
 * @param string $to  Target status being attempted.
 * @return array      ['ok' => bool, 'reason' => string]
 */
function sacrament_application_payment_gate(array $app, string $to): array
{
    if (($app['submission_channel'] ?? 'onsite') !== 'online') {
        return ['ok' => true];
    }
    if (($app['status'] ?? '') !== 'awaiting_payment') {
        return ['ok' => true];
    }
    if (!empty($app['paid_at'])) {
        return ['ok' => true];
    }
    if (in_array($to, ['rejected', 'cancelled'], true)) {
        return ['ok' => true];
    }
    return ['ok' => false, 'reason' => 'This online application is awaiting payment. Record the payment (Mark as Paid) before it can move forward.'];
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


/* =====================================================================
 * WORKFLOW REQUIREMENTS CHECKLIST
 * ===================================================================== */

/** Return the default checklist labels for a transaction type. */
function workflow_requirement_templates(string $entityType, string $sacramentType): array
{
    if ($entityType === 'certificate_request') {
        $templates = [
            'baptismal' => [
                'request_details' => 'Request details verified',
                'subject_identity' => 'Subject identity and birth details verified',
                'register_record' => 'Baptismal register record located',
                'payment_received' => 'Payment received (recorded by hand; no amount stored)',
                'supporting_document' => 'Supporting document received and reviewed',
            ],
            'confirmation' => [
                'request_details' => 'Request details verified',
                'subject_identity' => 'Subject identity and birth details verified',
                'register_record' => 'Confirmation register record located',
                'payment_received' => 'Payment received (recorded by hand; no amount stored)',
                'supporting_document' => 'Supporting document received and reviewed',
            ],
            'first_communion' => [
                'request_details' => 'Request details verified',
                'subject_identity' => 'Subject identity and birth details verified',
                'register_record' => 'First Communion register record located',
                'payment_received' => 'Payment received (recorded by hand; no amount stored)',
                'supporting_document' => 'Supporting document received and reviewed',
            ],
            'marriage' => [
                'request_details' => 'Request details verified',
                'party_identity' => 'Parties and marriage details verified',
                'register_record' => 'Marriage register record located',
                'payment_received' => 'Payment received (recorded by hand; no amount stored)',
                'supporting_document' => 'Supporting document received and reviewed',
            ],
        ];
        return $templates[$sacramentType] ?? [];
    }

    return [
        'application_details' => 'Application details verified',
        'requirements' => 'Required documents received and reviewed',
        'payment_received' => 'Payment received (recorded by hand; no amount stored)',
        'parish_review' => 'Parish review requirements completed',
    ];
}

/** Create missing default checklist rows; safe to call on every detail page. */
function ensure_workflow_requirements(PDO $pdo, string $entityType, int $entityId, string $sacramentType): void
{
    try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO workflow_requirements (entity_type, entity_id, requirement_key, requirement_label) VALUES (?, ?, ?, ?)');
        foreach (workflow_requirement_templates($entityType, $sacramentType) as $key => $label) {
            $stmt->execute([$entityType, $entityId, $key, $label]);
        }
    } catch (Throwable $e) {
        // Older installations can continue operating until migration 019 is applied.
    }
}

/** Fetch checklist rows for a transaction. */
function workflow_requirements_for(PDO $pdo, string $entityType, int $entityId): array
{
    try {
        $stmt = $pdo->prepare('SELECT wr.*, u.full_name AS reviewer_name FROM workflow_requirements wr LEFT JOIN users u ON u.id = wr.reviewed_by WHERE wr.entity_type = ? AND wr.entity_id = ? ORDER BY wr.id');
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function workflow_requirement_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

/** Human-facing certificate stages. Legacy values are mapped without changing old data. */
function certificate_timeline_definition(string $status): array
{
    $stages = [
        ['key' => 'received', 'label' => 'Received'],
        ['key' => 'processing', 'label' => 'Processing'],
        ['key' => 'for_printing', 'label' => 'For Printing'],
        ['key' => 'finalizing', 'label' => 'Finalizing'],
        ['key' => 'ready_for_pickup', 'label' => 'Ready for Pickup'],
        ['key' => 'released', 'label' => 'Released'],
    ];
    $map = [
        'pending' => 0, 'submitted' => 0, 'awaiting_payment' => 1, 'under_review' => 1, 'requirements_pending' => 1, 'requirements_incomplete' => 1, 'awaiting_onsite_verification' => 1, 'approved' => 2, 'processing' => 3,
        'for_printing' => 2, 'finalizing' => 3,
        'ready_for_pickup' => 4, 'released' => 5,
        'rejected' => 1, 'correction_needed' => 1, 'unable_to_locate_record' => 1,
    ];
    $index = $map[$status] ?? 0;
    return ['stages' => $stages, 'index' => $index, 'terminal' => in_array($status, ['rejected', 'cancelled'], true)];
}

/**
 * Generate a cryptographically secure UUIDv4 string.
 */
function generate_uuidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// =============================================================================
// CERTIFICATE NAME CASING (display-only -- never mutates stored data)
// =============================================================================
// Names are stored exactly as encoded or typed (mixed/lower/whatever the
// secretary or requester used) so the raw value stays useful for spotting
// records that need cleanup. These two helpers format a COPY of a name for
// printing on an actual certificate, matching the parish's existing
// baptismal-certificate convention: the person(s) being certified in ALL
// CAPS, everyone else (parents, minister, sponsors) in Title Case.
// Used by admin/certificates/generate.php and admin/certificates/baptismal-certificate.php.

/**
 * Title-case a name for certificate printing, keeping common Filipino/Spanish
 * name particles lowercase (e.g. "Juan de la Cruz", not "Juan De La Cruz")
 * and common suffixes in their conventional form ("Jr.", "III").
 * A placeholder dash or empty string is returned unchanged.
 */
function cert_format_title_case(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '—') {
        return $value;
    }

    // Parish naming convention keeps "de/dela/del/..." capitalized as part of
    // the surname (e.g. "Ramon Dela Cruz", "Maria De Guzman") -- only the
    // Spanish conjunction "y" ("and", as in "Garcia y Santos") stays lowercase.
    static $suffixes = ['jr' => 'Jr.', 'sr' => 'Sr.', 'ii' => 'II', 'iii' => 'III', 'iv' => 'IV'];
    static $lowerParticles = ['y'];

    $words = preg_split('/\s+/', $value);
    $out = [];
    foreach ($words as $i => $word) {
        $bare = rtrim($word, '.,');
        $key = function_exists('mb_strtolower') ? mb_strtolower($bare, 'UTF-8') : strtolower($bare);
        if (isset($suffixes[$key])) {
            $out[] = $suffixes[$key];
        } elseif ($i > 0 && in_array($key, $lowerParticles, true)) {
            $out[] = $key;
        } else {
            $out[] = function_exists('mb_convert_case') ? mb_convert_case($word, MB_CASE_TITLE, 'UTF-8') : ucwords(strtolower($word));
        }
    }
    return implode(' ', $out);
}

/**
 * Upper-case a name for certificate printing (the certified person's own
 * name). A placeholder dash or empty string is returned unchanged.
 */
function cert_format_upper_case(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '—') {
        return $value;
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function admin_sort_url(string $url, string $key, string $sort): string
{
    if ($key === 'date') {
        $next = $sort === 'date_desc' ? 'date_asc' : 'date_desc';
    } else {
        $next = $sort === $key ? $key . '_desc' : ($sort === $key . '_desc' ? $key : $key);
    }
    $query = $_GET;
    $query['sort'] = $next;
    $query['page'] = 1;
    return $url . '?' . http_build_query($query);
}

function admin_sort_indicator(string $key, string $sort): string
{
    $direction = null;
    if ($key === 'date') {
        $direction = $sort === 'date_desc' ? 'desc' : ($sort === 'date_asc' ? 'asc' : null);
    } elseif ($sort === $key) {
        $direction = 'asc';
    } elseif ($sort === $key . '_desc') {
        $direction = 'desc';
    }
    if ($direction === 'asc') {
        return ' <span class="sort-indicator" aria-hidden="true">↑</span>';
    }
    if ($direction === 'desc') {
        return ' <span class="sort-indicator" aria-hidden="true">↓</span>';
    }
    return ' <span class="sort-indicator sort-indicator-muted" aria-hidden="true">↕</span>';
}

// =====================================================================
// SCHEDULE CONFLICT HELPERS
// =====================================================================
// Shared by admin/schedule/calendar.php and admin/schedule/booking-conflicts.php
// so "conflict" always means an actual TIME overlap, not just the same date.

// Fallback event length used when a start_time exists but no end_time was
// given (e.g. a wedding application only records a single preferred time).
define('DEFAULT_EVENT_SLOT_MINUTES', 60);

/**
 * Whether two time windows on the same day overlap.
 *
 * A missing start time on either side means "time not yet confirmed" — we
 * can't rule out an overlap, so it's still reported as a possible conflict;
 * callers should distinguish this from a confirmed time clash in the UI.
 */
function event_times_overlap(?string $aStart, ?string $aEnd, ?string $bStart, ?string $bEnd, int $defaultMinutes = DEFAULT_EVENT_SLOT_MINUTES): bool
{
    if ($aStart === null || $bStart === null) {
        return true; // can't confirm either way — flag for manual review
    }
    $aStartTs = strtotime($aStart);
    $bStartTs = strtotime($bStart);
    $aEndTs = $aEnd !== null ? strtotime($aEnd) : $aStartTs + $defaultMinutes * 60;
    $bEndTs = $bEnd !== null ? strtotime($bEnd) : $bStartTs + $defaultMinutes * 60;
    return $aStartTs < $bEndTs && $bStartTs < $aEndTs;
}

/**
 * Format a start/end time pair as "10:00 AM–11:00 AM" (or just the start
 * time when there's no end). Returns '' when there's no start time.
 */
function format_event_time_range(?string $start, ?string $end): string
{
    if ($start === null) return '';
    $label = date('g:i A', strtotime($start));
    if ($end !== null) $label .= '–' . date('g:i A', strtotime($end));
    return $label;
}
