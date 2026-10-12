<?php
/**
 * File: admin/elderly_form.php
 * Stage: 4 - REGISTER + EDIT elderly household (admin only).
 * Create: elderly_form.php | Edit: elderly_form.php?id=7
 * Server validates everything (client attributes are convenience only).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role('admin', '../login.php');

$id = (int) ($_GET['id'] ?? 0);
$editing = $id > 0;

/* Secondary-contact options: active STAFF only (admin/caregiver). Family
   logins cannot be escalation recipients - documented decision (docs/09). */
$staff = db()->query(
    "SELECT id, full_name, role FROM users
     WHERE status = 'active' AND role IN ('admin','caregiver')
     ORDER BY full_name ASC"
)->fetchAll();

$livingOptions = ['lives_alone' => 'Lives alone', 'with_spouse' => 'With spouse',
    'with_family' => 'With family', 'with_other' => 'Other (see notes)'];
$genders  = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other',
    'prefer_not_to_say' => 'Prefer not to say'];
$statuses = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'];

/* Defaults (create) or current row (edit). */
$form = ['full_name' => '', 'date_of_birth' => '', 'gender' => '', 'address' => '',
    'phone' => '', 'emergency_contact_name' => '', 'emergency_contact_phone' => '',
    'emergency_contact_relationship' => '', 'secondary_contact_user_id' => '',
    'living_situation' => '', 'welfare_notes' => '', 'status' => 'active'];
if ($editing) {
    $stmt = db()->prepare('SELECT * FROM elderly_profiles WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('warning', 'Household not found.');
        redirect('elderly.php');
    }
    foreach ($form as $k => $v) {
        $form[$k] = (string) ($row[$k] ?? '');
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    foreach ($form as $k => $v) {
        $form[$k] = trim($_POST[$k] ?? '');
    }

    /* --- Validate --------------------------------------------------- */
    if ($e = valid_required_text($form['full_name'], 2, 100, 'Full name')) {
        $errors['full_name'] = $e;
    }
    if ($e = valid_optional_past_date($form['date_of_birth'])) {
        $errors['date_of_birth'] = $e;
    }
    if ($e = valid_enum($form['gender'], array_keys($genders), 'Gender', true)) {
        $errors['gender'] = $e;
    }
    if (mb_strlen($form['address']) > 1000) {
        $errors['address'] = 'Address must be under 1000 characters.';
    }
    if ($e = valid_optional_phone($form['phone'])) {
        $errors['phone'] = $e;
    }
    if (mb_strlen($form['emergency_contact_name']) > 100) {
        $errors['emergency_contact_name'] = 'Emergency contact name: max 100 characters.';
    }
    if ($e = valid_optional_phone($form['emergency_contact_phone'], 'Emergency contact phone')) {
        $errors['emergency_contact_phone'] = $e;
    }
    if (mb_strlen($form['emergency_contact_relationship']) > 50) {
        $errors['emergency_contact_relationship'] = 'Relationship: max 50 characters.';
    }
    if ($form['secondary_contact_user_id'] !== '') {
        $ok = false;
        foreach ($staff as $s) {
            if ((string) $s['id'] === $form['secondary_contact_user_id']) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $errors['secondary_contact_user_id'] = 'Secondary contact must be an active staff account.';
        }
    }
    if ($e = valid_enum($form['living_situation'], array_keys($livingOptions), 'Living situation', true)) {
        $errors['living_situation'] = $e;
    }
    if (mb_strlen($form['welfare_notes']) > 1000) {
        $errors['welfare_notes'] = 'Welfare notes must be under 1000 characters.';
    }
    if ($e = valid_enum($form['status'], array_keys($statuses), 'Status')) {
        $errors['status'] = $e;
    }

    /* --- Save -------------------------------------------------------- */
    if (!$errors) {
        $data = [
            'full_name' => $form['full_name'],
            'date_of_birth' => $form['date_of_birth'] === '' ? null : $form['date_of_birth'],
            'gender' => $form['gender'] === '' ? null : $form['gender'],
            'address' => $form['address'] === '' ? null : $form['address'],
            'phone' => $form['phone'] === '' ? null : $form['phone'],
            'emergency_contact_name' => $form['emergency_contact_name'] === '' ? null : $form['emergency_contact_name'],
            'emergency_contact_phone' => $form['emergency_contact_phone'] === '' ? null : $form['emergency_contact_phone'],
            'emergency_contact_relationship' => $form['emergency_contact_relationship'] === '' ? null : $form['emergency_contact_relationship'],
            'secondary_contact_user_id' => $form['secondary_contact_user_id'] === '' ? null : (int) $form['secondary_contact_user_id'],
            'living_situation' => $form['living_situation'] === '' ? null : $form['living_situation'],
            'welfare_notes' => $form['welfare_notes'] === '' ? null : $form['welfare_notes'],
            'status' => $form['status'],
        ];
        if ($editing) {
            $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));
            $data['id'] = $id;
            db()->prepare("UPDATE elderly_profiles SET {$set} WHERE id = :id")->execute($data);
            audit('update_elderly', 'elderly_profiles', $id);
            flash('success', 'Household updated.');
            redirect('elderly_view.php?id=' . $id);
        } else {
            $cols = implode(', ', array_keys($data)) . ', created_by';
            $phs  = implode(', ', array_map(fn($c) => ":{$c}", array_keys($data))) . ', :created_by';
            $data['created_by'] = (int) $user['id'];
            db()->prepare("INSERT INTO elderly_profiles ({$cols}) VALUES ({$phs})")->execute($data);
            $newId = (int) db()->lastInsertId();
            audit('create_elderly', 'elderly_profiles', $newId);
            flash('success', 'Household registered. You can now assign a caregiver.');
            redirect('elderly_view.php?id=' . $newId);
        }
    }
    // Validation failed: fall through and re-render with $errors + sticky $form.
}

