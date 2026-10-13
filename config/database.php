<?php
/**
 * File: config/database.php
 * Project: AI-Powered Elderly Household Welfare Check-In System
 * Stage: 2 (Environment and database)
 *
 * WHAT THIS FILE DOES
 *   Opens the ONE shared PDO connection to MySQL that every page and the
 *   monitoring script will reuse. Include it with require_once:
 *
 *       require_once __DIR__ . '/config/database.php';   // from project root
 *       require_once __DIR__ . '/../config/database.php'; // from admin/, caregiver/, etc.
 *
 *   then use either the $pdo variable or the db() helper:
 *
 *       $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
 *       $stmt->execute(['id' => $id]);
 *
 * CREDENTIALS
 *   Values below are STOCK XAMPP LOCAL DEFAULTS (root / empty password).
 *   They are safe to keep for classroom use on your own machine.
 *   NEVER put real production secrets in this file, and never commit real
 *   secrets to git. See config/database.example.php for the template.
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------
 * 1. PHP time zone - MUST match the MySQL session zone in section 3.
 *    Africa/Lagos (EAT, UTC+3) was confirmed in Stage 1. East Africa has
 *    no daylight saving, so the +03:00 MySQL offset below always agrees.
 * ------------------------------------------------------------------- */
date_default_timezone_set('Africa/Lagos');

/* ---------------------------------------------------------------------
 * 2. Connection settings - stock XAMPP defaults. Change ONLY if your
 *    MySQL uses a password or a different host (see docs/07, section C).
 * ------------------------------------------------------------------- */
const DB_HOST            = '127.0.0.1';        // 'localhost' also works; IP avoids socket quirks
const DB_NAME            = 'elderly_welfare_db';
const DB_USER            = 'root';             // XAMPP default user
const DB_PASS            = '';                // XAMPP default: root has NO password
const DB_CHARSET         = 'utf8mb4';         // full Unicode (matches database collation)
const DB_TIMEZONE_OFFSET = '+03:00';          // == Africa/Lagos; portable on stock XAMPP

/* ---------------------------------------------------------------------
 * 3. Connect once per request. Every query in later stages goes through
 *    this $pdo object using PREPARED statements (placeholders + bound
 *    values), which is what protects the app from SQL injection.
 * ------------------------------------------------------------------- */
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            // Throw exceptions on error so failures are loud during development
            // (we catch them and show a GENERIC message to users - see below).
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Fetch rows as ['col' => value] arrays by default.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Use REAL server-side prepared statements, not emulated ones.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Keep MySQL date maths (expected_at + grace < NOW()) in the same zone
    // as PHP. Fixed string, no user input -> no injection risk here.
    $pdo->exec("SET time_zone = '" . DB_TIMEZONE_OFFSET . "'");
} catch (PDOException $e) {
    // Log the REAL error privately (XAMPP: C:\xampp\apache\logs\error.log
    // or /opt/lampp/logs/error_log) ...
    error_log('Database connection failed: ' . $e->getMessage());

    // ... but NEVER show database details to the user (an attacker could
    // use hostnames/usernames). CLI (cron) and web need different exits.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed. Check config/database.php and that MySQL is running.\n");
        exit(1);
    }
    http_response_code(500);
    exit('Database connection failed. Please check the configuration and try again.');
}

/**
 * Shared accessor used by later stages. Both styles work:
 *   global $pdo; ...  - or -  $pdo = db();
 */
function db(): PDO
{
    global $pdo;
    return $pdo;
}
