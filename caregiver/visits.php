<?php
/**
 * File: caregiver/visits.php
 * Stage: 6 - physical-visit log (own households only).
 * A visit may link the alert that prompted it; 'no_answer' outcomes feed
 * the Stage 7 engine as a risk input (for now they are simply recorded).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('caregiver', '../login.php');
$mine = assigned_elderly_ids((int) $user['id']);

$households = [];
$openAlerts = [];
if ($mine) {
    $in = implode(',', array_fill(0, count($mine), '?'));
    $s = db()->prepare("SELECT id, full_name FROM elderly_profiles WHERE id IN ({$in}) AND status = 'active' ORDER BY full_name ASC");
    $s->execute($mine);
    $households = $s->fetchAll();
    $s = db()->prepare(
        "SELECT a.id, a.title, ep.full_name AS household FROM alerts a
         JOIN elderly_profiles ep ON ep.id = a.elderly_id
         WHERE a.elderly_id IN ({$in}) AND a.status IN ('open','acknowledged','escalated')
         ORDER BY a.created_at DESC LIMIT 50"
    );
    $s->execute($mine);
    $openAlerts = $s->fetchAll();
}
$householdIds = array_map(fn($h) => (string) $h['id'], $households);
$alertIds = array_map(fn($a) => (string) $a['id'], $openAlerts);

/* --- POST: log a visit ------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        $eid = trim($_POST['elderly_id'] ?? '');
        $aid = trim($_POST['alert_id'] ?? '');
        $vdate = trim($_POST['visit_date'] ?? '');
        $vtype = trim($_POST['visit_type'] ?? '');
        $outcome = trim($_POST['outcome'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!in_array($eid, $householdIds, true)) {
            throw new RuntimeException('Choose one of your assigned households.');
        }
        if ($aid !== '' && !in_array($aid, $alertIds, true)) {
            throw new RuntimeException('That alert is not yours to link.');
        }
        $vd = DateTime::createFromFormat('Y-m-d\TH:i', $vdate);
        if (!$vd) {
            throw new RuntimeException('Visit date/time is required.');
        }
        if ($vd->format('Y-m-d H:i:s') > date('Y-m-d H:i:s', strtotime('+1 day'))) {
            throw new RuntimeException('Visit date cannot be more than a day in the future.');
        }
        if (!in_array($vtype, ['routine', 'follow_up', 'emergency'], true)) {
            throw new RuntimeException('Choose a valid visit type.');
        }
        if (!in_array($outcome, ['well', 'needs_followup', 'referred', 'no_answer', 'other'], true)) {
            throw new RuntimeException('Choose a valid outcome.');
        }
        if (mb_strlen($notes) > 1000) {
            throw new RuntimeException('Notes must be under 1000 characters.');
        }
        db()->prepare(
            'INSERT INTO caregiver_visits (elderly_id, caregiver_id, alert_id, visit_date, visit_type, outcome, notes)
             VALUES (:e, :c, :a, :d, :t, :o, :n)'
        )->execute([
            'e' => (int) $eid, 'c' => (int) $user['id'],
            'a' => $aid === '' ? null : (int) $aid,
            'd' => $vd->format('Y-m-d H:i:00'), 't' => $vtype, 'o' => $outcome,
            'n' => $notes === '' ? null : $notes,
        ]);
        $vid = (int) db()->lastInsertId();
        audit('log_visit', 'caregiver_visits', $vid, "elderly={$eid} outcome={$outcome}");
        flash('success', 'Visit recorded.');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('visits.php?elderly_id=' . urlencode($_POST['f_elderly'] ?? ''));
}

/* --- GET: filter + paged list -------------------------------------------------- */
$fElderly = trim($_GET['elderly_id'] ?? '');
if ($fElderly !== '' && !in_array($fElderly, $householdIds, true)) {
    $fElderly = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = ['v.caregiver_id = ?'];
$params = [(int) $user['id']];
if ($fElderly !== '') {
    $where[] = 'v.elderly_id = ?';
    $params[] = (int) $fElderly;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$countStmt = db()->prepare("SELECT COUNT(*) FROM caregiver_visits v {$whereSql}");
$countStmt->execute($params);
$pg = pager((int) $countStmt->fetchColumn(), 10, $page);

$listStmt = db()->prepare(
    "SELECT v.*, ep.full_name AS household, a.title AS alert_title
     FROM caregiver_visits v
     JOIN elderly_profiles ep ON ep.id = v.elderly_id
     LEFT JOIN alerts a ON a.id = v.alert_id
     {$whereSql} ORDER BY v.visit_date DESC LIMIT ? OFFSET ?"
);
$listStmt->execute(array_merge($params, [$pg['limit'], $pg['offset']]));
$rows = $listStmt->fetchAll();

$pageTitle  = 'Visits';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Visits</h1>

<div class="card mb-4"><div class="card-header">Log a visit</div><div class="card-body">
    <?php if (!$households): ?>
        <p class="mb-0 text-muted">No households assigned to you yet.</p>
    <?php else: ?>
    <form method="post" action="visits.php" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="f_elderly" value="<?= e($fElderly) ?>">
        <div class="col-md-3">
            <label class="form-label" for="elderly_id">Household *</label>
            <select class="form-select" id="elderly_id" name="elderly_id" required>
                <?php foreach ($households as $h): ?>
                    <option value="<?= (int) $h['id'] ?>" <?= $fElderly === (string) $h['id'] ? 'selected' : '' ?>><?= e($h['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="visit_date">Date &amp; time *</label>
            <input class="form-control" type="datetime-local" id="visit_date" name="visit_date" required
                   value="<?= e(date('Y-m-d\TH:i')) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="visit_type">Type *</label>
            <select class="form-select" id="visit_type" name="visit_type">
                <?php foreach (['routine' => 'Routine', 'follow_up' => 'Follow-up', 'emergency' => 'Emergency'] as $v => $l): ?>
                    <option value="<?= e($v) ?>"><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="outcome">Outcome *</label>
            <select class="form-select" id="outcome" name="outcome">
                <?php foreach (['well' => 'Well', 'needs_followup' => 'Needs follow-up', 'referred' => 'Referred', 'no_answer' => 'No answer', 'other' => 'Other'] as $v => $l): ?>
                    <option value="<?= e($v) ?>"><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="alert_id">Linked alert</label>
            <select class="form-select" id="alert_id" name="alert_id">
                <option value="">— none —</option>
                <?php foreach ($openAlerts as $a): ?>
                    <option value="<?= (int) $a['id'] ?>">#<?= (int) $a['id'] ?> <?= e($a['household']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-10">
            <label class="form-label" for="notes">Notes</label>
            <input class="form-control" id="notes" name="notes" maxlength="1000">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary w-100" type="submit">Log visit</button>
        </div>
    </form>
    <?php endif; ?>
</div></div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-6">
        <select class="form-select" name="elderly_id" onchange="this.form.submit()">
            <option value="">All my households</option>
            <?php foreach ($households as $h): ?>
                <option value="<?= (int) $h['id'] ?>" <?= $fElderly === (string) $h['id'] ? 'selected' : '' ?>><?= e($h['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<?php if (!$rows): ?>
    <p class="text-muted">No visits logged yet.</p>
<?php else: ?>
<div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr><th>Date</th><th>Household</th><th>Type</th><th>Outcome</th><th>Alert</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['visit_date']) ?></td>
            <td><?= e($r['household']) ?></td>
            <td><?= e($r['visit_type']) ?></td>
            <td><span class="badge bg-<?= e($r['outcome'] === 'well' ? 'success' : ($r['outcome'] === 'no_answer' ? 'danger' : 'warning')) ?>"><?= e($r['outcome']) ?></span></td>
            <td><?= $r['alert_title'] ? '#' . (int) $r['alert_id'] . ' ' . e($r['alert_title']) : '—' ?></td>
            <td><?= e($r['notes'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?= pager_links('visits.php', ['elderly_id' => $fElderly], $pg['page'], $pg['pages']) ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
