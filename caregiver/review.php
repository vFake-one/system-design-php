<?php
/**
 * File: caregiver/review.php
 * Stage: 7 - PRIORITIZED REVIEW QUEUE (shared: caregiver own / admin all).
 * Answers "who should I look at first?" - one row per household that has
 * open/escalated alerts, worst-first, with the recorded reasons inline.
 * The Re-score button refreshes open/escalated alerts with the CURRENT
 * engine + thresholds (acknowledged cases are left alone mid-investigation).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../ai/risk_engine.php';

$user = require_role(['admin', 'caregiver'], '../login.php');
$isAdmin = $user['role'] === 'admin';
$mine = $isAdmin ? [] : assigned_elderly_ids((int) $user['id']);

/* --- POST: re-score open/escalated alerts in scope ---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $cond = "status IN ('open','escalated')";
    $args = [];
    if (!$isAdmin) {
        if (!$mine) {
            flash('info', 'Nothing in scope to re-score.');
            redirect('review.php');
        }
        $cond .= ' AND elderly_id IN (' . implode(',', array_fill(0, count($mine), '?')) . ')';
        $args = $mine;
    }
    $stmt = db()->prepare("SELECT id, elderly_id FROM alerts WHERE {$cond}");
    $stmt->execute($args);
    $targets = $stmt->fetchAll();
    $n = 0;
    $upd = db()->prepare(
        'UPDATE alerts SET risk_score = :s, priority = :p, reason = :r, observations = :o WHERE id = :id'
    );
    foreach ($targets as $t) {
        $ass = risk_assess((int) $t['elderly_id'], 'rescore');
        $upd->execute([
            's' => $ass['score'], 'p' => $ass['priority'],
            'r' => $ass['reasons'] ? implode('; ', $ass['reasons']) : 'No contributing factors',
            'o' => json_encode($ass['observations']), 'id' => $t['id'],
        ]);
        $n++;
    }
    audit('rescore_run', null, null, "Re-scored {$n} open/escalated alert(s) with rule-engine-v1.");
    flash('success', "Re-scored {$n} alert(s) with the current engine and thresholds.");
    redirect('review.php');
}

/* --- Queue: worst open alert per household, worst-first ------------------------ */
$cond = "a.status IN ('open','escalated')";
$args = [];
if (!$isAdmin) {
    if (!$mine) {
        $alerts = [];
    } else {
        $cond .= ' AND a.elderly_id IN (' . implode(',', array_fill(0, count($mine), '?')) . ')';
        $args = $mine;
    }
}
if (!isset($alerts)) {
    $stmt = db()->prepare(
        "SELECT a.*, ep.full_name AS household
         FROM alerts a JOIN elderly_profiles ep ON ep.id = a.elderly_id
         WHERE {$cond}
         ORDER BY FIELD(a.priority,'urgent','attention','routine'), a.risk_score DESC, a.created_at ASC
         LIMIT 200"
    );
    $stmt->execute($args);
    $alerts = $stmt->fetchAll();
}

// Group by household (first row = worst), then enrich with recency facts.
$queue = [];
foreach ($alerts as $a) {
    $eid = (int) $a['elderly_id'];
    if (!isset($queue[$eid])) {
        $queue[$eid] = ['household' => $a['household'], 'worst' => $a, 'open_count' => 0];
    }
    $queue[$eid]['open_count']++;
}
if ($queue) {
    $ids = array_keys($queue);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = db()->prepare(
        "SELECT elderly_id, MAX(checkin_at) m FROM checkin_records
         WHERE elderly_id IN ({$in}) GROUP BY elderly_id"
    );
    $s->execute($ids);
    foreach ($s->fetchAll() as $r) {
        $queue[(int) $r['elderly_id']]['last_success'] = $r['m'];
    }
    $s = db()->prepare(
        "SELECT elderly_id, MAX(visit_date) m FROM caregiver_visits
         WHERE elderly_id IN ({$in}) GROUP BY elderly_id"
    );
    $s->execute($ids);
    foreach ($s->fetchAll() as $r) {
        $queue[(int) $r['elderly_id']]['last_visit'] = $r['m'];
    }
}

$prioBadge = ['routine' => 'success', 'attention' => 'warning', 'urgent' => 'danger'];
$urgentAt = setting('urgent_threshold', '60');
$attentionAt = setting('attention_threshold', '30');

$pageTitle  = 'Review queue';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h1 class="mb-0">Review queue</h1>
    <form method="post" action="review.php">
        <?= csrf_field() ?>
        <button class="btn btn-outline-primary" type="submit"
                data-confirm="Re-score all open/escalated alerts in scope with the current engine?">↻ Re-score open cases</button>
    </form>
</div>
<p class="text-muted">Engine: <strong>rule-engine-v1 (R-1..R-7)</strong> ·
    Thresholds: urgent ≥ <?= e($urgentAt) ?>, attention ≥ <?= e($attentionAt) ?> ·
    Priorities are operational review order, <em>not</em> medical diagnoses.
    Every score recomputes from its stored observations + the rules table
    (<code>docs/12 §A</code>).</p>

<?php if (!$queue): ?>
    <div class="alert alert-success">Queue clear — no open or escalated cases<?= $isAdmin ? '' : ' for your households' ?>.</div>
<?php else: ?>
<div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr><th>#</th><th>Household</th><th>Worst case</th><th>Why (recorded reasons)</th><th>Last success</th><th>Last visit</th><th></th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($queue as $eid => $q): $i++; $w = $q['worst']; ?>
        <tr>
            <td><?= $i ?></td>
            <td><strong><?= e($q['household']) ?></strong><br>
                <small class="text-muted"><?= e((string) $q['open_count']) ?> open case(s)</small></td>
            <td><span class="badge bg-<?= e($prioBadge[$w['priority']] ?? 'secondary') ?>">
                <?= e($w['priority']) ?> (<?= e((string) ($w['risk_score'] ?? '?')) ?>)</span><br>
                <small class="text-muted">#<?= (int) $w['id'] ?> · <?= e($w['alert_type']) ?></small></td>
            <td class="small"><?= e($w['reason'] ?? '—') ?></td>
            <td class="small"><?= e($q['last_success'] ?? 'never') ?></td>
            <td class="small"><?= e($q['last_visit'] ?? 'never') ?></td>
            <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-danger" href="alerts.php?elderly_id=<?= $eid ?>&status=active">Review</a>
                <a class="btn btn-sm btn-outline-primary" href="../admin/elderly_view.php?id=<?= $eid ?>">Profile</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
