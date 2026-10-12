# Stage 1 — Database Design & Entity-Relationship Diagram

> **Stage 1 deliverable 4 of 6.** The complete *logical* design that Stage 2
> will implement as `database/elderly_welfare.sql`.
> Engine: **InnoDB**. Charset: **utf8mb4**. Collation: **utf8mb4_unicode_ci**.
> Time zone: single configured local zone — `[TODO-STUDENT: confirm, e.g. Africa/Lagos]`.

---

## 1. Design principles (why it looks like this)

1. **Normalized to 3NF.** Each fact lives in one place. Names live in
   `users` / `elderly_profiles`; assignments link them; events (schedules,
   check-ins, alerts, visits) reference them by ID. This avoids update
   anomalies ("change a phone number in 6 places").
2. **History is never deleted by normal actions.** Ending an assignment,
   cancelling a schedule, or suspending a user changes a `status` — past
   check-ins, alerts, and visits stay for reporting and audit.
3. **The database enforces what matters most.** Unique constraints stop
   duplicate emails, duplicate active assignments, second check-ins for one
   schedule, and second missed-alerts for one schedule — even if PHP has a
   bug. Application code *also* checks, but the database is the last line
   of defence.
4. **Timestamps are consistent.** Every table has `created_at`; mutable
   tables add `updated_at`. Event times (`expected_at`, `checkin_at`,
   `acknowledged_at`, `visit_date`) are `DATETIME` in the configured local
   zone; the app sets both PHP and MySQL session zones identically so
   `expected_at + grace < NOW()` comparisons are correct.
5. **Foreign keys protect relationships.** `ON DELETE CASCADE` only where
   the child is meaningless without the parent (e.g., acknowledgement rows
   without their alert); `ON DELETE SET NULL` where history must survive
   (e.g., visits keep their notes if a caregiver account is removed, with
   the ID nulled); `ON DELETE RESTRICT`-by-default elsewhere via
   application checks.

---

## 2. Table inventory

| # | Table | Purpose | Rows grow by |
|---|---|---|---|
| 1 | `users` | All logins: admins, caregivers, elderly/family. | New accounts |
| 2 | `elderly_profiles` | Household welfare profiles. | New registrations |
| 3 | `caregiver_assignments` | M:N link: which caregivers serve which elderly. | Assignments |
| 4 | `checkin_schedules` | Expected check-ins (who + when + grace). | New schedules |
| 5 | `checkin_records` | Actual submitted check-ins (scheduled or ad-hoc). | Each submission |
| 6 | `caregiver_visits` | Physical-visit log. | Each visit |
| 7 | `alerts` | Missed-check-in / pattern / assistance / escalation work items + risk score + reasons. | Each flag |
| 8 | `alert_acknowledgements` | Who acknowledged which alert, when, with what note. | Each acknowledgement |
| 9 | `followup_actions` | Calls/visits/referrals per alert with outcomes. | Each action |
| 10 | `audit_logs` | Append-only who-did-what trail. | Each important action |
| 11 | `system_settings` | Grace/escalation defaults, app name, zone label. *(The one justified extra table.)* | Rarely |

> `system_settings` (key/value) is the only table beyond the ten required
> ones. It holds *configurable defaults* the spec demands
> (grace/escalation periods) without hardcoding them. Per-schedule values
> can still override the defaults.

---

## 3. Full table definitions

Conventions: `PK` = primary key · `FK` = foreign key · `UQ` = unique ·
`NN` = NOT NULL · `DEF` = default.

