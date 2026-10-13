<?php
/**
 * File: admin/schedules.php
 * Stage: 5 - check-in SCHEDULE list (admin only).
 * Filters by household / caregiver / status (incl. derived 'overdue').
 * Create + edit live in schedule_form.php (shared with scoped caregivers).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('admin', '../login.php');

$households = db()->query(
    "SELECT id, full_name FROM elderly_profiles WHERE status <> 'archived' ORDER BY full_name ASC"
)->fetchAll();
$caregivers = db()->query(
    "SELECT id, full_name FROM users WHERE role = 'caregiver' ORDER BY full_name ASC"
)->fetchAll();

/* --- GET filters ------------------------------------------------------- */
$fElderly   = trim($_GET['elderly_id'] ?? '');
$fCaregiver = trim($_GET['caregiver_id'] ?? '');
$fStatus    = $_GET['status'] ?? 'open';
if (!in_array($fStatus, ['open', 'all', 'scheduled', 'overdue', 'completed', 'missed', 'cancelled'], true)) {
    $fStatus = 'open';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

/* 'open' = scheduled incl. overdue; 'overdue'/'scheduled' split them via
   the SAME deadline expression the monitor uses (DATE_ADD + grace). */
$where  = [];
$params = [];
if ($fElderly !== '' && ctype_digit($fElderly)) {
    $where[] = 'cs.elderly_id = :e';
    $params['e'] = $fElderly;
}
if ($fCaregiver !== '' && ctype_digit($fCaregiver)) {
    $where[] = 'cs.caregiver_id = :c';
    $params['c'] = $fCaregiver;
}
if ($fStatus === 'open') {
    $where[] = "cs.status = 'scheduled'";
} elseif ($fStatus === 'overdue') {
    $where[] = "cs.status = 'scheduled' AND DATE_ADD(cs.expected_at, INTERVAL cs.grace_minutes MINUTE) < NOW()";
} elseif ($fStatus === 'scheduled') {
    $where[] = "cs.status = 'scheduled' AND DATE_ADD(cs.expected_at, INTERVAL cs.grace_minutes MINUTE) >= NOW()";
} elseif ($fStatus !== 'all') {
    $where[] = 'cs.status = :st';
    $params['st'] = $fStatus;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM checkin_schedules cs {$whereSql}");
$countStmt->execute($params);
$pg = pager((int) $countStmt->fetchColumn(), 10, $page);

$listStmt = db()->prepare(
    "SELECT cs.*, ep.full_name AS household, u.full_name AS caregiver
     FROM checkin_schedules cs
     JOIN elderly_profiles ep ON ep.id = cs.elderly_id
     LEFT JOIN users u ON u.id = cs.caregiver_id
     {$whereSql}
     ORDER BY cs.expected_at DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue('limit', $pg['limit'], PDO::PARAM_INT);
$listStmt->bindValue('offset', $pg['offset'], PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$badge = ['scheduled' => 'primary', 'overdue' => 'danger', 'completed' => 'success',
    'missed' => 'warning', 'cancelled' => 'secondary'];

$pageTitle  = 'Check-in schedules';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Check-in schedules</h1>
    <a class="btn btn-primary" href="schedule_form.php<?= $fElderly !== '' ? '?elderly_id=' . (int) $fElderly : '' ?>">+ New schedule</a>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
        <select class="form-select" name="elderly_id">
            <option value="">All households</option>
            <?php foreach ($households as $h): ?>
                <option value="<?= (int) $h['id'] ?>" <?= $fElderly === (string) $h['id'] ? 'selected' : '' ?>><?= e($h['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select" name="caregiver_id">
            <option value="">All caregivers</option>
            <?php foreach ($caregivers as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $fCaregiver === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select" name="status">
            <?php foreach (['open' => 'Open (scheduled + overdue)', 'scheduled' => 'Scheduled (not overdue)', 'overdue' => 'Overdue', 'completed' => 'Completed', 'missed' => 'Missed', 'cancelled' => 'Cancelled', 'all' => 'All'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= $fStatus === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
</form>

<p class="text-muted"><?= e((string) $pg['total']) ?> schedule(s). Overdue is derived live, never stored.</p>

<?php if (!$rows): ?>
    <div class="alert alert-info">No schedules match these filters.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead><tr><th>Household</th><th>Title</th><th>Expected</th><th>Deadline</th><th>Caregiver</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $ds = schedule_display_status($r); ?>
        <tr>
            <td><?= e($r['household']) ?></td>
            <td><?= e($r['title'] ?? 'Welfare check-in') ?></td>
            <td><?= e($r['expected_at']) ?></td>
            <td><?= e(deadline_of($r)) ?></td>
            <td><?= e($r['caregiver'] ?? '—') ?></td>
            <td><span class="badge bg-<?= e($badge[$ds] ?? 'secondary') ?>"><?= e($ds) ?></span></td>
            <td><?php if ($r['status'] === 'scheduled'): ?>
                <a class="btn btn-sm btn-outline-secondary" href="schedule_form.php?id=<?= (int) $r['id'] ?>">Edit</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-primary" href="schedule_form.php?id=<?= (int) $r['id'] ?>">View</a>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?= pager_links('schedules.php', ['elderly_id' => $fElderly, 'caregiver_id' => $fCaregiver, 'status' => $fStatus], $pg['page'], $pg['pages']) ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
