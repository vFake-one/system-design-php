<?php
/**
 * File: admin/elderly.php
 * Stage: 4 - elderly household LIST (admin only).
 * Search by name/phone, filter by status, 10 per page, archive/restore.
 * Register (create) and edit live in elderly_form.php; details in elderly_view.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('admin', '../login.php');

/* --- POST: archive / restore (status flip; history is never deleted) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $id     = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id > 0 && in_array($action, ['archive', 'restore'], true)) {
        $newStatus = $action === 'archive' ? 'archived' : 'active';
        $stmt = db()->prepare('UPDATE elderly_profiles SET status = :st WHERE id = :id');
        $stmt->execute(['st' => $newStatus, 'id' => $id]);
        if ($stmt->rowCount() > 0) {
            audit($action === 'archive' ? 'archive_elderly' : 'restore_elderly', 'elderly_profiles', $id);
            flash('success', $action === 'archive' ? 'Household archived (history kept).' : 'Household restored to active.');
        } else {
            flash('warning', 'Household not found - it may have been removed.');
        }
    }
    redirect('elderly.php'); // POST-redirect-GET: refresh never re-submits
}

/* --- GET filters ------------------------------------------------------ */
$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'open'; // open = active+inactive (default hides archived)
if (!in_array($status, ['open', 'all', 'active', 'inactive', 'archived'], true)) {
    $status = 'open';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$where  = [];
$params = [];
if ($q !== '') {
    $where[]         = '(ep.full_name LIKE :q OR ep.phone LIKE :q)';
    $params['q']     = '%' . $q . '%';
}
if ($status === 'open') {
    $where[] = "ep.status IN ('active','inactive')"; // fixed strings, no user input
} elseif ($status !== 'all') {
    $where[]         = 'ep.status = :status';
    $params['status'] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM elderly_profiles ep {$whereSql}");
$countStmt->execute($params);
$pg = pager((int) $countStmt->fetchColumn(), 10, $page);

/* Primary caregiver per household via LEFT JOIN (may be none yet). */
$listStmt = db()->prepare(
    "SELECT ep.id, ep.full_name, ep.phone, ep.living_situation, ep.status,
            u.full_name AS primary_caregiver
     FROM elderly_profiles ep
     LEFT JOIN caregiver_assignments ca
       ON ca.elderly_id = ep.id AND ca.is_primary = 1 AND ca.status = 'active'
     LEFT JOIN users u ON u.id = ca.caregiver_id
     {$whereSql}
     ORDER BY ep.full_name ASC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue('limit', $pg['limit'], PDO::PARAM_INT);
$listStmt->bindValue('offset', $pg['offset'], PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'archived' => 'dark'];

$pageTitle  = 'Elderly households';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Elderly households</h1>
    <a class="btn btn-primary" href="elderly_form.php">+ Register household</a>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-6">
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>"
               placeholder="Search name or phone…" maxlength="100">
    </div>
    <div class="col-md-4">
        <select class="form-select" name="status">
            <?php foreach (['open' => 'Active + inactive', 'all' => 'All statuses', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= $status === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
</form>

<p class="text-muted"><?= e((string) $pg['total']) ?> household(s) found.</p>

<?php if (!$rows): ?>
    <div class="alert alert-info">No households match.
        <?php if ($q === '' && $status === 'open'): ?>Register the first one above.<?php endif; ?>
    </div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead><tr><th>Name</th><th>Phone</th><th>Living situation</th><th>Primary caregiver</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['full_name']) ?></td>
            <td><?= e($r['phone'] ?? '—') ?></td>
            <td><?= e($r['living_situation'] ?? '—') ?></td>
            <td><?= e($r['primary_caregiver'] ?? '— none —') ?></td>
            <td><span class="badge bg-<?= e($statusBadge[$r['status']] ?? 'secondary') ?>"><?= e($r['status']) ?></span></td>
            <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="elderly_view.php?id=<?= (int) $r['id'] ?>">View</a>
                <a class="btn btn-sm btn-outline-secondary" href="elderly_form.php?id=<?= (int) $r['id'] ?>">Edit</a>
                <form method="post" action="elderly.php" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <?php if ($r['status'] === 'archived'): ?>
                        <input type="hidden" name="action" value="restore">
                        <button class="btn btn-sm btn-outline-success" type="submit">Restore</button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="archive">
                        <button class="btn btn-sm btn-outline-dark" type="submit"
                                data-confirm="Archive <?= e($r['full_name']) ?>? History is kept.">Archive</button>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?= pager_links('elderly.php', ['q' => $q, 'status' => $status], $pg['page'], $pg['pages']) ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
