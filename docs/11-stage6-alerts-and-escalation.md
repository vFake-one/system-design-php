# Stage 6 — Alerts & Escalation (monitor, acknowledge, visits, follow-ups)

> **Stage 6 deliverable.** The system starts watching on its own: missed
> check-ins become alerts, ignored alerts escalate, and every response is
> logged. Prerequisite: Stage 5 cleanup done (seed S2/S5 overdue-`scheduled`).

**Files created/changed in this stage:**

| File | Purpose |
|---|---|
| `ai/risk_engine.php` | **New (triage v0):** `risk_assess()` — R-1/R-2/R-6 scoring; Stage 7 extends it in place |
| `cron/monitor_checkins.php` | **New:** 3-pass idempotent engine — missed detection (+ ad-hoc linking), escalation, patterns |
| `admin/settings.php` | **New:** tunable thresholds + manual "Run monitoring now" + run history |
| `caregiver/alerts.php` | **New (shared):** worklist with acknowledge / resolve-with-outcome / admin dismiss |
| `caregiver/visits.php` | **New:** visit log with optional alert link |
| `caregiver/followups.php` | **New:** follow-up action log (completed + outcome unlocks Resolve) |
| `includes/header.php` | **Extended:** Alerts/Visits/Follow-ups/Settings nav |
| `docs/11-…` | This guide |

---

## A. Running the monitor automatically (cron / Task Scheduler)

The monitor is a **CLI script** — it uses XAMPP's PHP directly, not the browser.

**Linux/macOS (cron)** — run `crontab -e` and add (every 15 minutes):
```bash
*/15 * * * * /opt/lampp/bin/php /opt/lampp/htdocs/elderly-welfare-system/cron/monitor_checkins.php >> /tmp/ewm-monitor.log 2>&1
```

**Windows (Task Scheduler)** — Task Scheduler → Create Basic Task:
- Trigger: Daily, Repeat every **15 minutes**; Action: Start a program:
- Program: `C:\xampp\php\php.exe`
- Arguments: `C:\xampp\htdocs\elderly-welfare-system\cron\monitor_checkins.php`

**Verify it ran** (either method): `SELECT created_at, details FROM audit_logs
WHERE action='monitor_run' ORDER BY id DESC LIMIT 3;` — new rows appear after
each run, and `admin/settings.php` lists them. Tests below use the **manual**
button (same engine) so you don't wait 15 minutes between cases.

---

## B. How it works (concepts)

1. **Idempotent by construction.** Missed-marking guards on
   `status='scheduled'`, alert creation checks existence AND the
   `uq_alert_schedule_type` key, escalation guards on `escalated_at IS NULL`.
   Run it 10× — the state after run 1 never changes (TC-6.03/6.06 prove it).
2. **Late proof counts (D-10).** An ad-hoc check-in inside
   expected−2h…deadline is LINKED to the schedule and marks it completed —
   the monitor distinguishes "no contact" from "contact recorded messily".
3. **Triage now, full engine later.** Missed/pattern alerts score with
   `risk_assess()` v0 (3 inputs); observations record `engine: triage-v0` so
   Stage 7's upgrade is traceable. Thresholds already come from settings.
4. **Escalation fires once.** `open` + delay elapsed + valid secondary
   (active staff, not the notified caregiver) → `escalated` with timestamp.
   No valid recipient → ONE audited skip (guarded against log spam), alert
   stays open and visible.
5. **Patterns need no single miss.** Silent (nothing scheduled + no success),
   drift (< half the weekly average), stale (open 48h+ without follow-up →
   priority +1, no new alert). Guard: one open pattern per household.
6. **Resolve requires proof of work.** The alerts page refuses Resolve until
   a completed follow-up with an outcome exists — the workflow from §12 step 9.
7. **Overlap-safe.** A file lock makes concurrent runs exit quietly instead
   of interleaving; direct browser hits get 403.

---

## C. Test table (run on XAMPP — record results for Stage 9)

Start state: S2 (Mary) + S5 (Grace) overdue-`scheduled`, no test rows
(Stage 5 cleanup applied). Use the manual run button unless stated.

