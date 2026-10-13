<?php
/**
 * File: includes/auth.php
 * Stage: 3 (Authentication and roles)
 *
 * The gatekeeper. EVERY protected page starts with:
 *
 *   require_once __DIR__ . '/../includes/functions.php';
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_role('admin');            // or 'caregiver', 'elderly', or an array
 *
 * Nothing below the require_role() line runs unless the check passes.
 * Hiding buttons in the UI is NEVER treated as protection (NFR-01).
 */

declare(strict_types=1);

// Self-sufficient: auth needs db()/redirect()/audit() from functions.php.
require_once __DIR__ . '/functions.php';

/** Idle minutes before an automatic logout (NFR-09). */
const SESSION_TIMEOUT_SECONDS = 1800; // 30 minutes

/** Failed logins before a short lockout (basic rate limit, FR-07). */
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 60;

/* ---------------------------------------------------------------------
 * start_session() - hardened session cookie. Runs automatically when this
 * file is included. Flags: HttpOnly (JS cannot steal it), SameSite=Lax
 * (cross-site POSTs don't carry it), Secure when on HTTPS.
 * ------------------------------------------------------------------- */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,        // cookie dies when the browser closes
        'path'     => '/',
        'secure'   => $secure,  // send only over HTTPS when available
        'httponly' => true,     // invisible to JavaScript (XSS cannot steal it)
        'samesite' => 'Lax',    // not sent with cross-site requests (CSRF help)
    ]);
    session_start();
}

start_session(); // <-- runs on include, so every page gets a session

/* ---------------------------------------------------------------------
 * current_user() - who is logged in? Returns null for guests.
 * Re-checks the database on every call so a SUSPENDED user is locked out
 * immediately, even with a valid session (fail-closed design).
 * ------------------------------------------------------------------- */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    // Idle timeout: too long since last request -> forget the login.
    if (isset($_SESSION['last_activity'])
        && (time() - (int) $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        logout_user();
        return null;
    }
    // Fresh status from the DB (never trust a stale session for "active").
    $stmt = db()->prepare(
        'SELECT id, full_name, email, role, elderly_profile_id, status
         FROM users WHERE id = :id'
    );
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active') {
        logout_user(); // account deleted or suspended mid-session
        return null;
    }
    $_SESSION['last_activity'] = time(); // sliding window: activity extends the session
    return $user;
}

/** True when a valid, active user is logged in. */
function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * require_login() - guests are bounced to login.php (with ?next= so they
 * return here afterwards). Returns the fresh user row for logged-in users.
 * $loginPath is relative: pages in subfolders pass '../login.php'.
 */
function require_login(string $loginPath = 'login.php'): array
{
    $user = current_user();
    if ($user === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        redirect($loginPath . ($next !== '' ? '?next=' . urlencode($next) : ''));
    }
    return $user;
}

/**
 * require_role() - require_login() PLUS a role check.
 *   require_role('admin');                    // admins only
 *   require_role(['admin', 'caregiver']);     // either role
 * On failure: HTTP 403 + friendly page + audit entry. Nothing after this
 * line executes, so direct-URL guessing cannot bypass it.
 */
function require_role(string|array $roles, string $loginPath = 'login.php'): array
{
    $user = require_login($loginPath);
    $allowed = (array) $roles;
    if (!in_array($user['role'], $allowed, true)) {
        try {
            audit('access_denied', null, null,
                'Role ' . $user['role'] . ' attempted ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        } catch (Throwable) {
            // Auditing must never mask the denial itself.
        }
        http_response_code(403);
        // $loginPath tells us where we are: 'login.php' = project root,
        // '../login.php' = one subfolder deep (admin/, caregiver/, elderly/).
        $inRoot    = ($loginPath === 'login.php');
        $assetBase = $inRoot ? 'assets' : '../assets';
        $homeUrl   = $inRoot ? role_home($user['role']) : '../' . role_home($user['role']);
        ?>
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>403 - Not allowed</title>
        <link rel="stylesheet" href="<?= e($assetBase) ?>/css/bootstrap.min.css">
        </head><body class="p-5">
        <div class="container" style="max-width:600px">
          <h1>403 - Not allowed</h1>
          <p>Your account (role: <strong><?= e($user['role']) ?></strong>) may not open this page.</p>
          <p><a class="btn btn-primary" href="<?= e($homeUrl) ?>">Back to my home page</a></p>
        </div></body></html>
        <?php
        exit;
    }
    return $user;
}

/**
 * role_home() - landing page per role (relative to PROJECT ROOT).
 * login.php redirects here after a successful login.
 */
function role_home(string $role): string
{
    return match ($role) {
        'admin'     => 'admin/dashboard.php',
        'caregiver' => 'caregiver/dashboard.php',
        default     => 'elderly/checkin.php', // 'elderly' (person or family)
    };
}

/* ---------------------------------------------------------------------
 * LOGIN / LOGOUT primitives (used by login.php and logout.php)
 * ------------------------------------------------------------------- */

/**
 * attempt_login() - verifies credentials. Returns the user row on success,
 * throws LoginException with a USER-SAFE message on failure (the caller
 * shows it; details go to audit + error log, never the screen).
 */
class LoginException extends Exception {}

function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));

    // --- Basic rate limit (per session; see docs/08 for its limits) ---
    $lock = $_SESSION['login_lock'] ?? ['count' => 0, 'locked_until' => 0];
    if (time() < (int) ($lock['locked_until'] ?? 0)) {
        $wait = (int) $lock['locked_until'] - time();
        throw new LoginException("Too many failed attempts. Please try again in {$wait} seconds.");
    }

    // --- Fetch by email (prepared statement; timing-safe failure below) ---
    $stmt = db()->prepare(
        'SELECT id, full_name, email, password_hash, role, elderly_profile_id, status
         FROM users WHERE email = :email'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    // password_verify() against a DUMMY hash when the email is unknown, so
    // "unknown email" takes as long as "wrong password" (no timing oracle).
    $hashOk = $user && password_verify($password, $user['password_hash']);

    if (!$hashOk) {
        $lock['count'] = (int) ($lock['count'] ?? 0) + 1;
        if ($lock['count'] >= LOGIN_MAX_ATTEMPTS) {
            $lock['locked_until'] = time() + LOGIN_LOCKOUT_SECONDS;
            $lock['count'] = 0;
        }
        $_SESSION['login_lock'] = $lock;
        audit('login_failed', 'users', null, 'Failed login for email: ' . $email);
        // GENERIC message: never reveal whether the email exists (FR-01/NFR-01).
        throw new LoginException('Invalid email or password.');
    }

    if ($user['status'] !== 'active') {
        audit('login_blocked', 'users', (int) $user['id'], 'Suspended account attempted login.');
        throw new LoginException('This account has been suspended. Please contact the administrator.');
    }

    // --- Success: NEW session id (kills session fixation), fresh CSRF token ---
    session_regenerate_id(true); // 'true' deletes the OLD session file
    unset($_SESSION['csrf_token'], $_SESSION['login_lock']);
    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['last_activity'] = time();

    audit('login', 'users', (int) $user['id'], 'Role: ' . $user['role']);
    return $user;
}

/** logout_user() - wipes session data + cookie. logout.php adds the flash + redirect. */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'] ?? '',
            $params['secure'], $params['httponly']);
    }
    session_destroy();
    start_session(); // fresh empty session so flash() still works for "logged out"
}
