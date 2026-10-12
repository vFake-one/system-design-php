# Stage 1 — Functional & Non-Functional Requirements

> **Stage 1 deliverable 2 of 6.** Defines *what* the system must do (functional)
> and *how well* it must do it (non-functional). IDs like FR-01 are used to
> trace objectives → requirements → test cases in Stage 9.

---

## 1. Actors (who uses the system)

| Actor ID | Actor | Description |
|---|---|---|
| A-1 | **Administrator** | Welfare-office staff. Full management access. Configures schedules, grace/escalation defaults, users, and views all reports and audit logs. |
| A-2 | **Caregiver / Welfare Coordinator** | Assigned carer. Sees **only** elderly people assigned to them (unless explicitly authorized). Records check-ins/visits, acknowledges alerts, documents follow-ups. |
| A-3 | **Elderly person / Authorized family member** | Sees **only** their own linked profile. Simple large-text UI: view schedule, submit check-in, request help, view own history. |
| A-4 | **Monitoring engine (system actor)** | The `cron/monitor_checkins.php` script. Runs periodically (and on manual admin trigger). Creates alerts, marks missed items, escalates. Never logs in as a human. |
| A-5 | **Python risk API (optional actor)** | Optional scikit-learn micro-service. Only scores when asked by PHP; PHP rule engine is the fallback. |

> A-4 and A-5 are *system* actors (software, not people). Naming them keeps
> the use-case and data-flow diagrams honest about what runs automatically.

---

## 2. Functional requirements

### 2.1 User authentication & authorization (Module 1)

| ID | Requirement | Actor(s) | Priority* |
|---|---|---|---|
| FR-01 | The system shall authenticate users by email + password using `password_hash()` / `password_verify()`. | A-1, A-2, A-3 | Must |
| FR-02 | The system shall create a server-side session on login, regenerate the session ID, and destroy it fully on logout. | A-1, A-2, A-3 | Must |
| FR-03 | The system shall enforce role-based access control **on the server** for every page and action; hidden menu items alone are not sufficient. | All human | Must |
| FR-04 | A caregiver shall only access elderly records explicitly assigned to them (assignment check on each query). | A-2 | Must |
| FR-05 | An elderly/family account shall only access its own linked elderly profile. | A-3 | Must |
| FR-06 | All state-changing forms (create/update/delete/acknowledge) shall require a valid CSRF token. | All human | Must |
| FR-07 | Failed and successful login attempts shall be rate-limited (basic) and recorded in the audit log without storing passwords. | System | Must |
| FR-08 | Admin shall be able to create, suspend, and reactivate user accounts and reset passwords (new password is hashed; never displayed/stored in plain text). | A-1 | Must |

*\*Priority: Must = core demo fails without it; Should = expected; Could = optional extension.*

### 2.2 Elderly household registration & profiles (Module 2)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-09 | Admin shall register an elderly profile: full name (required), date of birth, gender, address, phone, emergency contact (name/phone/relationship), secondary escalation contact, living situation, welfare notes, status. | A-1 | Must |
| FR-10 | Admin shall edit and archive (soft-delete via `status`) profiles; archived profiles are hidden from normal lists but history is kept. | A-1 | Must |
| FR-11 | The system shall validate inputs (required fields, date formats, phone patterns) on **both** client (JS) and server (PHP). | System | Must |
| FR-12 | Caregivers shall view full details only for assigned elderly people; elderly/family shall view only their own profile summary. | A-2, A-3 | Must |

### 2.3 Caregiver management & assignment (Module 3)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-13 | Admin shall create caregiver accounts and assign one or more caregivers to each elderly person, marking one as **primary**. | A-1 | Must |
| FR-14 | The system shall prevent duplicate active assignments of the same caregiver to the same elderly person. | System | Must |
| FR-15 | Admin shall end an assignment without deleting past check-ins, alerts, or visits (history is preserved). | A-1 | Must |

### 2.4 Welfare check-in scheduling (Module 4)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-16 | Admin (and authorized caregiver for own assigned elderly) shall create a check-in schedule: elderly person, assigned caregiver, expected date/time, grace period (minutes), escalation delay (minutes), notes. | A-1, A-2 | Must |
| FR-17 | The system shall support multiple schedules per elderly person (e.g., morning + evening) and one-off or repeated routines. | System | Must |
| FR-18 | Schedules shall have status `scheduled / completed / missed / cancelled`. `Overdue` is derived (past deadline + grace, still `scheduled`). | System | Must |
| FR-19 | Admin/caregiver shall be able to cancel a schedule with a reason; cancellation never deletes history. | A-1, A-2 | Should |

### 2.5 Check-in submission & history (Module 5)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-20 | Elderly/family users shall submit a check-in for their own profile, optionally flagging "needs assistance" with notes. | A-3 | Must |
| FR-21 | Caregivers shall record a check-in on behalf of an assigned elderly person, with the **submitter identity and method** stored (self_online / caregiver_entry / phone_logged / visit_logged). | A-2 | Must |
| FR-22 | The system shall allow ad-hoc check-ins with no prior schedule (schedule link nullable). | A-2, A-3 | Must |
| FR-23 | The system shall enforce **at most one** completed check-in record per schedule (unique constraint) while allowing unlimited ad-hoc records. | System | Must |
| FR-24 | Authorized users shall view filterable per-person check-in history (date range, status). | A-1, A-2, A-3 (own) | Must |