/** Small helper: Bootstrap invalid styling + message under a field. */
function field_error(?string $msg): string
{
    return $msg ? '<div class="invalid-feedback d-block">' . e($msg) . '</div>' : '';
}

$pageTitle  = $editing ? 'Edit household' : 'Register household';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1><?= e($pageTitle) ?></h1>
<form method="post" action="elderly_form.php<?= $editing ? '?id=' . $id : '' ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="full_name">Full name *</label>
            <input class="form-control" id="full_name" name="full_name" required minlength="2" maxlength="100"
                   value="<?= e($form['full_name']) ?>">
            <?= field_error($errors['full_name'] ?? null) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="date_of_birth">Date of birth</label>
            <input class="form-control" type="date" id="date_of_birth" name="date_of_birth"
                   value="<?= e($form['date_of_birth']) ?>" max="<?= e(date('Y-m-d')) ?>">
            <?= field_error($errors['date_of_birth'] ?? null) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="gender">Gender</label>
            <select class="form-select" id="gender" name="gender">
                <option value="">— not given —</option>
                <?php foreach ($genders as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $form['gender'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors['gender'] ?? null) ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="address">Address</label>
            <textarea class="form-control" id="address" name="address" rows="2" maxlength="1000"><?= e($form['address']) ?></textarea>
            <?= field_error($errors['address'] ?? null) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="phone">Phone</label>
            <input class="form-control" id="phone" name="phone" maxlength="20" value="<?= e($form['phone']) ?>">
            <?= field_error($errors['phone'] ?? null) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="living_situation">Living situation</label>
            <select class="form-select" id="living_situation" name="living_situation">
                <option value="">— not given —</option>
                <?php foreach ($livingOptions as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $form['living_situation'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors['living_situation'] ?? null) ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="emergency_contact_name">Emergency contact name</label>
            <input class="form-control" id="emergency_contact_name" name="emergency_contact_name" maxlength="100"
                   value="<?= e($form['emergency_contact_name']) ?>">
            <?= field_error($errors['emergency_contact_name'] ?? null) ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="emergency_contact_phone">Emergency contact phone</label>
            <input class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" maxlength="20"
                   value="<?= e($form['emergency_contact_phone']) ?>">
            <?= field_error($errors['emergency_contact_phone'] ?? null) ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="emergency_contact_relationship">Relationship</label>
            <input class="form-control" id="emergency_contact_relationship" name="emergency_contact_relationship"
                   maxlength="50" placeholder="e.g. daughter, neighbour" value="<?= e($form['emergency_contact_relationship']) ?>">
            <?= field_error($errors['emergency_contact_relationship'] ?? null) ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="secondary_contact_user_id">Secondary (escalation) contact</label>
            <select class="form-select" id="secondary_contact_user_id" name="secondary_contact_user_id">
                <option value="">— none —</option>
                <?php foreach ($staff as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $form['secondary_contact_user_id'] === (string) $s['id'] ? 'selected' : '' ?>>
                        <?= e($s['full_name']) ?> (<?= e($s['role']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors['secondary_contact_user_id'] ?? null) ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <?php foreach ($statuses as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $form['status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors['status'] ?? null) ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="welfare_notes">Welfare notes (non-diagnostic only)</label>
            <textarea class="form-control" id="welfare_notes" name="welfare_notes" rows="3"
                      maxlength="1000" placeholder="Mobility, language, access needs…"><?= e($form['welfare_notes']) ?></textarea>
            <?= field_error($errors['welfare_notes'] ?? null) ?>
        </div>
    </div>
    <div class="mt-3">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Register household' ?></button>
        <a class="btn btn-secondary" href="<?= $editing ? 'elderly_view.php?id=' . $id : 'elderly.php' ?>">Cancel</a>
    </div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
