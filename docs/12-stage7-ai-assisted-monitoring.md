# Stage 7 — AI-Assisted Monitoring (full rule engine + review queue)

> **Stage 7 deliverable.** The triage scorer grows into the complete
> explainable engine; caregivers get a worst-first review queue; every rule
> is proven by runnable scenarios. Per your locked decision, ML stays a
> design-only appendix (§D) — the shipped "AI" is this engine, honestly described.
> Prerequisite: Stage 6 working (monitor runs, alerts exist).

**Files created/changed in this stage:**

| File | Purpose |
|---|---|
| `ai/risk_engine.php` | **Upgraded v0 → v1:** all 8 inputs (R-1..R-7), same signature/return shape |
| `includes/functions.php` | **Extended:** `submit_checkin()` scores assistance alerts with v1 (`help_flag`, forced urgent) |
| `caregiver/review.php` | **New (shared):** worst-first household queue + Re-score button |
| `includes/header.php` | **Extended:** Review nav (admin + caregiver) |
| `docs/12-…` | This guide |
| `cron/monitor_checkins.php` | **Deliberately UNCHANGED** — new alerts score v1 with zero monitor edits (TC-7.10 proves the contract) |

---

## A. Final rules table (what the code implements — identical to docs/05 §3.2)

Points sum, capped at 100. Thresholds from settings (defaults urgent ≥ 60, attention ≥ 30).

| Rule | Condition (v1 implementation) | Pts | Reason fragment |
|---|---|---|---|
| R-1a/b/c | `missed_7d` = 1 / 2 / 3+ | 15/30/45 | "N missed check-in(s) in 7 days" |
| R-3 | `expected_30d` ≥ 4 AND `missed_30d/expected_30d` ≥ 0.5 | +10 | "Missed P% of expected check-ins (30d)" |
| R-2b/R-2 | `hours_since_success` 48–72 / > 72 (or never, with schedules expected) | +10/+20 | "No successful check-in for Nh" / "…yet" |
| R-4 | **Deviation:** weekly volume < half the prior-3-week avg (avg ≥ 2) **OR** ≥ 2 arrivals past half-grace in 7d | +10 | "Check-in pattern changed vs usual (detail)" |
| R-5b/R-5a | `days_since_visit` 14–30 / > 30 (never-visited counts only for > 30-day-old profiles, else new registrations would all flag) | +8/+15 | "No caregiver visit for N days" / "…yet (established household)" |
| R-6 | Any `open`/`escalated` alert for the household | +15 | "Previous alert still unacknowledged" |
| R-7 | `help_flag` = 1 (direct report) | floor 60 | "Person requested assistance / reported unwell (direct report)" |

v1 interpretation notes (locked as D-35/D-36): deviation is volume-OR-lateness
(either suffices); the never-visited rule keys on profile age so onboarding
many households at once stays quiet. Every assessment stores all inputs in
`observations` — hand-add them per this table and you must get `risk_score`.

---

## B. Review queue + re-score

- `caregiver/review.php` lists one row per household with open/escalated
  alerts, worst-first (priority rank, then score), with recorded reasons,
  recency facts, and Review/Profile links. Admins see all; caregivers see
  assigned (same scoping query shape as alerts).
- **Re-score** refreshes open/escalated alerts in scope with the current
  engine + thresholds and writes one summary `rescore_run` audit (not one row
  per alert — no log floods). Acknowledged cases are deliberately untouched:
  re-scoring someone mid-investigation would move the ground under them (D-37).
- Scores shown to staff only — elderly pages never display them (Stage 1 D-12).

---

## C. Scenario tests (fixtures + expected scores — run on XAMPP)

Run each fixture in phpMyAdmin (one paste per scenario), press **Run monitoring
now**, then inspect the NEW alert for that household. Fixtures use fixed names
starting `SCENARIO` for one-line cleanup (§C.5). Seed S2/S5 may also flip if
still scheduled — check the scenario rows specifically, not the totals.

### C.1 Scenario A → 15 / Routine (single miss, healthy otherwise)

