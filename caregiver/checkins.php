<?php
/**
 * File: caregiver/checkins.php
 * Stage: 5 - the caregiver's check-in workspace (own households ONLY):
 *  1. "Needs attention" - overdue scheduled check-ins with inline record forms.
 *  2. "Due & upcoming" - scheduled, not yet overdue, with the same forms.
 *  3. "Record ad-hoc check-in" - contact with no prior schedule.
 *  4. "History" - completed records (filter by household).
 * Every submission funnels through submit_checkin() (functions.php), so the
 * caregiver path and the elderly self-service path obey identical rules.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('caregiver', '../login.php');
$mine = assigned_elderly_ids((int) $user['id']); // active assignments only

$households = [];
if ($mine) {
    $in = implode(',', array_fill(0, count($mine), '?'));
    $stmt = db()->prepare(
        "SELECT id, full_name FROM elderly_profiles WHERE id IN ({$in}) AND status = 'active' ORDER BY full_name ASC"
    );
    $stmt->execute($mine);
    $households = $stmt->fetchAll();
}
$householdIds = array_map(fn($h) => (string) $h['id'], $households);

/* --- POST: record a check-in (scheduled or ad-hoc) ------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        $kind      = $_POST['kind'] ?? '';
        $method    = trim($_POST['method'] ?? '');
        $wellbeing = trim($_POST['wellbeing'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        if (!in_array($method, ['caregiver_entry', 'phone_logged', 'visit_logged'], true)) {
            throw new RuntimeException('Choose how this check-in was captured.');
        }
        if ($kind === 'scheduled') {
            $sid = (int) ($_POST['schedule_id'] ?? 0);
            $s = db()->prepare('SELECT elderly_id FROM checkin_schedules WHERE id = :s');
            $s->execute(['s' => $sid]);
            $eid = $s->fetchColumn();
            if ($eid === false || !in_array((string) $eid, $householdIds, true)) {
                audit('access_denied', 'checkin_schedules', $sid, 'Caregiver recorded foreign schedule.');
                throw new RuntimeException('That schedule is not for your households.');
            }
            $res = submit_checkin((int) $eid, $sid, (int) $user['id'], 'caregiver',
                $method, $wellbeing, $notes === '' ? null : $notes);
        } elseif ($kind === 'adhoc') {
            $eid = trim($_POST['elderly_id'] ?? '');
            if (!in_array($eid, $householdIds, true)) {
                throw new RuntimeException('Choose one of your assigned households.');
            }
            $res = submit_checkin((int) $eid, null, (int) $user['id'], 'caregiver',
                $method, $wellbeing, $notes === '' ? null : $notes);
        } else {
            throw new RuntimeException('Unknown submission type.');
        }
        flash('success', $res['alert_id']
            ? 'Check-in recorded. Assistance alert #' . $res['alert_id'] . ' opened as URGENT.'
            : 'Check-in recorded.');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('checkins.php?elderly_id=' . urlencode($_POST['f_elderly'] ?? ''));
}

/* --- GET: household filter ------------------------------------------------ */
$fElderly = trim($_GET['elderly_id'] ?? '');
if ($fElderly !== '' && !in_array($fElderly, $householdIds, true)) {
    $fElderly = ''; // silently drop forged ids (defence in depth; queries scope anyway)
}

/** Open schedules of mine, optionally one household, oldest first. */
function my_open_schedules(array $mine, string $one): array
{
    if (!$mine) {
        return [];
    }
    $in = implode(',', array_fill(0, count($mine), '?'));
    $sql = "SELECT cs.*, ep.full_name AS household
            FROM checkin_schedules cs
            JOIN elderly_profiles ep ON ep.id = cs.elderly_id
            WHERE cs.status = 'scheduled' AND cs.elderly_id IN ({$in})";
    $args = $mine;
    if ($one !== '') {
        $sql .= ' AND cs.elderly_id = ?';
        $args[] = (int) $one;
    }
    $stmt = db()->prepare($sql . ' ORDER BY cs.expected_at ASC');
    $stmt->execute($args);
    return $stmt->fetchAll();
}

