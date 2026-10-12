<?php
/**
 * File: admin/elderly_view.php
 * Stage: 4 - household PROFILE + assignments (check-in history joins in Stage 5).
 *
 * SHARED page: admins see everything; caregivers see ONLY profiles with an
 * ACTIVE assignment to them (can_access_elderly() - the 🔒 matrix check).
 * Anyone else gets a 403 here, not just a hidden link.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin', 'caregiver'], '../login.php');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT ep.*, sc.full_name AS secondary_name, cb.full_name AS created_by_name
     FROM elderly_profiles ep
     LEFT JOIN users sc ON sc.id = ep.secondary_contact_user_id
     LEFT JOIN users cb ON cb.id = ep.created_by
     WHERE ep.id = :id'
);
$stmt->execute(['id' => $id]);
$profile = $stmt->fetch();

if (!$profile) {
    flash('warning', 'Household not found.');
    redirect($user['role'] === 'admin' ? 'elderly.php' : '../caregiver/households.php');
}

if (!can_access_elderly($user, $id)) {
    audit('access_denied', 'elderly_profiles', $id, 'Role ' . $user['role'] . ' attempted profile view.');
    http_response_code(403);
    $pageTitle  = 'Not allowed';
    $rootPrefix = '../';
    require_once __DIR__ . '/../includes/header.php';
    echo '<h1>403 - Not allowed</h1><p>This household is not assigned to you.</p>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Assignments for this household (active + ended - full history). */
$assignStmt = db()->prepare(
    'SELECT ca.is_primary, ca.status, ca.assigned_at, ca.unassigned_at, u.full_name AS caregiver
     FROM caregiver_assignments ca
     JOIN users u ON u.id = ca.caregiver_id
     WHERE ca.elderly_id = :id
     ORDER BY ca.status ASC, ca.is_primary DESC, u.full_name ASC'
);
$assignStmt->execute(['id' => $id]);
$assignments = $assignStmt->fetchAll();

$isAdmin = $user['role'] === 'admin';
$age = age_of($profile['date_of_birth']);
$statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'archived' => 'dark'];

$pageTitle  = $profile['full_name'];
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0"><?= e($profile['full_name']) ?>
        <span class="badge bg-<?= e($statusBadge[$profile['status']] ?? 'secondary') ?>"><?= e($profile['status']) ?></span>
    </h1>
    <?php if ($isAdmin): ?>
        <a class="btn btn-outline-secondary" href="elderly_form.php?id=<?= $id ?>">Edit</a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card"><div class="card-header">Profile</div>
        <dl class="row card-body mb-0">
            <dt class="col-sm-5">Age</dt>
            <dd class="col-sm-7"><?= $age !== null ? e((string) $age) . ' (' . e((string) $profile['date_of_birth']) . ')' : '—' ?></dd>
            <dt class="col-sm-5">Gender</dt><dd class="col-sm-7"><?= e($profile['gender'] ?? '—') ?></dd>
            <dt class="col-sm-5">Phone</dt><dd class="col-sm-7"><?= e($profile['phone'] ?? '—') ?></dd>
            <dt class="col-sm-5">Address</dt><dd class="col-sm-7"><?= e($profile['address'] ?? '—') ?></dd>
            <dt class="col-sm-5">Living situation</dt><dd class="col-sm-7"><?= e($profile['living_situation'] ?? '—') ?></dd>
            <dt class="col-sm-5">Welfare notes</dt><dd class="col-sm-7"><?= e($profile['welfare_notes'] ?? '—') ?></dd>
        </dl></div>
    </div>
    <div class="col-md-6">
        <div class="card mb-4"><div class="card-header">Emergency &amp; escalation contacts</div>
        <dl class="row card-body mb-0">
            <dt class="col-sm-5">Emergency contact</dt>
            <dd class="col-sm-7"><?= e($profile['emergency_contact_name'] ?? '—') ?>
                <?= $profile['emergency_contact_relationship'] ? '(' . e($profile['emergency_contact_relationship']) . ')' : '' ?></dd>
            <dt class="col-sm-5">Emergency phone</dt><dd class="col-sm-7"><?= e($profile['emergency_contact_phone'] ?? '—') ?></dd>
            <dt class="col-sm-5">Secondary (escalation)</dt><dd class="col-sm-7"><?= e($profile['secondary_name'] ?? '— none —') ?></dd>
            <dt class="col-sm-5">Registered by</dt><dd class="col-sm-7"><?= e($profile['created_by_name'] ?? '—') ?></dd>
        </dl></div>

        <div class="card"><div class="card-header d-flex justify-content-between align-items-center">
            <span>Caregiver assignments</span>
            <?php if ($isAdmin): ?><a class="btn btn-sm btn-outline-primary" href="assignments.php?elderly_id=<?= $id ?>">Manage</a><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!$assignments): ?>
                <p class="mb-0 text-muted">No caregivers assigned yet.
                <?php if ($isAdmin): ?>Use <strong>Manage</strong> to assign one.<?php endif; ?></p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                <?php foreach ($assignments as $a): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= e($a['caregiver']) ?>
                            <?php if ((int) $a['is_primary'] === 1): ?><span class="badge bg-primary">primary</span><?php endif; ?>
                        </span>
                        <span class="badge bg-<?= $a['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e($a['status']) ?></span>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<p class="mt-3"><a href="<?= $isAdmin ? 'elderly.php' : '../caregiver/households.php' ?>">&larr; Back to list</a></p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
