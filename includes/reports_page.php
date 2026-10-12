<?php
/**
 * File: includes/reports_page.php
 * Stage: 8 - SHARED reports renderer. Included by admin/reports.php (scope
 * null) and caregiver/reports.php (scope = assigned ids). The caller must,
 * BEFORE the include: load auth.php + csrf.php + reports_lib.php, pass
 * require_role(), set $scope, and call ob_start() (the CSV branch discards
 * buffered HTML so it can send a file instead).
 *
 * Tabs: completion | missed | alerts | visits | followups.
 * Filters: household, from, to. CSV export via &export=csv (read-only GET,
 * reuses the same filtered dataset - what you see is what you export).
 */

declare(strict_types=1);

/* --- 1. Which tab? (whitelist) --- */
$tabs = [
    'completion' => 'Check-in completion',
    'missed'     => 'Missed check-ins',
    'alerts'     => 'Alert response',
    'visits'     => 'Caregiver visits',
    'followups'  => 'Follow-up actions',
];
$tab = (string) ($_GET['tab'] ?? 'completion');
if (!isset($tabs[$tab])) {
    $tab = 'completion';
}

/* --- 2. Filters --- */
$scope = $scope ?? null;
[$from, $to] = report_range($_GET['from'] ?? null, $_GET['to'] ?? null);
$hhFilter = (int) ($_GET['hh'] ?? 0);

/* Household dropdown: scoped the same way, so nobody sees foreign names. */
if ($scope !== null && !$scope) {
    $hhRows = [];
} else {
    [$hhSc, $hhP] = scope_sql($scope, 'ep', 'id');
    $hhRows = report_all(
        "SELECT ep.id, ep.full_name FROM elderly_profiles ep
          WHERE ep.status = 'active' {$hhSc} ORDER BY ep.full_name", $hhP);
}
if ($hhFilter && $scope !== null && !in_array($hhFilter, $scope, true)) {
    $hhFilter = 0; // tampered hh= id: silently ignore, stay in scope
}
$hhPick = $hhFilter ?: null;

/* --- 3. Run the report --- */
switch ($tab) {
    case 'completion':
        $rep = report_completion($scope, $hhPick, $from, $to);
        $cols = ['Household', 'Scheduled', 'Completed', 'Missed', 'Completion %', 'Longest gap (days)'];
        break;
    case 'missed':
        $rep = report_missed($scope, $hhPick, $from, $to);
        $cols = ['Schedule #', 'Household', 'Title', 'Expected at', 'Window end', 'Days ago'];
        break;
    case 'alerts':
        $rep = report_alerts($scope, $hhPick, $from, $to);
        $cols = ['Alert #', 'Household', 'Title', 'Priority', 'Status', 'Opened', 'First response lag'];
        break;
    case 'visits':
        $rep = report_visits($scope, $hhPick, $from, $to);
        $cols = ['Visit #', 'Household', 'Caregiver', 'Type', 'Date', 'Outcome'];
        break;
    default: // followups
        $rep = report_followups($scope, $hhPick, $from, $to);
        $cols = ['Action #', 'Alert #', 'Household', 'Action', 'By', 'Status', 'Action date', 'Days open'];
        break;
}

/* --- 4. CSV export (before ANY html output; the caller started ob_start) --- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    csv_download($tab . '_report_' . $from . '_to_' . $to . '.csv', $cols, $rep['rows']);
}

/* --- 5. Render --- */
$self = 'reports.php';
function tab_url(string $self, string $which, string $from, string $to, int $hh): string
{
    $q = ['tab' => $which, 'from' => $from, 'to' => $to];
    if ($hh) {
        $q['hh'] = $hh;
    }
    return $self . '?' . http_build_query($q);
}
?>
<p class="text-muted">Every report is a live database query over the current filters —
    nothing is pre-computed or hard-coded. CSV exports contain the same rows you see below.</p>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link<?= $key === $tab ? ' active' : '' ?>"
               href="<?= e(tab_url($self, $key, $from, $to, $hhFilter)) ?>"><?= e($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="col-md-4">
        <label class="form-label" for="f-hh">Household</label>
        <select class="form-select" id="f-hh" name="hh">
            <option value="0">All households</option>
            <?php foreach ($hhRows as $h): ?>
                <option value="<?= (int) $h['id'] ?>"<?= (int) $h['id'] === $hhFilter ? ' selected' : '' ?>>
                    <?= e($h['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="f-from">From</label>
        <input class="form-control" type="date" id="f-from" name="from" value="<?= e($from) ?>" max="<?= e($to) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="f-to">To</label>
        <input class="form-control" type="date" id="f-to" name="to" value="<?= e($to) ?>">
    </div>
    <div class="col-md-2">
        <button class="btn btn-primary w-100" type="submit">Apply</button>
    </div>
</form>

<div class="d-flex justify-content-between align-items-center mb-2">
    <strong><?= e((string) count($rep['rows'])) ?> row(s)
        <?= $rep['capped'] ? '(showing first ' . REPORT_ROW_CAP . ' — narrow the filters to see more)' : '' ?></strong>
    <a class="btn btn-sm btn-outline-success"
       href="<?= e(tab_url($self, $tab, $from, $to, $hhFilter) . '&export=csv') ?>">Export CSV</a>
</div>

<div class="table-responsive"><table class="table table-striped table-sm">
    <thead><tr><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php if (!$rep['rows']): ?>
        <tr><td colspan="<?= count($cols) ?>" class="text-muted">No rows for the current filters.</td></tr>
    <?php else: ?>
        <?php foreach ($rep['rows'] as $r): ?>
            <tr><?php foreach (array_values($r) as $cell): ?><td><?= e((string) ($cell ?? '')) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table></div>
