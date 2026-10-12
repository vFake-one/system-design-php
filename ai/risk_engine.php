<?php
/**
 * File: ai/risk_engine.php
 * Stage: 6 creates it as TRIAGE v0; Stage 7 extends it to the full engine.
 *
 * v0 uses 3 of the 8 Stage-1 inputs (R-1 missed count, R-2 silence hours,
 * R-6 unacknowledged flag) - enough to triage missed-check-in and pattern
 * alerts honestly. Stage 7 adds overdue-rate, pattern deviation, visit
 * recency, and help-floor integration WITHOUT changing this file's function
 * signature or return shape, so the monitor needs no rewrite.
 *
 * Pure assessor: reads the DB, returns score + priority + reasons. It never
 * writes - the caller (monitor) stores the assessment with the alert.
 *
 * @return array{score:int, priority:string, reasons:string[], observations:array}
 *   priority is one of: routine | attention | urgent
 */

declare(strict_types=1);

function risk_assess(int $elderlyId, string $context = 'general'): array
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
        // No success EVER: concerning only if schedules were expected.
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

    /* --- I-7: a previous alert is still unacknowledged (R-6) --- */
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

    $score = min(100, $score);
    $priority = $score >= $urgentAt ? 'urgent'
        : ($score >= $attentionAt ? 'attention' : 'routine');

    return [
        'score' => $score,
        'priority' => $priority,
        'reasons' => $reasons,
        'observations' => [
            'missed_7d' => $missed7,
            'hours_since_success' => $hoursSince,
            'has_unacked' => $hasUnacked ? 1 : 0,
            'context' => $context,
            'engine' => 'triage-v0 (R-1/R-2/R-6)',
            'scored_at' => date('Y-m-d H:i:s'),
        ],
    ];
}
