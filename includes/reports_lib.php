<?php
/**
 * File: includes/reports_lib.php
 * Stage: 8 - the SINGLE query source for dashboards, reports and CSV exports.
 *
 * Design rule: every statistic on screen is computed HERE from live tables
 * (FR-43). Nothing is pre-computed, cached or hard-coded. Dashboards,
 * reports and CSV all call the same functions, so the three can never
 * disagree with each other.
 *
 * Scoping rule: $scope is null for admins (whole system) or an array of
 * elderly ids for caregivers (assigned_elderly_ids()). Every query funnels
 * through scope_sql(), so a forgotten filter is impossible by construction.
 *
 * Conventions match the rest of the codebase: db() for PDO, positional '?'
 * placeholders (IN-lists vary in length, and PDO forbids mixing named and
 * positional parameters in one query).
 */

declare(strict_types=1);

// Self-sufficient: needs db() from functions.php.
require_once __DIR__ . '/functions.php';

/** Max rows rendered/exported per report (guard against huge ranges). */
const REPORT_ROW_CAP = 500;

/**
 * scope_sql() - the household-confinement fragment.
 *
 * @param int[]|null $scope null = whole system; [] = nobody (match nothing).
 * @param string $alias table (alias) owning the id column.
 * @param string $column id column name ('elderly_id' everywhere except
 *               elderly_profiles itself, whose PK is 'id').
 * @return array{0:string, 1:array} [AND-fragment ('' when unscoped), params]
 */
function scope_sql(?array $scope, string $alias = 's', string $column = 'elderly_id'): array
{
    if ($scope === null) {
        return ['', []]; // admin: no confinement
    }
    if (!$scope) {
        return ['AND 1 = 0', []]; // caregiver with no households: empty, fast
    }
    $in = implode(',', array_fill(0, count($scope), '?'));
    return ["AND {$alias}.{$column} IN ({$in})", array_values($scope)];
}

/**
 * report_range() - validated [from, to] date pair (Y-m-d) for filters.
 * Garbage in -> safe default out (last 30 days); from > to swaps.
 *
 * @return array{0:string, 1:string} [from, to]
 */
