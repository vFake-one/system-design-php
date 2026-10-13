# Stage 9 — Master test plan & release checks

**Goal:** prove the whole system works end-to-end on a fresh install, with no
stage broken by a later one. No new features in Stage 9 — this document,
the install guide (`docs/15`), the user manual (`docs/16`) and the report
guide (`docs/17`) are the deliverables, plus any defect fixes below.

---

## 1. Regression map (re-run before sign-off)

You already ran these during their stages. Before submission, re-run at
least the starred "smoke" row of each stage on your XAMPP; if any starred
test fails, re-run that whole stage's table.

| Stage | Doc | Starred smoke test | Full table |
|---|---|---|---|
| 2 | `docs/07` | Import succeeds; 5 demo logins work | §C |
| 3 | `docs/08` | ★ TC-3.01 + TC-3.20 (login OK, 403 blocks) | TC-3.01–3.20 |
| 4 | `docs/09` | ★ TC-4.01 (register household) | TC-4.01–4.21 |
| 5 | `docs/10` | ★ TC-5.01 (schedule → submit → history) | TC-5.01–5.17 |
| 6 | `docs/11` | ★ TC-6.01 (monitor run creates alerts) | TC-6.01–6.20 |
| 7 | `docs/12` | ★ TC-7.04 (scenario A scores 15) | TC-7.01–7.12 |
| 8 | `docs/13` | ★ TC-8.02 (tiles equal SQL) | TC-8.01–8.15 |

Record results in the sign-off table (§5).

---

## 2. Fresh-install end-to-end tests (TC-9.01 – TC-9.12)

Do these on a **fresh database** (drop + re-import per `docs/15 §2`) to
simulate the examiner's machine.

Demo logins: `admin@example.local` / `Admin123!` ·
`ruth.caregiver@example.local` / `Care123!` ·
`mary.elderly@example.local` / `Elder123!`.

| ID | Test | Steps | Expected |
|---|---|---|---|
| TC-9.01 | Fresh install | Follow `docs/15` exactly, top to bottom | All 5 demo logins work; dashboards load with seed figures |
| TC-9.02 | Admin journey | As admin: register a household → create a caregiver → assign → schedule a check-in due in 10 min | Each step succeeds; new rows visible in Households/Schedules |
| TC-9.03 | Elderly journey | As the new household's login: open My check-ins → submit "I'm OK" | Success message; schedule flips to completed; history shows it |
| TC-9.04 | Help journey | Same login: submit "I need help" | URGENT `assistance_request` alert exists immediately (no cron wait) |
| TC-9.05 | Caregiver journey | As the assigned caregiver: Dashboard → acknowledge the alert → log a follow-up with outcome → resolve | Each step allowed in order; resolve refused *before* the outcome exists (FR-38) |
| TC-9.06 | Monitor journey | Wait past a grace deadline (or craft an overdue schedule), run the monitor from Settings | Schedule marked missed; `missed_checkin` alert with score + reasons appears |
| TC-9.07 | Escalation journey | Leave the alert unacknowledged past escalation time; re-run monitor | Status becomes escalated; audit shows the escalation entry |
| TC-9.08 | Reports journey | Admin Reports: each tab → filter → CSV export | Rows match filters; CSV opens correctly in Excel |
| TC-9.09 | Audit journey | Admin Audit: filter by your actions today | Every action from TC-9.02–9.08 is present, newest first |
| TC-9.10 | Account journey | As caregiver: My account → wrong current password, then correct; log out/in | Wrong fails safely; correct works; new password required at login |
| TC-9.11 | Security sweep | As elderly, open `admin/dashboard.php`; as caregiver, open `admin/audit.php`; log out, open any page | 403, 403, redirect to login |
| TC-9.12 | Offline + small screen | Disconnect network; reload dashboards; resize to phone width | Charts render (local Chart.js); layout stays usable (Bootstrap) |

---

## 3. Static audit (run by the developer, 2026-10-13)

No PHP/MySQL exists in the build sandbox, so the code was audited
statically instead — the same checks an examiner can re-run with `grep`:

| # | Check | Result |
|---|---|---|
| A | Every `require/include` target file exists | ✅ (1 false positive: docblock example in `config/database.php`) |
| B | Every page under `admin/`, `caregiver/`, `elderly/` + `account.php` enforces `require_role`/`require_login` | ✅ (`index.php`/`logout.php` legitimately serve guests) |
| C | `index.php` redirects logged-in users; `logout.php` is POST+CSRF | ✅ |
| D | Every static + dynamic (`$rootPrefix`) link to `*.php`/`assets/*` resolves; all form/view pages reachable | ✅ |
| E | Every file reading `$_POST` calls `require_valid_csrf()` | ✅ |
| F | Every project function called is defined exactly where expected | ✅ |
| G | Every table in every query exists in `elderly_welfare.sql` | ✅ ("stays" = comment text) |
| H | All output escaped (`e()` / `(int)` casts / fixed literals) | ✅ |
| I | Login `?next=` validated by `is_safe_next()`; elderly pages locked to own profile; re-score scoped; settings enforce urgent > attention | ✅ |
| J | All pages including `header.php` set `$pageTitle` + `$rootPrefix` | ✅ |

---

## 4. Defect log

| ID | Found | Severity | Description | Fix | Retest |
|---|---|---|---|---|---|
| — | — | — | *No defects found in the Stage 9 audit. Add rows here if TC-9.xx fails.* | — | — |

Conventions if you must add a row: Severity = Blocker / Major / Minor.
Every fix gets a commit; every fixed Blocker/Major re-runs its stage's full
table (§1), not just the failing case.

---

## 5. Sign-off

| Check | Result (pass/fail) | Date | Initials |
|---|---|---|---|
| §1 smoke rows (7 stages) | | | |
| TC-9.01 – TC-9.12 | | | |
| Defect log empty or all fixed | | | |
| Install guide followed verbatim once | | | |
| Screenshots: dashboards, review queue, one CSV | | | |

When every row says **pass**, the system is ready for submission —
assemble the report per `docs/17`.
