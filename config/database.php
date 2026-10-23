<?php
/**
 * ============================================================================
 * SRS-SPPO — Schedule and Record System of Saint Patrick's Parish Office
 * ============================================================================
 *
 * FILE: config/database.php
 * ROLE: Shared PDO database connection for the entire application.
 *
 * Every page (public and admin) includes this file to get access
 * to the $pdo variable, which is used for ALL database queries in the system.
 *
 * Technology used: Plain PHP + PDO (PHP Data Objects) + MySQL/MariaDB.
 * No framework — this is hand-written, vanilla PHP.
 *
 * CHARACTERISTICS:
 *   - PDO::ATTR_ERRMODE => EXCEPTION: database errors throw catchable exceptions.
 *   - PDO::ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC: rows come back as associative
 *     arrays keyed by column name (e.g. $row['child_full_name']).
 *   - PDO::ATTR_EMULATE_PREPARES => false: real native prepared statements are used,
 *     which protects against SQL injection for every parameterized query.
 *   - charset=utf8mb4: full Unicode support (handles names with accents, special chars).
 *
 * CONFIGURATION:
 *   $DB_HOST  — MySQL server address (localhost for XAMPP).
 *   $DB_NAME  — Database name to connect to.
 *   $DB_USER  — MySQL username.
 *   $DB_PASS  — MySQL password.
 *   Credentials may come from SRS_DB_USER/SRS_DB_PASS or config/local.php.
 *
 * ERROR HANDLING:
 *   On connection failure, the script dies with the error message.
 *   In production, replace the die() with logging (e.g. error_log()) and a
 *   generic user-facing message — never expose raw DB errors to end users.
 *
 * USAGE IN OTHER FILES:
 *   require_once __DIR__ . '/../config/database.php';
 *   // $pdo is now available globally in that script.
 *
 * @package     SRS-SPPO
 * @subpackage  Config
 * @filesource  config/database.php
 */

$localConfig = [];
$localConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (!is_array($localConfig)) {
        die('Database setup error: config/local.php must return an array.');
    }
}

$DB_HOST = getenv('SRS_DB_HOST');
$DB_HOST = $DB_HOST !== false ? $DB_HOST : ($localConfig['DB_HOST'] ?? 'localhost');
$DB_NAME = getenv('SRS_DB_NAME');
$DB_NAME = $DB_NAME !== false ? $DB_NAME : ($localConfig['DB_NAME'] ?? 'srs_sppo_db');
$DB_USER = getenv('SRS_DB_USER');
$DB_USER = $DB_USER !== false ? $DB_USER : ($localConfig['DB_USER'] ?? null);
$DB_PASS = getenv('SRS_DB_PASS');
$DB_PASS = $DB_PASS !== false ? $DB_PASS : ($localConfig['DB_PASS'] ?? null);
if ($DB_USER === null || $DB_USER === '' || $DB_PASS === null) {
    http_response_code(503);
    die('Database setup error: set SRS_DB_USER and SRS_DB_PASS in the environment or config/local.php.');
}

// ---------------------------------------------------------------------------
// CREATE THE PDO INSTANCE
// ---------------------------------------------------------------------------
// We wrap the connection in a try/catch so that if the DB is unreachable,
// misconfigured, or the credentials are wrong, we handle the failure gracefully
// instead of letting PHP emit a fatal error.
try {
    // Build the PDO connection.
    // The "mysql:host=...;dbname=...;charset=..." string is called the DSN
    // (Data Source Name) and tells PDO what driver to use, where to connect,
    // and which database and charset to use.
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            // Throw an exception on every DB error — lets us use try/catch.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

            // Fetch rows as associative arrays (column_name => value).
            // More readable than numeric-indexed arrays.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // Use real (native) prepared statements, not emulated ones.
            // Emulated prepares interpolate values into the SQL string in PHP,
            // which can be less secure. Native prepares send SQL and data
            // separately to MySQL, making SQL injection impossible for
            // any query that correctly uses placeholders (?)."
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Connection succeeded — $pdo is now ready for queries.
    // No further setup is needed; every page can immediately use $pdo.

} catch (PDOException $e) {
    // -----------------------------------------------------------------------
    // CONNECTION FAILED — handle gracefully.
    // -----------------------------------------------------------------------
    // Log the real error for the developer/sysadmin (goes to Apache error log).
    // Show only a generic message to the visitor — never expose DB details.
    error_log('SRS-SPPO database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('The system is temporarily unavailable. Please try again shortly.');
}

// The $pdo variable is now a fully configured, connected PDO instance
// available to every script that includes this file.
