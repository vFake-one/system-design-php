<?php
/**
 * File: caregiver/followups.php
 * Stage: 6 - follow-up ACTION log (own households' alerts only).
 * Every row belongs to an alert; a COMPLETED row with a written outcome is
 * what unlocks Resolve on the alerts page (FR-38) - the form says so.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('caregiver', '../login.php');
$mine = assigned_elderly_ids((int) $user['id']);

$alertOptions = [];
if ($mine) {
    $in = implode(',', array_fill(0, count($mine), '?'));
    $s = db()->prepare(
        "SELECT a.id, a.title, a.status, ep.full_name AS household, ep.id AS eid
         FROM alerts a JOIN elderly_profiles ep ON ep.id = a.elderly_id
         WHERE a.elderly_id IN ({$in})
         ORDER BY a.status ASC, a.created_at DESC LIMIT 100"
    );
    $s->execute($mine);
    $alertOptions = $s->fetchAll();
}
$alertMap = [];
foreach ($alertOptions as $a) {
    $alertMap[(string) $a['id']] = (int) $a['eid'];
}

/* --- POST: log a follow-up ----------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        $aid = trim($_POST['alert_id'] ?? '');
        $atype = trim($_POST['action_type'] ?? '');
        $adate = trim($_POST['action_date'] ?? '');
        $outcome = trim($_POST['outcome'] ?? '');
        $next = trim($_POST['next_steps'] ?? '');
        $status = trim($_POST['status'] ?? '');
        if (!isset($alertMap[$aid])) {
            throw new RuntimeException('Choose one of your households\u{2019} alerts.');
        }
        if (!in_array($atype, ['phone_call', 'home_visit', 'family_contact', 'referral', 'other'], true)) {
            throw new RuntimeException('Choose a valid action type.');
        }
        $ad = DateTime::createFromFormat('Y-m-d\TH:i', $adate);
        if (!$ad) {
            throw new RuntimeException('Action date/time is required.');
        }
        if (!in_array($status, ['planned', 'completed', 'cancelled'], true)) {
            throw new RuntimeException('Choose a valid status.');
        }
        if ($status === 'completed' && $outcome === '') {
            throw new RuntimeException('A completed follow-up needs a written outcome.');
        }
        if (mb_strlen($outcome) > 2000 || mb_strlen($next) > 2000) {
            throw new RuntimeException('Outcome / next steps must be under 2000 characters.');
        }
        db()->prepare(
            'INSERT INTO followup_actions
             (alert_id, elderly_id, caregiver_id, action_type, action_date, outcome, next_steps, status)
             VALUES (:a, :e, :c, :t, :d, :o, :n, :s)'
        )->execute([
            'a' => (int) $aid, 'e' => $alertMap[$aid], 'c' => (int) $user['id'],
            't' => $atype, 'd' => $ad->format('Y-m-d H:i:00'),
            'o' => $outcome === '' ? null : $outcome,
            'n' => $next === '' ? null : $next, 's' => $status,
        ]);
        $fid = (int) db()->lastInsertId();
        audit('log_followup', 'followup_actions', $fid, "alert={$aid} status={$status}");
        flash('success', $status === 'completed' && $outcome !== ''
            ? "Follow-up recorded. Alert #{$aid} can now be resolved from the Alerts page."
            : 'Follow-up recorded.');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('followups.php?alert_id=' . urlencode($_POST['f_alert'] ?? ''));
}

/* --- GET: filter + paged list ---------------------------------------------------- */
$fAlert = trim($_GET['alert_id'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = ['fa.caregiver_id = ?'];
$params = [(int) $user['id']];
if ($fAlert !== '' && isset($alertMap[$fAlert])) {
    $where[] = 'fa.alert_id = ?';
    $params[] = (int) $fAlert;
} else {
    $fAlert = $fAlert !== '' ? '' : $fAlert; // drop forged ids silently
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$countStmt = db()->prepare("SELECT COUNT(*) FROM followup_actions fa {$whereSql}");
$countStmt->execute($params);
$pg = pager((int) $countStmt->fetchColumn(), 10, $page);

$listStmt = db()->prepare(
    "SELECT fa.*, ep.full_name AS household, a.title AS alert_title
     FROM followup_actions fa
     JOIN elderly_profiles ep ON ep.id = fa.elderly_id
     JOIN alerts a ON a.id = fa.alert_id
     {$whereSql} ORDER BY fa.action_date DESC LIMIT ? OFFSET ?"
);
$listStmt->execute(array_merge($params, [$pg['limit'], $pg['offset']]));
$rows = $listStmt->fetchAll();

$pageTitle  = 'Follow-ups';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Follow-ups</h1>

<div class="card mb-4"><div class="card-header">Log a follow-up action</div><div class="card-body">
    <?php if (!$alertOptions): ?>
        <p class="mb-0 text-muted">No alerts for your households yet — follow-ups attach to alerts.</p>
    <?php else: ?>
    <form method="post" action="followups.php" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="f_alert" value="<?= e($fAlert) ?>">
        <div class="col-md-4">
            <label class="form-label" for="alert_id">Alert *</label>
            <select class="form-select" id="alert_id" name="alert_id" required>
                <?php foreach ($alertOptions as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= $fAlert === (string) $a['id'] ? 'selected' : '' ?>>
                        #<?= (int) $a['id'] ?> · <?= e($a['household']) ?> · <?= e($a['status']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="action_type">Action *</label>
            <select class="form-select" id="action_type" name="action_type">
                <?php foreach (['phone_call' => 'Phone call', 'home_visit' => 'Home visit', 'family_contact' => 'Family contact', 'referral' => 'Referral', 'other' => 'Other'] as $v => $l): ?>
                    <option value="<?= e($v) ?>"><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="action_date">Date &amp; time *</label>
            <input class="form-control" type="datetime-local" id="action_date" name="action_date" required
                   value="<?= e(date('Y-m-d\TH:i')) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="status">Status *</label>
            <select class="form-select" id="status" name="status">
                <option value="completed">Completed</option>
                <option value="planned">Planned</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="outcome">Outcome (required to resolve the alert)</label>
            <input class="form-control" id="outcome" name="outcome" maxlength="2000"
                   placeholder="What happened? What did you find?">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="next_steps">Next steps</label>
            <input class="form-control" id="next_steps" name="next_steps" maxlength="2000"
                   placeholder="e.g. Revisit in 2 days">
        </div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Log follow-up</button></div>
    </form>
    <?php endif; ?>
</div></div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-6">
        <select class="form-select" name="alert_id" onchange="this.form.submit()">
            <option value="">All my alerts</option>
            <?php foreach ($alertOptions as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= $fAlert === (string) $a['id'] ? 'selected' : '' ?>>
                    #<?= (int) $a['id'] ?> · <?= e($a['household']) ?> · <?= e($a['status']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<?php if (!$rows): ?>
    <p class="text-muted">No follow-ups logged yet.</p>
<?php else: ?>
<div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr><th>Date</th><th>Alert</th><th>Action</th><th>Status</th><th>Outcome</th><th>Next steps</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['action_date']) ?></td>
            <td>#<?= (int) $r['alert_id'] ?> <?= e($r['household']) ?><br>
                <small class="text-muted"><?= e($r['alert_title']) ?></small></td>
            <td><?= e($r['action_type']) ?></td>
            <td><span class="badge bg-<?= e($r['status'] === 'completed' ? 'success' : ($r['status'] === 'planned' ? 'primary' : 'secondary')) ?>"><?= e($r['status']) ?></span></td>
            <td><?= e($r['outcome'] ?? '—') ?></td>
            <td><?= e($r['next_steps'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?= pager_links('followups.php', ['alert_id' => $fAlert], $pg['page'], $pg['pages']) ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
