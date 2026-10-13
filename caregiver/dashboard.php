<?php
/**
 * File: caregiver/dashboard.php
 * Stage: 8 (FULL version) - MY-households dashboard (same tiles/charts as
 * the admin's, scoped by assigned ids through dashboard_stats($mine)).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/reports_lib.php';

$user = require_role('caregiver', '../login.php');
$mine = assigned_elderly_ids((int) $user['id']);
$st = dashboard_stats($mine);

$tiles = [
    ['My households', $st['my_households'], 'primary', 'households.php'],
    ['Due today', $st['due_today'], 'info', 'checkins.php'],
    ['Overdue now', $st['overdue_now'], 'danger', 'checkins.php'],
    ['Completed (30d)', $st['completed_30d'], 'success', 'reports.php?tab=completion'],
    ['Missed (30d)', $st['missed_30d'], 'warning', 'reports.php?tab=missed'],
    ['Unacknowledged alerts', $st['unacked'], 'danger', 'alerts.php?status=active'],
    ['Urgent review', $st['urgent_open'], 'danger', 'review.php'],
    ['My visits (7d)', $st['visits_7d'], 'success', 'visits.php'],
    ['Planned follow-ups', $st['followups_planned'], 'warning', 'followups.php'],
];

$pageTitle  = 'Caregiver dashboard';
$rootPrefix = '../';
$extraScripts = ['assets/js/chart.umd.min.js'];
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Caregiver dashboard</h1>
<p class="text-muted">Welcome, <?= e($user['full_name']) ?>. Everything here covers
    only your <?= e((string) $st['my_households']) ?> assigned household(s).</p>

<?php if (!$mine): ?>
    <div class="alert alert-info">No households are assigned to you yet — figures
        below are zeros until your administrator assigns some.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ($tiles as [$label, $value, $color, $link]): ?>
        <div class="col-6 col-md-4">
            <a class="card text-decoration-none border-<?= e($color) ?> h-100" href="<?= e($link) ?>">
                <div class="card-body text-center">
                    <div class="display-6"><?= e((string) $value) ?></div>
                    <div class="text-muted"><?= e($label) ?></div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card"><div class="card-header">My check-ins: last 14 days</div>
        <div class="card-body"><canvas id="chart14" height="120"></canvas></div></div>
    </div>
    <div class="col-lg-4">
        <div class="card"><div class="card-header">My active alerts by priority</div>
        <div class="card-body"><canvas id="chartMix" height="180"></canvas></div></div>
    </div>
</div>

<div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span>Top active alerts</span>
    <a class="btn btn-sm btn-outline-primary" href="alerts.php">Open worklist</a>
</div>
<div class="card-body p-0">
    <?php if (!$st['recent_alerts']): ?>
        <p class="p-3 mb-0 text-muted">No active alerts for your households.</p>
    <?php else: ?>
    <table class="table table-striped mb-0">
        <thead><tr><th>#</th><th>Household</th><th>Title</th><th>Priority</th><th>Status</th><th>Opened</th></tr></thead>
        <tbody>
        <?php foreach ($st['recent_alerts'] as $a): ?>
            <tr>
                <td><?= (int) $a['id'] ?></td>
                <td><?= e($a['household']) ?></td>
                <td><?= e($a['title']) ?></td>
                <td><span class="badge bg-<?= e(['routine' => 'success', 'attention' => 'warning', 'urgent' => 'danger'][$a['priority']] ?? 'secondary') ?>"><?= e($a['priority']) ?></span></td>
                <td><?= e($a['status']) ?></td>
                <td><?= e($a['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div></div>

<script>
(function () {
    'use strict';
    var labels = <?= json_encode($st['chart_labels'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    new Chart(document.getElementById('chart14'), {
        type: 'bar',
        data: { labels: labels, datasets: [
            { label: 'Completed', data: <?= json_encode($st['chart_completed']) ?>, backgroundColor: '#198754' },
            { label: 'Missed', data: <?= json_encode($st['chart_missed']) ?>, backgroundColor: '#dc3545' }
        ]},
        options: { responsive: true, plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
    new Chart(document.getElementById('chartMix'), {
        type: 'doughnut',
        data: { labels: ['Routine', 'Attention', 'Urgent'], datasets: [{ data: [
            <?= (int) $st['mix']['routine'] ?>, <?= (int) $st['mix']['attention'] ?>, <?= (int) $st['mix']['urgent'] ?>
        ], backgroundColor: ['#198754', '#ffc107', '#dc3545'] }]},
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
