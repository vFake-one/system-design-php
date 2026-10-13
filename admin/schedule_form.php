<?php
/**
 * File: admin/schedule_form.php
 * Stage: 5 - CREATE + EDIT check-in schedules. SHARED page (FR-16):
 *  - admin: any active household, any active caregiver.
 *  - caregiver: ONLY own actively-assigned households; responsible
 *    caregiver is fixed to themselves (they own what they schedule).
 * Completed/missed/cancelled rows are READ-ONLY here (the monitor and
 * check-in writer own those transitions - Stages 5/6).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin', 'caregiver'], '../login.php');
$isAdmin = $user['role'] === 'admin';

$id = (int) ($_GET['id'] ?? 0);
$editing = $id > 0;

/* Household options depend on role (the 🔒 scoping). */
if ($isAdmin) {
    $households = db()->query(
        "SELECT ep.id, ep.full_name,
                (SELECT ca.caregiver_id FROM caregiver_assignments ca
                  WHERE ca.elderly_id = ep.id AND ca.status = 'active' AND ca.is_primary = 1 LIMIT 1) AS primary_id
         FROM elderly_profiles ep WHERE ep.status = 'active' ORDER BY ep.full_name ASC"
    )->fetchAll();
    $caregivers = db()->query(
        "SELECT id, full_name FROM users WHERE status = 'active' AND role = 'caregiver' ORDER BY full_name ASC"
    )->fetchAll();
} else {
    $stmt = db()->prepare(
        "SELECT ep.id, ep.full_name, :cid1 AS primary_id
         FROM caregiver_assignments ca
         JOIN elderly_profiles ep ON ep.id = ca.elderly_id
         WHERE ca.caregiver_id = :cid AND ca.status = 'active' AND ep.status = 'active'
         ORDER BY ep.full_name ASC"
    );
    $stmt->execute(['cid2' => $user['id']]);
    $households = $stmt->fetchAll();
    $caregivers = [['id' => $user['id'], 'full_name' => $user['full_name']]];
}
$householdIds = array_map(fn($h) => (string) $h['id'], $households);
$caregiverIds = array_map(fn($c) => (string) $c['id'], $caregivers);

$defGrace = (int) setting('default_grace_minutes', '120');
$defEsc   = (int) setting('default_escalation_minutes', '240');

$form = ['elderly_id' => $_GET['elderly_id'] ?? '', 'caregiver_id' => '',
    'title' => 'Welfare check-in', 'expected_at' => '', 'grace_minutes' => (string) $defGrace,
    'escalation_minutes' => (string) $defEsc, 'notes' => '', 'cancel' => ''];
$current = null;