### 2.6 Automatic missed-check-in detection (Module 6)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-25 | The monitoring engine shall, on each run: find `scheduled` items whose `expected_at + grace` has passed; check for a valid check-in; mark those without one as `missed`. | A-4 | Must |
| FR-26 | The engine shall be **idempotent**: re-running it shall not create duplicate missed markings or duplicate alerts for the same schedule. | A-4 | Must |
| FR-27 | The engine shall run via cron/Task Scheduler **and** via a manual admin "Run monitoring now" button (for demos). It shall not depend on any page being left open. | A-4, A-1 | Must |
| FR-28 | Every monitoring decision shall record timestamps and be traceable in the database. | A-4 | Must |

### 2.7 AI-assisted risk prioritization (Module 7)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-29 | The system shall compute a review priority (*Routine / Attention required / Urgent review*) from documented inputs: missed count (7/30 days), hours since last successful check-in, overdue frequency, deviation from usual pattern, days since last visit, unacknowledged-alert flag. | A-4 / System | Must |
| FR-30 | Every flagged case shall store: priority, numeric score, human-readable reasons, timestamp, observations snapshot, reviewer, and outcome. | System | Must |
| FR-31 | Caregivers shall see *why* a case was flagged (reasons list), not just the label. | A-2 | Must |
| FR-32 | The optional Python/scikit-learn scorer, if enabled, shall be called by PHP over authenticated localhost HTTP; on any failure the PHP rule engine result shall be used instead. | A-5 | Could |

### 2.8 Alerts & escalation (Modules 8–9)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-33 | The system shall create an alert for each newly missed check-in (type `missed_checkin`) addressed to the assigned/primary caregiver, with in-app visibility. | A-4 | Must |
| FR-34 | The system shall create pattern/assistance alerts (`pattern_flag`, `assistance_request`) with priority and reasons. | System | Must |
| FR-35 | At most **one** `missed_checkin` alert shall exist per schedule (unique constraint + application check). | System | Must |
| FR-36 | Caregivers shall acknowledge alerts with an action note; acknowledgement records who and when. | A-2 | Must |
| FR-37 | If an alert stays unacknowledged beyond the escalation delay, the system shall escalate **once** to the authorized secondary contact (`escalated` status + timestamp). No repeated escalation spam. | A-4 | Must |
| FR-38 | Alerts shall be resolvable only after a documented outcome/follow-up exists (enforced in application logic with a clear error message). | A-2 | Must |
| FR-39 | Alert statuses (`open / acknowledged / escalated / resolved / dismissed`) and full lifecycle timestamps shall be queryable and reportable. | A-1, A-2 | Must |
| FR-40 | In-app notification (alert lists + dashboard badges) is the guaranteed minimum; email is an optional extension; SMS is out of scope. | System | Must/Could |

### 2.9 Visits & follow-up records (Module 10)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-41 | Caregivers shall log visits: elderly person, date/time, type (routine/follow-up/emergency), outcome, notes, optional link to an alert. | A-2 | Must |
| FR-42 | Caregivers shall log follow-up actions per alert: type (call/visit/family contact/referral/other), date, outcome, next steps, status. Multiple follow-ups per alert are allowed. | A-2 | Must |

### 2.10 Dashboard & reports (Module 11)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-43 | Dashboards shall show live counts from the database (never hardcoded): total elderly, active caregivers, due today, completed, overdue, unacknowledged alerts, urgent-review cases, recent visits, unresolved follow-ups. | A-1, A-2 | Must |
| FR-44 | Reports shall be filterable by date range, caregiver, check-in status, and alert status, and shall cover: completion, missed, alerts/escalation, visits, follow-up outcomes. | A-1, A-2 | Must |
| FR-45 | Charts shall be rendered with Chart.js from server-computed aggregates. | System | Must |

### 2.11 Audit logging & privacy (Module 12)

| ID | Requirement | Actor(s) | Priority |
|---|---|---|---|
| FR-46 | The system shall append audit entries for: logins/logouts, user/assignment changes, profile changes, schedule changes, check-in submissions, alert acknowledge/escalate/resolve, visit/follow-up writes, settings changes. No passwords or secrets in logs. | System | Must |
| FR-47 | Admin shall view and filter audit logs; caregivers shall not see other caregivers' audit entries. | A-1 | Must |
| FR-48 | All database access shall use PDO prepared statements; all dynamic output shall be HTML-escaped. | System | Must |

---

## 3. Non-functional requirements