```sql
INSERT INTO elderly_profiles (full_name, status, created_by)
VALUES ('SCENARIO A', 'active', 1);
SET @a = LAST_INSERT_ID();
INSERT INTO checkin_schedules (elderly_id, title, expected_at, grace_minutes, escalation_minutes, status, created_by)
VALUES (@a, 'Morning check-in', NOW() - INTERVAL 5 HOUR, 120, 240, 'scheduled', 1);
INSERT INTO checkin_records (elderly_id, submitted_by, submitted_by_role, checkin_at, method, wellbeing_status, notes)
VALUES (@a, 1, 'admin', NOW() - INTERVAL 26 HOUR, 'self_online', 'ok', 'Scenario A fixture (synthetic).');
INSERT INTO caregiver_visits (elderly_id, caregiver_id, visit_date, visit_type, outcome, notes)
VALUES (@a, 2, NOW() - INTERVAL 5 DAY, 'routine', 'well', 'Scenario A fixture (synthetic).');
```
Expect: missed_7d=1 (+15); 26h silence (+0); 1 expected/30d (+0); no deviation;
visit 5d (+0); nothing unacked → **15 Routine**. Recompute check:
`SELECT risk_score, reason, observations FROM alerts WHERE elderly_id=@a;`
hand-add per §A → 15.

### C.2 Scenario B → 50 / Attention (repeat miss + silence + lateness)

```sql
INSERT INTO elderly_profiles (full_name, status, created_by)
VALUES ('SCENARIO B', 'active', 1);
SET @b = LAST_INSERT_ID();
INSERT INTO checkin_schedules (elderly_id, title, expected_at, grace_minutes, escalation_minutes, status, created_by)
VALUES (@b, 'Morning check-in', NOW() - INTERVAL 3 DAY, 120, 240, 'missed', 1);
INSERT INTO checkin_schedules (elderly_id, title, expected_at, grace_minutes, escalation_minutes, status, created_by)
VALUES (@b, 'Morning check-in', NOW() - INTERVAL 5 HOUR, 120, 240, 'scheduled', 1);
INSERT INTO checkin_schedules (elderly_id, title, expected_at, grace_minutes, escalation_minutes, status, created_by)
VALUES (@b, 'Morning check-in', NOW() - INTERVAL 6 DAY, 120, 240, 'completed', 1);
SET @b_l1 = LAST_INSERT_ID();
INSERT INTO checkin_schedules (elderly_id, title, expected_at, grace_minutes, escalation_minutes, status, created_by)
VALUES (@b, 'Morning check-in', NOW() - INTERVAL 5 DAY, 120, 240, 'completed', 1);
SET @b_l2 = LAST_INSERT_ID();
INSERT INTO checkin_schedules (elderly_id, title, expected_at, grace_minutes, escalation_minutes, status, created_by)
VALUES (@b, 'Morning check-in', NOW() - INTERVAL 4 DAY, 120, 240, 'completed', 1);
SET @b_t = LAST_INSERT_ID();
INSERT INTO checkin_records (schedule_id, elderly_id, submitted_by, submitted_by_role, checkin_at, method, wellbeing_status, notes) VALUES
 (NULL,   @b, 1, 'admin', NOW() - INTERVAL 55 HOUR, 'self_online', 'ok', 'Scenario B fixture (synthetic).'),
 (@b_l1,  @b, 1, 'admin', NOW() - INTERVAL 6 DAY + INTERVAL 90 MINUTE, 'self_online', 'ok', 'late arrival 1'),
 (@b_l2,  @b, 1, 'admin', NOW() - INTERVAL 5 DAY + INTERVAL 100 MINUTE, 'self_online', 'ok', 'late arrival 2'),
 (@b_t,   @b, 1, 'admin', NOW() - INTERVAL 4 DAY + INTERVAL 10 MINUTE, 'self_online', 'ok', 'on time');
INSERT INTO caregiver_visits (elderly_id, caregiver_id, visit_date, visit_type, outcome, notes)
VALUES (@b, 2, NOW() - INTERVAL 10 DAY, 'routine', 'well', 'Scenario B fixture (synthetic).');
```
Expect after monitor run: missed_7d=2 (+30); 55h silence (+10); 5 expected,
2 missed = 40% (+0); 2 late arrivals (+10); visit 10d (+0); nothing unacked →
**50 Attention**.

