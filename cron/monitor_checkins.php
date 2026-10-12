<?php
/**
 * File: cron/monitor_checkins.php
 * Stage: 6 - the AUTOMATIC monitoring engine (no browser needed).
 *
 * HOW IT RUNS (two doors, one engine):
 *  1. Scheduled: cron / Task Scheduler calls XAMPP's PHP CLI on this file
 *     every 15 minutes (exact commands in docs/11). CLI prints a summary.
 *  2. Manual: admin/settings.php includes this file and calls
 *     run_monitor('manual') - same code, same guarantees.
 *
 * Direct BROWSER access to this file is refused (403) - see bottom.
 *
 * WHAT ONE RUN DOES (three idempotent passes - safe to re-run):
 *  Pass 1: overdue scheduled rows -> link late/ad-hoc proof if found
 *          (Stage 1 D-10) else mark 'missed' + ONE missed_checkin alert.
 *  Pass 2: open alerts past their escalation delay -> escalate ONCE to the
 *          authorized secondary contact (or audit one skip if none valid).
 *  Pass 3: patterns with no single miss - silent forgotten households,
 *          drifting routines, stale cases (priority raised, no new alert).
 *
 * Overlap guard: an exclusive file lock means two simultaneous runs never
 * interleave (second run exits quietly with code 0).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../ai/risk_engine.php';

/* =====================================================================
 * PASS 1 - missed-check-in detection (+ late-proof linking)
 * =================================================================== */
function pass_missed_checkins(): array
{
    $checked = $completed = $missed = $alerts = 0;

    $rows = db()->query(
        "SELECT * FROM checkin_schedules WHERE status = 'scheduled'
         AND DATE_ADD(expected_at, INTERVAL grace_minutes MINUTE) < NOW()
         ORDER BY expected_at ASC"
    )->fetchAll();
    $checked = count($rows);

    $findExact = db()->prepare('SELECT id FROM checkin_records WHERE schedule_id = :s LIMIT 1');
    // D-10 window: ad-hoc proof counts when checkin_at falls in expected-2h..deadline.
    // (Positional placeholders: named ones cannot repeat with real prepares.)
    $findAdhoc = db()->prepare(
        'SELECT id FROM checkin_records
         WHERE schedule_id IS NULL AND elderly_id = ?
           AND checkin_at BETWEEN DATE_SUB(?, INTERVAL 2 HOUR)
                              AND DATE_ADD(?, INTERVAL ? MINUTE)
         ORDER BY checkin_at DESC LIMIT 1'
    );

    foreach ($rows as $s) {
        $sid = (int) $s['id'];
        $eid = (int) $s['elderly_id'];

        $findExact->execute(['s' => $sid]);
        $proofId = $findExact->fetchColumn();
        $linkedAdhoc = false;
        if ($proofId === false) {
            $findAdhoc->execute([$eid, $s['expected_at'], $s['expected_at'], (int) $s['grace_minutes']]);
            $proofId = $findAdhoc->fetchColumn();
            $linkedAdhoc = $proofId !== false;
        }

        if ($proofId !== false) {
            // Proof exists -> schedule fulfilled (link the ad-hoc row if needed).
            db()->beginTransaction();
            try {
                if ($linkedAdhoc) {
                    db()->prepare('UPDATE checkin_records SET schedule_id = :s WHERE id = :r')
                       ->execute(['s' => $sid, 'r' => $proofId]);
                }
                db()->prepare("UPDATE checkin_schedules SET status = 'completed' WHERE id = :s AND status = 'scheduled'")
                   ->execute(['s' => $sid]);
                db()->commit();
                $completed++;
                audit('schedule_completed', 'checkin_schedules', $sid,
                    $linkedAdhoc ? 'Late ad-hoc proof linked by monitor.' : 'Proof found by monitor.');
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                error_log("monitor pass1 schedule {$sid}: " . $e->getMessage());
            }
            continue;
        }

        // No proof -> mark missed + single alert (transactional + unique-guarded).
        db()->beginTransaction();
        try {
            $upd = db()->prepare("UPDATE checkin_schedules SET status = 'missed' WHERE id = :s AND status = 'scheduled'");
            $upd->execute(['s' => $sid]);
            if ($upd->rowCount() === 0) {
                db()->rollBack(); // another run got here first - fine, move on
                continue;
            }
            $missed++;

            $exists = db()->prepare(
                "SELECT id FROM alerts WHERE schedule_id = :s AND alert_type = 'missed_checkin' LIMIT 1"
            );
            $exists->execute(['s' => $sid]);
            if ($exists->fetchColumn() === false) {
                $ass = risk_assess($eid, 'missed');
                $caregiverId = $s['caregiver_id'] ?? null;
                if ($caregiverId === null) {
                    $caregiverId = primary_caregiver_id($eid);
                }
                $exp = (new DateTime($s['expected_at']))->format('j M Y H:i');
                db()->prepare(
                    "INSERT INTO alerts
                     (elderly_id, schedule_id, caregiver_id, alert_type, priority, risk_score,
                      title, message, reason, observations, status)
                     VALUES (:e, :s, :c, 'missed_checkin', :p, :score, :title, :msg, :reason, :obs, 'open')"
                )->execute([
                    'e' => $eid, 's' => $sid, 'c' => $caregiverId,
                    'p' => $ass['priority'], 'score' => $ass['score'],
                    'title' => 'Missed check-in: ' . ($s['title'] ?? 'Welfare check-in'),
                    'msg' => "No valid check-in for the {$exp} expectation (deadline " . deadline_of($s) . ').',
                    'reason' => $ass['reasons'] ? implode('; ', $ass['reasons']) : 'First miss recorded',
                    'obs' => json_encode($ass['observations']),
                ]);
                $alerts++;
                audit('alert_created', 'alerts', (int) db()->lastInsertId(),
                    "missed_checkin for schedule {$sid} (priority {$ass['priority']})");
            }
            db()->commit();
        } catch (PDOException $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if ($e->getCode() === '23000') {
                continue; // unique key won a race - the alert exists, all good
            }
            error_log("monitor pass1 schedule {$sid}: " . $e->getMessage());
        }
    }
    return compact('checked', 'completed', 'missed', 'alerts');
}