| ID | Category | Requirement | How it will be demonstrated |
|---|---|---|---|
| NFR-01 | **Security** | No plain-text passwords; `password_hash()` (default algorithm) + `password_verify()`; session ID regenerated at login; CSRF tokens; RBAC on every endpoint; prepared statements; escaped output. | Code review + login/RBAC/CSRF/SQLi test cases (Stage 9). |
| NFR-02 | **Usability (general)** | Consistent Bootstrap nav, clear labels, feedback messages, confirmations for destructive actions, empty-state messages, search/pagination on long lists. | UI walkthrough + mobile-responsiveness tests. |
| NFR-03 | **Usability (elderly)** | Elderly pages use ≥16px base font, large buttons (≥44px targets), one primary action per screen, plain language, minimal navigation depth (≤2 clicks to check in). | Checklist review + supervisor walkthrough. |
| NFR-04 | **Performance** | Key pages (dashboards, lists) respond in < 2s on XAMPP with seed data (~50 elderly, ~500 schedules); monitoring run completes in < 5s at that scale. | Timed demo (informal; not a load test). |
| NFR-05 | **Reliability** | Monitoring is idempotent; cron + manual trigger; no duplicate alerts across repeated runs; transactions for multi-write operations. | Duplicate-prevention test cases. |
| NFR-06 | **Maintainability** | Documented folder structure; shared `includes/` helpers; consistent table/column naming; commented rule engine; README install steps. | Structure review. |
| NFR-07 | **Compatibility** | Runs on current XAMPP (PHP 8.x + MariaDB/MySQL) on Windows/macOS/Linux; works in current Chrome/Edge/Firefox. | Install test. |
| NFR-08 | **Data integrity** | InnoDB + foreign keys; unique constraints (emails, assignment pairs, one-check-in-per-schedule, one-missed-alert-per-schedule); server-side validation; audit trail. | Schema review + constraint tests. |
| NFR-09 | **Privacy** | Personal data visible only to authorized roles; no real personal data in repo/seed; credentials outside versioned code; session timeout (e.g., 30 min inactivity). | Access-matrix tests. |
| NFR-10 | **Explainability** | Every risk flag shows reasons and inputs; no black-box scores without justification. | Risk-engine scenario tests. |
| NFR-11 | **Availability (demo)** | Monitoring does not require a browser page to stay open (cron/CLI). | Cron config + manual-run demo. |

---

## 4. Roles × permissions matrix (server-enforced)

Legend: ✅ allowed · ❌ denied · 🔒 allowed with ownership/assignment check.

| Capability | Admin (A-1) | Caregiver (A-2) | Elderly/Family (A-3) |
|---|---|---|---|
| View system dashboard | ✅ (global) | ✅ (own assignments only) | ❌ (own simple home instead) |
| Manage users (create/suspend/reset pw) | ✅ | ❌ | ❌ |
| Register/edit/archive elderly profiles | ✅ | 🔒 view assigned only | 🔒 view own summary only |
| Create caregiver assignments | ✅ | ❌ | ❌ |
| Create/edit/cancel schedules | ✅ (all) | 🔒 (own assigned only) | ❌ |
| Submit check-in (self) | ❌ | ❌ | ✅ (own profile) |
| Record check-in on behalf | ✅ | 🔒 (own assigned) | ❌ |
| View check-in history | ✅ (all) | 🔒 (own assigned) | 🔒 (own only) |
| View alerts | ✅ (all) | 🔒 (own assigned) | ❌ |
| Acknowledge / resolve alerts | ✅ | 🔒 (own assigned) | ❌ |
| Log visits & follow-ups | ✅ | 🔒 (own assigned) | ❌ |
| Configure grace/escalation defaults | ✅ | ❌ | ❌ |
| Run monitoring manually | ✅ | ❌ | ❌ |
| View reports (global filters) | ✅ | 🔒 (own assigned) | ❌ |
| View audit logs | ✅ | ❌ | ❌ |
| Request assistance ("I need help") | ❌ | ❌ | ✅ (own profile) |

> Every 🔒 row is enforced by a `WHERE` clause or explicit ownership check
> in PHP/SQL — e.g., a caregiver's alert query always joins
> `caregiver_assignments` for `caregiver_id = :current_user`.

---

## 5. Input validation summary (applied in Stages 3–6)

| Field class | Client (JS) | Server (PHP) |
|---|---|---|
| Required text (names, titles) | `required`, maxlength | `trim()`, length check, reject empty |
| Email | `type=email` | `filter_var(EMAIL)`, unique check |
| Phone | pattern hint | regex `^[+0-9][0-9 ()-]{5,19}$`, nullable |
| Dates/times | `datetime-local`, min/max | `DateTime` parse, range check, no far-future/past abuse |
| Enums (role, status, priority) | `<select>` | whitelist check against allowed list |
| Integers (grace/escalation minutes) | `type=number`, min/max | `(int)` cast + range (e.g., 5–1440) |
| Free text (notes) | maxlength | strip control chars, store raw, **escape on output** |
| File uploads | — | **Not implemented in core.** If added: MIME + size + extension whitelist, random filename, store outside web root. |

---

*Next: `03-architecture.md` — system architecture, flows, use-case diagram,
and security design.*
