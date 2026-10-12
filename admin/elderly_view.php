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

/* Stage 5: schedules + submitted records (one timeline per household). */
$schedStmt = db()->prepare(
    'SELECT cs.*, u.full_name AS caregiver
     FROM checkin_schedules cs
     LEFT JOIN users u ON u.id = cs.caregiver_id
     WHERE cs.elderly_id = :id
     ORDER BY cs.expected_at DESC LIMIT 20'
);
$schedStmt->execute(['id' => $id]);
$schedules = $schedStmt->fetchAll();

$recStmt = db()->prepare(
    'SELECT cr.*, cs.title AS schedule_title, u.full_name AS submitter
     FROM checkin_records cr
     LEFT JOIN checkin_schedules cs ON cs.id = cr.schedule_id
     LEFT JOIN users u ON u.id = cr.submitted_by
     WHERE cr.elderly_id = :id
     ORDER BY cr.checkin_at DESC LIMIT 20'
);
$recStmt->execute(['id' => $id]);
$records = $recStmt->fetchAll();

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

<h2 class="h4 mt-4">Check-in schedules (latest 20)</h2>
<?php if (!$schedules): ?>
    <p class="text-muted">No schedules yet.
    <?php if ($isAdmin): ?><a href="schedule_form.php?elderly_id=<?= $id ?>">Create one</a>.<?php endif; ?></p>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm table-striped align-middle">
    <thead><tr><th>Expected</th><th>Title</th><th>Deadline</th><th>Caregiver</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($schedules as $s): $ds = schedule_display_status($s); ?>
        <tr>
            <td><?= e($s['expected_at']) ?></td>
            <td><?= e($s['title'] ?? '') ?></td>
            <td><?= e(deadline_of($s)) ?></td>
            <td><?= e($s['caregiver'] ?? '—') ?></td>
            <td><span class="badge bg-<?= e(['scheduled' => 'primary', 'overdue' => 'danger', 'completed' => 'success', 'missed' => 'warning', 'cancelled' => 'secondary'][$ds] ?? 'secondary') ?>"><?= e($ds) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<h2 class="h4 mt-4">Submitted check-ins (latest 20)</h2>
<?php if (!$records): ?>
    <p class="text-muted">Nothing submitted yet.</p>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm table-striped align-middle">
    <thead><tr><th>When</th><th>Schedule</th><th>Submitted by</th><th>Via</th><th>Wellbeing</th></tr></thead>
    <tbody>
    <?php foreach ($records as $r): ?>
        <tr>
            <td><?= e($r['checkin_at']) ?></td>
            <td><?= e($r['schedule_title'] ?? '— ad-hoc —') ?></td>
            <td><?= e($r['submitter'] ?? '?') ?> <small class="text-muted">(<?= e($r['submitted_by_role'] ?? '') ?>)</small></td>
            <td><?= e($r['method']) ?></td>
            <td><span class="badge bg-<?= e(['ok' => 'success', 'needs_assistance' => 'warning', 'unwell' => 'danger'][$r['wellbeing_status']] ?? 'secondary') ?>"><?= e($r['wellbeing_status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<p class="mt-3"><a href="<?= $isAdmin ? 'elderly.php' : '../caregiver/households.php' ?>">&larr; Back to list</a></p>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