### C.3 Scenario C → 100 / Urgent (compounding everything, capped)

```sql
INSERT INTO elderly_profiles (full_name, status, created_by)
VALUES ('SCENARIO C', 'active', 1);
SET @c = LAST_INSERT_ID();
INSERT INTO checkin_schedules (elderly_id, title, expected_at, grace_minutes, escalation_minutes, status, created_by) VALUES
 (@c, 'Morning check-in', NOW() - INTERVAL 2 DAY, 120, 240, 'missed', 1),
 (@c, 'Morning check-in', NOW() - INTERVAL 3 DAY, 120, 240, 'missed', 1),
 (@c, 'Morning check-in', NOW() - INTERVAL 4 DAY, 120, 240, 'missed', 1),
 (@c, 'Morning check-in', NOW() - INTERVAL 5 HOUR, 120, 240, 'scheduled', 1);
INSERT INTO checkin_schedules (elderly_id, title, expected_at, grace_minutes, escalation_minutes, status, created_by)
VALUES (@c, 'Morning check-in', NOW() - INTERVAL 5 DAY, 120, 240, 'completed', 1);
SET @c_l1 = LAST_INSERT_ID();
INSERT INTO checkin_schedules (elderly_id, title, expected_at, grace_minutes, escalation_minutes, status, created_by)
VALUES (@c, 'Morning check-in', NOW() - INTERVAL 6 DAY, 120, 240, 'completed', 1);
SET @c_l2 = LAST_INSERT_ID();
INSERT INTO checkin_records (schedule_id, elderly_id, submitted_by, submitted_by_role, checkin_at, method, wellbeing_status, notes) VALUES
 (NULL,  @c, 1, 'admin', NOW() - INTERVAL 80 HOUR, 'self_online', 'ok', 'Scenario C fixture (synthetic).'),
 (@c_l1, @c, 1, 'admin', NOW() - INTERVAL 5 DAY + INTERVAL 90 MINUTE, 'self_online', 'ok', 'late arrival 1'),
 (@c_l2, @c, 1, 'admin', NOW() - INTERVAL 6 DAY + INTERVAL 100 MINUTE, 'self_online', 'ok', 'late arrival 2');
INSERT INTO caregiver_visits (elderly_id, caregiver_id, visit_date, visit_type, outcome, notes)
VALUES (@c, 2, NOW() - INTERVAL 35 DAY, 'routine', 'well', 'Scenario C fixture (synthetic).');
INSERT INTO alerts (elderly_id, caregiver_id, alert_type, priority, risk_score, title, message, reason, status)
VALUES (@c, 2, 'pattern_flag', 'attention', 40, 'Scenario C pre-existing flag',
        'Pre-existing open alert so R-6 fires. (Synthetic fixture.)', 'Fixture placeholder', 'open');
```
Expect after monitor run: missed_7d=4 (+45); 80h silence (+20); 6 expected,
4 missed = 67% (+10); 2 late (+10); visit 35d (+15); unacked (+15) = 115 →
**capped 100 Urgent**.

### C.4 Test table

