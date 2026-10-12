<?php
/**
 * File: caregiver/households.php
 * Stage: 4 - MY assigned households (caregiver only).
 * The query itself joins caregiver_assignments, so a caregiver can NEVER
 * list - or guess the URL of - another caregiver's households (FR-04).
 * Detail view: ../admin/elderly_view.php (shared page, assignment-checked).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('caregiver', '../login.php');

$stmt = db()->prepare(
    "SELECT ep.id, ep.full_name, ep.phone, ep.living_situation,
            ca.is_primary, ca.assigned_at
     FROM caregiver_assignments ca
     JOIN elderly_profiles ep ON ep.id = ca.elderly_id
     WHERE ca.caregiver_id = :cid AND ca.status = 'active'
       AND ep.status = 'active'
     ORDER BY ep.full_name ASC"
);
$stmt->execute(['cid' => $user['id']]);
$rows = $stmt->fetchAll();

$pageTitle  = 'My households';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>My households</h1>
<p class="text-muted">Households currently assigned to you. Check-in scheduling for
    them arrives in Stage 5.</p>

<?php if (!$rows): ?>
    <div class="alert alert-info">No households are assigned to you yet. Please contact
        your administrator.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-striped align-middle">
    <thead><tr><th>Name</th><th>Phone</th><th>Living situation</th><th>Role</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['full_name']) ?></td>
            <td><?= e($r['phone'] ?? '—') ?></td>
            <td><?= e($r['living_situation'] ?? '—') ?></td>
            <td><?= (int) $r['is_primary'] === 1
                    ? '<span class="badge bg-primary">primary</span>'
                    : '<span class="badge bg-secondary">secondary</span>' ?></td>
            <td><a class="btn btn-sm btn-outline-primary"
                   href="../admin/elderly_view.php?id=<?= (int) $r['id'] ?>">View</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
