<?php
/**
 * File: admin/audit.php
 * Stage: 8 - read-only audit-trail viewer (admin only, FR-45).
 *
 * Filters: actor (user), action (whitelisted against values actually in the
 * log), from/to dates. Paginated with the shared pager() helper (50/page).
 * Read-only by construction: every query below is SELECT-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/reports_lib.php';

$user = require_role('admin', '../login.php');

/* Action dropdown: every distinct action actually in the log. */
$actions = array_column(
    report_all('SELECT DISTINCT action FROM audit_logs ORDER BY action'),
    'action'
);

/* Filters (all optional; invalid input falls back to "no filter"). */
$actor = (int) ($_GET['actor'] ?? 0);
$action = (string) ($_GET['action'] ?? '');
if ($action !== '' && !in_array($action, $actions, true)) {
    $action = '';
}
[$from, $to] = report_range($_GET['from'] ?? null, $_GET['to'] ?? null);

$where = [];
$params = [];
if ($actor) {
    $where[] = 'a.user_id = ?';
    $params[] = $actor;
}
if ($action !== '') {
    $where[] = 'a.action = ?';
    $params[] = $action;
}
$where[] = 'DATE(a.created_at) BETWEEN ? AND ?';
$params[] = $from;
$params[] = $to;
$clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) report_value("SELECT COUNT(*) FROM audit_logs a {$clause}", $params);
$pg = pager($total, 50, (int) ($_GET['page'] ?? 1));

$rows = report_all(
    "SELECT a.id, a.action, a.entity_type, a.entity_id, a.details,
            a.created_at, u.full_name AS actor
       FROM audit_logs a
       LEFT JOIN users u ON u.id = a.user_id
       {$clause}
      ORDER BY a.id DESC
      LIMIT ? OFFSET ?",
    array_merge($params, [$pg['limit'], $pg['offset']])
);

$actors = report_all(
    'SELECT DISTINCT u.id, u.full_name
       FROM audit_logs a JOIN users u ON u.id = a.user_id
      ORDER BY u.full_name'
);

$pageTitle  = 'Audit trail';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Audit trail</h1>
<p class="text-muted">Read-only view of everything the system has recorded in
    <code>audit_logs</code> — newest first. <?= e((string) $total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?>
    match the current filters.</p>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label" for="f-actor">Actor</label>
        <select class="form-select" id="f-actor" name="actor">
            <option value="0">Anybody</option>
            <?php foreach ($actors as $a): ?>
                <option value="<?= (int) $a['id'] ?>"<?= (int) $a['id'] === $actor ? ' selected' : '' ?>>
                    <?= e($a['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="f-action">Action</label>
        <select class="form-select" id="f-action" name="action">
            <option value="">Any action</option>
            <?php foreach ($actions as $a): ?>
                <option value="<?= e($a) ?>"<?= $a === $action ? ' selected' : '' ?>><?= e($a) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="f-from">From</label>
        <input class="form-control" type="date" id="f-from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label" for="f-to">To</label>
        <input class="form-control" type="date" id="f-to" name="to" value="<?= e($to) ?>">
    </div>
    <div class="col-md-2">
        <button class="btn btn-primary w-100" type="submit">Apply</button>
    </div>
</form>

<div class="table-responsive"><table class="table table-striped table-sm">
    <thead><tr><th>#</th><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Detail</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-muted">No audit entries for the current filters.</td></tr>
    <?php else: ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= (int) $r['id'] ?></td>
                <td><?= e($r['created_at']) ?></td>
                <td><?= e($r['actor'] ?? '(system)') ?></td>
                <td><code><?= e($r['action']) ?></code></td>
                <td><?= e(trim(($r['entity_type'] ?? '') . ' #' . ($r['entity_id'] ?? ''), ' #')) ?></td>
                <td><?= e($r['details'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table></div>

<?= pager_links('audit.php', array_filter([
    'actor' => $actor ?: null,
    'action' => $action !== '' ? $action : null,
    'from' => $from,
    'to' => $to,
]), $pg['page'], $pg['pages']) ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
