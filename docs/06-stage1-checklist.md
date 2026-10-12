# Stage 1 — Review Checklist & Gate to Stage 2

> **Stage 1 deliverable 6 of 6.** Use this page to confirm Stage 1 is complete
> before any Stage 2 code is generated. The rule from your brief applies:
> *"Do not proceed to the next development stage until the current stage has
> been explained clearly and its deliverables are complete."*

---

## 1. Stage 1 deliverables — where each required item lives

| Required Stage 1 item (brief §18) | Delivered in | Status |
|---|---|---|
| Refined project proposal | `docs/01-proposal-scope.md` §1 | ✅ Done |
| Problem statement | `docs/01-proposal-scope.md` §3 | ✅ Done |
| Main + specific objectives (SO-1…SO-10) | `docs/01-proposal-scope.md` §4–5 | ✅ Done |
| Project scope + limitations | `docs/01-proposal-scope.md` §6–7 | ✅ Done |
| Functional requirements (FR-01…FR-48) | `docs/02-requirements.md` §2 | ✅ Done |
| Non-functional requirements (NFR-01…NFR-11) | `docs/02-requirements.md` §3 | ✅ Done |
| Roles × permissions matrix | `docs/02-requirements.md` §4 | ✅ Done |
| System architecture + request/background flows | `docs/03-architecture.md` §1–4 | ✅ Done |
| Use-case diagram + catalogue (UC-01…UC-16) | `docs/03-architecture.md` §5 | ✅ Done |
| DFD Level 0 + Level-1 process list | `docs/03-architecture.md` §6 | ✅ Done |
| Page map / folder rationale | `docs/03-architecture.md` §7 + `README.md` §3 | ✅ Done |
| Security architecture | `docs/03-architecture.md` §8 | ✅ Done |
| Database design (11 tables, keys, constraints, indexes) | `docs/04-database-design.md` §2–3 | ✅ Done |
| ERD (Mermaid) + relationship explanations | `docs/04-database-design.md` §4–5 | ✅ Done |
| Normalization notes | `docs/04-database-design.md` §7 | ✅ Done |
| SQL creation order (for Stage 2) | `docs/04-database-design.md` §8 | ✅ Done |
| Monitoring algorithm (pseudocode) | `docs/05-monitoring-and-risk-rules.md` §2 | ✅ Done |
| Risk-engine rules + worked examples | `docs/05-monitoring-and-risk-rules.md` §3 | ✅ Done |
| Optional ML integration sketch | `docs/05-monitoring-and-risk-rules.md` §5 | ✅ Done (design only) |
| Honest AI wording for report/viva | `docs/01-proposal-scope.md` §1.3 | ✅ Done |
| Assumptions flagged for student | `docs/01-proposal-scope.md` §9 + §3 below | ✅ Done |

**Stage 1 code status:** no application PHP/SQL has been generated yet, and
none is claimed as complete. Empty module folders (`admin/`, `caregiver/`,
`elderly/`, `ai/`, `cron/`, `config/`, `includes/`, `assets/`, `database/`)
exist as placeholders so the agreed structure is visible in the repo.

---

## 2. Deliberate design decisions (recorded so later stages stay consistent)

