<?php
/**
 * File: ai/risk_engine.php
 * Stage: 7 FULL engine (upgraded in place from Stage 6 triage v0).
 *
 * Implements ALL of the Stage 1 scoring table (docs/05 §3.2):
 *  R-1 missed count (7d) · R-2 silence hours · R-3 miss rate (30d) ·
 *  R-4 pattern deviation (low volume OR repeated lateness) · R-5 visit
 *  recency · R-6 unacknowledged flag · R-7 help-request floor (60).
 * Priorities come from the tunable thresholds (urgent/attention settings).
 *
 * Contract (unchanged since v0, so cron/monitor_checkins.php needed ZERO
 * edits for this upgrade): risk_assess(int, string, array) returns
 * {score, priority, reasons[], observations[]}. Pure assessor - reads only.
 * A supervisor can recompute any score by hand from `observations` + the
 * table in docs/05 §3.2 / docs/12 §A. That recomputability IS the
 * "explainable AI" claim of this project - nothing more is promised.
 *
 * @return array{score:int, priority:string, reasons:string[], observations:array}
 */

declare(strict_types=1);

function risk_assess(int $elderlyId, string $context = 'general', array $extra = []): array
{
    $reasons = [];
    $score = 0;

    $urgentAt    = (int) setting('urgent_threshold', '60');
    $attentionAt = (int) setting('attention_threshold', '30');

    /* --- I-1: missed check-ins in the last 7 days (R-1a/b/c) --- */
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM checkin_schedules
         WHERE elderly_id = :e AND status = 'missed'
           AND expected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $stmt->execute(['e' => $elderlyId]);
    $missed7 = (int) $stmt->fetchColumn();
    if ($missed7 >= 3) {
        $score += 45;
        $reasons[] = '3+ missed check-ins in 7 days';
    } elseif ($missed7 === 2) {
        $score += 30;
        $reasons[] = '2 missed check-ins in 7 days';
    } elseif ($missed7 === 1) {
        $score += 15;
        $reasons[] = '1 missed check-in in 7 days';
    }

    /* --- I-2 + I-4: 30-day counts and miss rate (R-3) --- */
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM checkin_schedules
         WHERE elderly_id = :e AND status IN ('scheduled','completed','missed')
           AND expected_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $stmt->execute(['e' => $elderlyId]);
    $expected30 = (int) $stmt->fetchColumn();
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM checkin_schedules
         WHERE elderly_id = :e AND status = 'missed'
           AND expected_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $stmt->execute(['e' => $elderlyId]);
    $missed30 = (int) $stmt->fetchColumn();
    $rate30 = $expected30 > 0 ? $missed30 / $expected30 : 0.0;
    if ($expected30 >= 4 && $rate30 >= 0.5) {
        $score += 10;
        $reasons[] = 'Missed ' . (int) round($rate30 * 100) . '% of expected check-ins (30d)';
    }

    /* --- I-3: hours since last successful check-in (R-2/R-2b) --- */
    $stmt = db()->prepare(
        'SELECT MAX(checkin_at) FROM checkin_records WHERE elderly_id = :e'
    );
    $stmt->execute(['e' => $elderlyId]);
    $lastSuccess = $stmt->fetchColumn();
    $hoursSince = null;
    if ($lastSuccess) {
        $hoursSince = (int) floor((time() - strtotime((string) $lastSuccess)) / 3600);
    }
    if ($hoursSince === null) {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM checkin_schedules WHERE elderly_id = :e'
        );
        $stmt->execute(['e' => $elderlyId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $score += 20;
            $reasons[] = 'No successful check-in yet';
        }
    } elseif ($hoursSince > 72) {
        $score += 20;
        $reasons[] = "No successful check-in for {$hoursSince}h";
    } elseif ($hoursSince >= 48) {
        $score += 10;
        $reasons[] = "No successful check-in for {$hoursSince}h";
    }

    /* --- I-5: deviation from usual pattern (R-4): low volume OR lateness --- */
    $deviation = 0;
    $devDetail = '';
    // (a) Volume: this week < half the prior-3-week average (avg >= 2/week).
    $stmt = db()->prepare(
        'SELECT
           SUM(CASE WHEN checkin_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week,
           SUM(CASE WHEN checkin_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
                     AND checkin_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS prior3
         FROM checkin_records WHERE elderly_id = :e'
    );
    $stmt->execute(['e' => $elderlyId]);
    $vol = $stmt->fetch();
    $weeklyAvg = ((int) $vol['prior3']) / 3;
    if ($weeklyAvg >= 2 && (int) $vol['this_week'] < $weeklyAvg / 2) {
        $deviation = 1;
        $devDetail = "only {$vol['this_week']} this week vs usual " . round($weeklyAvg, 1) . '/week';
    }
    // (b) Lateness: 2+ arrivals past half-grace in 7 days (compared in PHP
    // for portability - no exotic SQL date maths).
    $late = 0;
    $stmt = db()->prepare(
        'SELECT cr.checkin_at, cs.expected_at, cs.grace_minutes
         FROM checkin_records cr
         JOIN checkin_schedules cs ON cs.id = cr.schedule_id
         WHERE cr.elderly_id = :e AND cr.checkin_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    );
    $stmt->execute(['e' => $elderlyId]);
    foreach ($stmt->fetchAll() as $r) {
        $halfGrace = (int) floor(((int) $r['grace_minutes']) / 2);
        $lateLine = (new DateTime($r['expected_at']))->modify("+{$halfGrace} minutes");
        if (new DateTime($r['checkin_at']) > $lateLine) {
            $late++;
        }
    }
    if ($late >= 2) {
        $deviation = 1;
        $devDetail = trim($devDetail . ($devDetail !== '' ? '; ' : '') . "{$late} late arrivals in 7 days");
    }
    if ($deviation) {
        $score += 10;
        $reasons[] = 'Check-in pattern changed vs usual' . ($devDetail !== '' ? " ({$devDetail})" : '');
    }

    /* --- I-6: days since last caregiver visit (R-5a/b) --- */
    $stmt = db()->prepare(
        'SELECT MAX(visit_date) FROM caregiver_visits WHERE elderly_id = :e'
    );
    $stmt->execute(['e' => $elderlyId]);
    $lastVisit = $stmt->fetchColumn();
    $daysSinceVisit = null;
    if ($lastVisit) {
        $daysSinceVisit = (int) floor((time() - strtotime((string) $lastVisit)) / 86400);
    } else {
        // Never visited: concerning only for established (>30d) profiles,
        // so brand-new registrations don't all flag at once.
        $stmt = db()->prepare('SELECT created_at FROM elderly_profiles WHERE id = :e');
        $stmt->execute(['e' => $elderlyId]);
        $created = $stmt->fetchColumn();
        $profileAgeDays = $created ? (int) floor((time() - strtotime((string) $created)) / 86400) : 0;
        if ($profileAgeDays > 30) {
            $daysSinceVisit = 999; // sentinel: established but never visited
        }
    }
    if ($daysSinceVisit !== null && $daysSinceVisit > 30) {
        $score += 15;
        $reasons[] = $daysSinceVisit === 999
            ? 'No caregiver visit yet (established household)'
            : "No caregiver visit for {$daysSinceVisit} days";
    } elseif ($daysSinceVisit !== null && $daysSinceVisit >= 14) {
        $score += 8;
        $reasons[] = "No caregiver visit for {$daysSinceVisit} days";
    }

    /* --- I-7: previous alert still unacknowledged (R-6) --- */
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM alerts
         WHERE elderly_id = :e AND status IN ('open','escalated')"
    );
    $stmt->execute(['e' => $elderlyId]);
    $hasUnacked = (int) $stmt->fetchColumn() > 0;
    if ($hasUnacked) {
        $score += 15;
        $reasons[] = 'Previous alert still unacknowledged';
    }

    /* --- I-8: direct cry for help floors the score at 60 (R-7) --- */
    $helpFlag = (int) ($extra['help_flag'] ?? 0);
    if ($helpFlag) {
        $score = max($score, 60);
        $reasons[] = 'Person requested assistance / reported unwell (direct report)';
    }

    $score = min(100, $score);
    $priority = $score >= $urgentAt ? 'urgent'
        : ($score >= $attentionAt ? 'attention' : 'routine');

    return [
        'score' => $score,
        'priority' => $priority,
        'reasons' => $reasons,
        'observations' => [
            'missed_7d' => $missed7,
            'missed_30d' => $missed30,
            'expected_30d' => $expected30,
            'overdue_rate_30d' => round($rate30, 2),
            'hours_since_success' => $hoursSince,
            'deviation_flag' => $deviation,
            'deviation_detail' => $devDetail,
            'late_arrivals_7d' => $late,
            'this_week' => (int) $vol['this_week'],
            'weekly_avg' => round($weeklyAvg, 1),
            'days_since_visit' => $daysSinceVisit,
            'has_unacked' => $hasUnacked ? 1 : 0,
            'help_flag' => $helpFlag,
            'context' => $context,
            'engine' => 'rule-engine-v1 (R-1..R-7)',
            'scored_at' => date('Y-m-d H:i:s'),
        ],
    ];
}