### 3.1 `users` — all login accounts

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | `PK`, `AUTO_INCREMENT` | — |
| `full_name` | `VARCHAR(100)` | `NN` | Trimmed, 2–100 chars. |
| `email` | `VARCHAR(150)` | `NN`, `UQ` | `FILTER_VALIDATE_EMAIL`, lowercase, max 150. Login identifier. |
| `phone` | `VARCHAR(20)` | `NULL` | Regex `^[+0-9][0-9 ()-]{5,19}$` or NULL. |
| `password_hash` | `VARCHAR(255)` | `NN` | Output of `password_hash()` only. Never readable in UI/logs. |
| `role` | `ENUM('admin','caregiver','elderly')` | `NN` | Server-side whitelist. `elderly` covers elderly person **and** authorized family member (both link to a profile — see next row). |
| `elderly_profile_id` | `INT UNSIGNED` | `NULL`, `FK → elderly_profiles(id) ON DELETE SET NULL` | Set **only** for `role='elderly'`: links this login to its permitted profile. Many logins may share one profile (elderly + family). |
| `status` | `ENUM('active','suspended')` | `NN`, `DEF 'active'` | Suspended users cannot log in; history kept. |
| `created_at` | `TIMESTAMP` | `NN`, `DEF CURRENT_TIMESTAMP` | — |
| `updated_at` | `TIMESTAMP` | `NN`, `DEF CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | — |

Indexes: `UQ users(email)` · `INDEX idx_users_role (role)` ·
`INDEX idx_users_status (status)` · `INDEX idx_users_elderly (elderly_profile_id)`.

> **Why one `users` table for three roles?** One login form, one session
> scheme, one password policy. Role-specific data lives in linked tables.
> This is simpler and safer for Year 2 than three parallel user tables.

### 3.2 `elderly_profiles` — household welfare profiles

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | `PK`, `AUTO_INCREMENT` | — |
| `full_name` | `VARCHAR(100)` | `NN` | Required, 2–100 chars. |
| `date_of_birth` | `DATE` | `NULL` | Must not be future; age sanity-checked in PHP (e.g., ≤ 120). |
| `gender` | `ENUM('male','female','other','prefer_not_to_say')` | `NULL` | Optional. |
| `address` | `TEXT` | `NULL` | Free text, max ~1000 chars in PHP. |
| `phone` | `VARCHAR(20)` | `NULL` | Same phone regex. |
| `emergency_contact_name` | `VARCHAR(100)` | `NULL` | — |
| `emergency_contact_phone` | `VARCHAR(20)` | `NULL` | Same phone regex. |
| `emergency_contact_relationship` | `VARCHAR(50)` | `NULL` | e.g., daughter, neighbour. |
| `secondary_contact_user_id` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | **Authorized** escalation recipient (must be an active user; validated in PHP — cannot be the same as the primary caregiver for that alert). |
| `living_situation` | `VARCHAR(100)` | `NULL` | e.g., `lives_alone`, `with_spouse`, `with_family`. Free but guided by `<select>`. |
| `welfare_notes` | `TEXT` | `NULL` | Non-diagnostic notes only (mobility, language, access). Labelled as such in UI. |
| `status` | `ENUM('active','inactive','archived')` | `NN`, `DEF 'active'` | `archived` = soft-deleted; hidden from lists, history kept. |
| `created_by` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | Admin who registered. |
| `created_at` / `updated_at` | `TIMESTAMP` | As in `users`. | — |

Indexes: `INDEX idx_elderly_status (status)` ·
`INDEX idx_elderly_name (full_name)` ·
`INDEX idx_elderly_secondary (secondary_contact_user_id)`.

### 3.3 `caregiver_assignments` — who serves whom (M:N link)

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | `PK`, `AUTO_INCREMENT` | — |
| `elderly_id` | `INT UNSIGNED` | `NN`, `FK → elderly_profiles(id) ON DELETE CASCADE` | Profile must be `active`. |
| `caregiver_id` | `INT UNSIGNED` | `NN`, `FK → users(id) ON DELETE CASCADE` | User must have `role='caregiver'` and `status='active'` (enforced in PHP; documented check). |
| `is_primary` | `TINYINT(1)` | `NN`, `DEF 0` | `1` = primary (first to be alerted). At most one primary per elderly — enforced in PHP transaction + documented; a partial unique index is a possible extension. |
| `status` | `ENUM('active','ended')` | `NN`, `DEF 'active'` | Ending preserves history. |
| `assigned_by` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | Admin who made the assignment. |
| `assigned_at` | `TIMESTAMP` | `NN`, `DEF CURRENT_TIMESTAMP` | — |
| `unassigned_at` | `TIMESTAMP` | `NULL` | Set when `status → 'ended'`. |

Constraints: `UQ uq_assignment_pair (elderly_id, caregiver_id)` — one row
per pair ever; re-assignment reactivates the row instead of inserting a
duplicate.
Indexes: `INDEX idx_assignment_elderly (elderly_id, status)` ·
`INDEX idx_assignment_caregiver (caregiver_id, status)`.

### 3.4 `checkin_schedules` — expected check-ins

> Design decision: **one row = one expected occurrence** (not a cron-like
> recurrence template). A "daily 9am" routine is stored as individual dated
> rows, optionally bulk-created by a helper. This keeps deadline maths
> (`expected_at + grace`) trivially queryable for Year 2 and makes the
> monitor a simple `SELECT`.

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | `PK`, `AUTO_INCREMENT` | — |
| `elderly_id` | `INT UNSIGNED` | `NN`, `FK → elderly_profiles(id) ON DELETE CASCADE` | Must be `active`. |
| `caregiver_id` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | Snapshot of responsible caregiver at creation (usually the primary). Nullable so schedules survive caregiver removal. |
| `title` | `VARCHAR(150)` | `NULL`, `DEF 'Welfare check-in'` | e.g., "Morning check-in". |
| `expected_at` | `DATETIME` | `NN` | Must be a valid datetime; normally future at creation. Compared in local zone. |
| `grace_minutes` | `INT UNSIGNED` | `NN`, `DEF 120` | Range 5–1440 in PHP. Default copied from `system_settings`. |
| `escalation_minutes` | `INT UNSIGNED` | `NN`, `DEF 240` | Range 15–10080 in PHP. Default copied from `system_settings`. |
| `status` | `ENUM('scheduled','completed','missed','cancelled')` | `NN`, `DEF 'scheduled'` | `completed` set when a valid check-in links; `missed` set only by monitor; `cancelled` by authorized user with reason in notes. `Overdue` is **derived**, not stored: `status='scheduled' AND expected_at + grace < NOW()`. |
| `notes` | `TEXT` | `NULL` | Includes cancellation reason when relevant. |
| `created_by` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | — |
| `created_at` / `updated_at` | `TIMESTAMP` | As in `users`. | — |

Indexes: `INDEX idx_schedule_elderly_expected (elderly_id, expected_at)` ·
`INDEX idx_schedule_status_expected (status, expected_at)` *(the monitor's
main query)* · `INDEX idx_schedule_caregiver (caregiver_id)`.

### 3.5 `checkin_records` — actual submissions

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | `PK`, `AUTO_INCREMENT` | — |
| `schedule_id` | `INT UNSIGNED` | `NULL`, `FK → checkin_schedules(id) ON DELETE SET NULL`, `UQ` | Link to the expected item, or `NULL` for ad-hoc. `UNIQUE(schedule_id)` allows many NULLs but only one record per schedule (MySQL semantics) — exactly the rule we need. |
| `elderly_id` | `INT UNSIGNED` | `NN`, `FK → elderly_profiles(id) ON DELETE CASCADE` | Must match the schedule's elderly when linked (PHP check). |
| `submitted_by` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | Who clicked submit. NULL only for legacy/system rows. |
| `submitted_by_role` | `VARCHAR(20)` | `NULL` | Snapshot of submitter's role at submit time (`admin/caregiver/elderly`) — proves "who submitted each entry" even if roles later change. |
| `checkin_at` | `DATETIME` | `NN`, `DEF CURRENT_TIMESTAMP` | When the contact happened (defaults to now; caregiver may backdate slightly with reason in notes — range-checked in PHP). |
| `method` | `ENUM('self_online','caregiver_entry','phone_logged','visit_logged')` | `NN`, `DEF 'self_online'` | How the check-in was captured. |
| `wellbeing_status` | `ENUM('ok','needs_assistance','unwell')` | `NN`, `DEF 'ok'` | `needs_assistance` / `unwell` immediately raise an assistance alert (Stage 6). These are self-reported flags, not diagnoses. |
| `notes` | `TEXT` | `NULL` | — |
| `created_at` | `TIMESTAMP` | `NN`, `DEF CURRENT_TIMESTAMP` | — |

Indexes: `UQ uq_checkin_schedule (schedule_id)` ·
`INDEX idx_checkin_elderly_at (elderly_id, checkin_at)` ·
`INDEX idx_checkin_submitter (submitted_by)`.

### 3.6 `caregiver_visits` — physical visit log

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | `PK`, `AUTO_INCREMENT` | — |
| `elderly_id` | `INT UNSIGNED` | `NN`, `FK → elderly_profiles(id) ON DELETE CASCADE` | — |
| `caregiver_id` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | Nullable so visit history survives account removal. |
| `alert_id` | `INT UNSIGNED` | `NULL`, `FK → alerts(id) ON DELETE SET NULL` | Optional link when the visit responds to an alert. |
| `visit_date` | `DATETIME` | `NN` | Not far-future (PHP check). |
| `visit_type` | `ENUM('routine','follow_up','emergency')` | `NN`, `DEF 'routine'` | — |
| `outcome` | `ENUM('well','needs_followup','referred','no_answer','other')` | `NN`, `DEF 'well'` | `no_answer` is a risk input (feeds the rule engine). |
| `notes` | `TEXT` | `NULL` | — |
| `created_at` | `TIMESTAMP` | `NN`, `DEF CURRENT_TIMESTAMP` | — |

Indexes: `INDEX idx_visits_elderly_date (elderly_id, visit_date)` ·
`INDEX idx_visits_caregiver (caregiver_id)`.

### 3.7 `alerts` — the prioritized worklist (+ stored risk assessment)

> The risk assessment required by §11 (priority, reason, timestamp,
> observations, reviewer, outcome) is stored **in this table**
> (`priority`, `risk_score`, `reason`, `created_at`, `observations`,
> reviewer via acknowledgements, outcome via follow-ups + `resolved_at`).
> A separate `risk_assessments` table would add joins without teaching
> value at Year 2 — this decision is documented here deliberately.

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | `PK`, `AUTO_INCREMENT` | — |
| `elderly_id` | `INT UNSIGNED` | `NN`, `FK → elderly_profiles(id) ON DELETE CASCADE` | — |
| `schedule_id` | `INT UNSIGNED` | `NULL`, `FK → checkin_schedules(id) ON DELETE SET NULL` | Set for `missed_checkin`; NULL for pattern/assistance alerts. |
| `caregiver_id` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | Notified caregiver (usually primary at creation). |
| `alert_type` | `ENUM('missed_checkin','pattern_flag','assistance_request','escalation')` | `NN` | `escalation` is used for the escalation *notification record* if the design keeps the original alert and adds an escalation child; otherwise escalation updates the same row's `status`. **Decision: update the same row** (`status → 'escalated'`, `escalated_to/at` set) to keep one work item per case. The `escalation` type is reserved for the optional extension. |
| `priority` | `ENUM('routine','attention','urgent')` | `NN`, `DEF 'attention'` | Maps to Routine / Attention required / Urgent review. |
| `risk_score` | `TINYINT UNSIGNED` | `NULL` | 0–100 transparent score (NULL only if scoring was skipped — never in core flow). |
| `title` | `VARCHAR(150)` | `NN` | e.g., "Missed check-in: Morning check-in (09:00)". |
| `message` | `TEXT` | `NN` | Human-readable detail. |
| `reason` | `TEXT` | `NULL` | **Explainability:** bullet-style reasons, e.g. `"2 missed in 7 days; 36h since last success; unacknowledged alert exists"`. |
| `observations` | `TEXT` | `NULL` | JSON snapshot of inputs used for scoring (counts, timestamps) — lets a supervisor verify the score later. |
| `status` | `ENUM('open','acknowledged','escalated','resolved','dismissed')` | `NN`, `DEF 'open'` | Lifecycle: `open → acknowledged → resolved`; `open → escalated → acknowledged → resolved`; `dismissed` only by admin with reason. |
| `escalated_to` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | Must equal the profile's authorized secondary contact (PHP check). |
| `escalated_at` | `DATETIME` | `NULL` | Set once; never overwritten (prevents repeat spam). |
| `resolved_at` | `DATETIME` | `NULL` | Set when `status → 'resolved'`. Requires a documented follow-up (PHP check). |
| `created_at` / `updated_at` | `TIMESTAMP` | As in `users`. | — |

Constraints: `UQ uq_alert_schedule_type (schedule_id, alert_type)` —
at most one `missed_checkin` alert per schedule (NULL schedule rows
for pattern alerts are unaffected).
Indexes: `INDEX idx_alerts_status_priority (status, priority)` ·
`INDEX idx_alerts_elderly_created (elderly_id, created_at)` ·
`INDEX idx_alerts_caregiver_status (caregiver_id, status)`.

### 3.8 `alert_acknowledgements` — who confirmed, when

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | `PK`, `AUTO_INCREMENT` | — |
| `alert_id` | `INT UNSIGNED` | `NN`, `FK → alerts(id) ON DELETE CASCADE` | Child of the alert; deleted with it. |
| `acknowledged_by` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | — |
| `acknowledged_at` | `DATETIME` | `NN`, `DEF CURRENT_TIMESTAMP` | — |
| `action_taken` | `VARCHAR(100)` | `NULL` | Short label, e.g., "Called elderly person". |
| `notes` | `TEXT` | `NULL` | — |

Indexes: `INDEX idx_ack_alert (alert_id)` · `INDEX idx_ack_user (acknowledged_by)`.

### 3.9 `followup_actions` — what was done about it

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `INT UNSIGNED` | `PK`, `AUTO_INCREMENT` | — |
| `alert_id` | `INT UNSIGNED` | `NN`, `FK → alerts(id) ON DELETE CASCADE` | Every follow-up belongs to an alert. |
| `elderly_id` | `INT UNSIGNED` | `NN`, `FK → elderly_profiles(id) ON DELETE CASCADE` | Denormalized copy of `alerts.elderly_id` for simpler reporting queries (documented trade-off; PHP keeps them consistent; a periodic consistency check is a Stage 9 test). |
| `caregiver_id` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | Who did the action. |
| `action_type` | `ENUM('phone_call','home_visit','family_contact','referral','other')` | `NN` | — |
| `action_date` | `DATETIME` | `NN` | — |
| `outcome` | `TEXT` | `NULL` | Required before the parent alert can be `resolved` (PHP check). |
| `next_steps` | `TEXT` | `NULL` | e.g., "Revisit in 2 days". |
| `status` | `ENUM('planned','completed','cancelled')` | `NN`, `DEF 'completed'` | `planned` items appear on the "unresolved follow-ups" dashboard count. |
| `created_at` | `TIMESTAMP` | `NN`, `DEF CURRENT_TIMESTAMP` | — |

Indexes: `INDEX idx_followup_alert (alert_id)` ·
`INDEX idx_followup_elderly (elderly_id)`.

### 3.10 `audit_logs` — append-only trail

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | `PK`, `AUTO_INCREMENT` | `BIGINT` because this table grows fastest. |
| `user_id` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | NULL = system/cron action. |
| `action` | `VARCHAR(100)` | `NN` | Fixed vocabulary, e.g., `login`, `logout`, `create_elderly`, `acknowledge_alert`, `monitor_run`. |
| `entity_type` | `VARCHAR(50)` | `NULL` | e.g., `alerts`, `checkin_schedules`, `users`. |
| `entity_id` | `VARCHAR(36)` | `NULL` | Stored as text so it fits `INT` and `BIGINT` targets. |
| `details` | `TEXT` | `NULL` | Free text / JSON. **Never** passwords, hashes, or tokens. |
| `ip_address` | `VARCHAR(45)` | `NULL` | IPv4/IPv6 (`$_SERVER['REMOTE_ADDR']`; `cli` for cron). |
| `created_at` | `TIMESTAMP` | `NN`, `DEF CURRENT_TIMESTAMP` | No `updated_at` — rows are never updated or deleted by the app. |

Indexes: `INDEX idx_audit_user (user_id)` · `INDEX idx_audit_action (action)` ·
`INDEX idx_audit_created (created_at)` · `INDEX idx_audit_entity (entity_type, entity_id)`.

### 3.11 `system_settings` — configurable defaults (extra table)

| Column | Type | Constraints | Validation / notes |
|---|---|---|---|
| `setting_key` | `VARCHAR(50)` | `PK` | e.g., `default_grace_minutes`. |
| `setting_value` | `VARCHAR(255)` | `NN` | Stored as text; cast in PHP. |
| `description` | `TEXT` | `NULL` | Shown on the settings page. |
| `updated_by` | `INT UNSIGNED` | `NULL`, `FK → users(id) ON DELETE SET NULL` | — |
| `updated_at` | `TIMESTAMP` | `NN`, `DEF CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | — |

