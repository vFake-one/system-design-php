# Academic report guide (assembly + viva prep)

Your report is ~80% written already — it's the `docs/` folder. This guide
maps it onto a standard Year 2 project report and tells you what to add.

---

## 1. Chapter map

| Report chapter | Source in this repo | You add |
|---|---|---|
| 1. Introduction (problem, objectives, scope) | `docs/01` | Institution cover page, abstract |
| 2. Literature / background | (yours) | 1–2 pages: existing welfare/monitoring approaches, why a rule engine fits |
| 3. Requirements analysis | `docs/02` (+ roles matrix) | — |
| 4. System design | `docs/03` (architecture, DFD, use-case), `docs/04` (DB + ERD), `docs/05` (monitoring + risk rules) | Render the Mermaid diagrams as figures |
| 5. Implementation | `docs/07–13` stage guides (one section each), decisions D-1…D-44 | Screenshot per stage |
| 6. Testing | `docs/14` (plan + sign-off) + per-stage TC tables in `docs/08–13` | Your filled pass/fail columns, defect log rows |
| 7. Installation & user guide | `docs/15`, `docs/16` | — (append as appendices if preferred) |
| 8. Conclusion (objectives met, limitations, future work) | `docs/01` scope/limitations + `docs/12` ML appendix | ML extension + SMS/offline ideas as future work |
| References / appendices | — | ERD figure, sample audit rows, one exported CSV |

Decisions **D-1 … D-44** scattered through the docs are your "design
justification" evidence — cite them when examiners ask *why*.

## 2. Screenshots to capture (do it once, reuse everywhere)

1. Admin dashboard (tiles + charts) and caregiver dashboard.
2. Review queue with one alert's scoring evidence expanded.
3. Alert lifecycle: open → acknowledged → resolved (3 shots or 1 annotated).
4. One report + its CSV opened in Excel.
5. Audit trail filtered to one action.
6. Elderly big-button check-in page.

## 3. Viva prep (10 likely questions)

1. *"Why is overdue derived, not stored?"* → D-3: one source of truth,
   impossible to go stale; same expression in schedules, dashboards, monitor.
2. *"How do you stop a caregiver seeing other households?"* → scope is in
   the SQL (`scope_sql()` / `IN (…)`), not just hidden buttons (NFR-01);
   plus `can_access_elderly()` on profile pages.
3. *"Is the AI real?"* → explainable rule engine (8 inputs, 7 rules,
   capped 0–100, every point has a reason string); ML appendix in
   `docs/12` shows the honest path to ML. Never a medical device.
4. *"What if two monitors run at once?"* → lock file + idempotent unique
   keys (`uq_alert_schedule_type`).
5. *"How do you know the dashboard numbers are right?"* → `docs/13 §6`
   SQL cross-checks; TC-8.02/TC-8.03.
6. *"Why can't an alert be resolved immediately?"* → FR-38: needs a
   completed follow-up with a written outcome — accountability.
7. *"CSRF/logout design?"* → per-session token, `hash_equals`, 419 on
   mismatch; logout is POST-only.
8. *"Password storage?"* → `password_hash()` (bcrypt), never plain text;
   generic login errors; rate-limit + dummy-verify timing guard.
9. *"CSV export safety?"* → formula-injection guard (`'`-prefix), BOM,
   same-dataset-as-screen, disclosed 500-row cap.
10. *"Biggest limitation?"* → manual/cron monitor (no real-time push),
    single-server XAMPP deployment, English-only UI — all in scope docs.

## 4. Submission checklist

- [ ] Fresh database imports with zero errors (`docs/15 §2`).
- [ ] All 5 demo logins work; each role's home page loads.
- [ ] `docs/14 §5` sign-off table fully passed.
- [ ] `config/database.php` has stock defaults only (no real secrets).
- [ ] Report assembled per §1; screenshots embedded; TC tables filled.
- [ ] Git history shows staged commits (examiners *will* look).
