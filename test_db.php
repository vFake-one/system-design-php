<?php
/**
 * File: test_db.php (PROJECT ROOT - TEMPORARY)
 * Stage: 2 - connection + seed verification.
 *
 *   1. Import database/elderly_welfare.sql (see docs/07).
 *   2. Start Apache + MySQL in XAMPP.
 *   3. Visit http://localhost/elderly-welfare-system/test_db.php
 *   4. Expect every row GREEN. Fix red rows using the docs/07 table.
 *   5. DELETE THIS FILE before your final submission - it reveals
 *      table/row details that no production server should expose.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

/** Tiny HTML escaper (the full e() helper arrives with includes/ in Stage 3). */
function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$checks = [];

/** Run one named check; store green/red + detail (never secrets). */
function check(string $label, callable $fn): void
{
    global $checks;
    try {
        $checks[] = ['label' => $label, 'ok' => true, 'detail' => (string) $fn()];
    } catch (Throwable $e) {
        error_log('test_db [' . $label . ']: ' . $e->getMessage()); // private log only
        $checks[] = ['label' => $label, 'ok' => false, 'detail' => 'FAILED - see guidance in docs/07 section D'];
    }
}

/* --- 1. Connection ------------------------------------------------ */
check('PDO connection', fn() => 'Connected to `' . DB_NAME . '` on ' . DB_HOST . ' as ' . DB_USER);

/* --- 2. Time zones agree (PHP vs MySQL session) --------------------- */
check('Time zones agree', function () {
    $row = db()->query('SELECT @@session.time_zone AS tz, NOW() AS now_sql')->fetch();
    $php = date_default_timezone_get() . ' ' . date('Y-m-d H:i:s');
    if ($row['tz'] !== DB_TIMEZONE_OFFSET) {
        throw new RuntimeException('session zone is ' . $row['tz']);
    }
    return 'PHP=' . $php . ' | MySQL=' . $row['tz'] . ' ' . $row['now_sql'];
});

/* --- 3. All 11 tables exist ----------------------------------------- */
check('Tables present (expect 11)', function () {
    $n = (int) db()->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    if ($n !== 11) {
        throw new RuntimeException('found ' . $n . ' - did the full SQL script import?');
    }
    return $n . ' tables found';
});

/* --- 4. Row counts per table ---------------------------------------- */
$expectedMin = [ // table => minimum rows the seed must have inserted
    'users' => 5, 'elderly_profiles' => 3, 'caregiver_assignments' => 4,
    'checkin_schedules' => 7, 'checkin_records' => 3, 'caregiver_visits' => 3,
    'alerts' => 2, 'alert_acknowledgements' => 1, 'followup_actions' => 1,
    'audit_logs' => 2, 'system_settings' => 6,
];
foreach ($expectedMin as $table => $min) {
    // $table comes from our own fixed whitelist above - never from user input.
    check('rows: ' . $table . ' (expect >= ' . $min . ')', function () use ($table, $min) {
        $n = (int) db()->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($n < $min) {
            throw new RuntimeException('found ' . $n);
        }
        return (string) $n;
    });
}

/* --- 5. Demo accounts exist (emails only - NEVER passwords/hashes) -- */
check('Demo accounts', function () {
    $rows = db()->query('SELECT email, role, status FROM users ORDER BY id')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = $r['email'] . ' (' . $r['role'] . '/' . $r['status'] . ')';
    }
    if (count($out) < 5) {
        throw new RuntimeException('seed users missing');
    }
    return implode(' | ', $out);
});

/* --- 6. Seed schedule mix (the monitor demo needs overdue rows) ----- */
check('Schedule mix', function () {
    $rows = db()->query("SELECT status, COUNT(*) c FROM checkin_schedules GROUP BY status")->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[] = $r['status'] . '=' . $r['c'];
    }
    return implode(', ', $map) . ' (overdue derived at runtime, not stored)';
});

$allOk = array_reduce($checks, fn($carry, $c) => $carry && $c['ok'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stage 2 - Database connection test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; }
        .warn { background: #fff3cd; border: 2px solid #c00; padding: 1rem; margin-bottom: 1rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #999; padding: .5rem; text-align: left; }
        .ok { color: #060; font-weight: bold; } .fail { color: #c00; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Stage 2 - Database connection test</h1>
    <div class="warn"><strong>TEMPORARY FILE.</strong> Delete <code>test_db.php</code>
        after every row below is green and before your final submission.</div>
    <h2 class="<?= $allOk ? 'ok' : 'fail' ?>"><?= $allOk ? 'ALL CHECKS PASSED' : 'SOME CHECKS FAILED - see docs/07 section D' ?></h2>
    <table>
        <tr><th>#</th><th>Check</th><th>Result</th><th>Detail</th></tr>
        <?php foreach ($checks as $i => $c): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= h($c['label']) ?></td>
            <td class="<?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? 'PASS' : 'FAIL' ?></td>
            <td><?= h($c['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
