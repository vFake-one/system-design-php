<?php
/**
 * File: caregiver/alerts.php
 * Stage: 6 - the ALERT worklist. SHARED page:
 *  - caregiver: alerts for actively-assigned households only.
 *  - admin: every alert (plus the exclusive Dismiss action).
 *
 * Lifecycle enforced here (FR-36..39): open -> acknowledged -> resolved,
 * with open -> escalated (monitor) rejoining via acknowledge. Resolve is
 * REFUSED until a completed follow-up with a written outcome exists (FR-38).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin', 'caregiver'], '../login.php');
$isAdmin = $user['role'] === 'admin';
$mine = $isAdmin ? [] : assigned_elderly_ids((int) $user['id']);

/** Scope test: may $user act on this alert row? */
$mayAccess = function (array $a) use ($isAdmin, $mine): bool {
    return $isAdmin || in_array((int) $a['elderly_id'], $mine, true);
};

/* --- POST actions ---------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $back = 'alerts.php?status=' . urlencode($_POST['f_status'] ?? 'active');
    try {
        $aid = (int) ($_POST['alert_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM alerts WHERE id = :id');
        $stmt->execute(['id' => $aid]);
        $alert = $stmt->fetch();
        if (!$alert || !$mayAccess($alert)) {
            audit('access_denied', 'alerts', $aid, 'Role ' . $user['role'] . ' touched out-of-scope alert.');
            throw new RuntimeException('Alert not found.');
        }
        $action = $_POST['action'] ?? '';
        $notes = trim($_POST['notes'] ?? '');

        if ($action === 'ack') {
            if (!in_array($alert['status'], ['open', 'escalated'], true)) {
                throw new RuntimeException('Only open or escalated alerts can be acknowledged.');
            }
            $taken = trim($_POST['action_taken'] ?? '');
            if ($taken === '' || mb_strlen($taken) > 100) {
                throw new RuntimeException('Describe the action taken (1-100 characters).');
            }
            db()->beginTransaction();
            db()->prepare(
                'INSERT INTO alert_acknowledgements (alert_id, acknowledged_by, action_taken, notes)
                 VALUES (:a, :by, :t, :n)'
            )->execute(['a' => $aid, 'by' => $user['id'], 't' => $taken,
                'n' => $notes === '' ? null : $notes]);
            db()->prepare("UPDATE alerts SET status = 'acknowledged' WHERE id = :a")->execute(['a' => $aid]);
            db()->commit();
            audit('acknowledge_alert', 'alerts', $aid, $taken);
            flash('success', "Alert #{$aid} acknowledged.");
        } elseif ($action === 'resolve') {
            if (!in_array($alert['status'], ['open', 'acknowledged', 'escalated'], true)) {
                throw new RuntimeException('This alert is already ' . $alert['status'] . '.');
            }
            $proof = db()->prepare(
                "SELECT 1 FROM followup_actions
                 WHERE alert_id = :a AND status = 'completed'
                   AND outcome IS NOT NULL AND outcome <> '' LIMIT 1"
            );
            $proof->execute(['a' => $aid]);
            if ($proof->fetchColumn() === false) {
                throw new RuntimeException(
                    "Alert #{$aid} cannot be resolved yet: record a completed follow-up with a written outcome first (Follow-ups page).");
            }
            db()->prepare("UPDATE alerts SET status = 'resolved', resolved_at = NOW() WHERE id = :a")
               ->execute(['a' => $aid]);
            audit('resolve_alert', 'alerts', $aid);
            flash('success', "Alert #{$aid} resolved.");
        } elseif ($action === 'dismiss') {
            if (!$isAdmin) {
                throw new RuntimeException('Only administrators can dismiss alerts.');
            }
            if ($notes === '') {
                throw new RuntimeException('A dismissal reason is required.');
            }
            db()->prepare("UPDATE alerts SET status = 'dismissed' WHERE id = :a")->execute(['a' => $aid]);
            audit('dismiss_alert', 'alerts', $aid, $notes);
            flash('success', "Alert #{$aid} dismissed.");
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('danger', $e->getMessage());
    }
    redirect($back); // PRG (filters partially preserved; page resets to 1)
}

/* --- GET filters ------------------------------------------------------------- */
$households = $isAdmin
    ? db()->query('SELECT id, full_name FROM elderly_profiles ORDER BY full_name ASC')->fetchAll()
    : (function () use ($mine) {
        if (!$mine) {
            return [];
        }
        $in = implode(',', array_fill(0, count($mine), '?'));
        $s = db()->prepare("SELECT id, full_name FROM elderly_profiles WHERE id IN ({$in}) ORDER BY full_name ASC");
        $s->execute($mine);
        return $s->fetchAll();
    })();