/* =====================================================================
 * PASS 2 - escalation of unacknowledged alerts (fires ONCE per alert)
 * =================================================================== */
function pass_escalations(): array
{
    $escalated = $skipped = 0;
    $defaultDelay = (int) setting('default_escalation_minutes', '240');

    $rows = db()->query(
        "SELECT a.*, cs.escalation_minutes, ep.secondary_contact_user_id
         FROM alerts a
         JOIN elderly_profiles ep ON ep.id = a.elderly_id
         LEFT JOIN checkin_schedules cs ON cs.id = a.schedule_id
         WHERE a.status = 'open' AND a.escalated_at IS NULL"
    )->fetchAll();

    foreach ($rows as $a) {
        $aid = (int) $a['id'];
        $delayMin = isset($a['escalation_minutes']) ? (int) $a['escalation_minutes'] : $defaultDelay;
        $dueAt = (new DateTime($a['created_at']))->modify("+{$delayMin} minutes");
        if (new DateTime() < $dueAt) {
            continue; // delay has not elapsed yet
        }

        // Recipient must be an ACTIVE staff user and NOT the notified caregiver.
        $sec = $a['secondary_contact_user_id'];
        $valid = false;
        if ($sec !== null && (int) $sec !== (int) ($a['caregiver_id'] ?? 0)) {
            $u = db()->prepare("SELECT id FROM users WHERE id = :u AND status = 'active' AND role IN ('admin','caregiver')");
            $u->execute(['u' => $sec]);
            $valid = $u->fetchColumn() !== false;
        }

        if ($valid) {
            $upd = db()->prepare(
                "UPDATE alerts SET status = 'escalated', escalated_to = :to, escalated_at = NOW()
                 WHERE id = :id AND status = 'open' AND escalated_at IS NULL"
            );
            $upd->execute(['to' => $sec, 'id' => $aid]);
            if ($upd->rowCount() > 0) {
                $escalated++;
                audit('alert_escalated', 'alerts', $aid, "Escalated to user {$sec} after {$delayMin} min.");
            }
            continue;
        }

        // No valid recipient: audit ONE skip per alert (guarded, else every
        // 15-minute run would spam the log for the same alert).
        $seen = db()->prepare(
            "SELECT 1 FROM audit_logs WHERE action = 'escalation_skipped_no_contact'
             AND entity_type = 'alerts' AND entity_id = :id LIMIT 1"
        );
        $seen->execute(['id' => (string) $aid]);
        if ($seen->fetchColumn() === false) {
            audit('escalation_skipped_no_contact', 'alerts', $aid,
                'No active secondary contact differs from the notified caregiver.');
            $skipped++;
        }
    }
    return compact('escalated', 'skipped');
}

