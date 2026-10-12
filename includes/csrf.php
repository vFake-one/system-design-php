<?php
/**
 * File: includes/csrf.php
 * Stage: 3 (Authentication and roles)
 *
 * WHAT IS CSRF? Cross-Site Request Forgery: evil-site.example tricks YOUR
 * browser into POSTing to OUR app while you are logged in (e.g. a hidden
 * form that "acknowledges" an alert). The browser would send your session
 * cookie, so the app must demand extra proof the request came from OUR
 * form: a secret per-session TOKEN the evil site cannot guess.
 *
 * Usage in any state-changing form:
 *   <form method="post" ...>
 *     <?= csrf_field() ?>
 *     ...
 *   </form>
 *
 * Usage at the top of any POST handler:
 *   require_valid_csrf();
 */

declare(strict_types=1);

// Self-sufficient: csrf_field() needs e(), require_valid_csrf() needs audit().
require_once __DIR__ . '/functions.php';

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // 64 hex chars, unguessable
    }
    return $_SESSION['csrf_token'];
}

/** Prints: <input type="hidden" name="csrf_token" value="..."> */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Compares the submitted token with the session token using hash_equals()
 * (timing-attack safe). On mismatch: audit + HTTP 419 + stop.
 * The token is rotated at LOGIN (see auth.php), not per request, so
 * multiple open tabs keep working - a deliberate usability decision.
 */
function require_valid_csrf(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $sent = $_POST['csrf_token'] ?? '';
    $real = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || $real === '' || !hash_equals($real, $sent)) {
        try {
            audit('csrf_blocked', null, null, 'Invalid CSRF token on ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        } catch (Throwable) {
            // Auditing must never mask the block itself.
        }
        http_response_code(419); // 419 = authentication timeout / invalid token (common convention)
        exit('Invalid form token. Please go back, reload the page, and try again.');
    }
}