$open = my_open_schedules($mine, $fElderly);
$now = date('Y-m-d H:i:s');
$overdue = array_values(array_filter($open, fn($s) => is_overdue($s, $now)));
$upcoming = array_values(array_filter($open, fn($s) => !is_overdue($s, $now)));

/* History: completed records of mine (paged; ?page= matches pager_links()). */
$hPage = max(1, (int) ($_GET['page'] ?? 1));
$hWhere = '';
$hArgs = [];
if ($mine) {
    $in = implode(',', array_fill(0, count($mine), '?'));
    $hWhere = "cr.elderly_id IN ({$in})";
    $hArgs = $mine;
    if ($fElderly !== '') {
        $hWhere .= ' AND cr.elderly_id = ?';
        $hArgs[] = (int) $fElderly;
    }
} else {
    $hWhere = '1 = 0';
}
$countStmt = db()->prepare(
    "SELECT COUNT(*) FROM checkin_records cr WHERE {$hWhere}"
);
$countStmt->execute($hArgs);
$pg = pager((int) $countStmt->fetchColumn(), 15, $hPage);
$hStmt = db()->prepare(
    "SELECT cr.*, ep.full_name AS household, cs.title AS schedule_title,
            u.full_name AS submitter
     FROM checkin_records cr
     JOIN elderly_profiles ep ON ep.id = cr.elderly_id
     LEFT JOIN checkin_schedules cs ON cs.id = cr.schedule_id
     LEFT JOIN users u ON u.id = cr.submitted_by
     WHERE {$hWhere}
     ORDER BY cr.checkin_at DESC LIMIT :limit OFFSET :offset"
);
foreach ($hArgs as $i => $v) {
    $hStmt->bindValue($i + 1, $v, PDO::PARAM_INT);
}
$hStmt->bindValue('limit', $pg['limit'], PDO::PARAM_INT);
$hStmt->bindValue('offset', $pg['offset'], PDO::PARAM_INT);
$hStmt->execute();
$history = $hStmt->fetchAll();

$methods = ['caregiver_entry' => 'In person (I recorded it)', 'phone_logged' => 'Phone call',
    'visit_logged' => 'Home visit'];
$wellbeings = ['ok' => 'Well', 'needs_assistance' => 'Needs assistance', 'unwell' => 'Unwell'];
$wbBadge = ['ok' => 'success', 'needs_assistance' => 'warning', 'unwell' => 'danger'];

