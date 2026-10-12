# AI-Powered Elderly Household Welfare Check-In System

> **Academic prototype** — Year 2, Semester 1 Computer Science project.
> A web-based system that helps caregivers and welfare coordinators monitor
> elderly people living alone, detect missed check-ins, prioritize cases for
> review, and record follow-up actions.
>
> ⚠️ **Important disclaimer:** this is a teaching prototype. It is **not**
> a substitute for emergency services, continuous medical monitoring, or
> professional clinical judgement. A missed check-in does **not** prove danger,
> and a completed check-in does **not** guarantee safety.

---

## 1. What problem does this solve?

Older people who live alone may experience illness, falls, or emergencies
without anyone noticing quickly. Traditional monitoring (physical visits,
phone calls) fails when:

- caregivers are unavailable or visits are missed,
- records are kept on paper and get lost,
- nobody notices a missed visit until much later,
- an elderly person cannot call for help.

This system provides **one central place** to:

1. Register elderly household profiles.
2. Assign caregivers.
3. Schedule expected welfare check-ins.
4. Record completed check-ins (by the elderly person, a family member,
   or a caregiver on their behalf).
5. **Automatically** detect missed / overdue check-ins.
6. Generate **alerts**, notify caregivers in-app, and **escalate**
   unacknowledged alerts to a secondary contact.
7. Apply a **transparent, rule-based risk-prioritization engine**
   (Routine / Attention required / Urgent review) so caregivers know
   what to review first.
8. Record visits, follow-up actions, and outcomes.
9. Show dashboards, charts, and reports from real database records.
10. Keep an audit trail and protect personal data.

---

## 2. Technology stack (as specified)

| Layer | Technology |
|---|---|
| Frontend structure | HTML5 |
| Styling | CSS3 + Bootstrap 5 (responsive, mobile-friendly) |
| Client interaction | JavaScript (validation, UX) + Chart.js (dashboard charts) |
| Backend | PHP (current XAMPP PHP 8.x), PHP sessions for auth |
| Database access | PDO with prepared statements (SQL-injection protection) |
| Database | MySQL / MariaDB (InnoDB), managed via phpMyAdmin |
| Dev environment | XAMPP (Apache + MySQL), VS Code, modern browser |
| Monitoring | PHP CLI script (`cron/monitor_checkins.php`), cron-compatible, + manual admin run button |
| AI-assisted monitoring | **Stage 1–7:** explainable PHP rule engine (`ai/risk_engine.php`). **Optional extension:** Python + scikit-learn micro-API (only if a suitable dataset and time allow; synthetic data only, never claimed as clinically validated) |

**Primary stack is PHP + MySQL.** Flask / Django / SQLite are **not**
used as the main backend or database.

---

## 3. Project structure

```text
elderly-welfare-system/
├── config/
│   └── database.php          # PDO connection (Stage 2). Credentials kept out of git.
├── includes/
│   ├── auth.php              # Session + role checks (Stage 3)
│   ├── functions.php         # Shared helpers, escaping, settings
│   ├── csrf.php              # CSRF token generation/validation
│   ├── header.php            # Shared layout top + nav
│   └── footer.php            # Shared layout bottom
├── admin/                    # Admin-only pages (role = admin)
│   ├── dashboard.php
│   ├── elderly.php           # Registration + list + edit
│   ├── caregivers.php
│   ├── assignments.php
│   └── settings.php          # Grace / escalation defaults, schedules
├── caregiver/                # Caregiver pages (role = caregiver)
│   ├── dashboard.php
│   ├── checkins.php
│   ├── alerts.php
│   ├── followups.php
│   └── visits.php
├── elderly/                  # Simple large-text interface (role = elderly/family)
│   ├── profile.php
│   └── checkin.php
├── ai/
│   └── risk_engine.php       # Explainable rule engine (Stage 7)
├── cron/
│   └── monitor_checkins.php  # Missed-check-in + escalation engine (Stage 6)
├── assets/
│   ├── css/ js/ images/
├── database/
│   └── elderly_welfare.sql   # Full MySQL script (Stage 2)
├── docs/                     # Stage-by-stage academic documentation
├── index.php / login.php / logout.php
└── README.md
```

> Any improvement to this structure will be explained when it is made.
> `docs/` and `database/` additions in Stage 1 are design documents only —
> no application code is claimed as complete yet.

---

## 4. Development stages