function report_range(mixed $from, mixed $to): array
{
    $clean = function (mixed $v): ?string {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y) ? $v : null;
    };
    $from = $clean($from);
    $to = $clean($to);
    if ($from === null || $to === null) {
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-29 days'));
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

/** One scalar value (COUNT/SUM/...) or null. */
function report_value(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $v = $stmt->fetchColumn();
    return $v === false ? null : $v;
}

/** All rows as assoc arrays. */
function report_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * dashboard_stats() - every tile, chart series and the alert strip.
 *
 * "Unacknowledged" = status open OR escalated: an escalated alert still
 * awaits a HUMAN acknowledgement (alerts.php accepts ack on both).
 * "Active" alerts (charts/strip) = open + acknowledged + escalated,
 * the same definition as the alerts worklist filter.
 *
 * @param int[]|null $scope null = admin (system-wide); ids = caregiver.
 */
function dashboard_stats(?array $scope): array
{
    [$scS, $pS] = scope_sql($scope, 's');   // checkin_schedules alias s
    [$scA, $pA] = scope_sql($scope, 'a');   // alerts alias a
    [$scV, $pV] = scope_sql($scope, 'v');   // caregiver_visits alias v
    [$scF, $pF] = scope_sql($scope, 'f');   // followup_actions alias f
    [$scE, $pE] = scope_sql($scope, 'ep', 'id'); // elderly_profiles PK is id

    $st = [];
    $st['elderly_active'] = (int) report_value(
        "SELECT COUNT(*) FROM elderly_profiles ep WHERE ep.status = 'active' {$scE}", $pE);
    $st['caregivers_active'] = $scope === null
        ? (int) report_value(
            "SELECT COUNT(*) FROM users WHERE role = 'caregiver' AND status = 'active'")
        : 0; // meaningless when scoped; caregivers see my_households instead
    $st['my_households'] = $scope === null ? 0 : count($scope);

    $st['due_today'] = (int) report_value(
        "SELECT COUNT(*) FROM checkin_schedules s
          WHERE s.status = 'scheduled' AND DATE(s.expected_at) = CURDATE() {$scS}", $pS);
    $st['overdue_now'] = (int) report_value(
        "SELECT COUNT(*) FROM checkin_schedules s
          WHERE s.status = 'scheduled'
            AND s.expected_at + INTERVAL s.grace_minutes MINUTE < NOW() {$scS}", $pS);
    $st['completed_30d'] = (int) report_value(
        "SELECT COUNT(*) FROM checkin_schedules s
          WHERE s.status = 'completed' AND s.expected_at >= CURDATE() - INTERVAL 29 DAY {$scS}", $pS);
    $st['missed_30d'] = (int) report_value(
        "SELECT COUNT(*) FROM checkin_schedules s
          WHERE s.status = 'missed' AND s.expected_at >= CURDATE() - INTERVAL 29 DAY {$scS}", $pS);

    $st['unacked'] = (int) report_value(
        "SELECT COUNT(*) FROM alerts a WHERE a.status IN ('open','escalated') {$scA}", $pA);
    $st['urgent_open'] = (int) report_value(
        "SELECT COUNT(*) FROM alerts a
          WHERE a.priority = 'urgent'
            AND a.status IN ('open','acknowledged','escalated') {$scA}", $pA);
    $st['visits_7d'] = (int) report_value(
        "SELECT COUNT(*) FROM caregiver_visits v
          WHERE v.visit_date >= CURDATE() - INTERVAL 6 DAY {$scV}", $pV);
    $st['followups_planned'] = (int) report_value(
        "SELECT COUNT(*) FROM followup_actions f WHERE f.status = 'planned' {$scF}", $pF);

    /* 14-day chart: completed vs missed per expected day (oldest -> today). */
    $st['chart_labels'] = $st['chart_completed'] = $st['chart_missed'] = [];
    $dayRows = report_all(
        "SELECT DATE(s.expected_at) AS d, s.status, COUNT(*) AS c
           FROM checkin_schedules s
          WHERE s.status IN ('completed','missed')
            AND s.expected_at >= CURDATE() - INTERVAL 13 DAY {$scS}
          GROUP BY DATE(s.expected_at), s.status", $pS);
    $byDay = [];
    foreach ($dayRows as $r) {
        $byDay[$r['d']][$r['status']] = (int) $r['c'];
    }
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $st['chart_labels'][] = date('d M', strtotime($d));
        $st['chart_completed'][] = $byDay[$d]['completed'] ?? 0;
        $st['chart_missed'][] = $byDay[$d]['missed'] ?? 0;
    }

    /* Alert mix: active alerts by priority. */
    $mix = ['routine' => 0, 'attention' => 0, 'urgent' => 0];
    foreach (report_all(
        "SELECT a.priority, COUNT(*) AS c FROM alerts a
          WHERE a.status IN ('open','acknowledged','escalated') {$scA}
          GROUP BY a.priority", $pA) as $r) {
        $mix[$r['priority']] = (int) $r['c'];
    }
    $st['mix'] = $mix;

    /* Top 5 active alerts: urgent first, then newest. */
    $st['recent_alerts'] = report_all(
        "SELECT a.id, ep.full_name AS household, a.title, a.priority, a.status, a.created_at
           FROM alerts a
           JOIN elderly_profiles ep ON ep.id = a.elderly_id
          WHERE a.status IN ('open','acknowledged','escalated') {$scA}
          ORDER BY FIELD(a.priority, 'urgent', 'attention', 'routine'), a.created_at DESC
          LIMIT 5", $pA);

    return $st;
}

/** Optional single-household filter fragment (AND ... + param). */
function hh_sql(?int $hh, string $alias = 's', string $column = 'elderly_id'): array
{
    return $hh ? ["AND {$alias}.{$column} = ?", [$hh]] : ['', []];
}

/* --- The five reports. Each returns ['rows', 'capped']. ------------------ */

/** Per-household completion: scheduled / completed / missed / % / longest gap. */
function report_completion(?array $scope, ?int $hh, string $from, string $to): array
{
    /* NOTE: scoped on ep (not s) so assigned households with zero schedules
       in range still appear with 0s instead of vanishing (LEFT JOIN stays left). */
    [$sc, $p] = scope_sql($scope, 'ep', 'id');
    [$hhSql, $hhP] = hh_sql($hh, 'ep', 'id');
    $rows = report_all(
        "SELECT ep.id, ep.full_name AS household,
                SUM(s.status <> 'cancelled') AS scheduled,
                SUM(s.status = 'completed') AS completed,
                SUM(s.status = 'missed') AS missed
           FROM elderly_profiles ep
           LEFT JOIN checkin_schedules s
             ON s.elderly_id = ep.id
            AND DATE(s.expected_at) BETWEEN ? AND ?
          WHERE ep.status = 'active' {$sc} {$hhSql}
          GROUP BY ep.id, ep.full_name
          ORDER BY ep.full_name",
        array_merge([$from, $to], $p, $hhP));
    /* Longest gap between actual submissions, per household (PHP-side: the
       "previous row" logic is clearer here than in SQL for Year 2). */
    foreach ($rows as &$r) {
        $sched = (int) $r['scheduled'];
        $done = (int) $r['completed'];
        $r['pct'] = $sched > 0 ? round($done * 100 / $sched, 1) : null;
        $stamps = report_all(
            'SELECT checkin_at FROM checkin_records
              WHERE elderly_id = ? AND DATE(checkin_at) BETWEEN ? AND ?
              ORDER BY checkin_at',
            [(int) $r['id'], $from, $to]);
        $gap = 0;
        $prev = null;
        foreach ($stamps as $t) {
            if ($prev !== null) {
                $gap = max($gap, (int) floor(
                    (strtotime($t['checkin_at']) - strtotime($prev)) / 86400));
            }
            $prev = $t['checkin_at'];
        }
        $r['gap'] = count($stamps) < 2 ? null : $gap;
        $r = [$r['household'], (int) $r['scheduled'], (int) $r['completed'],
              (int) $r['missed'], $r['pct'] === null ? '—' : $r['pct'] . '%',
              $r['gap'] ?? '—'];
    }
    unset($r);
    $capped = count($rows) > REPORT_ROW_CAP;
    return ['rows' => array_slice($rows, 0, REPORT_ROW_CAP), 'capped' => $capped];
}

/** Every missed schedule in range, newest first. */
function report_missed(?array $scope, ?int $hh, string $from, string $to): array
{
    [$sc, $p] = scope_sql($scope, 's');
    [$hhSql, $hhP] = hh_sql($hh, 's');
    $rows = report_all(
        "SELECT s.id, ep.full_name AS household, s.title, s.expected_at,
                s.expected_at + INTERVAL s.grace_minutes MINUTE AS window_end,
                DATEDIFF(NOW(), s.expected_at) AS days_ago
           FROM checkin_schedules s
           JOIN elderly_profiles ep ON ep.id = s.elderly_id
          WHERE s.status = 'missed' AND DATE(s.expected_at) BETWEEN ? AND ?
          {$sc} {$hhSql}
          ORDER BY s.expected_at DESC",
        array_merge([$from, $to], $p, $hhP));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [$r['id'], $r['household'], $r['title'] ?? 'Welfare check-in',
                  $r['expected_at'], $r['window_end'], $r['days_ago']];
    }
    return capped_from_rows($out);
}

/** Alerts opened in range + first-response lag (first acknowledgement). */
function report_alerts(?array $scope, ?int $hh, string $from, string $to): array
{
    [$sc, $p] = scope_sql($scope, 'a');
    [$hhSql, $hhP] = hh_sql($hh, 'a');
    $rows = report_all(
        "SELECT a.id, ep.full_name AS household, a.title, a.priority, a.status,
                a.created_at,
                (SELECT MIN(k.acknowledged_at) FROM alert_acknowledgements k
                  WHERE k.alert_id = a.id) AS first_ack
           FROM alerts a
           JOIN elderly_profiles ep ON ep.id = a.elderly_id
          WHERE DATE(a.created_at) BETWEEN ? AND ? {$sc} {$hhSql}
          ORDER BY a.created_at DESC",
        array_merge([$from, $to], $p, $hhP));
    $out = [];
    foreach ($rows as $r) {
        $lag = '—';
        if ($r['first_ack'] !== null) {
            $mins = (int) floor(
                (strtotime($r['first_ack']) - strtotime($r['created_at'])) / 60);
            $lag = $mins < 60 ? $mins . ' min'
                : floor($mins / 60) . 'h ' . ($mins % 60) . 'm';
        }
        $out[] = [$r['id'], $r['household'], $r['title'], $r['priority'],
                  $r['status'], $r['created_at'], $lag];
    }
    return capped_from_rows($out);
}

/** Visits in range, newest first ('(removed)' when the account is gone). */
function report_visits(?array $scope, ?int $hh, string $from, string $to): array
{
    [$sc, $p] = scope_sql($scope, 'v');
    [$hhSql, $hhP] = hh_sql($hh, 'v');
    $rows = report_all(
        "SELECT v.id, ep.full_name AS household, u.full_name AS caregiver,
                v.visit_type, v.visit_date, v.outcome
           FROM caregiver_visits v
           JOIN elderly_profiles ep ON ep.id = v.elderly_id
           LEFT JOIN users u ON u.id = v.caregiver_id
          WHERE DATE(v.visit_date) BETWEEN ? AND ? {$sc} {$hhSql}
          ORDER BY v.visit_date DESC",
        array_merge([$from, $to], $p, $hhP));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [$r['id'], $r['household'], $r['caregiver'] ?? '(removed)',
                  $r['visit_type'], $r['visit_date'], $r['outcome']];
    }
    return capped_from_rows($out);
}

/** Follow-up actions in range + days open (planned ones only count). */
function report_followups(?array $scope, ?int $hh, string $from, string $to): array
{
    [$sc, $p] = scope_sql($scope, 'f');
    [$hhSql, $hhP] = hh_sql($hh, 'f');
    $rows = report_all(
        "SELECT f.id, f.alert_id, ep.full_name AS household, f.action_type,
                u.full_name AS caregiver, f.status, f.action_date, f.created_at
           FROM followup_actions f
           JOIN elderly_profiles ep ON ep.id = f.elderly_id
           LEFT JOIN users u ON u.id = f.caregiver_id
          WHERE DATE(f.action_date) BETWEEN ? AND ? {$sc} {$hhSql}
          ORDER BY f.action_date DESC",
        array_merge([$from, $to], $p, $hhP));
    $out = [];
    foreach ($rows as $r) {
        $open = $r['status'] === 'planned'
            ? (int) floor((time() - strtotime($r['created_at'])) / 86400)
            : '—';
        $out[] = [$r['id'], $r['alert_id'], $r['household'], $r['action_type'],
                  $r['caregiver'] ?? '(removed)', $r['status'],
                  $r['action_date'], $open];
    }
    return capped_from_rows($out);
}

/** Apply REPORT_ROW_CAP to an already-built row list. */
function capped_from_rows(array $out): array
{
    $capped = count($out) > REPORT_ROW_CAP;
    return ['rows' => array_slice($out, 0, REPORT_ROW_CAP), 'capped' => $capped];
}

/**
 * csv_cell() - formula-injection guard. A cell whose first character is
 * = + - @ could execute as a formula in Excel/Sheets, so it is neutralized
 * with a leading apostrophe (the standard OWASP recommendation).
 */
function csv_cell(mixed $cell): string
{
    $s = (string) ($cell ?? '');
    if ($s !== '' && str_contains('=+-@', $s[0])) {
        $s = "'" . $s;
    }
    return $s;
}

/**
 * csv_download() - stream $rows as a CSV file and stop. The caller passes
 * the EXACT rows shown on screen, so exports always match the filters.
 */
function csv_download(string $filename, array $columns, array $rows): void
{
    /* Discard any buffered page output: export and HTML are mutually exclusive. */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM: Excel opens accents correctly
    fputcsv($out, array_map('csv_cell', $columns));
    foreach ($rows as $r) {
        fputcsv($out, array_map('csv_cell', array_values($r)));
    }
    fclose($out);
    exit;
}