Seed rows (defaults — admin can change):

| `setting_key` | `setting_value` | Meaning |
|---|---|---|
| `default_grace_minutes` | `120` | Lateness allowed before overdue (2h). |
| `default_escalation_minutes` | `240` | Unacknowledged time before escalation (4h). |
| `monitor_window_days` | `30` | How far back the risk engine looks. |
| `urgent_threshold` | `60` | Score ≥ this → Urgent review. |
| `attention_threshold` | `30` | Score ≥ this → Attention required. |
| `app_timezone_label` | `[TODO-STUDENT]` | Display label, e.g. `Africa/Lagos`. |

---

## 4. Entity-Relationship Diagram (Mermaid)

```mermaid
erDiagram
    users ||--o{ caregiver_assignments : "serves as caregiver"
    elderly_profiles ||--o{ caregiver_assignments : "is served by"
    users ||--o{ elderly_profiles : "created_by"
    users ||--o{ elderly_profiles : "secondary contact"
    users ||--o{ checkin_schedules : "responsible caregiver"
    users ||--o{ checkin_schedules : "created_by"
    elderly_profiles ||--o{ checkin_schedules : "expects"
    checkin_schedules ||--o| checkin_records : "fulfilled by (0..1)"
    elderly_profiles ||--o{ checkin_records : "subject of"
    users ||--o{ checkin_records : "submitted_by"
    elderly_profiles ||--o{ alerts : "subject of"
    checkin_schedules ||--o{ alerts : "triggers (0..1 missed)"
    users ||--o{ alerts : "notified caregiver"
    users ||--o{ alerts : "escalated_to"
    alerts ||--o{ alert_acknowledgements : "acknowledged by"
    users ||--o{ alert_acknowledgements : "acknowledger"
    alerts ||--o{ followup_actions : "resolved via"
    elderly_profiles ||--o{ followup_actions : "subject of"
    users ||--o{ followup_actions : "action by"
    elderly_profiles ||--o{ caregiver_visits : "visited"
    users ||--o{ caregiver_visits : "visitor"
    alerts ||--o{ caregiver_visits : "prompts (optional)"
    users ||--o{ audit_logs : "actor (nullable)"
    users ||--o{ system_settings : "updated_by"
    users }o--|| elderly_profiles : "elderly login links to"

    users {
        int id PK
        string email UQ
        string password_hash
        enum role
        int elderly_profile_id FK
        enum status
    }
    elderly_profiles {
        int id PK
        string full_name
        date date_of_birth
        int secondary_contact_user_id FK
        enum status
    }
    caregiver_assignments {
        int id PK
        int elderly_id FK
        int caregiver_id FK
        bool is_primary
        enum status
    }
    checkin_schedules {
        int id PK
        int elderly_id FK
        int caregiver_id FK
        datetime expected_at
        int grace_minutes
        enum status
    }
    checkin_records {
        int id PK
        int schedule_id FK_UQ
        int elderly_id FK
        int submitted_by FK
        datetime checkin_at
        enum method
        enum wellbeing_status
    }
    caregiver_visits {
        int id PK
        int elderly_id FK
        int caregiver_id FK
        int alert_id FK
        datetime visit_date
        enum outcome
    }
    alerts {
        int id PK
        int elderly_id FK
        int schedule_id FK
        int caregiver_id FK
        enum alert_type
        enum priority
        int risk_score
        enum status
    }
    alert_acknowledgements {
        int id PK
        int alert_id FK
        int acknowledged_by FK
        datetime acknowledged_at
    }
    followup_actions {
        int id PK
        int alert_id FK
        int elderly_id FK
        enum action_type
        enum status
    }
    audit_logs {
        bigint id PK
        int user_id FK
        string action
        string entity_type
    }
    system_settings {
        string setting_key PK
        string setting_value
    }
```

