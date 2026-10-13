<?php
/**
 * File: admin/settings.php
 * Stage: 6 - MONITOR configuration + manual run (admin only).
 *  - Edit the tunable thresholds (validated ranges; urgent > attention).
 *  - "Run monitoring now" executes the SAME engine cron uses.
 *  - Last runs + current alert snapshot for the demo/verification.
 * Missing keys self-heal (INSERT IGNORE) so Stage 6's two new pattern keys
 * appear without any manual SQL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('admin', '../login.php');

/** key => [label, min, max, help]. app_timezone_label is display-only. */
const KNOWN_SETTINGS = [
    'default_grace_minutes'      => ['Default grace (minutes)', 5, 1440, 'Copied onto each new schedule; can be overridden per schedule.'],
    'default_escalation_minutes' => ['Default escalation delay (minutes)', 15, 10080, 'Unacknowledged time before the secondary contact is notified.'],
    'monitor_window_days'        => ['Monitor history window (days)', 7, 90, 'How far back detection looks for schedules/history.'],
    'attention_threshold'        => ['Attention threshold (score)', 1, 99, 'Score at/above this => Attention required.'],
    'urgent_threshold'           => ['Urgent threshold (score)', 2, 100, 'Score at/above this => Urgent review. Must exceed Attention.'],
    'pattern_silent_days'        => ['Silent-household days', 3, 30, 'No check-in for this long (+ nothing scheduled) => pattern flag.'],
    'pattern_stale_hours'        => ['Stale-case hours', 12, 168, 'Open alert this old without follow-up => priority raised one step.'],
];
const SETTING_DEFAULTS = [
    'default_grace_minutes' => '120', 'default_escalation_minutes' => '240',
    'monitor_window_days' => '30', 'attention_threshold' => '30',
    'urgent_threshold' => '60', 'pattern_silent_days' => '7',
    'pattern_stale_hours' => '48', 'app_timezone_label' => 'Africa/Lagos',
];

// Self-heal: insert any missing keys (fresh Stage 6 keys on a Stage 2 DB).
$heal = db()->prepare(
    'INSERT IGNORE INTO system_settings (setting_key, setting_value, description)
     VALUES (:k, :v, :d)'
);
foreach (KNOWN_SETTINGS as $k => [$label, $min, $max, $help]) {
    $heal->execute(['k' => $k, 'v' => SETTING_DEFAULTS[$k], 'd' => $label . '. ' . $help]);
}

$runSummary = null;

/* --- POST: save settings / run monitor ------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $errors = [];
        $values = [];
        foreach (KNOWN_SETTINGS as $k => [$label, $min, $max, $help]) {
            $v = trim($_POST[$k] ?? '');
            if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
                $errors[] = "{$label} must be {$min}-{$max}.";
            } else {
                $values[$k] = $v;
            }
        }
        if (!$errors && (int) $values['urgent_threshold'] <= (int) $values['attention_threshold']) {
            $errors[] = 'Urgent threshold must be greater than Attention threshold.';
        }
        if ($errors) {
            foreach ($errors as $e) {
                flash('danger', $e);
            }
        } else {
            $upd = db()->prepare(
                'UPDATE system_settings SET setting_value = :v, updated_by = :u WHERE setting_key = :k'
            );
            foreach ($values as $k => $v) {
                $upd->execute(['v' => $v, 'u' => $user['id'], 'k' => $k]);
            }
            audit('update_settings', null, null, 'Monitoring thresholds updated.');
            flash('success', 'Settings saved. New schedules and future runs use them.');
        }
        redirect('settings.php');
    }

    if ($action === 'run') {
        require_once __DIR__ . '/../cron/monitor_checkins.php';
        $runSummary = run_monitor('manual'); // same engine, admin-attributed audit
        flash('success', 'Monitoring run finished - see the summary below.');
    }
}

/* --- Current values + snapshot ---------------------------------------------- */
$current = [];
foreach (db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll() as $r) {
    $current[$r['setting_key']] = $r['setting_value'];
}
$tzLabel = $current['app_timezone_label'] ?? 'Africa/Lagos';

