<?php
/**
 * File: admin/caregivers.php
 * Stage: 4 - CAREGIVER + USER ACCOUNT list (admin only).
 * Tabs filter by role; create/edit live in caregiver_form.php.
 * Suspend/reactivate + one-time temporary password reset happen here (POST).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('admin', '../login.php');

/* --- POST actions ------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $id     = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $back   = 'caregivers.php?role=' . urlencode($_POST['role'] ?? 'caregiver');

    if ($id > 0 && in_array($action, ['suspend', 'reactivate', 'resetpw'], true)) {
        if ($id === (int) $user['id'] && $action !== 'resetpw') {
            // You cannot suspend yourself (you would lock yourself out next click).
            flash('danger', 'You cannot suspend or deactivate your own account.');
            redirect($back);
        }
        if ($action === 'resetpw') {
            $temp = temp_password();
            $stmt = db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
            $stmt->execute(['h' => password_hash($temp, PASSWORD_DEFAULT), 'id' => $id]);
            if ($stmt->rowCount() > 0) {
                // The password itself is NEVER written to audit_logs - only the fact.
                audit('reset_password', 'users', $id, 'Temporary password issued by admin.');
                flash('success', 'Temporary password (show once - copy it now): ' . $temp);
            } else {
                flash('warning', 'Account not found.');
            }
        } else {
            $newStatus = $action === 'suspend' ? 'suspended' : 'active';
            $stmt = db()->prepare('UPDATE users SET status = :st WHERE id = :id');
            $stmt->execute(['st' => $newStatus, 'id' => $id]);
            if ($stmt->rowCount() > 0) {
                audit($action === 'suspend' ? 'suspend_user' : 'reactivate_user', 'users', $id);
                flash('success', $action === 'suspend' ? 'Account suspended.' : 'Account reactivated.');
            } else {
                flash('warning', 'Account not found.');
            }
        }
    }
    redirect($back); // POST-redirect-GET
}

/* --- GET filters ------------------------------------------------------- */
$role = $_GET['role'] ?? 'caregiver';
if (!in_array($role, ['caregiver', 'admin', 'elderly', 'all'], true)) {
    $role = 'caregiver';
}
$q    = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

$where  = [];
$params = [];
if ($role !== 'all') {
    $where[]         = 'u.role = :role';
    $params['role']  = $role;
}
if ($q !== '') {
    $where[]     = '(u.full_name LIKE :q OR u.email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM users u {$whereSql}");
$countStmt->execute($params);
$pg = pager((int) $countStmt->fetchColumn(), 10, $page);

/* Active-assignment count per user (meaningful for caregivers; 0 otherwise). */
$listStmt = db()->prepare(
    "SELECT u.id, u.full_name, u.email, u.phone, u.role, u.status,
            ep.full_name AS linked_profile,
            (SELECT COUNT(*) FROM caregiver_assignments ca
              WHERE ca.caregiver_id = u.id AND ca.status = 'active') AS active_cases
     FROM users u
     LEFT JOIN elderly_profiles ep ON ep.id = u.elderly_profile_id
     {$whereSql}
     ORDER BY u.full_name ASC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue('limit', $pg['limit'], PDO::PARAM_INT);
$listStmt->bindValue('offset', $pg['offset'], PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$pageTitle  = 'Caregivers & accounts';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Caregivers &amp; accounts</h1>
    <a class="btn btn-primary" href="caregiver_form.php">+ New account</a>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['caregiver' => 'Caregivers', 'admin' => 'Admins', 'elderly' => 'Elderly/family logins', 'all' => 'All'] as $val => $label): ?>
        <li class="nav-item">
            <a class="nav-link <?= $role === $val ? 'active' : '' ?>"
               href="caregivers.php?role=<?= e($val) ?>"><?= e($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<form method="get" class="row g-2 mb-3">
    <input type="hidden" name="role" value="<?= e($role) ?>">
    <div class="col-md-10">
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>"
               placeholder="Search name or email…" maxlength="100">
    </div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Search</button></div>
</form>

<?php if (!$rows): ?>
    <div class="alert alert-info">No accounts found.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role / link</th><th>Cases</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['full_name']) ?><?= (int) $r['id'] === (int) $user['id'] ? ' <span class="badge bg-info text-dark">you</span>' : '' ?></td>
            <td><?= e($r['email']) ?></td>
            <td><?= e($r['phone'] ?? '—') ?></td>
            <td><span class="badge bg-primary"><?= e($r['role']) ?></span>
                <?= $r['linked_profile'] ? '<br><small class="text-muted">→ ' . e($r['linked_profile']) . '</small>' : '' ?></td>
            <td><?= $r['role'] === 'caregiver' ? (int) $r['active_cases'] : '—' ?></td>
            <td><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : 'danger' ?>"><?= e($r['status']) ?></span></td>
            <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="caregiver_form.php?id=<?= (int) $r['id'] ?>">Edit</a>
                <form method="post" action="caregivers.php" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="role" value="<?= e($role) ?>">
                    <?php if ($r['status'] === 'active'): ?>
                        <input type="hidden" name="action" value="suspend">
                        <button class="btn btn-sm btn-outline-danger" type="submit"
                                data-confirm="Suspend <?= e($r['full_name']) ?>? They will be logged out.">Suspend</button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="reactivate">
                        <button class="btn btn-sm btn-outline-success" type="submit">Reactivate</button>
                    <?php endif; ?>
                </form>
                <form method="post" action="caregivers.php" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="role" value="<?= e($role) ?>">
                    <input type="hidden" name="action" value="resetpw">
                    <button class="btn btn-sm btn-outline-warning" type="submit"
                            data-confirm="Issue a NEW temporary password for <?= e($r['full_name']) ?>? The old one stops working.">Reset pw</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?= pager_links('caregivers.php', ['role' => $role, 'q' => $q], $pg['page'], $pg['pages']) ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
