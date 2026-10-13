<?php
/**
 * File: account.php
 * Stage: 8 - "My account" page for every role (FR-46).
 *
 * Shows read-only profile facts; the only mutation is password change, which
 * requires the CURRENT password (stolen-session safety), enforces the same
 * valid_password() policy as registration, regenerates the session id, and
 * writes an audit row.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_login('login.php');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE id = :id');
    $stmt->execute(['id' => (int) $user['id']]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, (string) $row['password_hash'])) {
        $errors[] = 'Your current password is not correct.';
    } elseif ($new !== $confirm) {
        $errors[] = 'The new passwords do not match.';
    } elseif (($pwError = valid_password($new)) !== null) {
        $errors[] = $pwError;
    } elseif (password_verify($new, (string) $row['password_hash'])) {
        $errors[] = 'The new password must differ from the current one.';
    } else {
        $upd = db()->prepare(
            'UPDATE users SET password_hash = :h WHERE id = :id');
        $upd->execute([
            'h' => password_hash($new, PASSWORD_DEFAULT),
            'id' => (int) $user['id'],
        ]);
        session_regenerate_id(true); // steal the session -> it stops working
        audit('password_changed', 'users', (int) $user['id'], 'Changed via My account page.');
        flash('success', 'Password changed. Please use it next time you log in.');
        redirect('account.php');
    }
}

/* Profile facts (read-only). header.php renders the flash after redirect. */
$stmt = db()->prepare(
    'SELECT full_name, email, role, phone, created_at FROM users WHERE id = :id');
$stmt->execute(['id' => (int) $user['id']]);
$profile = $stmt->fetch();

$pageTitle  = 'My account';
$rootPrefix = '';
require_once __DIR__ . '/includes/header.php';
?>
<h1>My account</h1>

<?php foreach ($errors as $eMsg): ?>
    <div class="alert alert-danger"><?= e($eMsg) ?></div>
<?php endforeach; ?>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card"><div class="card-header">Profile</div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= e($profile['full_name']) ?></dd>
                <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($profile['email']) ?></dd>
                <dt class="col-sm-4">Role</dt><dd class="col-sm-8"><?= e(ucfirst($profile['role'])) ?></dd>
                <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?= e($profile['phone'] ?? '—') ?></dd>
                <dt class="col-sm-4">Member since</dt><dd class="col-sm-8"><?= e(substr((string) $profile['created_at'], 0, 10)) ?></dd>
            </dl>
        </div></div>
    </div>
    <div class="col-md-7">
        <div class="card"><div class="card-header">Change password</div>
        <div class="card-body">
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="pw-cur">Current password</label>
                    <input class="form-control" type="password" id="pw-cur" name="current_password"
                           required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="pw-new">New password</label>
                    <input class="form-control" type="password" id="pw-new" name="new_password"
                           required autocomplete="new-password"
                           aria-describedby="pw-help">
                    <div class="form-text" id="pw-help">Same policy as registration:
                        minimum 8 characters with at least one letter and one number.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="pw-confirm">Confirm new password</label>
                    <input class="form-control" type="password" id="pw-confirm" name="confirm_password"
                           required autocomplete="new-password">
                </div>
                <button class="btn btn-primary" type="submit">Change password</button>
            </form>
        </div></div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