$openAlerts = (int) db()->query("SELECT COUNT(*) FROM alerts WHERE status IN ('open','escalated')")->fetchColumn();
$overdueNow = (int) db()->query(
    "SELECT COUNT(*) FROM checkin_schedules WHERE status = 'scheduled'
     AND DATE_ADD(expected_at, INTERVAL grace_minutes MINUTE) < NOW()"
)->fetchColumn();
$runs = db()->query(
    "SELECT created_at, details FROM audit_logs WHERE action = 'monitor_run'
     ORDER BY id DESC LIMIT 10"
)->fetchAll();

$pageTitle  = 'Monitoring settings';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Monitoring settings</h1>
<p class="text-muted">Time zone: <strong><?= e($tzLabel) ?></strong> (set in code +
    database; label shown for transparency). Open alerts: <strong><?= e((string) $openAlerts) ?></strong>;
    overdue right now: <strong><?= e((string) $overdueNow) ?></strong>.</p>

<?php if ($runSummary): ?>
<div class="card border-success mb-4"><div class="card-header bg-success text-white">
    Manual run finished in <?= e((string) $runSummary['seconds']) ?>s</div>
<div class="card-body">
    <dl class="row mb-0">
        <?php foreach (['checked' => 'Overdue checked', 'completed' => 'Completed (proof found)', 'missed' => 'Marked missed',
            'alerts' => 'Missed alerts created', 'escalated' => 'Escalated', 'esc_skipped' => 'Escalation skips',
            'patterns' => 'Pattern flags', 'stale_raised' => 'Stale priorities raised'] as $k => $label): ?>
            <dt class="col-sm-6"><?= e($label) ?></dt>
            <dd class="col-sm-6"><?= e((string) $runSummary[$k]) ?></dd>
        <?php endforeach; ?>
    </dl>
</div></div>
<?php endif; ?>

<div class="row g-4">
<div class="col-lg-6">
<div class="card"><div class="card-header">Thresholds</div><div class="card-body">
    <form method="post" action="settings.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php foreach (KNOWN_SETTINGS as $k => [$label, $min, $max, $help]): ?>
            <div class="mb-3">
                <label class="form-label" for="<?= e($k) ?>"><?= e($label) ?> (<?= e((string) $min) ?>–<?= e((string) $max) ?>)</label>
                <input class="form-control" type="number" id="<?= e($k) ?>" name="<?= e($k) ?>"
                       min="<?= e((string) $min) ?>" max="<?= e((string) $max) ?>" required
                       value="<?= e($current[$k] ?? '') ?>">
                <div class="form-text"><?= e($help) ?></div>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">Save settings</button>
    </form>
</div></div>
</div>

<div class="col-lg-6">
<div class="card mb-4"><div class="card-header">Manual run (demo)</div><div class="card-body">
    <p>Runs the exact engine cron uses. Safe to repeat - re-runs create nothing new.</p>
    <form method="post" action="settings.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="run">
        <button class="btn btn-success" type="submit">▶ Run monitoring now</button>
    </form>
</div></div>

<div class="card"><div class="card-header">Last monitor runs</div>
<div class="card-body">
    <?php if (!$runs): ?>
        <p class="mb-0 text-muted">No runs yet - press the button above.</p>
    <?php else: ?>
        <ul class="list-group list-group-flush">
        <?php foreach ($runs as $r):
            $d = json_decode((string) $r['details'], true) ?: []; ?>
            <li class="list-group-item small">
                <strong><?= e($r['created_at']) ?></strong> (<?= e($d['trigger'] ?? '?') ?>):
                checked <?= e((string) ($d['checked'] ?? '?')) ?>,
                missed <?= e((string) ($d['missed'] ?? '?')) ?>,
                alerts <?= e((string) ($d['alerts'] ?? '?')) ?>,
                escalated <?= e((string) ($d['escalated'] ?? '?')) ?>,
                patterns <?= e((string) ($d['patterns'] ?? '?')) ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div></div>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