if ($editing) {
    $stmt = db()->prepare('SELECT * FROM checkin_schedules WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $current = $stmt->fetch();
    if (!$current) {
        flash('warning', 'Schedule not found.');
        redirect($isAdmin ? 'schedules.php' : '../caregiver/checkins.php');
    }
    // Caregivers may only touch schedules of their own households.
    if (!$isAdmin && !in_array((string) $current['elderly_id'], $householdIds, true)) {
        audit('access_denied', 'checkin_schedules', $id, 'Caregiver touched foreign schedule.');
        http_response_code(403);
        $pageTitle = 'Not allowed';
        $rootPrefix = '../';
        require_once __DIR__ . '/../includes/header.php';
        echo '<h1>403 - Not allowed</h1><p>This schedule is not for your households.</p>';
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }
    $form['elderly_id'] = (string) $current['elderly_id'];
    $form['caregiver_id'] = (string) ($current['caregiver_id'] ?? '');
    $form['title'] = (string) ($current['title'] ?? '');
    $form['expected_at'] = (new DateTime($current['expected_at']))->format('Y-m-d\TH:i');
    $form['grace_minutes'] = (string) $current['grace_minutes'];
    $form['escalation_minutes'] = (string) $current['escalation_minutes'];
    $form['notes'] = (string) ($current['notes'] ?? '');
}
$locked = $editing && $current['status'] !== 'scheduled'; // read-only mode

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    require_valid_csrf();
    $form['elderly_id'] = trim($_POST['elderly_id'] ?? '');
    $form['caregiver_id'] = $isAdmin ? trim($_POST['caregiver_id'] ?? '') : (string) $user['id'];
    $form['title'] = trim($_POST['title'] ?? '');
    $form['expected_at'] = trim($_POST['expected_at'] ?? '');
    $form['grace_minutes'] = trim($_POST['grace_minutes'] ?? '');
    $form['escalation_minutes'] = trim($_POST['escalation_minutes'] ?? '');
    $form['notes'] = trim($_POST['notes'] ?? '');
    $form['cancel'] = isset($_POST['cancel']) ? '1' : '';

    if (!in_array($form['elderly_id'], $householdIds, true)) {
        $errors['elderly_id'] = 'Choose a household you may schedule for.';
    }
    if ($form['caregiver_id'] !== '' && !in_array($form['caregiver_id'], $caregiverIds, true)) {
        $errors['caregiver_id'] = 'Choose a valid caregiver.';
    }
    if ($form['title'] !== '' && (mb_strlen($form['title']) < 2 || mb_strlen($form['title']) > 150)) {
        $errors['title'] = 'Title must be 2-150 characters (or blank for default).';
    }
    $exp = DateTime::createFromFormat('Y-m-d\TH:i', $form['expected_at']);
    if (!$exp) {
        $errors['expected_at'] = 'Expected date/time is required.';
    } elseif ($exp->format('Y-m-d H:i:s') <= date('Y-m-d H:i:s')) {
        $errors['expected_at'] = 'Expected time must be in the future.';
    }
    if (!ctype_digit($form['grace_minutes']) || (int) $form['grace_minutes'] < 5 || (int) $form['grace_minutes'] > 1440) {
        $errors['grace_minutes'] = 'Grace must be 5-1440 minutes.';
    }
    if (!ctype_digit($form['escalation_minutes']) || (int) $form['escalation_minutes'] < 15 || (int) $form['escalation_minutes'] > 10080) {
        $errors['escalation_minutes'] = 'Escalation delay must be 15-10080 minutes.';
    }
    if (mb_strlen($form['notes']) > 1000) {
        $errors['notes'] = 'Notes must be under 1000 characters.';
    }
    if ($form['cancel'] === '1' && $form['notes'] === '') {
        $errors['notes'] = 'A reason is required when cancelling a schedule.';
    }

    if (!$errors) {
        $expectedSql = $exp->format('Y-m-d H:i:00');
        if ($editing) {
            if ($form['cancel'] === '1') {
                db()->prepare("UPDATE checkin_schedules SET status = 'cancelled', notes = :n WHERE id = :id")
                  ->execute(['n' => $form['notes'], 'id' => $id]);
                audit('cancel_schedule', 'checkin_schedules', $id);
                flash('success', 'Schedule cancelled.');
            } else {
                db()->prepare(
                    'UPDATE checkin_schedules
                     SET elderly_id = :e, caregiver_id = :c, title = :t, expected_at = :ex,
                         grace_minutes = :g, escalation_minutes = :esc, notes = :n
                     WHERE id = :id'
                )->execute([
                    'e' => $form['elderly_id'],
                    'c' => $form['caregiver_id'] === '' ? null : (int) $form['caregiver_id'],
                    't' => $form['title'] === '' ? 'Welfare check-in' : $form['title'],
                    'ex' => $expectedSql, 'g' => (int) $form['grace_minutes'],
                    'esc' => (int) $form['escalation_minutes'],
                    'n' => $form['notes'] === '' ? null : $form['notes'], 'id' => $id,
                ]);
                audit('update_schedule', 'checkin_schedules', $id);
                flash('success', 'Schedule updated.');
            }
        } else {
            db()->prepare(
                'INSERT INTO checkin_schedules
                 (elderly_id, caregiver_id, title, expected_at, grace_minutes,
                  escalation_minutes, status, notes, created_by)
                 VALUES (:e, :c, :t, :ex, :g, :esc, \'scheduled\', :n, :by)'
            )->execute([
                'e' => $form['elderly_id'],
                'c' => $form['caregiver_id'] === '' ? null : (int) $form['caregiver_id'],
                't' => $form['title'] === '' ? 'Welfare check-in' : $form['title'],
                'ex' => $expectedSql, 'g' => (int) $form['grace_minutes'],
                'esc' => (int) $form['escalation_minutes'],
                'n' => $form['notes'] === '' ? null : $form['notes'],
                'by' => $user['id'],
            ]);
            $newId = (int) db()->lastInsertId();
            audit('create_schedule', 'checkin_schedules', $newId);
            flash('success', 'Schedule created.');
            $id = $newId;
        }
        redirect($isAdmin ? 'schedules.php' : '../caregiver/checkins.php');
    }
}

function field_error(?string $msg): string
{
    return $msg ? '<div class="invalid-feedback d-block">' . e($msg) . '</div>' : '';
}

$pageTitle  = $editing ? ($locked ? 'View schedule' : 'Edit schedule') : 'New schedule';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1><?= e($pageTitle) ?></h1>

<?php if ($locked): ?>
    <div class="alert alert-info">This schedule is <strong><?= e($current['status']) ?></strong>
        and can no longer be edited (completed/missed/cancelled are terminal).</div>
    <dl class="row">
        <dt class="col-sm-3">Expected</dt><dd class="col-sm-9"><?= e($current['expected_at']) ?></dd>
        <dt class="col-sm-3">Deadline</dt><dd class="col-sm-9"><?= e(deadline_of($current)) ?></dd>
        <dt class="col-sm-3">Notes</dt><dd class="col-sm-9"><?= e($current['notes'] ?? '—') ?></dd>
    </dl>
    <p><a class="btn btn-secondary" href="<?= $isAdmin ? 'schedules.php' : '../caregiver/checkins.php' ?>">Back</a></p>
<?php else: ?>
<form method="post" action="schedule_form.php<?= $editing ? '?id=' . $id : '' ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="elderly_id">Household *</label>
            <select class="form-select" id="elderly_id" name="elderly_id" required>
                <?php foreach ($households as $h): ?>
                    <option value="<?= (int) $h['id'] ?>" data-primary="<?= e((string) ($h['primary_id'] ?? '')) ?>"
                        <?= $form['elderly_id'] === (string) $h['id'] ? 'selected' : '' ?>><?= e($h['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors['elderly_id'] ?? null) ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="caregiver_id">Responsible caregiver</label>
            <?php if ($isAdmin): ?>
                <select class="form-select" id="caregiver_id" name="caregiver_id">
                    <option value="">— none —</option>
                    <?php foreach ($caregivers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $form['caregiver_id'] === (string) $c['id'] ? 'selected' : '' ?>>
                            <?= e($c['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input class="form-control" value="<?= e($user['full_name']) ?> (you)" disabled>
            <?php endif; ?>
            <?= field_error($errors['caregiver_id'] ?? null) ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="title">Title</label>
            <input class="form-control" id="title" name="title" maxlength="150" value="<?= e($form['title']) ?>">
            <?= field_error($errors['title'] ?? null) ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="expected_at">Expected date &amp; time *</label>
            <input class="form-control" type="datetime-local" id="expected_at" name="expected_at" required
                   value="<?= e($form['expected_at']) ?>">
            <?= field_error($errors['expected_at'] ?? null) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="grace_minutes">Grace (min) *</label>
            <input class="form-control" type="number" id="grace_minutes" name="grace_minutes" required
                   min="5" max="1440" value="<?= e($form['grace_minutes']) ?>">
            <?= field_error($errors['grace_minutes'] ?? null) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="escalation_minutes">Escalation (min) *</label>
            <input class="form-control" type="number" id="escalation_minutes" name="escalation_minutes" required
                   min="15" max="10080" value="<?= e($form['escalation_minutes']) ?>">
            <?= field_error($errors['escalation_minutes'] ?? null) ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="notes">Notes<?= $editing ? ' (required to cancel)' : '' ?></label>
            <input class="form-control" id="notes" name="notes" maxlength="1000" value="<?= e($form['notes']) ?>">
            <?= field_error($errors['notes'] ?? null) ?>
        </div>
        <?php if ($editing): ?>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="cancel" name="cancel" value="1">
                <label class="form-check-label text-danger" for="cancel">Cancel this schedule (needs a reason above)</label>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="mt-3">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Create schedule' ?></button>
        <a class="btn btn-secondary" href="<?= $isAdmin ? 'schedules.php' : '../caregiver/checkins.php' ?>">Cancel</a>
    </div>
</form>
<script>
// UX only: pre-select the household's primary caregiver (admin view).
(function () {
    var h = document.getElementById('elderly_id'), c = document.getElementById('caregiver_id');
    if (!h || !c) return;
    function sync() {
        var p = h.options[h.selectedIndex].getAttribute('data-primary');
        if (p) c.value = p;
    }
    h.addEventListener('change', sync);
    <?php if (!$editing): ?>sync();<?php endif; ?>
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
