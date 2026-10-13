<?php
/**
 * File: elderly/checkin.php
 * Stage: 5 (FULL version) - the older person's own page. Design rules (NFR-03):
 * one idea per screen, huge buttons, plain words, no jargon, no scores.
 * Two actions: "I am well" and "I need help" (help opens an URGENT alert).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('elderly', '../login.php');
$eid = (int) $user['elderly_profile_id'];

/* --- POST: well / help --------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        $kind  = $_POST['kind'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        if (!in_array($kind, ['well', 'help'], true)) {
            throw new RuntimeException('Please choose one of the two buttons.');
        }
        $open = find_open_schedule($eid); // attach to the due schedule if any
        $res = submit_checkin($eid, $open ? (int) $open['id'] : null,
            (int) $user['id'], 'elderly', 'self_online',
            $kind === 'well' ? 'ok' : 'needs_assistance',
            $notes === '' ? null : $notes);
        flash('success', $kind === 'well'
            ? 'Thank you! Your check-in has been recorded.'
            : 'Your request for help has been sent. Your caregiver has been notified urgently.');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('checkin.php');
}

/* --- What to show ---------------------------------------------------------- */
$ep = db()->prepare('SELECT full_name FROM elderly_profiles WHERE id = :e');
$ep->execute(['e' => $eid]);
$name = $ep->fetchColumn() ?: $user['full_name'];

// Next due (nearest scheduled, even slightly future) + overdue count.
$nextStmt = db()->prepare(
    "SELECT * FROM checkin_schedules
     WHERE elderly_id = :e AND status = 'scheduled' ORDER BY expected_at ASC LIMIT 1"
);
$nextStmt->execute(['e' => $eid]);
$next = $nextStmt->fetch();
$odStmt = db()->prepare(
    "SELECT COUNT(*) FROM checkin_schedules
     WHERE elderly_id = :e AND status = 'scheduled'
       AND DATE_ADD(expected_at, INTERVAL grace_minutes MINUTE) < NOW()"
);
$odStmt->execute(['e' => $eid]);
$overdueCount = (int) $odStmt->fetchColumn();

// Last own check-in + recent history (simple words only).
$lastStmt = db()->prepare(
    'SELECT checkin_at FROM checkin_records WHERE elderly_id = :e ORDER BY checkin_at DESC LIMIT 1'
);
$lastStmt->execute(['e' => $eid]);
$last = $lastStmt->fetchColumn();
$histStmt = db()->prepare(
    'SELECT checkin_at, wellbeing_status FROM checkin_records
     WHERE elderly_id = :e ORDER BY checkin_at DESC LIMIT 5'
);
$histStmt->execute(['e' => $eid]);
$history = $histStmt->fetchAll();

$pageTitle  = 'My check-ins';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="elderly-zone">
    <h1>Hello, <?= e($name) ?></h1>

    <?php if ($overdueCount > 0): ?>
        <div class="alert alert-warning">You have <?= e((string) $overdueCount) ?> overdue
            check-in(s). Please press <strong>I am well</strong> below.</div>
    <?php elseif ($next): ?>
        <p>Your next check-in is due on
            <strong><?= e((new DateTime($next['expected_at']))->format('l, j F Y \a\t H:i')) ?></strong>.</p>
    <?php else: ?>
        <p>There is nothing scheduled right now. You can still check in below.</p>
    <?php endif; ?>

    <?php if ($last): ?>
        <p>Your last check-in was on
            <strong><?= e((new DateTime($last))->format('l, j F Y \a\t H:i')) ?></strong>.</p>
    <?php endif; ?>

    <div class="row g-3 mt-2">
        <div class="col-md-6">
            <form method="post" action="checkin.php">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="well">
                <button class="btn btn-success w-100" type="submit">✔ I am well</button>
            </form>
        </div>
        <div class="col-md-6">
            <form method="post" action="checkin.php">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="help">
                <label class="form-label" for="notes">Message for your caregiver (optional)</label>
                <input class="form-control mb-2" id="notes" name="notes" maxlength="500"
                       placeholder="For example: I feel dizzy">
                <button class="btn btn-danger w-100" type="submit">✚ I need help</button>
            </form>
        </div>
    </div>

    <?php if ($history): ?>
        <h2 class="h4 mt-5">Your recent check-ins</h2>
        <ul class="list-group">
            <?php foreach ($history as $h): ?>
                <li class="list-group-item">
                    <?= e((new DateTime($h['checkin_at']))->format('l, j F Y \a\t H:i')) ?> —
                    <?= $h['wellbeing_status'] === 'ok' ? 'You said you were well.' : 'You asked for help.' ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
