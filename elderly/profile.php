<?php
/**
 * File: elderly/profile.php
 * Stage: 5 - the older person's OWN profile summary + schedules + history.
 * Strictly own profile (can_access_elderly); read-only; plain language.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('elderly', '../login.php');
$eid = (int) $user['elderly_profile_id'];

if (!can_access_elderly($user, $eid)) {
    http_response_code(403);
    $pageTitle = 'Not allowed';
    $rootPrefix = '../';
    require_once __DIR__ . '/../includes/header.php';
    echo '<h1>403 - Not allowed</h1><p>No household is linked to this login.</p>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$ep = db()->prepare(
    'SELECT ep.*, sc.full_name AS secondary_name
     FROM elderly_profiles ep
     LEFT JOIN users sc ON sc.id = ep.secondary_contact_user_id
     WHERE ep.id = :e'
);
$ep->execute(['e' => $eid]);
$profile = $ep->fetch();

$pcStmt = db()->prepare(
    "SELECT u.full_name, u.phone FROM caregiver_assignments ca
     JOIN users u ON u.id = ca.caregiver_id
     WHERE ca.elderly_id = :e AND ca.status = 'active'
     ORDER BY ca.is_primary DESC, u.full_name ASC"
);
$pcStmt->execute(['e' => $eid]);
$carers = $pcStmt->fetchAll();

$schedStmt = db()->prepare(
    "SELECT * FROM checkin_schedules WHERE elderly_id = :e AND status = 'scheduled'
     ORDER BY expected_at ASC LIMIT 10"
);
$schedStmt->execute(['e' => $eid]);
$schedules = $schedStmt->fetchAll();

$histStmt = db()->prepare(
    'SELECT checkin_at, wellbeing_status FROM checkin_records
     WHERE elderly_id = :e ORDER BY checkin_at DESC LIMIT 15'
);
$histStmt->execute(['e' => $eid]);
$history = $histStmt->fetchAll();

$pageTitle  = 'My profile';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="elderly-zone">
    <h1>My profile</h1>
    <dl>
        <dt>Name</dt><dd><?= e($profile['full_name']) ?></dd>
        <dt>My phone</dt><dd><?= e($profile['phone'] ?? '—') ?></dd>
        <dt>My address</dt><dd><?= e($profile['address'] ?? '—') ?></dd>
        <dt>Emergency contact</dt><dd><?= e($profile['emergency_contact_name'] ?? '—') ?>
            <?= e($profile['emergency_contact_phone'] ?? '') ?></dd>
    </dl>

    <h2 class="h4">My caregivers</h2>
    <?php if (!$carers): ?>
        <p>No caregiver is assigned yet.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($carers as $c): ?>
                <li><?= e($c['full_name']) ?><?= $c['phone'] ? ' — ' . e($c['phone']) : '' ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2 class="h4">My upcoming check-ins</h2>
    <?php if (!$schedules): ?>
        <p>Nothing scheduled right now.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($schedules as $s): ?>
                <li><?= e((new DateTime($s['expected_at']))->format('l, j F Y \a\t H:i')) ?>
                    <?= is_overdue($s) ? ' (overdue — please check in)' : '' ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2 class="h4">My check-in history</h2>
    <?php if (!$history): ?>
        <p>No check-ins yet.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($history as $h): ?>
                <li><?= e((new DateTime($h['checkin_at']))->format('l, j F Y \a\t H:i')) ?> —
                    <?= $h['wellbeing_status'] === 'ok' ? 'Well.' : 'Asked for help.' ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
