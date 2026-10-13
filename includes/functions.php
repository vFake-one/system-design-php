<?php
/**
 * File: includes/functions.php
 * Stage: 3 (Authentication and roles)
 *
 * Shared helpers used by EVERY page. Include order at the top of each page:
 *
 *   require_once __DIR__ . '/../includes/functions.php'; // (path varies by folder)
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_once __DIR__ . '/../includes/csrf.php';
 *
 * functions.php pulls in config/database.php itself, so $pdo / db() are
 * available everywhere after this one include.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/* ---------------------------------------------------------------------
 * e() - escape output. Use on EVERY value printed into HTML that came
 * from the database, the session, or user input. This is what stops
 * cross-site scripting (XSS): <script> becomes harmless text.
 * ------------------------------------------------------------------- */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ---------------------------------------------------------------------
 * redirect() - safe internal redirect. MUST be called before ANY output
 * (even a blank line before <?php breaks it - "headers already sent").
 * ------------------------------------------------------------------- */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/* ---------------------------------------------------------------------
 * Flash messages - one-time notices ("Logged in", "Invalid password")
 * stored in the session, shown once by header.php, then cleared.
 * ------------------------------------------------------------------- */
function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

/** Returns all pending flashes and clears them. Types: success/danger/warning/info. */
function flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $pending = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $pending;
}

/* ---------------------------------------------------------------------
 * audit() - append one row to audit_logs. Call AFTER important actions:
 * logins, profile/user changes, alert acknowledge/escalate/resolve, etc.
 * NEVER pass passwords, hashes or tokens in $details.
 * ------------------------------------------------------------------- */
function audit(string $action, ?string $entityType = null, int|string|null $entityId = null, ?string $details = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip_address)'
    );
    $stmt->execute([
        'user_id'     => $_SESSION['user_id'] ?? null, // null = system/cron action
        'action'      => $action,
        'entity_type' => $entityType,
        'entity_id'   => $entityId !== null ? (string) $entityId : null,
        'details'     => $details,
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? 'cli',
    ]);
}

/* ---------------------------------------------------------------------
 * is_safe_next() - open-redirect guard for ?next= return URLs.
 * Only internal absolute paths ('/elderly-welfare-system/...') pass;
 * 'https://evil.example' or '//evil.example' are rejected.
 * ------------------------------------------------------------------- */
function is_safe_next(mixed $url): bool
{
    return is_string($url)
        && str_starts_with($url, '/')
        && !str_starts_with($url, '//')
        && !str_contains($url, '://');
}

/* =====================================================================
 * STAGE 4 HELPERS - validation, ownership checks, pagination.
 * Every helper returns an ERROR STRING or null (null = valid), so forms
 * collect $errors['field'] = ... and re-render with sticky values.
 * =================================================================== */

/** Required text between $min and $max chars (after trimming). */
function valid_required_text(?string $v, int $min, int $max, string $label): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return $label . ' is required.';
    }
    $len = mb_strlen($v);
    if ($len < $min || $len > $max) {
        return $label . " must be {$min}-{$max} characters.";
    }
    return null;
}

/** Required, well-formed, lowercase-able email. Uniqueness is checked by the caller. */
function valid_email(string $v): ?string
{
    $v = strtolower(trim($v));
    if ($v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) === false || strlen($v) > 150) {
        return 'A valid email address (max 150 chars) is required.';
    }
    return null;
}

/** Optional phone: empty or matching the project pattern (Stage 1 decision). */
function valid_optional_phone(?string $v, string $label = 'Phone'): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^[+0-9][0-9 ()-]{5,19}$/', $v)) {
        return $label . ' looks invalid (digits, spaces, +, -, brackets only).';
    }
    return null;
}