| ID | Feature | Preconditions | Input / action | Expected result | Actual | Pass? |
|---|---|---|---|---|---|---|
| TC-7.01 | Scenario A | §C.1 fixture | Run monitor; inspect new alert | 15 / routine; hand-recompute from observations → 15 | | |
| TC-7.02 | Scenario B | §C.2 fixture | Run monitor; inspect new alert | 50 / attention; reasons list 3 fragments | | |
| TC-7.03 | Scenario C | §C.3 fixture | Run monitor; inspect new alert | 100 / urgent (capped); 6 fragments incl. cap evidence (sum > 100 in obs inputs) | | |
| TC-7.04 | Help floor (D) | Mary login | ✚ I need help + note | Alert urgent, score ≥ 60, reason has R-7 fragment, observations carry full v1 keys + `help_flag:1` + `record_id` | | |
| TC-7.05 | Queue order | TC-7.01–03 done | Admin review queue | C (#1, 100) above B (50) above A (15); reasons visible; Review/Profile links work | | |
| TC-7.06 | Re-score upgrades | Any Stage 6 alert with `triage-v0` observations | Press Re-score | Observations now `rule-engine-v1` with 12+ keys; score/priority refreshed; ONE `rescore_run` audit | | |
| TC-7.07 | Re-score spares acked | Acknowledged alert + changed history | Press Re-score | Acknowledged alert's score/observations UNCHANGED (only open/escalated refresh) | | |
| TC-7.08 | Thresholds live | Admin | Set urgent=10 → Re-score → restore 60 | More cases flip urgent, then flip back; queue reorders accordingly | | |
| TC-7.09 | Queue scoping | Ruth | Review queue | Only assigned households; rescore affects only own (verify by admin view diff) | | |
| TC-7.10 | Monitor untouched | Repo | `git log --oneline -2 -- cron/monitor_checkins.php` | Latest change is the Stage 6 commit, yet new alerts carry v1 observations | | |
| TC-7.11 | Mobile queue | 375 px | review.php | Table scrolls inside `.table-responsive`; buttons usable | | |
| TC-7.12 | Cleanup | After above | §C.5 SQL | Scenario households + all children gone; counts back to pre-fixture | | |

### C.5 Cleanup (cascades remove children; audit text remnants are fine)

```sql
DELETE FROM elderly_profiles WHERE full_name LIKE 'SCENARIO %';
SELECT COUNT(*) FROM alerts WHERE elderly_id NOT IN (SELECT id FROM elderly_profiles); -- expect 0
```

---

## D. ML-extension appendix (DESIGN ONLY — not built, per rules-only decision)

**Why not built:** no real dataset exists (synthetic-only would prove
integration, not insight); the rule engine is fully explainable (a viva
strength); and a downed Python service must never break the PHP demo.
This appendix exists so your report can discuss the option honestly.

**If a future team builds it:** PHP keeps scoring first; then POSTs the 8
inputs as JSON to `http://127.0.0.1:5001/score` with an `X-EWM-SECRET` header
(32 random bytes in untracked config), 2 s timeout; Python (scikit-learn
`LogisticRegression`, trained ONLY on documented synthetic histories)
responds `{ml_score, model, notes}`; PHP stores it BESIDE the rule score and
displays it labelled "experimental, synthetic-data only, not validated".
Any failure → rules stand alone. The model would imitate the rules (its
labels come from them) — the report must state that circularity plainly.

---

## E. Decisions locked in Stage 7 (append to D-list)

| # | Decision |
|---|---|
| D-34 | v1 implements R-1..R-7 with zero monitor changes (signature contract held). |
| D-35 | Deviation = low weekly volume OR ≥ 2 half-grace-late arrivals (either suffices). |
| D-36 | Never-visited scores only for profiles older than 30 days. |
| D-37 | Re-score is manual, open/escalated only, one summary audit per run. |
| D-38 | Direct reports force priority urgent + score ≥ 60 via engine `help_flag` (submit_checkin upgraded; supersedes the Stage 5 minimal-observation alert). |

---

## F. Stage 7 checklist & gate

- [x] Full 8-input engine, same contract, hand-recomputable (FR-29/30/31)
- [x] Review queue with reasons + recency + scoped re-score (FR-31)
- [x] Scenario fixtures with exact expected scores (worked-example evaluation)
- [x] ML appendix honest + design-only (per locked rules-only scope)
- [x] Static verification here (see commit); live TC-7.01–7.12 are YOUR runs
- [ ] **Student action:** run TC-7.01–7.12, then §C.5 cleanup

**Stage 8 preview (Dashboard & reporting):** Chart.js bundled locally (like
Bootstrap), full `admin/dashboard.php` + `caregiver/dashboard.php` (live
counts, no hardcoding), `admin/reports.php` + `caregiver/reports.php`
(filters + completion/missed/alert/visit/follow-up reports + audit-log
viewer for admins), `account.php` (own profile + password change). Reply
**"Approved — proceed to Stage 8"** when TC-7.01–7.12 pass (or paste failures).
