<?php
/**
 * File: caregiver/dashboard.php
 * Stage: 3 PLACEHOLDER - proves caregiver-only access works end to end.
 * Assigned households, due check-ins and alerts arrive in Stages 5-6.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('caregiver', '../login.php'); // caregivers only

$pageTitle  = 'Caregiver dashboard';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Caregiver dashboard</h1>
<p class="lead">Welcome, <?= e($user['full_name']) ?>. You are signed in as
    <span class="badge bg-primary"><?= e($user['role']) ?></span>.</p>
<div class="alert alert-info">
    Stage 3 placeholder: login + role check passed. Your assigned households,
    due check-ins and alerts arrive in <strong>Stages 5-6</strong>.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
