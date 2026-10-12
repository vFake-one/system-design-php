<?php
/**
 * File: admin/dashboard.php
 * Stage: 3 PLACEHOLDER - proves admin-only access works end to end.
 * The full statistics dashboard (live counts + Chart.js) is built in Stage 8.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('admin', '../login.php'); // admins only - 403 for anyone else

$pageTitle  = 'Administrator dashboard';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Administrator dashboard</h1>
<p class="lead">Welcome, <?= e($user['full_name']) ?>. You are signed in as
    <span class="badge bg-primary"><?= e($user['role']) ?></span>.</p>
<div class="alert alert-info">
    Stage 3 placeholder: login + role check passed. The full dashboard with live
    statistics and charts arrives in <strong>Stage 8</strong>; household and
    caregiver management arrive in <strong>Stage 4</strong>.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
