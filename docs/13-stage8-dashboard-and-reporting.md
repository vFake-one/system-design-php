# Stage 8 — Dashboard & reporting

**Goal:** turn the data collected in Stages 4–7 into live statistics, charts,
filterable reports with CSV export, a read-only audit viewer, and a
self-service account page — with **zero hard-coded numbers** (FR-43).

**Design decisions added in this stage:** D-39 … D-44 (see §7).

---

## 1. What was built

| File | Purpose |
|---|---|
| `includes/reports_lib.php` | **Single query source** (D-39): `dashboard_stats()`, the five `report_*()` queries, `scope_sql()`, `report_range()`, `csv_download()`. Dashboards, reports and CSV all call the same functions, so the three can never disagree. |
| `includes/reports_page.php` | **Shared reports renderer** (D-43): tabs + filters + table + CSV. Used by both roles — only `$scope` differs. |
| `admin/dashboard.php` | System-wide dashboard (10 tiles + 2 charts + alert strip). |
| `caregiver/dashboard.php` | Same layout, confined to assigned households (9 tiles). |
| `admin/reports.php` / `caregiver/reports.php` | Thin gates: role check → `$scope` → shared renderer. |
| `admin/audit.php` | Read-only `audit_logs` viewer: actor/action/date filters + paging. |
| `account.php` | Every role: read-only profile + password change (current password required). |
| `assets/js/chart.umd.min.js` | Chart.js v4.5.1, bundled locally like Bootstrap (works fully offline). |
| `includes/header.php` | Nav: Reports (admin + caregiver), Audit (admin), My account (everyone). |
| `includes/footer.php` | `$extraScripts` support so only chart pages load Chart.js. |

---

## 2. Tile definitions (exact semantics)

| Tile | Counts |
|---|---|
| Elderly (active) | `elderly_profiles` with `status='active'` |
| Caregivers (active) | `users` with `role='caregiver'` and `status='active'` |
| My households | size of your assigned-id list |
| Due today | schedules `status='scheduled'` with `DATE(expected_at) = today` |
| Overdue now | `status='scheduled'` **and** `expected_at + grace_minutes < NOW()` — the same derived expression as the schedules page (D-44, still no stored "overdue") |
| Completed / Missed (30d) | schedules with that status and `expected_at` in the last 30 days |
| Unacknowledged alerts | alerts with `status` **open or escalated** (D-40: an escalated alert still awaits a *human* acknowledgement) |
| Urgent review | `priority='urgent'` and status open/acknowledged/escalated |
| Visits (7d) | `caregiver_visits` with `visit_date` in the last 7 days |
| Planned follow-ups | `followup_actions` with `status='planned'` |

Every tile is a link to the page where that work gets done. Caregiver tiles
count **assigned households only** — the same functions, a narrower `$scope`.

---

## 3. Charts

- **Check-ins, last 14 days** (stacked bar): completed vs missed per expected
  day. Bars always sum to the matching report rows for the same range.
- **Active alerts by priority** (doughnut): routine / attention / urgent over
  open + acknowledged + escalated — the same "active" definition as the
  alerts worklist filter.

Chart.js is loaded from `assets/js/` (no CDN), so dashboards work on a
machine with no internet — a stated project constraint.

---

## 4. The five reports

| Tab | One row per… | Key columns |
|---|---|---|
| Check-in completion | household | scheduled / completed / missed, completion %, longest gap between actual submissions (days) |
| Missed check-ins | missed schedule | expected time, window end (= expected + grace), days ago |
| Alert response | alert opened in range | priority, status, first-response lag (= first acknowledgement − opened; `—` while nobody has acked) |
| Caregiver visits | visit | caregiver (`(removed)` when the account is gone but history survives), type, outcome |
| Follow-up actions | action | action type, by whom, status, days open (planned ones only) |

Filters: household dropdown (itself scope-confined — a tampered `hh=` id is
silently ignored), from/to dates (garbage → safe last-30-days default).

**CSV export** re-runs the *same filtered dataset* through `csv_download()`:
UTF-8 BOM (accents open correctly in Excel) and a formula-injection guard —
cells starting with `= + - @` get a leading `'` (D-41). Exports are capped
with the screen at `REPORT_ROW_CAP` = 500 rows (D-42); the UI says so
honestly and asks you to narrow the filters.

---

## 5. Audit viewer & account page

- **Audit viewer** (`admin/audit.php`, FR-45): newest-first rows from
  `audit_logs` with actor / action / date filters and 50-per-page paging
  (shared `pager()` helper). SELECT-only by construction. Note the date
  filter defaults to the last 30 days — widen it to see older entries.
- **My account** (`account.php`, FR-46): read-only profile facts plus a
  password-change form that (a) demands the *current* password, (b) enforces
  the same `valid_password()` policy as registration (min 8 chars, at least
  one letter and one number), (c) regenerates the session id, and
  (d) writes a `password_changed` audit row.

