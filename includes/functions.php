<?php
/**
 * File: includes/functions.php
 * Stage: 3 (Authentication and roles)
 *
 * Shared helpers used by EVERY page. Include order at the top of each page:
 *
 *   require_once __DIR__ . '/../includes/functions.php'; // (path varies by folder)
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_once __DIR__ . '/../includes/csrf.php';
 *
 * functions.php pulls in config/database.php itself, so $pdo / db() are
 * available everywhere after this one include.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/* ---------------------------------------------------------------------
 * e() - escape output. Use on EVERY value printed into HTML that came
 * from the database, the session, or user input. This is what stops
 * cross-site scripting (XSS): <script> becomes harmless text.
 * ------------------------------------------------------------------- */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ---------------------------------------------------------------------
 * redirect() - safe internal redirect. MUST be called before ANY output
 * (even a blank line before <?php breaks it - "headers already sent").
 * ------------------------------------------------------------------- */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/* ---------------------------------------------------------------------
 * Flash messages - one-time notices ("Logged in", "Invalid password")
 * stored in the session, shown once by header.php, then cleared.
 * ------------------------------------------------------------------- */
function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

/** Returns all pending flashes and clears them. Types: success/danger/warning/info. */
function flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $pending = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $pending;
}

/* ---------------------------------------------------------------------
 * audit() - append one row to audit_logs. Call AFTER important actions:
 * logins, profile/user changes, alert acknowledge/escalate/resolve, etc.
 * NEVER pass passwords, hashes or tokens in $details.
 * ------------------------------------------------------------------- */
function audit(string $action, ?string $entityType = null, int|string|null $entityId = null, ?string $details = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip)'
    );
    $stmt->execute([
        'user_id'     => $_SESSION['user_id'] ?? null, // null = system/cron action
        'action'      => $action,
        'entity_type' => $entityType,
        'entity_id'   => $entityId !== null ? (string) $entityId : null,
        'details'     => $details,
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? 'cli',
    ]);
}

/* ---------------------------------------------------------------------
 * is_safe_next() - open-redirect guard for ?next= return URLs.
 * Only internal absolute paths ('/elderly-welfare-system/...') pass;
 * 'https://evil.example' or '//evil.example' are rejected.
 * ------------------------------------------------------------------- */
function is_safe_next(mixed $url): bool
{
    return is_string($url)
        && str_starts_with($url, '/')
        && !str_starts_with($url, '//')
        && !str_contains($url, '://');
}
