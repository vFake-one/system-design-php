<?php
/**
 * File: logout.php (PROJECT ROOT)
 * Stage: 3 - destroys the session.
 *
 * Logout is STATE-CHANGING, so the real work happens on POST with a CSRF
 * token (the navbar sends this). A plain GET shows a confirmation button
 * instead - this stops "logout-by-malicious-link" tricks and satisfies
 * the CSRF requirement for every state change.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    if (is_logged_in()) {
        audit('logout', 'users', (int) current_user()['id']);
    }
    logout_user(); // wipes session + cookie, starts a fresh empty session
    flash('success', 'You have been logged out.');
    redirect('login.php');
}

// GET: confirmation page (also shown if a guest opens this URL directly).
$pageTitle  = 'Log out';
$rootPrefix = '';
require_once __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <h1>Log out?</h1>
        <p>Press the button below to end your session securely.</p>
        <form method="post" action="logout.php">
            <?= csrf_field() ?>
            <button class="btn btn-primary" type="submit">Log me out</button>
            <a class="btn btn-secondary" href="index.php">Cancel</a>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