---

## 6. Accuracy cross-checks (run in phpMyAdmin)

Each tile must equal its SQL below. Replace `N,…` with assigned ids for a
caregiver check (e.g. Ruth's), or drop the `AND … IN (…)` line for admin.

```sql
-- Unacknowledged alerts (admin; D-40 definition)
SELECT COUNT(*) FROM alerts WHERE status IN ('open','escalated');

-- Same, confined to households 1 and 2 (caregiver check)
SELECT COUNT(*) FROM alerts
 WHERE status IN ('open','escalated') AND elderly_id IN (1,2);

-- Overdue now (derived, D-44)
SELECT COUNT(*) FROM checkin_schedules
 WHERE status = 'scheduled'
   AND expected_at + INTERVAL grace_minutes MINUTE < NOW();

-- Urgent review
SELECT COUNT(*) FROM alerts
 WHERE priority = 'urgent'
   AND status IN ('open','acknowledged','escalated');

-- 14-day chart column for one day, e.g. 2026-10-13
SELECT status, COUNT(*) FROM checkin_schedules
 WHERE status IN ('completed','missed')
   AND DATE(expected_at) = '2026-10-13' GROUP BY status;
```

---

## 7. Decisions D-39 … D-44

| ID | Decision | Why |
|---|---|---|
| D-39 | One query library (`reports_lib.php`) feeds tiles, reports and CSV | Three screens can never disagree; one place to fix a definition |
| D-40 | "Unacknowledged" = open + escalated | Escalation is a monitor status change, not a human response |
| D-41 | CSV: BOM + `'`-prefix on `=+-@` cells | Excel compatibility; OWASP formula-injection guard |
| D-42 | 500-row cap, disclosed in the UI | Keeps giant ranges fast without silent truncation |
| D-43 | One shared reports renderer, scope-only difference | Admin/caregiver reports cannot drift apart |
| D-44 | Dashboards derive "overdue" with the same expression as Stage 5 | D-3 (derived overdue) holds everywhere, including charts |

---

## 8. Test cases TC-8.01 – TC-8.15

Demo logins (seeded): `admin@example.local` / `Admin123!` ·
`ruth.caregiver@example.local` / `Care123!` ·
`mary.elderly@example.local` / `Elder123!`.

| ID | Test | Steps | Expected |
|---|---|---|---|
| TC-8.01 | Admin dashboard loads | Log in as admin → Dashboard | 10 tiles, 2 charts, alert strip; no errors |
| TC-8.02 | Tile accuracy | Run the §6 queries, compare | Every tile equals its SQL count |
| TC-8.03 | Chart accuracy | Pick one day-bar; run the §6 day query | Bar segments equal the query counts |
| TC-8.04 | Caregiver scoping | Log in as Ruth → Dashboard; run §6 queries with `IN (her ids)` | Tiles equal the *confined* counts; no foreign names anywhere |
| TC-8.05 | Tile links | Click each tile | Lands on the working page (schedules/alerts/review/reports…) |
| TC-8.06 | Completion report | Reports → Check-in completion; change household + dates | Rows recompute; % = completed ÷ scheduled (cancelled excluded) |
| TC-8.07 | Missed report | Reports → Missed check-ins | Window end = expected + grace; newest first |
| TC-8.08 | Alert response lag | Reports → Alert response; acknowledge one open alert; re-run | Lag changes from `—` to a real duration |
| TC-8.09 | CSV export | Any tab → Export CSV; open in Excel/Sheets | Same rows as screen; accents fine; `=1+1`-style note cells render as text, not formulas |
| TC-8.10 | Scope tamper | As Ruth, open `caregiver/reports.php?hh=<foreign id>` | Falls back to all *her* households; no foreign rows |
| TC-8.11 | Role blocks | As Ruth, open `admin/audit.php`; as Mary, open `admin/reports.php` | 403 "Not allowed" both times |
| TC-8.12 | Audit viewer | As admin, change your password (TC-8.13), then Audit → filter action `password_changed` | Your entry appears, newest first |
| TC-8.13 | Password change | My account → wrong current password, then correct one | Wrong: error, nothing changes. Correct: success flash; old password stops working, new one works |
| TC-8.14 | Caregiver reports parity | Ruth: My reports → each tab + CSV | Same layout as admin's, confined rows; exports match |
| TC-8.15 | Offline charts | Disconnect network → reload both dashboards | Charts still render (local Chart.js) |

**Gate:** do not approve Stage 9 until every row above passes (or a failure
is pasted back for a fix).

---

## 9. What Stage 9 will do

Full end-to-end test plan (all stages, fresh-DB run), defect fixes, install
guide, user manual, and academic report assembly. No new features.