/** Optional date of birth: empty, or a real past date with age <= $maxAge. */
function valid_optional_past_date(?string $v, int $maxAge = 120): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        return 'Date of birth must be a valid date (YYYY-MM-DD).';
    }
    $today = new DateTime('today');
    if ($d > $today) {
        return 'Date of birth cannot be in the future.';
    }
    if ((int) $d->diff($today)->y > $maxAge) {
        return "Date of birth implies an age over {$maxAge} - please check it.";
    }
    return null;
}

/** Whole years between a Y-m-d date and today (null when unknown). */
function age_of(?string $dob): ?int
{
    if (!$dob) {
        return null;
    }
    try {
        return (new DateTime($dob))->diff(new DateTime('today'))->y;
    } catch (Exception) {
        return null;
    }
}

/** Value must be in the whitelist (ENUM guard). */
function valid_enum(mixed $v, array $allowed, string $label, bool $allowEmpty = false): ?string
{
    if (($v === '' || $v === null) && $allowEmpty) {
        return null;
    }
    if (!in_array($v, $allowed, true)) {
        return $label . ' has an invalid value.';
    }
    return null;
}

/** Classroom password policy: >= 8 chars with at least one letter and one number. */
function valid_password(string $v): ?string
{
    if (strlen($v) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $v) || !preg_match('/[0-9]/', $v)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

/** Random temporary password (unambiguous chars only: no 0/O, 1/l). */
function temp_password(int $len = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

/* ---------------------------------------------------------------------
 * Ownership helpers - the server-side 🔒 checks from the Stage 1 matrix.
 * ------------------------------------------------------------------- */

/** Active (or all) elderly ids assigned to a caregiver. */
function assigned_elderly_ids(int $caregiverId, bool $activeOnly = true): array
{
    $sql = 'SELECT elderly_id FROM caregiver_assignments WHERE caregiver_id = :cid';
    if ($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $stmt = db()->prepare($sql);
    $stmt->execute(['cid' => $caregiverId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * can_access_elderly() - may $user see this profile?
 * admin: yes. caregiver: only with an ACTIVE assignment. elderly: only
 * their own linked profile. Used by elderly_view.php (Stage 4) and every
 * later page that takes an elderly id (Stages 5-8).
 */
function can_access_elderly(array $user, int $elderlyId): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    if ($user['role'] === 'caregiver') {
        return in_array($elderlyId, assigned_elderly_ids((int) $user['id']), true);
    }
    return (int) ($user['elderly_profile_id'] ?? 0) === $elderlyId;
}

/* ---------------------------------------------------------------------
 * Pagination: pager() returns [rows..., 'nav' => HTML]. Queries stay in
 * the calling page (visible SQL); only the maths + links live here.
 * ------------------------------------------------------------------- */
function pager(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);
    return ['total' => $total, 'pages' => $pages, 'page' => $page,
            'offset' => ($page - 1) * $perPage, 'limit' => $perPage];
}

/** Bootstrap page links preserving $params (search/filters). $base is the script name. */
function pager_links(string $base, array $params, int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($base, $params) {
        $params['page'] = $p;
        $cls = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
        return '<li class="' . $cls . '"><a class="page-link" href="' . e($base . '?' . http_build_query($params)) . '">' . e($label) . '</a></li>';
    };
    $html = '<nav aria-label="Pages"><ul class="pagination">';
    $html .= $link(max(1, $page - 1), '« Prev', disabled: $page <= 1);
    for ($p = 1; $p <= $pages; $p++) {
        $html .= $link($p, (string) $p, $p === $page);
    }
    $html .= $link(min($pages, $page + 1), 'Next »', disabled: $page >= $pages);
    return $html . '</ul></nav>';
}

/* =====================================================================
 * STAGE 5 HELPERS - schedules, overdue derivation, check-in submission.
 * Overdue is DERIVED (Stage 1 D-3): status='scheduled' AND deadline passed.
 * =================================================================== */

/** Read one system_settings value (falls back when missing). */
function setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :k');
    $stmt->execute(['k' => $key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : (string) $val;
}

/** Deadline of a schedule row: expected_at + grace_minutes (Y-m-d H:i:s). */
function deadline_of(array $sched): string
{
    $dt = new DateTime($sched['expected_at']);
    $dt->modify('+' . max(0, (int) $sched['grace_minutes']) . ' minutes');
    return $dt->format('Y-m-d H:i:s');
}

/** True when a 'scheduled' row's deadline has passed (optionally vs a fixed $now for tests). */
function is_overdue(array $sched, ?string $now = null): bool
{
    if (($sched['status'] ?? '') !== 'scheduled') {
        return false;
    }
    return deadline_of($sched) < ($now ?? date('Y-m-d H:i:s'));
}

/** Display status: real status, except scheduled+past-deadline shows 'overdue'. */
function schedule_display_status(array $sched, ?string $now = null): string
{
    return is_overdue($sched, $now) ? 'overdue' : (string) ($sched['status'] ?? '');
}

/**
 * find_open_schedule() - the schedule a new self-submission should attach to:
 * earliest 'scheduled' row whose acceptance window has OPENED
 * (expected_at - 2h <= now, mirroring the Stage 1 D-10 monitor window).
 * Returns null when nothing is due -> caller records an AD-HOC check-in.
 */
function find_open_schedule(int $elderlyId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM checkin_schedules
         WHERE elderly_id = :e AND status = 'scheduled'
           AND expected_at <= DATE_ADD(NOW(), INTERVAL 2 HOUR)
         ORDER BY expected_at ASC LIMIT 1"
    );
    $stmt->execute(['e' => $elderlyId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Active PRIMARY caregiver id for a household (null when none). */
function primary_caregiver_id(int $elderlyId): ?int
{
    $stmt = db()->prepare(
        "SELECT caregiver_id FROM caregiver_assignments
         WHERE elderly_id = :e AND status = 'active' AND is_primary = 1 LIMIT 1"
    );
    $stmt->execute(['e' => $elderlyId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * submit_checkin() - THE shared writer for every check-in (Stage 5).
 * Used by caregiver/checkins.php AND elderly/checkin.php so both paths
 * obey identical rules. Throws RuntimeException (user-safe) on any refusal.
 *
 * Steps: validate elderly + schedule link -> INSERT checkin_records ->
 * mark schedule 'completed' -> if wellbeing != 'ok', score with the FULL
 * engine (help_flag=1 applies the R-7 floor) and open an URGENT
 * assistance alert immediately (Stage 7 D-38: direct reports are always
 * urgent; the score may exceed 60 when history is also bad).
 *
 * @return array{record_id:int, schedule_id:?int, alert_id:?int}
 */
function submit_checkin(
    int $elderlyId,
    ?int $scheduleId,
    int $submittedBy,
    string $submittedByRole,
    string $method,
    string $wellbeing,
    ?string $notes = null,
    ?string $checkinAt = null
): array {
    $method    = trim($method);
    $wellbeing = trim($wellbeing);
    if (!in_array($method, ['self_online', 'caregiver_entry', 'phone_logged', 'visit_logged'], true)) {
        throw new RuntimeException('Unknown check-in method.');
    }
    if (!in_array($wellbeing, ['ok', 'needs_assistance', 'unwell'], true)) {
        throw new RuntimeException('Unknown wellbeing value.');
    }
    $when = $checkinAt ?? date('Y-m-d H:i:s');
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $when) ?: DateTime::createFromFormat('Y-m-d H:i', $when);
    if (!$d) {
        throw new RuntimeException('Invalid check-in time.');
    }
    $when = $d->format('Y-m-d H:i:s');

    // Elderly must exist and be active (archived households take no check-ins).
    $ep = db()->prepare("SELECT id FROM elderly_profiles WHERE id = :e AND status = 'active'");
    $ep->execute(['e' => $elderlyId]);
    if (!$ep->fetch()) {
        throw new RuntimeException('This household is not active.');
    }

    // A linked schedule must belong to the household and still be open.
    $sched = null;
    if ($scheduleId !== null) {
        $s = db()->prepare('SELECT * FROM checkin_schedules WHERE id = :s');
        $s->execute(['s' => $scheduleId]);
        $sched = $s->fetch();
        if (!$sched || (int) $sched['elderly_id'] !== $elderlyId) {
            throw new RuntimeException('That schedule does not belong to this household.');
        }
        if ($sched['status'] !== 'scheduled') {
            throw new RuntimeException('That schedule is already ' . $sched['status'] . '.');
        }
    }

    db()->beginTransaction();
    try {
        try {
            $ins = db()->prepare(
                'INSERT INTO checkin_records
                 (schedule_id, elderly_id, submitted_by, submitted_by_role,
                  checkin_at, method, wellbeing_status, notes)
                 VALUES (:s, :e, :by, :role, :at, :m, :w, :n)'
            );
            $ins->execute([
                's' => $scheduleId, 'e' => $elderlyId, 'by' => $submittedBy,
                'role' => $submittedByRole, 'at' => $when, 'm' => $method,
                'w' => $wellbeing, 'n' => ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
            ]);
        } catch (PDOException $e) {
            // 23000 = unique violation -> uq_checkin_schedule: already recorded.
            if ($e->getCode() === '23000') {
                throw new RuntimeException('A check-in was already recorded for that schedule.');
            }
            throw $e;
        }
        $recordId = (int) db()->lastInsertId();

        if ($scheduleId !== null) {
            db()->prepare("UPDATE checkin_schedules SET status = 'completed' WHERE id = :s")
               ->execute(['s' => $scheduleId]);
        }

        $alertId = null;
        if ($wellbeing !== 'ok') {
            // Direct cry for help -> URGENT alert at once (never waits for cron).
            $caregiverId = $sched['caregiver_id'] ?? null;
            if ($caregiverId === null) {
                $caregiverId = primary_caregiver_id($elderlyId);
            }
            $label = $wellbeing === 'needs_assistance' ? 'requested assistance' : 'reported feeling unwell';
            // Full-engine assessment with the R-7 floor (score >= 60 guaranteed);
            // priority is FORCED urgent for direct reports (D-38), whatever the
            // admin tuned the urgent threshold to.
            $ass = risk_assess($elderlyId, 'assistance', ['help_flag' => 1]);
            $alert = db()->prepare(
                'INSERT INTO alerts
                 (elderly_id, schedule_id, caregiver_id, alert_type, priority, risk_score,
                  title, message, reason, observations, status)
                 VALUES (:e, :s, :c, \'assistance_request\', \'urgent\', :score,
                         :title, :msg, :reason, :obs, \'open\')'
            );
            $alert->execute([
                'e' => $elderlyId, 's' => $scheduleId, 'c' => $caregiverId,
                'score' => $ass['score'],
                'title' => 'Assistance requested',
                'msg' => "Household member {$label} via check-in #{$recordId}."
                       . ($notes ? ' Note: ' . trim($notes) : ''),
                'reason' => $ass['reasons'] ? implode('; ', $ass['reasons']) : 'Direct report',
                'obs' => json_encode($ass['observations'] + ['record_id' => $recordId]),
            ]);
            $alertId = (int) db()->lastInsertId();
            audit('alert_created', 'alerts', $alertId, 'assistance_request (urgent, direct report)');
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    audit('submit_checkin', 'checkin_records', $recordId,
        "elderly={$elderlyId} schedule=" . ($scheduleId ?? 'ad-hoc') . " wellbeing={$wellbeing}");
    return ['record_id' => $recordId, 'schedule_id' => $scheduleId, 'alert_id' => $alertId];
}

// Stage 7: the risk engine lives in one file; loading it here makes
// risk_assess() available to submit_checkin() and every page. require_once
// keeps CLI (monitor), web, and review page loads safe against doubles.
require_once __DIR__ . '/../ai/risk_engine.php';
