# Stage 5 — Check-In Management (schedules, submissions, history, overdue)

> **Stage 5 deliverable.** Time enters the system: expected check-ins are
> scheduled, submitted (by anyone authorized), historised, and overdue is
> derived live. Prerequisite: Stages 2–4 (log in as admin/caregiver/elderly).

**Files created/changed in this stage:**

| File | Purpose |
|---|---|
| `includes/functions.php` | **Extended:** `setting()`, `deadline_of()`, `is_overdue()`, `schedule_display_status()`, `find_open_schedule()`, `primary_caregiver_id()`, `submit_checkin()` |
| `includes/header.php` | **Extended:** Schedules / Check-ins / My-profile nav |
| `admin/schedules.php` | Schedule list: household/caregiver/status filters incl. derived overdue |
| `admin/schedule_form.php` | **Shared** create/edit/cancel (admin full; caregiver own-households, self-responsible) |
| `caregiver/checkins.php` | Workspace: overdue attention list, due/upcoming, ad-hoc form, paged history |
| `elderly/checkin.php` | **Rewritten:** big "I am well" / "I need help" page + last check-in + recent history |
| `elderly/profile.php` | **New:** own summary + carers + upcoming + history (read-only, plain words) |
| `admin/elderly_view.php` | **Extended:** latest-20 schedules + latest-20 records per household |
| `docs/10-…` | This guide |

**Structural note:** `admin/schedules.php` + `admin/schedule_form.php` are new
(the brief's file list has no schedule page, yet §13 requires schedule/check-in
pages and §15 parks "schedules" under settings). List + form follows the
Stage 4 pattern; `admin/settings.php` stays reserved for Stage 6
(grace/escalation defaults + manual monitor run + alert tuning).

---

## A. How it works (concepts)

1. **One writer, two doors.** `submit_checkin()` is the ONLY code that inserts
   `checkin_records`. The caregiver workspace and the elderly page both call
   it, so validation, schedule-flipping, and help-alerts behave identically.
2. **Overdue is computed, never saved.** `deadline = expected_at + grace`;
   `is_overdue()` compares with "now" on every page load, and the SQL twin
   `DATE_ADD(expected_at, INTERVAL grace_minutes MINUTE) < NOW()` powers
   filters. Nothing can go stale because nothing is stored (D-3).
3. **Schedules are terminal-state machines.** `scheduled → completed`
   (writer) / `→ missed` (Stage 6 monitor) / `→ cancelled` (form + reason).
   Nothing leaves completed/missed/cancelled — the form goes read-only.
4. **Self-submissions attach smartly.** `find_open_schedule()` links an "I am
   well" press to the earliest schedule whose window opened (expected − 2h),
   else records ad-hoc. Both count for the monitor (Stage 6 honours ad-hoc
   inside the window per D-10).
5. **A cry for help never waits.** `wellbeing != ok` opens an **urgent**
   `assistance_request` alert INSIDE the same transaction (score 60 = the
   documented R-7 floor; full engine scoring lands in Stage 7). No cron
   needed — the caregiver sees it at their next click.
