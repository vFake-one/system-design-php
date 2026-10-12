<?php
/**
 * File: config/database.example.php (TEMPLATE - copy, do not edit in place)
 *
 * HOW TO USE
 *   1. Copy this file to config/database.php:
 *        Windows: copy config\database.example.php config\database.php
 *        Linux/macOS: cp config/database.example.php config/database.php
 *   2. Edit ONLY the values in section 2 of the copy to match your MySQL.
 *   3. On stock XAMPP the defaults below already work - nothing to change.
 *
 * WHY A TEMPLATE EXISTS
 *   If you ever deploy beyond your own machine, the real database.php
 *   (with its password) stays OUT of git, and only this safe template is
 *   committed. For this classroom project the committed database.php holds
 *   nothing but XAMPP local defaults, which are not a real secret.
 */

declare(strict_types=1);

date_default_timezone_set('Africa/Lagos');   // must match DB_TIMEZONE_OFFSET

const DB_HOST            = '127.0.0.1';      // or 'localhost'
const DB_NAME            = 'elderly_welfare_db';
const DB_USER            = 'root';           // your MySQL username
const DB_PASS            = '';              // your MySQL password ('CHANGE-ME' outside XAMPP!)
const DB_CHARSET         = 'utf8mb4';
const DB_TIMEZONE_OFFSET = '+03:00';         // == Africa/Lagos (no DST)

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec("SET time_zone = '" . DB_TIMEZONE_OFFSET . "'");
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed. Check config/database.php and that MySQL is running.\n");
        exit(1);
    }
    http_response_code(500);
    exit('Database connection failed. Please check the configuration and try again.');
}

function db(): PDO
{
    global $pdo;
    return $pdo;
}