> If your institution requires a Chen/crow's-foot ERD drawn in a CASE tool,
> recreate this diagram in draw.io / dbdiagram.io using the same table and
> column names — the Mermaid version above is the normative reference so
> names stay consistent.

---

## 5. Relationships explained (for your report §16–17)

| # | Relationship | Type | Business rule |
|---|---|---|---|
| R-1 | users ↔ elderly_profiles via caregiver_assignments | M:N (linked) | A caregiver serves many elderly; an elderly person has many caregivers (one primary). Ending a row preserves history. |
| R-2 | users.elderly_profile_id → elderly_profiles | N:1 | Many logins (elderly + family) share one profile; each login sees only that profile. |
| R-3 | elderly_profiles → checkin_schedules | 1:N | Many expected check-ins per person over time. |
| R-4 | checkin_schedules → checkin_records | 1:0..1 | At most one completion record per schedule; unmatched records are ad-hoc (`schedule_id IS NULL`). |
| R-5 | elderly_profiles → checkin_records | 1:N | Full submission history per person. |
| R-6 | checkin_schedules → alerts (missed) | 1:0..1 | At most one missed-check-in alert per schedule (idempotency). |
| R-7 | elderly_profiles → alerts | 1:N | Many alerts per person over time (missed, pattern, assistance). |
| R-8 | alerts → alert_acknowledgements | 1:N | Each acknowledgement is kept (re-acknowledgement after escalation is visible). |
| R-9 | alerts → followup_actions | 1:N | Many actions per case; resolution requires ≥1 with an outcome. |
| R-10 | alerts → caregiver_visits | 1:N (optional) | Visits may link to the alert that prompted them. |
| R-11 | elderly_profiles → caregiver_visits | 1:N | Visit history per person. |
| R-12 | users → audit_logs | 1:N (nullable) | Human actions link to a user; cron actions store NULL. |
| R-13 | elderly_profiles.secondary_contact → users | N:1 | Each profile nominates one authorized escalation recipient. |