$fStatus   = $_GET['status'] ?? 'active';
$fPriority = $_GET['priority'] ?? '';
$fType     = $_GET['type'] ?? '';
$fElderly  = trim($_GET['elderly_id'] ?? '');
if (!in_array($fStatus, ['active', 'all', 'open', 'acknowledged', 'escalated', 'resolved', 'dismissed'], true)) {
    $fStatus = 'active';
}
if (!in_array($fPriority, ['', 'routine', 'attention', 'urgent'], true)) {
    $fPriority = '';
}
if (!in_array($fType, ['', 'missed_checkin', 'pattern_flag', 'assistance_request', 'escalation'], true)) {
    $fType = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = [];
$params = [];
if (!$isAdmin) { // scope FIRST: the query itself enforces FR-04
    if (!$mine) {
        $where[] = '1 = 0';
    } else {
        $in = implode(',', array_fill(0, count($mine), '?'));
        $where[] = "a.elderly_id IN ({$in})";
        $params = array_merge($params, $mine);
    }
}
if ($fStatus === 'active') {
    $where[] = "a.status IN ('open','acknowledged','escalated')";
} elseif ($fStatus !== 'all') {
    $where[] = 'a.status = ?';
    $params[] = $fStatus;
}
if ($fPriority !== '') {
    $where[] = 'a.priority = ?';
    $params[] = $fPriority;
}
if ($fType !== '') {
    $where[] = 'a.alert_type = ?';
    $params[] = $fType;
}
if ($fElderly !== '' && ctype_digit($fElderly)) {
    $where[] = 'a.elderly_id = ?';
    $params[] = (int) $fElderly;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM alerts a {$whereSql}");
$countStmt->execute($params);
$pg = pager((int) $countStmt->fetchColumn(), 10, $page);

$listStmt = db()->prepare(
    "SELECT a.*, ep.full_name AS household, u.full_name AS caregiver,
            eu.full_name AS escalated_name, cs.title AS schedule_title
     FROM alerts a
     JOIN elderly_profiles ep ON ep.id = a.elderly_id
     LEFT JOIN users u ON u.id = a.caregiver_id
     LEFT JOIN users eu ON eu.id = a.escalated_to
     LEFT JOIN checkin_schedules cs ON cs.id = a.schedule_id
     {$whereSql}
     ORDER BY FIELD(a.status,'open','escalated','acknowledged','resolved','dismissed'),
              FIELD(a.priority,'urgent','attention','routine'), a.created_at DESC
     LIMIT ? OFFSET ?"
);
$listStmt->execute(array_merge($params, [$pg['limit'], $pg['offset']]));
$alerts = $listStmt->fetchAll();

/* Acknowledgements + follow-up counts for this page (two queries, no N+1). */
$acks = [];
$fuCounts = [];
if ($alerts) {
    $ids = array_map(fn($a) => (int) $a['id'], $alerts);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = db()->prepare(
        "SELECT ak.*, u.full_name AS by_name FROM alert_acknowledgements ak
         LEFT JOIN users u ON u.id = ak.acknowledged_by
         WHERE ak.alert_id IN ({$in}) ORDER BY ak.acknowledged_at ASC"
    );
    $s->execute($ids);
    foreach ($s->fetchAll() as $r) {
        $acks[(int) $r['alert_id']][] = $r;
    }
    $s = db()->prepare(
        "SELECT alert_id, COUNT(*) c FROM followup_actions WHERE alert_id IN ({$in}) GROUP BY alert_id"
    );
    $s->execute($ids);
    foreach ($s->fetchAll() as $r) {
        $fuCounts[(int) $r['alert_id']] = (int) $r['c'];
    }
}

$prioBadge = ['routine' => 'success', 'attention' => 'warning', 'urgent' => 'danger'];
$statusBadge = ['open' => 'danger', 'escalated' => 'warning', 'acknowledged' => 'primary',
    'resolved' => 'success', 'dismissed' => 'secondary'];

$pageTitle  = 'Alerts';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Alerts<?= $isAdmin ? ' (all households)' : ' (my households)' ?></h1>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
        <select class="form-select" name="status">
            <?php foreach (['active' => 'Active (open/ack/escalated)', 'open' => 'Open', 'acknowledged' => 'Acknowledged', 'escalated' => 'Escalated', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed', 'all' => 'All'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $fStatus === $v ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="priority">
            <option value="">All priorities</option>
            <?php foreach (['urgent' => 'Urgent', 'attention' => 'Attention', 'routine' => 'Routine'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $fPriority === $v ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select" name="type">
            <option value="">All types</option>
            <?php foreach (['missed_checkin' => 'Missed check-in', 'pattern_flag' => 'Pattern', 'assistance_request' => 'Assistance request'] as $v => $l): ?>
                <option value="<?= e($v) ?>" <?= $fType === $v ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="elderly_id">
            <option value="">All households</option>
            <?php foreach ($households as $h): ?>
                <option value="<?= (int) $h['id'] ?>" <?= $fElderly === (string) $h['id'] ? 'selected' : '' ?>><?= e($h['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
</form>

<?php if (!$alerts): ?>
    <div class="alert alert-info">No alerts match. Quiet is good — it means check-ins are being kept.</div>
<?php else: ?>
<?php foreach ($alerts as $a): ?>
<div class="card mb-3 border-<?= e($prioBadge[$a['priority']] ?? 'secondary') ?>">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>#<?= (int) $a['id'] ?> · <?= e($a['title']) ?></strong>
        <span>
            <span class="badge bg-<?= e($prioBadge[$a['priority']] ?? 'secondary') ?>"><?= e($a['priority']) ?> (<?= e((string) ($a['risk_score'] ?? '?')) ?>)</span>
            <span class="badge bg-<?= e($statusBadge[$a['status']] ?? 'secondary') ?>"><?= e($a['status']) ?></span>
        </span>
    </div>
    <div class="card-body">
        <p class="mb-1"><strong><?= e($a['household']) ?></strong>
            <small class="text-muted">· <?= e($a['alert_type']) ?><?= $a['schedule_title'] ? ' · ' . e($a['schedule_title']) : '' ?>
            · opened <?= e($a['created_at']) ?> · caregiver: <?= e($a['caregiver'] ?? '—') ?></small></p>
        <p class="mb-1"><?= e($a['message']) ?></p>
        <p class="mb-1"><strong>Why flagged:</strong> <?= e($a['reason'] ?? '—') ?></p>
        <?php if ($a['status'] === 'escalated'): ?>
            <p class="mb-1 text-danger"><strong>Escalated</strong> to <?= e($a['escalated_name'] ?? '?') ?>
                at <?= e($a['escalated_at'] ?? '') ?> (unacknowledged too long).</p>
        <?php endif; ?>
        <?php if (!empty($acks[$a['id']])): ?>
            <ul class="mb-1 small">
            <?php foreach ($acks[$a['id']] as $ak): ?>
                <li>Acknowledged by <?= e($ak['by_name'] ?? '?') ?> at <?= e($ak['acknowledged_at']) ?>:
                    <?= e($ak['action_taken'] ?? '') ?><?= $ak['notes'] ? ' — ' . e($ak['notes']) : '' ?></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <details class="small mb-2"><summary>Scoring evidence (observations)</summary>
            <pre class="mb-0"><?= e($a['observations'] ?? '{}') ?></pre></details>
        <p class="small mb-2">Follow-ups recorded: <strong><?= e((string) ($fuCounts[$a['id']] ?? 0)) ?></strong>
            <?php if (!$isAdmin): ?>(<a href="followups.php?alert_id=<?= (int) $a['id'] ?>">log one</a>)<?php endif; ?></p>

        <div class="d-flex gap-2 flex-wrap">
        <?php if (in_array($a['status'], ['open', 'escalated'], true)): ?>
            <form method="post" action="alerts.php" class="row g-1 flex-grow-1">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ack">
                <input type="hidden" name="alert_id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="f_status" value="<?= e($fStatus) ?>">
                <div class="col-md-4"><input class="form-control form-control-sm" name="action_taken" required maxlength="100" placeholder="Action taken *"></div>
                <div class="col-md-5"><input class="form-control form-control-sm" name="notes" maxlength="500" placeholder="Note (optional)"></div>
                <div class="col-md-3"><button class="btn btn-sm btn-primary w-100" type="submit">Acknowledge</button></div>
            </form>
        <?php endif; ?>
        <?php if (in_array($a['status'], ['open', 'acknowledged', 'escalated'], true)): ?>
            <form method="post" action="alerts.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resolve">
                <input type="hidden" name="alert_id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="f_status" value="<?= e($fStatus) ?>">
                <button class="btn btn-sm btn-success" type="submit"
                        data-confirm="Resolve alert #<?= (int) $a['id'] ?>? A completed follow-up with an outcome is required.">Resolve</button>
            </form>
        <?php endif; ?>
        <?php if ($isAdmin && !in_array($a['status'], ['resolved', 'dismissed'], true)): ?>
            <form method="post" action="alerts.php" class="row g-1">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="dismiss">
                <input type="hidden" name="alert_id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="f_status" value="<?= e($fStatus) ?>">
                <div class="col-auto"><input class="form-control form-control-sm" name="notes" required maxlength="500" placeholder="Dismiss reason *"></div>
                <div class="col-auto"><button class="btn btn-sm btn-outline-secondary" type="submit">Dismiss</button></div>
            </form>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?= pager_links('alerts.php', ['status' => $fStatus, 'priority' => $fPriority, 'type' => $fType, 'elderly_id' => $fElderly], $pg['page'], $pg['pages']) ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
