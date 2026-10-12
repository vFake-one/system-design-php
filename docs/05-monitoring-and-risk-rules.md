# Stage 1 — Monitoring & Risk-Prioritization Rules

> **Stage 1 deliverable 5 of 6.** Explains *in plain English with worked
> numbers* how the system detects missed check-ins and how the explainable
> PHP rule engine turns observations into
> **Routine / Attention required / Urgent review** priorities.
> Implementation comes in Stages 6–7 (`cron/monitor_checkins.php` and
> `ai/risk_engine.php`); nothing here is code yet — this is the agreed
> contract the code must implement.

---

## 1. Concepts (read once, then the rules make sense)

| Concept | Definition | Example |
|---|---|---|
| **Expected time** | `checkin_schedules.expected_at` — when the check-in should happen. | `2026-10-12 09:00:00` |
| **Grace period** | Extra minutes the system waits before judging (`grace_minutes`). | `120` min → deadline `11:00` |
| **Deadline** | `expected_at + grace_minutes`. One SQL expression — no background state. | `11:00` |
| **Valid check-in** | A `checkin_records` row with matching `schedule_id` (or an ad-hoc record for the same elderly person inside an acceptance window — decision below). | — |
| **Overdue (derived)** | `status='scheduled'` **and** `NOW() > deadline`. Never stored — always computed, so it can never go stale. | At `11:01` with no record → overdue |
| **Missed (stored)** | Overdue **and** confirmed by a monitor run → `status` updated to `'missed'` + alert row created. | After the 11:15 cron run |
| **Idempotent** | Re-running changes nothing the second time (unique keys + `IF NOT EXISTS` checks). | Run cron 5× → 1 alert |

### Acceptance-window decision (agreed for implementation)

A scheduled check-in counts as **valid** if **either**:

- (a) a `checkin_records` row exists with that exact `schedule_id`, **or**
- (b) an ad-hoc record (`schedule_id IS NULL`) exists for the same
  `elderly_id` with `checkin_at` between `expected_at − 2h` and `deadline`.

Case (b) covers "elderly person checked in from their simple page without
opening the specific schedule". The monitor **links** the ad-hoc record to
the schedule (sets its `schedule_id`) when it matches — preserving the
one-record-per-schedule invariant. This linking rule will be implemented and
tested in Stage 6.

---

## 2. Missed-check-in detection algorithm (Stage 6 contract)

Pseudocode — the real PHP will follow this exactly:

```text
BEGIN monitor_run(manual_or_cron):
  now = NOW() in app time zone
  for each schedule S where S.status = 'scheduled'
                     and S.expected_at + INTERVAL S.grace_minutes MINUTE < now
                     order by expected_at ASC:
    valid = find checkin_records where schedule_id = S.id
         OR (schedule_id IS NULL
             and elderly_id = S.elderly_id
             and checkin_at between S.expected_at - 2h and deadline(S))
    if valid found:
      link ad-hoc record if needed
      S.status = 'completed'
      audit('schedule_completed', ...)
    else:
      S.status = 'missed'
      if no alerts where schedule_id = S.id and alert_type = 'missed_checkin':
        score, reasons, observations = risk_engine.score(S.elderly_id, context='missed')
        create alert(missed_checkin, priority from score, reasons, observations)
        audit('alert_created', ...)
      else:
        skip  -- idempotent: already alerted
  run_escalation_pass(now)
  run_pattern_pass(now)   -- §4: flags without a single missed schedule
  audit('monitor_run', summary counts)
END
```

**Escalation pass:**

```text
for each alert A where A.status = 'open'
                   and A.created_at + INTERVAL schedule.escalation_minutes MINUTE < now
                   and A.escalated_at IS NULL:
  secondary = elderly_profiles.secondary_contact_user_id
  if secondary is active user and secondary != A.caregiver_id:
    A.status = 'escalated'; A.escalated_to = secondary; A.escalated_at = now
    audit('alert_escalated', ...)
  else:
    leave open; audit('escalation_skipped_no_contact', ...)  -- visible, not silent
```

Notes:

- Escalation fires **once** (`escalated_at IS NULL` guard + never
  overwriting the timestamp).
- If no valid secondary contact exists, the alert stays `open` and the
  skip is audited — the system never invents a recipient.
