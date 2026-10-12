<?php
/**
 * File: admin/assignments.php
 * Stage: 4 - link caregivers to households (admin only).
 *
 * Rules (Stage 1 FR-13..15): many caregivers per household, one PRIMARY;
 * ending preserves history; re-assigning an ended pair REACTIVATES it
 * (the UNIQUE pair key forbids a second row). Primary switches run in a
 * TRANSACTION so two primaries can never exist half-way through.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('admin', '../login.php');

/* Dropdown sources: active households + active caregivers only. */
$households = db()->query(
    "SELECT id, full_name FROM elderly_profiles WHERE status = 'active' ORDER BY full_name ASC"
)->fetchAll();
$caregivers = db()->query(
    "SELECT id, full_name FROM users WHERE status = 'active' AND role = 'caregiver' ORDER BY full_name ASC"
)->fetchAll();
$householdIds = array_map(fn($h) => (string) $h['id'], $households);
$caregiverIds = array_map(fn($c) => (string) $c['id'], $caregivers);

/* --- POST actions ------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? '';
    // Keep the list filters after redirect (better UX, still PRG).
    $back = 'assignments.php?elderly_id=' . urlencode($_POST['f_elderly'] ?? '')
          . '&caregiver_id=' . urlencode($_POST['f_caregiver'] ?? '');

    try {
        if ($action === 'assign') {
            $eid = trim($_POST['elderly_id'] ?? '');
            $cid = trim($_POST['caregiver_id'] ?? '');
            $primary = isset($_POST['is_primary']) ? 1 : 0;
            if (!in_array($eid, $householdIds, true) || !in_array($cid, $caregiverIds, true)) {
                throw new RuntimeException('Choose an active household and an active caregiver.');
            }
            db()->beginTransaction();
            $existing = db()->prepare(
                'SELECT id, status FROM caregiver_assignments
                 WHERE elderly_id = :e AND caregiver_id = :c'
            );
            $existing->execute(['e' => $eid, 'c' => $cid]);
            $row = $existing->fetch();
            if ($row && $row['status'] === 'active') {
                throw new RuntimeException('That caregiver is already assigned to this household.');
            }
            if ($primary === 1) { // new primary demotes the old one atomically
                db()->prepare(
                    'UPDATE caregiver_assignments SET is_primary = 0 WHERE elderly_id = :e'
                )->execute(['e' => $eid]);
            }
            if ($row) { // ended pair -> reactivate the SAME row (unique key)
                db()->prepare(
                    "UPDATE caregiver_assignments
                     SET status = 'active', unassigned_at = NULL, is_primary = :p,
                         assigned_by = :by, assigned_at = NOW()
                     WHERE id = :id"
                )->execute(['p' => $primary, 'by' => $user['id'], 'id' => $row['id']]);
                audit('reactivate_assignment', 'caregiver_assignments', (int) $row['id']);
                flash('success', 'Assignment reactivated.');
            } else {
                db()->prepare(
                    'INSERT INTO caregiver_assignments
                     (elderly_id, caregiver_id, is_primary, status, assigned_by)
                     VALUES (:e, :c, :p, \'active\', :by)'
                )->execute(['e' => $eid, 'c' => $cid, 'p' => $primary, 'by' => $user['id']]);
                audit('create_assignment', 'caregiver_assignments', (int) db()->lastInsertId());
                flash('success', 'Caregiver assigned.');
            }
            db()->commit();
        } elseif (in_array($action, ['set_primary', 'end', 'reactivate'], true)) {
            $aid = (int) ($_POST['id'] ?? 0);
            $cur = db()->prepare('SELECT * FROM caregiver_assignments WHERE id = :id');
            $cur->execute(['id' => $aid]);
            $row = $cur->fetch();
            if (!$row) {
                throw new RuntimeException('Assignment not found.');
            }
            db()->beginTransaction();
            if ($action === 'set_primary') {
                if ($row['status'] !== 'active') {
                    throw new RuntimeException('Only an active assignment can be primary.');
                }
                db()->prepare(
                    'UPDATE caregiver_assignments SET is_primary = 0 WHERE elderly_id = :e'
                )->execute(['e' => $row['elderly_id']]);
                db()->prepare(
                    'UPDATE caregiver_assignments SET is_primary = 1 WHERE id = :id'
                )->execute(['id' => $aid]);
                audit('set_primary_assignment', 'caregiver_assignments', $aid);
                flash('success', 'Primary caregiver updated.');
            } elseif ($action === 'end') {
                db()->prepare(
                    "UPDATE caregiver_assignments
                     SET status = 'ended', unassigned_at = NOW() WHERE id = :id"
                )->execute(['id' => $aid]);
                audit('end_assignment', 'caregiver_assignments', $aid);
                flash('success', 'Assignment ended (history kept).');
            } else { // reactivate
                // A reactivated PRIMARY would clash if another primary exists:
                // keep it primary only when no other active primary remains.
                $otherPrimary = db()->prepare(
                    'SELECT id FROM caregiver_assignments
                     WHERE elderly_id = :e AND status = \'active\' AND is_primary = 1 AND id <> :id'
                );
                $otherPrimary->execute(['e' => $row['elderly_id'], 'id' => $aid]);
                $keepPrimary = ((int) $row['is_primary'] === 1 && !$otherPrimary->fetch()) ? 1 : 0;
                db()->prepare(
                    'UPDATE caregiver_assignments
                     SET status = \'active\', unassigned_at = NULL, is_primary = :p WHERE id = :id'
                )->execute(['p' => $keepPrimary, 'id' => $aid]);
                audit('reactivate_assignment', 'caregiver_assignments', $aid);
                flash('success', $keepPrimary ? 'Assignment reactivated (as primary).'
                                              : 'Assignment reactivated (as secondary - another primary exists).');
            }
            db()->commit();
        }
    } catch (RuntimeException $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('danger', $e->getMessage());
    }
    redirect($back);
}

/* --- GET filters + list -------------------------------------------------- */
$fElderly   = trim($_GET['elderly_id'] ?? '');
$fCaregiver = trim($_GET['caregiver_id'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

$where  = [];
$params = [];
// Filters accept any numeric id (history may reference archived
// households); the NEW-assignment dropdowns stay active-only.
if ($fElderly !== '' && ctype_digit($fElderly)) {
    $where[] = 'ca.elderly_id = :e';
    $params['e'] = $fElderly;
}
if ($fCaregiver !== '' && ctype_digit($fCaregiver)) {
    $where[] = 'ca.caregiver_id = :c';
    $params['c'] = $fCaregiver;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM caregiver_assignments ca {$whereSql}");
$countStmt->execute($params);
$pg = pager((int) $countStmt->fetchColumn(), 10, $page);

$listStmt = db()->prepare(
    "SELECT ca.id, ca.is_primary, ca.status, ca.assigned_at, ca.unassigned_at,
            ep.full_name AS household, u.full_name AS caregiver
     FROM caregiver_assignments ca
     JOIN elderly_profiles ep ON ep.id = ca.elderly_id
     JOIN users u ON u.id = ca.caregiver_id
     {$whereSql}
     ORDER BY ep.full_name ASC, ca.status ASC, ca.is_primary DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue('limit', $pg['limit'], PDO::PARAM_INT);
$listStmt->bindValue('offset', $pg['offset'], PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$pageTitle  = 'Caregiver assignments';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Caregiver assignments</h1>

<div class="card mb-4"><div class="card-header">New assignment</div>
<div class="card-body">
    <?php if (!$households || !$caregivers): ?>
        <p class="mb-0 text-muted">You need at least one active household and one
            active caregiver before assigning.</p>
    <?php else: ?>
    <form method="post" action="assignments.php" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="f_elderly" value="<?= e($fElderly) ?>">
        <input type="hidden" name="f_caregiver" value="<?= e($fCaregiver) ?>">
        <div class="col-md-4">
            <label class="form-label" for="elderly_id">Household</label>
            <select class="form-select" id="elderly_id" name="elderly_id" required>
                <?php foreach ($households as $h): ?>
                    <option value="<?= (int) $h['id'] ?>"><?= e($h['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="caregiver_id">Caregiver</label>
            <select class="form-select" id="caregiver_id" name="caregiver_id" required>
                <?php foreach ($caregivers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="is_primary" name="is_primary" value="1">
                <label class="form-check-label" for="is_primary">Primary</label>
            </div>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary w-100" type="submit">Assign</button>
        </div>
    </form>
    <?php endif; ?>
</div></div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-5">
        <select class="form-select" name="elderly_id">
            <option value="">All households</option>
            <?php foreach ($households as $h): ?>
                <option value="<?= (int) $h['id'] ?>" <?= $fElderly === (string) $h['id'] ? 'selected' : '' ?>><?= e($h['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-5">
        <select class="form-select" name="caregiver_id">
            <option value="">All caregivers</option>
            <?php foreach ($caregivers as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $fCaregiver === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
</form>

<?php if (!$rows): ?>
    <div class="alert alert-info">No assignments found.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead><tr><th>Household</th><th>Caregiver</th><th>Primary</th><th>Status</th><th>Since</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['household']) ?></td>
            <td><?= e($r['caregiver']) ?></td>
            <td><?= (int) $r['is_primary'] === 1 ? '<span class="badge bg-primary">primary</span>' : '—' ?></td>
            <td><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e($r['status']) ?></span></td>
            <td><?= e($r['assigned_at']) ?></td>
            <td class="text-nowrap">
                <form method="post" action="assignments.php" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="f_elderly" value="<?= e($fElderly) ?>">
                    <input type="hidden" name="f_caregiver" value="<?= e($fCaregiver) ?>">
                    <?php if ($r['status'] === 'active'): ?>
                        <?php if ((int) $r['is_primary'] !== 1): ?>
                            <input type="hidden" name="action" value="set_primary">
                            <button class="btn btn-sm btn-outline-primary" type="submit">Make primary</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="end">
                            <button class="btn btn-sm btn-outline-danger" type="submit"
                                    data-confirm="End the PRIMARY assignment of <?= e($r['caregiver']) ?>?">End</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <input type="hidden" name="action" value="reactivate">
                        <button class="btn btn-sm btn-outline-success" type="submit">Reactivate</button>
                    <?php endif; ?>
                </form>
                <?php if ($r['status'] === 'active' && (int) $r['is_primary'] !== 1): ?>
                <form method="post" action="assignments.php" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="action" value="end">
                    <input type="hidden" name="f_elderly" value="<?= e($fElderly) ?>">
                    <input type="hidden" name="f_caregiver" value="<?= e($fCaregiver) ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit"
                            data-confirm="End this assignment? History is kept.">End</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?= pager_links('assignments.php', ['elderly_id' => $fElderly, 'caregiver_id' => $fCaregiver], $pg['page'], $pg['pages']) ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