| ID | Feature | Preconditions | Input / action | Expected result | Actual | Pass? |
|---|---|---|---|---|---|---|
| TC-6.01 | First run detects | Start state | Run monitoring now | Summary: checked=2, missed=2, alerts=2; S2/S5 `missed`; 2 open `missed_checkin` alerts with score/reasons/observations | | |
| TC-6.02 | Alert contents | TC-6.01 | Open alerts page (admin) | Each shows priority badge, "Why flagged" reasons, scoring evidence `<details>`, correct household + caregiver | | |
| TC-6.03 | Idempotent re-run | TC-6.01 | Run again immediately | checked=0 (nothing scheduled-overdue), missed=0, alerts=0; alert COUNT unchanged | | |
| TC-6.04 | Late proof links | Fresh schedule expected −3h (grace 120) + ad-hoc record inside window | Run monitor | Schedule → `completed` (not missed); ad-hoc row now carries the `schedule_id` | | |
| TC-6.05 | Completed skipped | Completed schedule past deadline | Run monitor | Untouched; no alert (proof short-circuits) | | |
| TC-6.06 | Escalation fires once | Alert from TC-6.01: `UPDATE alerts SET created_at=NOW()-INTERVAL 5 HOUR WHERE id=…` | Run monitor | Status → `escalated`, `escalated_to` = household secondary, timestamp set; run again → no change, no second audit | | |
| TC-6.07 | Escalation skip | Alert with household secondary = NULL (edit profile) + aged `created_at` | Run monitor | Stays `open`; exactly ONE `escalation_skipped_no_contact` audit (re-runs silent); restore secondary after | | |
| TC-6.08 | Pattern: silent | New active household, no schedules, no check-ins (or aged seed) | Run monitor | `pattern_flag` (≥ attention); second run adds none (one-open guard) | | |
| TC-6.09 | Stale raise | Open alert `created_at` −49h, no follow-ups | Run monitor | Priority steps up one (attention→urgent); `priority_raised` audit; no new alert | | |
| TC-6.10 | Acknowledge | Ruth, Mary's open alert | action_taken + note → Acknowledge | Status `acknowledged`; ack row with Ruth + time; audit | | |
| TC-6.11 | Resolve blocked | Acknowledged alert, no follow-ups | Press Resolve | Refused with guidance message; status unchanged | | |
| TC-6.12 | Follow-up unlocks resolve | TC-6.11 alert | Log completed follow-up with outcome → Resolve | Follow-up row; alert `resolved` + `resolved_at`; audit | | |
| TC-6.13 | Dismiss admin-only | Ruth on open alert | (No dismiss button visible) forge POST `action=dismiss` | "Only administrators…" — server refuses, not just UI | | |
| TC-6.14 | Visit log | Ruth | Log routine visit for Mary, link alert | Row stored; appears in list; `log_visit` audit | | |
| TC-6.15 | Scoping | Ruth | Alerts page | Only Mary/John alerts; forged `alert_id` of Grace's → "not found" + `access_denied` | | |
| TC-6.16 | Settings validation | Admin | urgent=20, attention=30 → save; grace=99999 | Both refused with messages; DB unchanged | | |
| TC-6.17 | CLI run | Terminal | `C:\xampp\php\php.exe cron\monitor_checkins.php` (project dir) | Prints one summary line, exit 0; `monitor_run` audit with trigger `cron` | | |
| TC-6.18 | Direct web blocked | Browser | Open `/cron/monitor_checkins.php` directly | 403 "Direct web access… not allowed" | | |
| TC-6.19 | Threshold effect | Admin | Set urgent=10 → run with new miss | New alert scores `urgent` at low score (thresholds live, not hardcoded) — restore 60/30 after | | |
| TC-6.20 | Audit coverage | After above | Group audit actions | Contains `schedule_completed`, `alert_created`, `alert_escalated`, `priority_raised`, `monitor_run`, `acknowledge_alert`, `resolve_alert`, `log_visit`, `log_followup`, `update_settings` | | |

Handy SQL for time-travel tests (adjust ids to YOUR rows):
```sql
UPDATE alerts SET created_at = NOW() - INTERVAL 5 HOUR WHERE id = 3;
UPDATE alerts SET created_at = NOW() - INTERVAL 49 HOUR WHERE id = 4;
```

---

## D. Troubleshooting

| Symptom | Cause → fix |
|---|---|
| Run finds 0 though schedules look old | Deadlines use DB `NOW()` — check `SELECT NOW()` shows EAT; import set session zone per-connection, PHP sets it per-request (`config/database.php` ✓). |
| CLI: "could not find driver" | Wrong `php` binary (system PHP without mysqlnd) — use XAMPP's full path (`C:\xampp\php\php.exe`). |
| CLI: "Database connection failed" | Same `config/database.php` as web — MySQL must be Started in XAMPP panel. |
| Task Scheduler runs but no audits | "Start in" directory unset — set it to the project folder; or quote paths with spaces. |
| Escalation never fires | `escalation_minutes` on the schedule (not the default) governs missed alerts — check the schedule's value + `created_at` age. |
| Resolve still blocked after follow-up | Follow-up must be `completed` AND `outcome` non-empty — `planned` rows don't count (by design). |

---

## E. Decisions locked in Stage 6 (append to D-list)

| # | Decision |
|---|---|
| D-27 | Monitor engine is one includable file: CLI + manual admin run share `run_monitor()`; direct web hits 403. |
| D-28 | `ai/risk_engine.php` ships as triage v0 (R-1/R-2/R-6; `engine` recorded in observations); Stage 7 extends it in place. |
| D-29 | Pattern flags floor at Attention; max one open pattern per household. |
| D-30 | Escalation-skip audits once per alert (guarded) to avoid log spam. |
| D-31 | `caregiver/alerts.php` is role-shared (admin sees all + exclusive Dismiss). |
| D-32 | New settings keys self-heal via INSERT IGNORE (no manual migration SQL). |
| D-33 | Stale cases raise priority in place; never a duplicate alert. |

---

## F. Stage 6 checklist & gate

- [x] Idempotent 3-pass monitor: missed + ad-hoc link + escalation + patterns (FR-25–28, FR-37)
- [x] Triage scoring stored with every monitor alert (FR-29/30 partial — full inputs Stage 7)
- [x] Alert lifecycle UI: acknowledge / resolve-with-outcome / dismiss (FR-33–39)
- [x] Visits + follow-ups with ownership + audit (FR-41/42)
- [x] Tunable settings + manual run + run history (FR-18/27)
- [x] Static verification here (see commit); live TC-6.01–6.20 are YOUR runs
- [ ] **Student action:** run TC-6.01–6.20; configure real cron/Task Scheduler; keep one `monitor_run` screenshot

**Stage 7 preview (AI-assisted monitoring):** extend `risk_assess()` to all 8
inputs (overdue-rate, deviation, visit recency, help-floor), add the review
queue UI (prioritized open cases with reasons), re-score pass for open alerts,
worked-example scenario tests, and the honest ML-extension appendix (design
only, per your rules-only decision). Reply **"Approved — proceed to Stage 7"**
when TC-6.01–6.20 pass (or paste failures).