/* =====================================================================
 * PASS 3 - patterns without a single miss (silent / drift / stale)
 * Guard: at most ONE open pattern_flag per household at a time, so the
 * pattern pass can never pile alerts onto an already-flagged household.
 * =================================================================== */
function open_pattern_exists(int $eid): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM alerts WHERE elderly_id = :e AND alert_type = 'pattern_flag'
         AND status IN ('open','acknowledged','escalated') LIMIT 1"
    );
    $stmt->execute(['e' => $eid]);
    return $stmt->fetchColumn() !== false;
}

function insert_pattern_alert(int $eid, string $reasonExtra, array $extraObs): int
{
    $ass = risk_assess($eid, 'pattern');
    // Pattern flags floor at Attention (Stage 1 §4 table) - a silent
    // household should never file as Routine.
    $floor = (int) setting('attention_threshold', '30');
    if ($ass['score'] < $floor) {
        $ass['score'] = $floor;
        $ass['priority'] = 'attention';
    }
    $reasons = array_merge([$reasonExtra], $ass['reasons']);
    db()->prepare(
        "INSERT INTO alerts
         (elderly_id, schedule_id, caregiver_id, alert_type, priority, risk_score,
          title, message, reason, observations, status)
         VALUES (:e, NULL, :c, 'pattern_flag', :p, :score, :title, :msg, :reason, :obs, 'open')"
    )->execute([
        'e' => $eid, 'c' => primary_caregiver_id($eid),
        'p' => $ass['priority'], 'score' => $ass['score'],
        'title' => 'Pattern change detected',
        'msg' => $reasonExtra . '. Please review this household.',
        'reason' => implode('; ', $reasons),
        'obs' => json_encode($ass['observations'] + $extraObs),
    ]);
    return (int) db()->lastInsertId();
}

