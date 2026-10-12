<?php
/**
 * File: elderly/checkin.php
 * Stage: 3 PLACEHOLDER - proves elderly/family-only access works end to end.
 * The simple large-text check-in form itself arrives in Stage 5.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('elderly', '../login.php'); // elderly/family only

$pageTitle  = 'My check-ins';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="elderly-zone">
    <h1>Hello, <?= e($user['full_name']) ?></h1>
    <p>You are signed in. Your simple check-in page arrives in
        <strong>Stage 5</strong>.</p>
    <p>What you will be able to do here:</p>
    <ul>
        <li>See when your next check-in is due.</li>
        <li>Press one big button to say you are well.</li>
        <li>Press one big button to ask for help.</li>
    </ul>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