| # | Decision | Rationale | Locked? |
|---|---|---|---|
| D-1 | One `users` table with `role` ENUM (`admin/caregiver/elderly`); family shares `elderly` role via `elderly_profile_id` link. | One login/session scheme; simplest secure option. | ✅ Yes |
| D-2 | One schedule row = one expected occurrence (no recurrence template engine). | Deadline maths stays a simple SQL expression; Year-2 friendly. | ✅ Yes |
| D-3 | `Overdue` is derived, never stored; `missed` is stored by the monitor only. | Derived values can't go stale; stored values are auditable. | ✅ Yes |
| D-4 | At-most-one check-in per schedule via `UNIQUE(schedule_id)` (NULLs allowed for ad-hoc). | Database enforces the rule even if PHP has a bug. | ✅ Yes |
| D-5 | At-most-one `missed_checkin` alert per schedule via `UNIQUE(schedule_id, alert_type)`. | Idempotent monitoring. | ✅ Yes |
| D-6 | Escalation updates the same alert row (`status → escalated`, timestamp once) rather than creating a child alert. | One work item per case; no escalation spam. | ✅ Yes |
| D-7 | Risk assessment stored in `alerts` (score/reasons/observations) — no separate `risk_assessments` table. | Fewer joins, same evidence; documented in DB design. | ✅ Yes |
| D-8 | One documented denormalization: `followup_actions.elderly_id`. | Simpler reporting queries; consistency test in Stage 9. | ✅ Yes |
| D-9 | Extra table `system_settings` for tunable thresholds/defaults. | Spec requires configurability without hardcoding. | ✅ Yes |
| D-10 | Ad-hoc check-ins can satisfy a schedule inside a −2h…deadline window (monitor links them). | Covers real elderly-page usage; tested in Stage 6. | ✅ Yes |
| D-11 | Help requests floor the score at 60 (never Routine). | A human asking for help outranks statistics. | ✅ Yes |
| D-12 | Scores/reasons visible to caregivers/admins only — never to elderly users. | Avoids alarming older people with labels. | ✅ Yes |
| D-13 | Only structural addition to the brief's folder list: `admin/reports.php` + `caregiver/reports.php`. | Brief §13 requires a reports page but §15 gives it no file. | ✅ Yes |
| D-14 | Optional Python ML is localhost-only, secret-guarded, fallback-to-rules, synthetic-data-only. | Core demo cannot be broken by the extension. | ✅ Yes |

> If you want to change any D-item, say so **now** — changing table or
> status design after Stage 2 means rewriting SQL and all later code.

---

## 3. Questions for you (the student) — ✅ ANSWERED 2026-10-12

| # | Question | Confirmed answer |
|---|---|---|
| 1 | Time zone | **`Africa/Lagos`** — Stage 2 will configure PHP + MySQL session zone to this. |
| 2 | Grace / escalation defaults | **120 min grace / 240 min escalation** (seed defaults; admin-tunable). |
| 3 | Role naming | `elderly` covering elderly + family (no objection raised — locked as D-1). |
| 4 | ML extension | **PHP rule engine only** — optional Python extension stays design-only. |
| 5 | Scheduler | **Real cron / Task Scheduler** + manual button (both paths built and tested). |
| 6 | Citation style + emergency number + data-protection rules | ⏳ Still `[TODO-STUDENT]` — provide when known; does not block Stage 2. |

Original prompt (kept for report traceability): *"Approved — proceed to
Stage 2. Timezone = X, grace/escalation = Y/Z, role naming = …, ML = yes/no,
scheduler = …"* — still awaiting your explicit Stage 2 approval (see §6).

---

## 4. What Stage 2 will deliver (preview — not started)

1. XAMPP install & configure guide (Apache + MySQL + `htdocs` placement).
2. `database/elderly_welfare.sql` — complete script in the §8 creation
   order, with synthetic seed data (clearly labelled fictional users,
   profiles, schedules) + demo accounts (passwords documented, to be changed).
3. `config/database.php` (+ `config/database.example.php` template) — PDO
   connection with `ERRMODE_EXCEPTION`, `utf8mb4`, emulated prepares off,
   session time zone set.
4. `test_db.php` (temporary, deleted after verification) + connection-test
   instructions via browser and phpMyAdmin import steps.
5. Updated `README.md` stage badges + Stage 2 checklist.

Nothing in Stage 2 changes the contracts agreed in Stage 1 without a
recorded decision.

---

## 5. Academic-use reminder

- Copy/adapt these docs into your report chapters (proposal, objectives,
  scope, requirements, architecture, DB schema, methodology) — they are
  written in report-ready language.
- Items marked `[TODO-STUDENT]` are facts **you** must supply; do not submit
  them unfilled.
- No test results, interviews, or references have been invented in Stage 1.
  Keep it that way: Stage 9 provides empty Actual/Status columns for you to
  complete by running the tests yourself.

## 6. Stage gate — awaiting your approval ⏳

Stage 1 is **complete and pushed**. To open Stage 2, reply exactly:

> **"Approved — proceed to Stage 2"**

On approval, Stage 2 will generate: XAMPP setup guide,
`database/elderly_welfare.sql` (full script + synthetic seed + demo accounts),
`config/database.php` (+ `.example` template), and the connection test.
