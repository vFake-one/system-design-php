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
│   ├── database.php          # PDO connection ✅ Stage 2 (XAMPP defaults; see .example template)
│   └── database.example.php  # Safe template copy
├── includes/
│   ├── auth.php              # Session + role checks ✅ Stage 3
│   ├── functions.php         # Escaper, redirect, flash, audit ✅ Stage 3
│   ├── csrf.php              # CSRF tokens ✅ Stage 3
│   ├── header.php            # Shared layout top + nav ✅ Stage 3
│   └── footer.php            # Shared layout bottom ✅ Stage 3
├── admin/                    # Admin-only pages (role = admin)
│   ├── dashboard.php         # Placeholder ✅ Stage 3 (full: Stage 8)
│   ├── elderly.php           # Household list ✅ Stage 4
│   ├── elderly_form.php      # Register/edit household ✅ Stage 4
│   ├── elderly_view.php      # Profile (shared w/ scoped caregivers) ✅ Stage 4
│   ├── caregivers.php        # Account list + suspend/reset ✅ Stage 4
│   ├── caregiver_form.php    # Create/edit account ✅ Stage 4
│   ├── assignments.php       # Assign/unassign/primary ✅ Stage 4
│   └── settings.php          # Grace / escalation defaults (Stage 6)
├── caregiver/                # Caregiver pages (role = caregiver)
│   ├── dashboard.php         # Placeholder ✅ Stage 3 (full: Stages 5-6)
│   ├── households.php        # My assigned households ✅ Stage 4
│   ├── checkins.php
│   ├── alerts.php
│   ├── followups.php
│   └── visits.php
├── elderly/                  # Simple large-text interface (role = elderly/family)
│   ├── profile.php           # (Stage 5)
│   └── checkin.php           # Placeholder ✅ Stage 3 (full: Stage 5)
├── ai/
│   └── risk_engine.php       # Explainable rule engine (Stage 7)
├── cron/
│   └── monitor_checkins.php  # Missed-check-in + escalation engine (Stage 6)
├── assets/
│   ├── css/ js/ images/
├── database/
│   └── elderly_welfare.sql   # Full MySQL script ✅ Stage 2
├── docs/                     # Stage-by-stage academic documentation
├── index.php / login.php / logout.php   # ✅ Stage 3 (`test_db.php` removed after use)
└── README.md
```

> Any improvement to this structure will be explained when it is made.
> Checkboxes (✅ Stage N) mark implemented files; unmarked files are planned
> for their listed stage. Only Bootstrap's own files sit in `assets/` vendor
> copies; everything else is project code.

---

## 4. Development stages

| Stage | Focus | Status |
|---|---|---|
| **Stage 1** | Requirements & design (proposal, scope, requirements, architecture, DB design, ERD, use-case, monitoring rules) | ✅ Done |
| **Stage 2** | Environment & database (XAMPP, `elderly_welfare.sql`, PDO connection) | ✅ Done |
| **Stage 3** | Authentication & roles (login/logout, hashing, RBAC) | ✅ Done |
| **Stage 4** | Household & caregiver management (profiles, accounts, assignments) | ✅ Done — run TC-4.01–4.21 on your XAMPP (see `docs/09`) |
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
| `docs/07-stage2-environment-and-database.md` | **Stage 2 guide:** XAMPP install, SQL import, connection config, `test_db.php` test, troubleshooting |
| `docs/08-stage3-authentication-and-roles.md` | **Stage 3 guide:** sessions, hashing, RBAC, CSRF, test cases TC-3.01–3.20, viva Q&A |
| `docs/09-stage4-household-and-caregiver-management.md` | **Stage 4 guide:** profiles, accounts, assignments, test cases TC-4.01–4.21 |

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

## 7. Demo accounts (local testing only — change these passwords)

Seeded by `database/elderly_welfare.sql` — **log in with them from Stage 3**:

- `admin@example.local` / `Admin123!` (administrator)
- `ruth.caregiver@example.local` / `Care123!` (caregiver)
- `david.caregiver@example.local` / `Care123!` (caregiver)
- `mary.elderly@example.local` / `Elder123!` (elderly/family)
- `peter.family@example.local` / `Elder123!` (elderly/family)

Passwords are stored as bcrypt hashes (cost 10). Never commit real passwords
or contact details. The login page shows a reminder box with the first three
accounts — remove that box before any real deployment or final submission.

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

1. On your XAMPP, log in as admin and work through **TC-4.01–TC-4.21** in
   `docs/09-stage4-household-and-caregiver-management.md`, filling Actual/Pass.
2. Run the cleanup SQL at the end of §B so later demos start from clean seed.
3. Reply **"Approved — proceed to Stage 5"** (or paste any failure for help).
4. Only then will Stage 5 (schedules, check-in submission, history) be built.

---

## 11. Repository hygiene

- Database credentials live in `config/database.php` (Stage 2) and are
  **never committed with real secrets** — a `config/database.example.php`
  template will be provided.
- `database/elderly_welfare.sql` contains schema + synthetic seed data only.
- Time zone is configured once (application + MySQL session) — see
  `docs/04-database-design.md`.