---

## 6. How the required scenarios are represented (traceability)

| Spec scenario (§8) | Representation |
|---|---|
| Multiple caregivers | Many `users` with `role='caregiver'`. |
| Multiple elderly per caregiver | Many `caregiver_assignments` rows per `caregiver_id`. |
| Multiple schedules per elderly | Many `checkin_schedules` rows per `elderly_id`. |
| Completed and missed check-ins | `checkin_records` row exists → completed; `checkin_schedules.status='missed'` + alert → missed; derived overdue otherwise. |
| Multiple alerts over time | Many `alerts` rows per `elderly_id`, ordered by `created_at`. |
| Acknowledgement + escalation history | `alert_acknowledgements` rows + `alerts.escalated_to/at` + `status` transitions. |
| Multiple follow-ups per case | Many `followup_actions` rows per `alert_id`. |

---

## 7. Normalization notes (for your viva)

- **1NF:** all columns atomic (no comma-separated lists); each row uniquely
  identified by its PK.
- **2NF:** every non-key column depends on the whole PK (no partial
  dependencies — e.g., caregiver names are not stored in assignments).
- **3NF:** no transitive dependencies — with **one documented exception**:
  `followup_actions.elderly_id` duplicates `alerts.elderly_id` to simplify
  reporting joins. PHP maintains it; a Stage 9 consistency check verifies it.
  `checkin_records.submitted_by_role` is a deliberate **audit snapshot**,
  not a normalization violation (it records history, not current state).

---

## 8. Creation order for `elderly_welfare.sql` (Stage 2 preview)

Tables must be created parents-before-children because of foreign keys:

```
1. users (without FK to elderly_profiles first — added later via ALTER TABLE,
   because the reference is circular at creation time)
2. elderly_profiles (FKs to users OK)
3. ALTER TABLE users ADD FK elderly_profile_id → elderly_profiles(id)
4. caregiver_assignments
5. checkin_schedules
6. checkin_records
7. alerts
8. alert_acknowledgements
9. followup_actions
10. caregiver_visits
11. audit_logs
12. system_settings
13. Seed settings + synthetic demo rows
```

> The full script is a **Stage 2** deliverable. This order is agreed now so
> Stage 2 has no surprises. The circular reference between `users` and
> `elderly_profiles` is resolved with a deferred `ALTER TABLE` — a standard
> technique you can mention in your report.

---

*Next: `05-monitoring-and-risk-rules.md` — exactly how detection and scoring
work, with worked examples you can reuse in your report.*