| Stage | Focus | Status |
|---|---|---|
| **Stage 1** | Requirements & design (proposal, scope, requirements, architecture, DB design, ERD, use-case, monitoring rules) | ✅ **This stage — in progress** |
| Stage 2 | Environment & database (XAMPP, `elderly_welfare.sql`, PDO connection) | ⏳ Next |
| Stage 3 | Authentication & roles (login/logout, hashing, RBAC) | ⏳ |
| Stage 4 | Household & caregiver management | ⏳ |
| Stage 5 | Check-in management | ⏳ |
| Stage 6 | Alerts & escalation | ⏳ |
| Stage 7 | AI-assisted (rule-based) monitoring | ⏳ |
| Stage 8 | Dashboard & reporting | ⏳ |
| Stage 9 | Testing & documentation | ⏳ |

**Rule:** we do not start the next stage until the current stage's
deliverables are complete and understood.

---

## 5. Stage 1 deliverables (where to read them)

All Stage 1 documents live in `docs/`:

| File | Contents |
|---|---|
| `docs/01-proposal-scope.md` | Refined proposal, problem statement, objectives, scope, limitations, justification |
| `docs/02-requirements.md` | Functional & non-functional requirements, roles & permissions matrix |
| `docs/03-architecture.md` | System architecture, request flows, use-case diagram, DFD Level 0, folder rationale, security architecture |
| `docs/04-database-design.md` | Normalized table design, keys, constraints, indexes, relationships, ERD (Mermaid) |
| `docs/05-monitoring-and-risk-rules.md` | How missed-check-in detection and the explainable risk engine work, with worked examples |
| `docs/06-stage1-checklist.md` | What is done / assumed / left for you to verify, and how to approve Stage 2 |

Start with `docs/01-proposal-scope.md` and read in order.

---

## 6. Roles (summary)

| Role | Who | Key permissions |
|---|---|---|
| **Administrator** | System / welfare office admin | Dashboards, elderly CRUD, caregiver accounts, assignments, schedules, escalation settings, alerts, reports, audit logs, user management |
| **Caregiver / Welfare Coordinator** | Assigned carer | Own assigned elderly only: schedules, check-in recording, alerts acknowledge/resolve, visits, follow-ups, history, reports |
| **Elderly / Authorized family member** | Older person or approved family | Own profile only: view schedule, submit check-in, request assistance, view own history. Large-text simple UI |

Full permission matrix: `docs/02-requirements.md`.

---

## 7. Demo accounts (from Stage 3 onward — not active yet)

> Sample accounts will be created in Stage 2/3 **for local testing only**
> with clearly synthetic data. You must change their passwords before any
> real use. Never commit real passwords or contact details.

Planned local-only accounts (to be created later):

- `admin@example.local` / `Admin123!` (administrator)
- `caregiver@example.local` / `Care123!` (caregiver)
- `elderly@example.local` / `Elder123!` (elderly/family)

These do **not** exist yet — they are documented here so the plan is
consistent across stages.

---

## 8. What is / is not in scope

**Will be implemented (core):**

- Secure login, sessions, role-based access, CSRF protection, audit logs.
- Elderly profiles, caregiver accounts, assignments.
- Check-in schedules, submissions, history, overdue calculation.
- Automatic missed-check-in detection (cron-compatible PHP script + manual run).
- Alert lifecycle: open → acknowledged → escalated / resolved, with no duplicates.
- Escalation to an authorized secondary contact after a configurable delay.
- Transparent PHP risk-prioritization rules with recorded reasons.
- Visits, follow-up actions, dashboards, Chart.js reports from live data.

**Optional extensions (only if time allows, clearly labelled):**

- Email notifications (PHP `mail()` / SMTP).
- Python + scikit-learn risk API behind the PHP app (synthetic data only).

**Explicitly future work (not in this project):**

- SMS gateway, mobile apps, real-time medical sensors, clinical diagnosis,
  integration with hospital systems.

---

## 9. Academic integrity notes

- All demonstration data is **synthetic** (fictional names, addresses, phones).
- No research findings, interviews, references, or test results are invented.
  Placeholders you must fill in yourself are marked with **`[TODO-STUDENT]`**.
- Test results are only marked pass/fail after you actually run them (Stage 9).
- The "AI" in this project is an **explainable rule engine** unless the
  optional ML extension is built — it is never described as a medical device.

---

## 10. How to proceed

1. Read the six Stage 1 docs in `docs/` in order.
2. Check `docs/06-stage1-checklist.md` for decisions you need to confirm
   (time zone, grace/escalation defaults, role naming).
3. Reply **"Approved — proceed to Stage 2"** (or ask for changes).
4. Only then will Stage 2 (XAMPP setup + `elderly_welfare.sql` + PDO
   connection) be generated.

---

## 11. Repository hygiene

- Database credentials live in `config/database.php` (Stage 2) and are
  **never committed with real secrets** — a `config/database.example.php`
  template will be provided.
- `database/elderly_welfare.sql` contains schema + synthetic seed data only.
- Time zone is configured once (application + MySQL session) — see
  `docs/04-database-design.md`.