function pass_patterns(): array
{
    $patterns = $staleRaised = 0;
    $silentDays = (int) setting('pattern_silent_days', '7');
    $staleHours = (int) setting('pattern_stale_hours', '48');

    // 3a. SILENT forgotten household: active, nothing scheduled in the
    // monitor window AND no successful check-in in the silent window.
    $silent = db()->query(
        "SELECT ep.id FROM elderly_profiles ep
         WHERE ep.status = 'active'
           AND NOT EXISTS (SELECT 1 FROM checkin_schedules cs
                           WHERE cs.elderly_id = ep.id
                             AND cs.expected_at >= DATE_SUB(NOW(), INTERVAL " . (int) setting('monitor_window_days', '30') . " DAY))
           AND NOT EXISTS (SELECT 1 FROM checkin_records cr
                           WHERE cr.elderly_id = ep.id
                             AND cr.checkin_at >= DATE_SUB(NOW(), INTERVAL {$silentDays} DAY))"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($silent as $eid) {
        $eid = (int) $eid;
        if (open_pattern_exists($eid)) {
            continue;
        }
        $aid = insert_pattern_alert($eid,
            "No check-in for {$silentDays}+ days and nothing scheduled",
            ['pattern' => 'silent']);
        $patterns++;
        audit('alert_created', 'alerts', $aid, 'pattern_flag: silent household.');
    }

    // 3b. DRIFT: this week's completions < half the 3-week average (avg >= 2).
    $profiles = db()->query("SELECT id FROM elderly_profiles WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($profiles as $eid) {
        $eid = (int) $eid;
        if (open_pattern_exists($eid)) {
            continue;
        }
        $c = db()->prepare(
            'SELECT
               SUM(CASE WHEN checkin_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week,
               SUM(CASE WHEN checkin_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
                         AND checkin_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS prior3
             FROM checkin_records WHERE elderly_id = :e'
        );
        $c->execute(['e' => $eid]);
        $r = $c->fetch();
        $avg = ((int) $r['prior3']) / 3;
        if ($avg >= 2 && (int) $r['this_week'] < $avg / 2) {
            $aid = insert_pattern_alert($eid,
                "Only {$r['this_week']} check-ins this week vs usual " . round($avg, 1) . "/week",
                ['pattern' => 'drift', 'this_week' => (int) $r['this_week'], 'weekly_avg' => round($avg, 1)]);
            $patterns++;
            audit('alert_created', 'alerts', $aid, 'pattern_flag: drift.');
        }
    }

    // 3c. STALE case: open alert older than X hours with NO follow-up row ->
    // raise priority one step (no new alert; the case resurfaces on top).
    $stale = db()->prepare(
        "SELECT id, priority FROM alerts
         WHERE status = 'open' AND created_at < DATE_SUB(NOW(), INTERVAL {$staleHours} HOUR)
           AND NOT EXISTS (SELECT 1 FROM followup_actions fa WHERE fa.alert_id = alerts.id)"
    );
    $stale->execute();
    foreach ($stale->fetchAll() as $a) {
        $next = ['routine' => 'attention', 'attention' => 'urgent', 'urgent' => 'urgent'][$a['priority']];
        if ($next !== $a['priority']) {
            db()->prepare('UPDATE alerts SET priority = :p WHERE id = :id')
               ->execute(['p' => $next, 'id' => $a['id']]);
            $staleRaised++;
            audit('priority_raised', 'alerts', (int) $a['id'],
                "Stale {$staleHours}h+ without follow-up: {$a['priority']} -> {$next}.");
        }
    }
    return ['patterns' => $patterns, 'stale_raised' => $staleRaised];
}

/* =====================================================================
 * Orchestrator - called by CLI (below) and by admin/settings.php.
 * =================================================================== */
function run_monitor(string $trigger): array
{
    $t0 = microtime(true);
    $p1 = pass_missed_checkins();
    $p2 = pass_escalations();
    $p3 = pass_patterns();
    $summary = [
        'trigger' => $trigger,
        'checked' => $p1['checked'], 'completed' => $p1['completed'],
        'missed' => $p1['missed'], 'alerts' => $p1['alerts'],
        'escalated' => $p2['escalated'], 'esc_skipped' => $p2['skipped'],
        'patterns' => $p3['patterns'], 'stale_raised' => $p3['stale_raised'],
        'seconds' => round(microtime(true) - $t0, 2),
        'ran_at' => date('Y-m-d H:i:s'),
    ];
    audit('monitor_run', null, null, json_encode($summary));
    return $summary;
}

/* =====================================================================
 * Entry point: CLI runs immediately (with overlap lock); direct web hits
 * are refused; includes (settings.php) just gain the functions above.
 * =================================================================== */
if (PHP_SAPI === 'cli') {
    $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ewm-monitor.lock';
    $lock = fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another monitor run is active - exiting quietly.\n");
        exit(0);
    }
    $s = run_monitor('cron');
    echo '[' . $s['ran_at'] . "] monitor ({$s['trigger']}): "
       . "checked={$s['checked']} completed={$s['completed']} missed={$s['missed']} "
       . "alerts={$s['alerts']} escalated={$s['escalated']} esc_skipped={$s['esc_skipped']} "
       . "patterns={$s['patterns']} stale_raised={$s['stale_raised']} ({$s['seconds']}s)\n";
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct web access to the monitor is not allowed. Run it via cron or the admin settings page.');
}