- Acknowledging an `escalated` alert moves it to `acknowledged` (both the
  caregiver and secondary contact's views update).

---

## 3. The explainable risk engine (Stage 7 contract)

### 3.1 Inputs (all queryable from MySQL — no hidden data)

| # | Input | Query (in words) | Why it matters |
|---|---|---|---|
| I-1 | `missed_7d` | Count of `missed` schedules for this person in the last 7 days. | Repeated misses are more concerning than one. |
| I-2 | `missed_30d` | Same over 30 days. | Long pattern vs. one bad week. |
| I-3 | `hours_since_success` | Hours since the newest `checkin_records` row (any method). `NULL` if never. | Long silence is the core signal. |
| I-4 | `overdue_rate_30d` | `missed / (scheduled+completed+missed)` over 30 days. | Frequency, not just count. |
| I-5 | `deviation_flag` | `1` if this week's check-ins deviate from the person's usual pattern (fewer than half their 4-week weekly average, or ≥2 late arrivals past half-grace). | "Unusual for *this* person" matters more than absolute counts. |
| I-6 | `days_since_visit` | Days since newest `caregiver_visits` row. `NULL` if never. | Nobody has laid eyes on them recently. |
| I-7 | `has_unacked` | `1` if any alert for this person is `open` or `escalated`. | Compounding concern. |
| I-8 | `help_flag` | `1` if the triggering event is a `needs_assistance`/`unwell` self-report or `no_answer` visit outcome. | Direct request for help outweighs statistics. |

### 3.2 Scoring table (defaults — stored in `system_settings`, tunable by admin)

Points add up, capped at 100. Every contributing rule appends a
human-readable reason string.

| Rule | Condition | Points | Reason text fragment |
|---|---|---|---|
| R-1a | `missed_7d == 1` | +15 | `"1 missed check-in in 7 days"` |
| R-1b | `missed_7d == 2` | +30 | `"2 missed check-ins in 7 days"` |
| R-1c | `missed_7d >= 3` | +45 | `"3+ missed check-ins in 7 days"` |
| R-2 | `hours_since_success > 72` (or never, with schedules older than 72h) | +20 | `"No successful check-in for {n}h"` |
| R-2b | `hours_since_success` 48–72 | +10 | `"No successful check-in for {n}h"` |
| R-3 | `overdue_rate_30d >= 0.5` (min 4 expected) | +10 | `"Missed {p}% of expected check-ins (30d)"` |
| R-4 | `deviation_flag == 1` | +10 | `"Check-in pattern changed vs usual"` |
| R-5a | `days_since_visit > 30` (or never, profile older than 30d) | +15 | `"No caregiver visit for {n} days"` |
| R-5b | `days_since_visit` 14–30 | +8 | `"No caregiver visit for {n} days"` |
| R-6 | `has_unacked == 1` | +15 | `"Previous alert still unacknowledged"` |
| R-7 | `help_flag == 1` | **force ≥ 60** (floor, not just points) | `"Person requested assistance / reported unwell"` |

**Priority mapping** (thresholds in settings, defaults shown):

- `score >= 60` → **Urgent review** (`urgent`)
- `score >= 30` → **Attention required** (`attention`)
- else → **Routine** (`routine`)

R-7's floor means a direct help request is **never** labelled Routine,
no matter how clean the history looks.

### 3.3 Worked examples (reuse these in your report)

**Example A — routine single miss, otherwise healthy pattern.**
Mrs A (daily 9am schedule, grace 2h). One miss yesterday; last success 26h
ago; 1/14 missed in 30d (7%); no deviation; visited 5 days ago; no open
alerts; no help flag.

- R-1a: +15 → reasons: `["1 missed check-in in 7 days"]`
- R-2b: 26h → +0 (under 48) → total **15 → Routine**.

Caregiver sees: *"Routine — 1 missed check-in in 7 days. Last success 26h
ago. Suggest routine call."*

**Example B — attention: repeat misses + silence.**
Mr B. 2 missed in 7 days; last success 55h ago; 4/12 missed in 30d (33%
— below the 50% rule, so R-3 doesn't fire); pattern deviated (usually 6–7
check-ins/week, this week 2); visited 10 days ago; no open alerts.

- R-1b +30, R-2b +10, R-4 +10 → total **50 → Attention required**.

**Example C — urgent: compounding signals.**
Mrs C. 3 missed in 7 days; last success 80h ago; 8/12 missed in 30d (67%);
deviated; last visit 35 days ago; one older alert still open.

- R-1c +45, R-2 +20, R-3 +10, R-4 +10, R-5a +15, R-6 +15 = 115 → capped
  **100 → Urgent review**.

**Example D — help request overrides clean history.**
Mr D. First ever schedule, submitted Code"needs assistance" via the simple
elderly page. No misses at all — but R-7 floors the score to 60 → **Urgent
review** with reason `"Person requested assistance"`. This is the correct
behaviour: a human asked for help.

### 3.4 What is stored per assessment (explainability proof)

Each alert row keeps: `risk_score`, `priority`, `reason` (joined fragments),
`observations` (JSON, e.g.
`{"missed_7d":2,"hours_since_success":55,"overdue_rate_30d":0.33,...,
"scored_at":"2026-10-12 11:15:00"}`), `created_at`, plus reviewer/outcome via
acknowledgements and follow-ups. A supervisor can recompute the score by hand
from `observations` + the table in §3.2 — that recomputability is what makes
the engine "explainable" in your report.

### 3.5 What the engine explicitly does NOT do

- No medical inference ("fall risk", "dementia") — vocabulary is
  operational only (missed, overdue, unacknowledged, unvisited).
- No learning from data in the core engine — thresholds are set by humans
  and visible in `system_settings`.
- No automatic resolution — only a human with a documented outcome resolves.
- No punishment of the elderly person — a high score changes the
  *caregiver's worklist order*, nothing else.

---

## 4. Pattern pass (flags without a single new miss)

Some concerning situations have no single "missed schedule" to attach to:

| Pattern | Detection | Alert type / priority floor |
|---|---|---|
| Silent with no schedules | No `completed` in `X` days (default 7) and no schedules at all (forgotten profile) | `pattern_flag`, ≥ Attention |
| Drift | `deviation_flag` with zero formal misses (always late but inside grace) | `pattern_flag`, Routine–Attention by score |
| Stale case | Open alert older than 48h with no follow-up row | Re-surfaced on dashboard (no new alert; the existing alert's priority may be raised with an audit entry) |

The pattern pass runs inside the same monitor script, guarded by the same
idempotency rules (one open `pattern_flag` per elderly person per pattern —
implemented as: skip creation if an open `pattern_flag` with the same
`reason` prefix already exists).

---

## 5. Optional scikit-learn extension (design sketch only — no code in core)

**When to consider it:** only after Stages 1–8 work and only if your
supervisor asks for it.

```text
PHP risk_engine.php --(already scored 0-100 by rules)--> optional POST
  http://127.0.0.1:5001/score
  Headers: X-EWM-SECRET: <random 32-byte token in untracked config>
  Body (JSON): {"missed_7d":2,"hours_since_success":55,...}
  Timeout: 2s
Python responds: {"ml_score":0-100,"model":"logreg-v0-synthetic","notes":"experimental"}
PHP stores ml_score NEXT TO rule score; displayed as "Experimental ML score
(synthetic data, not validated)". Rule priority remains authoritative.
```

- Training data: **synthetic only**, generated by a documented script
  (e.g., 5,000 fictional histories with random features + rule-derived
  labels). The report states the circularity honestly: the model *imitates*
  the rules; it proves *integration*, not medical insight.
- Security: localhost-only bind, shared secret, input clamping, logging of
  failures with fallback to rules.
- If time is short: **omit entirely**. The PHP engine alone satisfies
  objectives SO-5 and module 7.

---

## 6. Fairness & safety notes (for your report's ethics/limitations chapter)

1. Thresholds treat every profile identically — document that different
   living situations (lives alone vs. with family) might justify different
   thresholds in real deployment (future work).
2. The engine cannot see phone calls, neighbour visits, or anything not
   entered — "no record" is not "no care". The UI reminds caregivers of this.
3. Scores are **never shown to elderly users** — only to authorized
   caregivers/admins — to avoid alarming older people with labels.
4. All reasons use non-alarmist operational language ("unacknowledged",
   "overdue") — never diagnostic language.

---

*Next: `06-stage1-checklist.md` — deliverables review, decisions for you to
confirm, and the gate to Stage 2.*
