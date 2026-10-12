<?php
/**
 * File: login.php (PROJECT ROOT)
 * Stage: 3 - login form (GET) + credential check (POST).
 *
 * Security notes (see docs/08 for the full explanation):
 *  - POST requires a CSRF token (require_valid_csrf()).
 *  - Passwords verified with password_verify() against bcrypt hashes.
 *  - Failure message is GENERIC ("Invalid email or password") so attackers
 *    cannot probe which emails exist. Suspended accounts get one clear
 *    message instead (documented decision - staff emails are not secret).
 *  - Basic rate limit: 5 failures -> 60-second lockout (per session).
 *  - Every attempt (success/failure/blocked) is written to audit_logs.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Already logged in? No need to see this form.
if (is_logged_in()) {
    redirect(role_home(current_user()['role']));
}

$error = '';
$email = '';

// ?next= carries the page the guest originally wanted (open-redirect safe).
$next = $_GET['next'] ?? $_POST['next'] ?? '';
if (!is_safe_next($next)) {
    $next = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf(); // 419 + stop on mismatch - before touching credentials

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        try {
            $user = attempt_login($email, $password); // regenerates session id inside
            flash('success', 'Welcome back, ' . $user['full_name'] . '.');
            // $next is an absolute in-app path ('/elderly-welfare-system/...'),
            // valid for Location: as-is. role_home() is root-relative instead.
            redirect($next !== '' ? $next : role_home($user['role']));
        } catch (LoginException $e) {
            $error = $e->getMessage(); // user-safe by construction
        }
    }
}

$pageTitle  = 'Log in';
$rootPrefix = '';
require_once __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <h1 class="mb-3">Log in</h1>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <?= csrf_field() ?>
            <?php if ($next !== ''): ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control" type="email" id="email" name="email"
                       value="<?= e($email) ?>" required autocomplete="username" autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" type="password" id="password" name="password"
                       required autocomplete="current-password">
            </div>
            <button class="btn btn-primary w-100" type="submit">Log in</button>
        </form>

        <div class="card border-info mt-4">
            <div class="card-header bg-info text-dark">Local demo accounts (classroom only)</div>
            <div class="card-body small">
                <ul class="mb-1">
                    <li><code>admin@example.local</code> / <code>Admin123!</code> - administrator</li>
                    <li><code>ruth.caregiver@example.local</code> / <code>Care123!</code> - caregiver</li>
                    <li><code>mary.elderly@example.local</code> / <code>Elder123!</code> - elderly</li>
                </ul>
                <p class="mb-0 text-muted">Remove this box before any real deployment or final
                    submission. Passwords live in the database as hashes only.</p>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