6. **Duplicates die twice.** The writer refuses non-`scheduled` links AND the
   `uq_checkin_schedule` key rejects races (caught as friendly "already
   recorded"). Submit the same schedule twice to prove both layers.
7. **Scoping travels with the feature.** Caregiver dropdowns/queries only
   contain assigned households; forged ids are dropped (`checkins.php`) or
   403 (`schedule_form.php`); elderly pages only know `elderly_profile_id`.

---

## B. Test table (run on XAMPP — record results for Stage 9)

Seed reminder: S2 (Mary) + S5 (Grace) are overdue-but-`scheduled` at import —
TC-5.05/5.07 use them. Run these BEFORE the Stage 6 monitor exists (nothing
flips them to `missed` yet — that contrast is the point).

| ID | Feature | Preconditions | Input / action | Expected result | Actual | Pass? |
|---|---|---|---|---|---|---|
| TC-5.01 | Create schedule (admin) | Admin | Mary, title "Test call", expected tomorrow 09:00, grace 60, esc 120 | Row `scheduled`; deadline = 10:00 shown; `create_schedule` audit | | |
| TC-5.02 | Past expected refused | Admin | Expected = yesterday | "Expected time must be in the future."; no row | | |
| TC-5.03 | Edit + cancel needs reason | TC-5.01 row | Tick Cancel with empty notes → error; add reason → cancelled | Two-step behaviour; `cancel_schedule` audit; row read-only after | | |
| TC-5.04 | Caregiver schedules own | Ruth | New schedule for Mary (fixed self as responsible) | Works; household dropdown lacks Grace (unassigned to Ruth) | | |
| TC-5.05 | Overdue derived | Seed S2/S5 untouched | Open `admin/schedules.php`, filter Overdue | S2 + S5 listed `overdue`, yet DB `status` still `scheduled` (`SELECT status…` proves derived) | | |
| TC-5.06 | Caregiver records scheduled | Ruth, S2 overdue | Attention row: In person / Well / note → Record | Schedule → `completed`; record shows Ruth + `caregiver_entry`; success flash | | |
| TC-5.07 | Double-record refused | TC-5.06 done | Re-record S2 (or forge POST) | "already completed/recorded" — exactly ONE record for S2 in DB | | |
| TC-5.08 | Ad-hoc record | Ruth | Ad-hoc for John, Phone call / Well | Record with `schedule_id NULL`; nothing else changes | | |
| TC-5.09 | Assistance from caregiver | Ruth | Ad-hoc for Mary, wellbeing Needs assistance + note | Record + URGENT `assistance_request` alert (`SELECT * FROM alerts` — priority urgent, score 60, reason mentions R-7) | | |
| TC-5.10 | Foreign household blocked | Ruth | Forge POST with Grace's id (unassigned) | "not for your households" + `access_denied` audit; no row | | |
| TC-5.11 | Elderly "I am well" | Mary login | Press ✔ I am well | Success flash; record `self_online`/`ok`, submitter Mary; attaches to open schedule or ad-hoc | | |
| TC-5.12 | Elderly "I need help" | Mary login | Message + ✚ I need help | Urgent alert immediately (no cron); flash confirms caregiver notified | | |
| TC-5.13 | Elderly sees own only | Mary login | Open profile page | Mary data/schedules/history only; no scores, no other households | | |
| TC-5.14 | Admin history view | Admin | Mary's `elderly_view.php` | Latest schedules with overdue badges + latest records with submitter roles | | |
| TC-5.15 | Caregiver history filter | Ruth | History + household filter Mary | Only Mary's records, newest first, pager works | | |
| TC-5.16 | PRG + audit | Any submit | Refresh after recording | No resubmit prompt; `submit_checkin` audit rows present | | |
| TC-5.17 | Mobile elderly page | Mary login, 375 px | checkin.php + profile.php | Buttons full-width, no scroll-sideways, text large | | |

Suggested cleanup (keeps Stage 6 demos crisp — monitor needs overdue rows):
```sql
-- Keep seed S2/S5 scheduled; remove YOUR test rows only (adjust ids):
DELETE FROM checkin_records WHERE id > 3;
DELETE FROM alerts WHERE id > 2;
UPDATE checkin_schedules SET status='scheduled' WHERE id IN (2,5);
DELETE FROM checkin_schedules WHERE id > 7;
```

---

## C. Troubleshooting

| Symptom | Cause → fix |
|---|---|
| Overdue list empty though S2/S5 old | System clock zone wrong — `SELECT NOW()` in phpMyAdmin must show EAT; re-check `SET time_zone` + PHP zone. |
| "already completed" on first record | Schedule was completed by an earlier test — check `checkin_records` for its `schedule_id`. |
| Caregiver dropdown empty | No *active* assignment to an *active* household — revisit `assignments.php`. |
| "I am well" creates ad-hoc, not linked | No schedule window open (expected − 2h rule) — correct behaviour, not a bug. |
| Help alert has no caregiver | Household has no primary AND schedule had none — alerts still open (caregiver NULL); assign a primary. |

---

## D. Decisions locked in Stage 5 (append to D-list)

| # | Decision |
|---|---|
| D-21 | `submit_checkin()` is the single writer for all check-in paths (caregiver + elderly). |
| D-22 | Self-submissions link via `find_open_schedule()` (window opened: expected − 2h), else ad-hoc. |
| D-23 | Assistance/unwell reports open URGENT alerts immediately in-band (score 60 = R-7 floor); full scoring in Stage 7. |
| D-24 | Completed/missed/cancelled schedules are terminal (form read-only; monitor owns `missed`). |
| D-25 | Caregiver-created schedules fix responsible caregiver to the creator. |
| D-26 | Cancelling requires a written reason (stored in `notes`). |

---

## E. Stage 5 checklist & gate

- [x] Schedule CRUD + cancel-with-reason, admin + scoped caregiver (FR-16–19)
- [x] Check-in submission: scheduled + ad-hoc + on-behalf with submitter tracking (FR-20–23)
- [x] Histories: caregiver workspace, elderly own, admin household view (FR-24)
- [x] Overdue derivation in PHP + SQL twins, never stored (FR-18)
- [x] Immediate urgent alerts for direct help (FR-34 partial; full alerts UI = Stage 6)
- [x] Static verification here (see commit); live TC-5.01–5.17 are YOUR runs
- [ ] **Student action:** run TC-5.01–5.17, fill Actual/Pass, run cleanup SQL

**Stage 6 preview (Alerts & escalation):** `cron/monitor_checkins.php`
(idempotent missed-detection + escalation pass + CLI/cron + manual admin run),
`admin/settings.php` (grace/escalation defaults + Run-monitor button),
`caregiver/alerts.php` (acknowledge + resolve-with-outcome),
`caregiver/visits.php` + `caregiver/followups.php` (visit/action logs),
pattern pass for silent/drifting households. Reply
**"Approved — proceed to Stage 6"** when TC-5.01–5.17 pass (or paste failures).