$pageTitle  = 'Check-ins';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Check-ins</h1>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-6">
        <select class="form-select" name="elderly_id" onchange="this.form.submit()">
            <option value="">All my households</option>
            <?php foreach ($households as $h): ?>
                <option value="<?= (int) $h['id'] ?>" <?= $fElderly === (string) $h['id'] ? 'selected' : '' ?>>
                    <?= e($h['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <a class="btn btn-outline-primary" href="../admin/schedule_form.php">+ New schedule for my households</a>
    </div>
</form>

<?php if (!$mine): ?>
    <div class="alert alert-info">No households are assigned to you yet.</div>
<?php else: ?>

<h2 class="h4 mt-4">Needs attention (<?= count($overdue) ?> overdue)</h2>
<?php if (!$overdue): ?>
    <p class="text-muted">Nothing overdue. Good work.</p>
<?php else: ?>
<div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr><th>Household</th><th>Expected</th><th>Deadline passed</th><th>Record it</th></tr></thead>
    <tbody>
    <?php foreach ($overdue as $s): ?>
        <tr class="table-danger">
            <td><?= e($s['household']) ?><br><small class="text-muted"><?= e($s['title'] ?? '') ?></small></td>
            <td><?= e($s['expected_at']) ?></td>
            <td><?= e(deadline_of($s)) ?></td>
            <td>
                <form method="post" action="checkins.php" class="row g-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="kind" value="scheduled">
                    <input type="hidden" name="schedule_id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="f_elderly" value="<?= e($fElderly) ?>">
                    <div class="col-4"><select class="form-select form-select-sm" name="method" required>
                        <?php foreach ($methods as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="col-4"><select class="form-select form-select-sm" name="wellbeing" required>
                        <?php foreach ($wellbeings as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="col-4"><input class="form-control form-control-sm" name="notes" maxlength="500" placeholder="Note (optional)"></div>
                    <div class="col-12 mt-1"><button class="btn btn-sm btn-danger w-100" type="submit">Record check-in</button></div>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<h2 class="h4 mt-4">Due &amp; upcoming (<?= count($upcoming) ?>)</h2>
<?php if (!$upcoming): ?>
    <p class="text-muted">Nothing scheduled ahead<?= $fElderly !== '' ? ' for this household' : '' ?>.</p>
<?php else: ?>
<div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr><th>Household</th><th>Title</th><th>Expected</th><th>Deadline</th><th>Record it</th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $s): ?>
        <tr>
            <td><?= e($s['household']) ?></td>
            <td><?= e($s['title'] ?? '') ?></td>
            <td><?= e($s['expected_at']) ?></td>
            <td><?= e(deadline_of($s)) ?></td>
            <td>
                <form method="post" action="checkins.php" class="row g-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="kind" value="scheduled">
                    <input type="hidden" name="schedule_id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="f_elderly" value="<?= e($fElderly) ?>">
                    <div class="col-4"><select class="form-select form-select-sm" name="method" required>
                        <?php foreach ($methods as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="col-4"><select class="form-select form-select-sm" name="wellbeing" required>
                        <?php foreach ($wellbeings as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="col-4"><input class="form-control form-control-sm" name="notes" maxlength="500" placeholder="Note (optional)"></div>
                    <div class="col-12 mt-1"><button class="btn btn-sm btn-primary w-100" type="submit">Record check-in</button></div>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="card mt-4"><div class="card-header">Record ad-hoc check-in (no schedule)</div>
<div class="card-body">
    <form method="post" action="checkins.php" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="kind" value="adhoc">
        <input type="hidden" name="f_elderly" value="<?= e($fElderly) ?>">
        <div class="col-md-3">
            <label class="form-label" for="elderly_id">Household</label>
            <select class="form-select" id="elderly_id" name="elderly_id" required>
                <?php foreach ($households as $h): ?>
                    <option value="<?= (int) $h['id'] ?>" <?= $fElderly === (string) $h['id'] ? 'selected' : '' ?>>
                        <?= e($h['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="method">Captured via</label>
            <select class="form-select" id="method" name="method" required>
                <?php foreach ($methods as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="wellbeing">Wellbeing</label>
            <select class="form-select" id="wellbeing" name="wellbeing" required>
                <?php foreach ($wellbeings as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="notes">Note</label>
            <input class="form-control" id="notes" name="notes" maxlength="500">
        </div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Record ad-hoc check-in</button></div>
    </form>
</div></div>
<?php endif; ?>

<h2 class="h4 mt-4">History</h2>
<?php if (!$history): ?>
    <p class="text-muted">No check-ins recorded yet<?= $fElderly !== '' ? ' for this household' : '' ?>.</p>
<?php else: ?>
<div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr><th>When</th><th>Household</th><th>Schedule</th><th>Submitted by</th><th>Via</th><th>Wellbeing</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
        <tr>
            <td><?= e($h['checkin_at']) ?></td>
            <td><?= e($h['household']) ?></td>
            <td><?= e($h['schedule_title'] ?? '— ad-hoc —') ?></td>
            <td><?= e($h['submitter'] ?? '?') ?> <small class="text-muted">(<?= e($h['submitted_by_role'] ?? '') ?>)</small></td>
            <td><?= e($methods[$h['method']] ?? $h['method']) ?></td>
            <td><span class="badge bg-<?= e($wbBadge[$h['wellbeing_status']] ?? 'secondary') ?>"><?= e($wellbeings[$h['wellbeing_status']] ?? $h['wellbeing_status']) ?></span></td>
            <td><?= e($h['notes'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?= pager_links('checkins.php', ['elderly_id' => $fElderly], $pg['page'], $pg['pages']) ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
