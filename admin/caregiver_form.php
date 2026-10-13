<?php
/**
 * File: admin/caregiver_form.php
 * Stage: 4 - CREATE + EDIT user accounts (admin only).
 * Roles: caregiver (default), admin, elderly (must link exactly one profile).
 * Role is LOCKED after creation (prevents demoting the last admin by
 * accident); password is set here on create, and via Reset on the list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('admin', '../login.php');

$id = (int) ($_GET['id'] ?? 0);
$editing = $id > 0;

$profiles = db()->query(
    "SELECT id, full_name FROM elderly_profiles WHERE status = 'active' ORDER BY full_name ASC"
)->fetchAll();

$form = ['full_name' => '', 'email' => '', 'phone' => '', 'role' => 'caregiver',
    'elderly_profile_id' => '', 'status' => 'active'];
$currentRole = 'caregiver';

if ($editing) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('warning', 'Account not found.');
        redirect('caregivers.php');
    }
    $form['full_name'] = (string) $row['full_name'];
    $form['email']     = (string) $row['email'];
    $form['phone']     = (string) ($row['phone'] ?? '');
    $form['role']      = $row['role'];
    $form['elderly_profile_id'] = (string) ($row['elderly_profile_id'] ?? '');
    $form['status']    = $row['status'];
    $currentRole = $row['role'];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $form['full_name'] = trim($_POST['full_name'] ?? '');
    $form['email']     = strtolower(trim($_POST['email'] ?? ''));
    $form['phone']     = trim($_POST['phone'] ?? '');
    $form['status']    = $_POST['status'] ?? 'active';
    // Role + profile link are editable ONLY on create (locked afterwards).
    if (!$editing) {
        $form['role'] = $_POST['role'] ?? 'caregiver';
        $form['elderly_profile_id'] = trim($_POST['elderly_profile_id'] ?? '');
    }
    $pw1 = $_POST['password'] ?? '';
    $pw2 = $_POST['password2'] ?? '';

    if ($e = valid_required_text($form['full_name'], 2, 100, 'Full name')) {
        $errors['full_name'] = $e;
    }
    if ($e = valid_email($form['email'])) {
        $errors['email'] = $e;
    } else {
        $dup = db()->prepare(
            'SELECT id FROM users WHERE email = :email AND id <> :id'
        );
        $dup->execute(['email' => $form['email'], 'id' => $id]);
        if ($dup->fetch()) {
            $errors['email'] = 'That email is already registered.';
        }
    }
    if ($e = valid_optional_phone($form['phone'])) {
        $errors['phone'] = $e;
    }
    if ($e = valid_enum($form['status'], ['active', 'suspended'], 'Status')) {
        $errors['status'] = $e;
    }
    if ($id === (int) $user['id'] && $form['status'] === 'suspended') {
        $errors['status'] = 'You cannot suspend your own account.';
    }
    if (!$editing) {
        if ($e = valid_enum($form['role'], ['admin', 'caregiver', 'elderly'], 'Role')) {
            $errors['role'] = $e;
        }
        if ($form['role'] === 'elderly') {
            $ok = false;
            foreach ($profiles as $p) {
                if ((string) $p['id'] === $form['elderly_profile_id']) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors['elderly_profile_id'] = 'Choose the active household this login may access.';
            }
        }
        if ($e = valid_password($pw1)) {
            $errors['password'] = $e;
        } elseif ($pw1 !== $pw2) {
            $errors['password'] = 'The two passwords do not match.';
        }
    }

    if (!$errors) {
        if ($editing) {
            db()->prepare(
                'UPDATE users SET full_name = :n, email = :e, phone = :p, status = :s WHERE id = :id'
            )->execute([
                'n' => $form['full_name'], 'e' => $form['email'],
                'p' => $form['phone'] === '' ? null : $form['phone'],
                's' => $form['status'], 'id' => $id,
            ]);
            audit('update_user', 'users', $id, 'Role: ' . $currentRole);
            flash('success', 'Account updated.');
        } else {
            db()->prepare(
                'INSERT INTO users (full_name, email, phone, password_hash, role, elderly_profile_id, status)
                 VALUES (:n, :e, :p, :h, :r, :ep, :s)'
            )->execute([
                'n' => $form['full_name'], 'e' => $form['email'],
                'p' => $form['phone'] === '' ? null : $form['phone'],
                'h' => password_hash($pw1, PASSWORD_DEFAULT),
                'r' => $form['role'],
                'ep' => $form['role'] === 'elderly' ? (int) $form['elderly_profile_id'] : null,
                's' => $form['status'],
            ]);
            $newId = (int) db()->lastInsertId();
            audit('create_user', 'users', $newId, 'Role: ' . $form['role']);
            flash('success', 'Account created.');
        }
        redirect('caregivers.php?role=' . urlencode($editing ? $currentRole : $form['role']));
    }
}

function field_error(?string $msg): string
{
    return $msg ? '<div class="invalid-feedback d-block">' . e($msg) . '</div>' : '';
}

$pageTitle  = $editing ? 'Edit account' : 'New account';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1><?= e($pageTitle) ?></h1>
<form method="post" action="caregiver_form.php<?= $editing ? '?id=' . $id : '' ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="full_name">Full name *</label>
            <input class="form-control" id="full_name" name="full_name" required minlength="2" maxlength="100"
                   value="<?= e($form['full_name']) ?>">
            <?= field_error($errors['full_name'] ?? null) ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="email">Email *</label>
            <input class="form-control" type="email" id="email" name="email" required maxlength="150"
                   value="<?= e($form['email']) ?>">
            <?= field_error($errors['email'] ?? null) ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="phone">Phone</label>
            <input class="form-control" id="phone" name="phone" maxlength="20" value="<?= e($form['phone']) ?>">
            <?= field_error($errors['phone'] ?? null) ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="role">Role <?= $editing ? '(locked)' : '*' ?></label>
            <?php if ($editing): ?>
                <input class="form-control" value="<?= e($currentRole) ?>" disabled>
                <?php if ($currentRole === 'elderly'): ?>
                    <div class="form-text">Linked household cannot be changed here.</div>
                <?php endif; ?>
            <?php else: ?>
                <select class="form-select" id="role" name="role">
                    <?php foreach (['caregiver' => 'Caregiver', 'admin' => 'Administrator', 'elderly' => 'Elderly / family login'] as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $form['role'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors['role'] ?? null) ?>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="suspended" <?= $form['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            </select>
            <?= field_error($errors['status'] ?? null) ?>
        </div>
        <?php if (!$editing): ?>
        <div class="col-md-6" id="profileWrap">
            <label class="form-label" for="elderly_profile_id">Linked household (required for elderly/family logins)</label>
            <select class="form-select" id="elderly_profile_id" name="elderly_profile_id">
                <option value="">— choose —</option>
                <?php foreach ($profiles as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $form['elderly_profile_id'] === (string) $p['id'] ? 'selected' : '' ?>>
                        <?= e($p['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors['elderly_profile_id'] ?? null) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="password">Password *</label>
            <input class="form-control" type="password" id="password" name="password" required
                   minlength="8" autocomplete="new-password">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="password2">Repeat password *</label>
            <input class="form-control" type="password" id="password2" name="password2" required autocomplete="new-password">
        </div>
        <div class="col-12"><?= field_error($errors['password'] ?? null) ?>
            <div class="form-text">Minimum 8 characters with at least one letter and one number.</div>
        </div>
        <?php endif; ?>
    </div>
    <div class="mt-3">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Create account' ?></button>
        <a class="btn btn-secondary" href="caregivers.php">Cancel</a>
    </div>
</form>
<?php if (!$editing): ?>
<script>
// UX only: hide the household picker unless role = elderly (server enforces anyway).
(function () {
    var role = document.getElementById('role'), wrap = document.getElementById('profileWrap');
    function toggle() { wrap.style.display = role.value === 'elderly' ? '' : 'none'; }
    role.addEventListener('change', toggle); toggle();
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
