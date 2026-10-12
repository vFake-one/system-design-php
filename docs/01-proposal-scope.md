# Stage 1 — Project Proposal, Objectives, Scope & Limitations

> **Stage 1 deliverable 1 of 6.** Read this first, then `02-requirements.md`
> through `06-stage1-checklist.md` in order.
> Level: Year 2, Semester 1 — key terms are explained where they first appear.

---

## 1. Refined project proposal

### 1.1 Title

**AI-Powered Elderly Household Welfare Check-In System**
(a web-based academic prototype using PHP and MySQL with an explainable,
rule-based risk-prioritization component).

### 1.2 One-paragraph summary

This project designs and builds a centralized, web-based system for
registering elderly people, assigning caregivers, scheduling routine welfare
check-ins, recording completed check-ins and visits, automatically detecting
missed or overdue check-ins, generating and escalating alerts, prioritizing
cases for human review with transparent rules, and producing dashboards and
reports. Three roles are supported — **Administrator**,
**Caregiver / Welfare Coordinator**, and **Elderly person / Authorized
family member** — with strict server-side access control so each user only
sees records they are authorized to see.

### 1.3 Why "AI-powered" — honest wording for your report

In this project, "AI-assisted" means:

1. **Automated monitoring** — the system watches deadlines without a human
   staring at a screen (a scheduled PHP script finds overdue check-ins).
2. **Explainable rule-based prioritization** — a documented set of
   `IF … THEN …` rules turns observations
   (missed count, hours since last success, days since last visit,
   unacknowledged alerts, deviation from usual pattern) into a
   **review priority** — *Routine*, *Attention required*, or *Urgent review* —
   **with a recorded reason** every time.

These labels are **operational review priorities** (who should a caregiver
look at first?). They are **not medical diagnoses** and never claim to
predict health outcomes.

> An optional extension — a small Python + scikit-learn model called through
> a local API — is described in `05-monitoring-and-risk-rules.md`, but only
> as an *optional* enhancement using clearly labelled synthetic data. The core
> project does **not** depend on it, and your report must not claim clinical
> validation.

Use this wording in your proposal defence: the system **"uses automated
monitoring and transparent rule-based risk prioritization to help caregivers
decide what to review first."**

---

## 2. Background

Many older people live alone or have limited daily contact with family.
When illness, a fall, or another emergency occurs, help may be delayed
simply because nobody realizes something is wrong.

Common current practice in community welfare settings includes:

- scheduled home visits by caregivers or volunteers,
- phone-call check-ins,
- paper notebooks or spreadsheets recording who was visited and when.

These approaches are valuable but fragile:

- If a caregiver is sick, on leave, or overloaded, visits slip.
- Paper records are hard to search, easy to lose, and impossible to
  aggregate into statistics.
- A missed visit may only be noticed days later, if at all.
- There is no automatic reminder, no central alert list, and no audit trail
  showing who did what and when.

A low-cost, web-based system running on ordinary hardware (XAMPP + browser)
can remove much of this fragility without requiring expensive devices or
constant internet-connected sensors.

---

## 3. Problem statement

> There is a need for an affordable, accessible, centralized system that
> helps caregivers and community welfare coordinators register elderly
> households, schedule and record welfare check-ins, automatically detect
> missed or overdue check-ins, flag potentially concerning patterns for
> human review, notify the responsible caregiver promptly, escalate
> unacknowledged alerts to an authorized secondary contact, and maintain
> reliable records of visits, follow-up actions, and outcomes.

Without such a system:

- missed visits and check-ins go unnoticed,
- records are incomplete or inconsistent,
- follow-up is delayed or duplicated,
- coordinators cannot answer basic questions ("Who was missed this week?
  Which cases are still open? Who visited whom?"),
- personal data is handled inconsistently with no audit trail.

The project therefore asks: **can a well-designed PHP/MySQL web application
with automated monitoring and explainable prioritization rules measurably
improve the timeliness and traceability of elderly welfare check-ins in a
community-care setting?**

---

## 4. Main objective

> **To design and develop a secure, web-based elderly household welfare
> check-in system that uses automated monitoring and explainable,
> rule-based analysis to detect missed check-ins, prioritize cases for
> caregiver review, notify responsible caregivers, escalate unacknowledged
> alerts, and maintain complete records of check-ins, visits, and
> follow-up actions.**

---

## 5. Specific objectives

Numbered SO-1…SO-10 so they can be traced to requirements and test cases
in later stages. Each objective maps to one or more system modules.

| ID | Specific objective | Traced to module(s) |
|---|---|---|
| SO-1 | Register elderly people and maintain household welfare profiles. | Elderly management (Stage 4) |
| SO-2 | Manage administrators, caregivers, family contacts, and assignments. | User + assignment management (Stages 3–4) |
| SO-3 | Schedule and record routine welfare check-ins. | Check-in management (Stage 5) |
| SO-4 | Detect missed or overdue check-ins automatically. | Monitoring script (Stage 6) |
| SO-5 | Identify unusual check-in patterns and prioritize cases for review. | Risk engine (Stage 7) |
| SO-6 | Notify assigned caregivers of missed check-ins / concerning patterns. | Alerts, in-app notifications (Stage 6) |
| SO-7 | Escalate unacknowledged alerts to authorized secondary contacts. | Escalation (Stage 6) |
| SO-8 | Maintain caregiver visit records and follow-up actions. | Visits & follow-ups (Stage 6) |
| SO-9 | Provide dashboards, reports, and statistics. | Dashboard & reports (Stage 8) |
| SO-10 | Protect personal information via authentication, access control, and secure data handling. | Security + audit, all stages |

> Tip for your report: in the evaluation chapter you will revisit each SO
> and state to what extent it was achieved and how it was tested
> (see Stage 9 test plan).

---

## 6. Project scope

### 6.1 In scope (will be built and demonstrated)

**Users & access**

- Secure login/logout with hashed passwords, sessions, and three roles.
- Server-enforced role-based access control (RBAC) — not just hidden buttons.
- CSRF tokens on state-changing forms; escaped output; prepared statements.

**Household & caregiver management**

- Create, view, update, archive elderly household profiles.
- Create caregiver accounts; assign one or more caregivers per elderly
  person; designate a primary caregiver; end assignments without deleting
  history.

**Check-ins**

- Create expected check-in schedules (elderly person, caregiver, expected
  date/time, grace period, status).
- Submit check-ins as: (a) elderly/family self-submission,
  (b) caregiver recording on someone's behalf (submitter is recorded),
  (c) ad-hoc check-in without a prior schedule, including "needs assistance".
- View per-person check-in history with status (completed / missed / overdue).

**Monitoring, alerts, escalation**

- A cron-compatible PHP script that: finds past-deadline schedules, checks
  for a valid check-in, marks missed items, creates **at most one**
  missed-check-in alert per schedule (idempotent), notifies the assigned
  caregiver in-app, and records timestamps.
- A manual "Run monitoring now" action for admins (for classroom demo
  without waiting for cron).
- Alert lifecycle: `open → acknowledged → resolved`, with `escalated` as a
  branch when unacknowledged past the escalation delay.
- Escalation notifies an **authorized secondary contact** once (no spam).
- Configurable grace periods, escalation delays, and alert statuses.

**Risk prioritization (explainable)**

- A PHP rule engine scoring recent history into
  *Routine / Attention required / Urgent review*, storing the score,
  the reasons, the timestamp, and the review outcome.

**Visits, follow-ups, reporting**

- Visit log (routine / follow-up / emergency) and follow-up action log
  (call, visit, family contact, referral) linked to alerts.
- Dashboards with live counts (no hardcoded numbers) and Chart.js charts.
- Filterable reports: completion, missed, alerts/escalation, visits,
  follow-up outcomes.
- Audit log of important actions (logins, profile changes, alert actions).

### 6.2 Optional extensions (only if time allows)

Clearly labelled as extensions in the report and demo:

1. **Email notifications** via PHP mail/SMTP (in-app notification remains
   the guaranteed minimum).
2. **Python + scikit-learn micro-service** for risk scoring, called by PHP
   over HTTP on `localhost` with a shared secret. Trained and tested only
   on synthetic data; PHP rule engine remains the fallback if the service
   is down.

### 6.3 Out of scope / future work (do not promise these)

- SMS delivery, mobile apps, wearable/sensor integration.
- Real-time video monitoring or continuous vital-signs monitoring.
- Clinical diagnosis, triage, or treatment advice.
- Integration with hospital / government record systems.
- Multi-language support beyond English (the UI will use plain, large-text
  English suitable for older users).

---

## 7. Limitations (state these explicitly in your report)

1. **Prototype, not a safety-critical system.** It assists human caregivers;
   it cannot guarantee anyone's safety and must not replace emergency
   services (state your local emergency number in the user manual —
   `[TODO-STUDENT]`).
2. **Detection depends on data entry.** If nobody creates schedules or
   records check-ins, there is nothing to monitor. The system detects
   *missing records*, not real-world events directly.
3. **Rules are heuristic, not validated.** Thresholds (e.g., "2 missed in
   7 days → urgent") are sensible defaults chosen for demonstration, not
   clinically validated cut-offs.
4. **No real dataset.** All demo data is synthetic. Any ML extension is a
   teaching exercise, not a validated model.
5. **Single-server deployment.** Designed for XAMPP on one machine; high
   availability, load balancing, and offline-first mobile sync are not
   addressed.
6. **In-app notification only (core).** Email/SMS depend on infrastructure
   outside the project and are therefore optional.
7. **Time-zone assumption.** The app uses one configured local time zone
   (see `04-database-design.md`). Multi-time-zone households are not handled.

---

## 8. Project justification & significance

**Why this project matters:**

- **Social value:** earlier notice of missed contact can lead to earlier
  human follow-up (a call or visit), which is the realistic benefit being
  claimed — not automatic rescue.
- **Administrative value:** one searchable database replaces scattered paper
  records; coordinators can answer "who is overdue?" in seconds.
- **Accountability value:** every alert, acknowledgement, visit, and
  follow-up is timestamped and attributed, creating an audit trail.
- **Educational value:** the project exercises the full Year 2 curriculum —
  requirements, relational design, normalization, SQL, server-side
  programming, authentication, validation, and testing — in one coherent
  system.
- **Cost value:** it runs on free, widely available tools (XAMPP, MySQL,
  Bootstrap, Chart.js) with no per-user licence cost.

**Who benefits:**

- Elderly people living alone (more reliable contact routines).
- Family members (visibility of check-in history for their relative).
- Caregivers (clear prioritized worklist instead of a pile of notes).
- Welfare coordinators / admins (statistics, reports, audit logs).

---

## 9. Assumptions you must verify yourself `[TODO-STUDENT]`

Mark these as assumptions in your report until confirmed:

- [ ] Your institution's required **citation style** (APA 7th? IEEE?) —
      references chapter must follow it.
- [ ] Your country's / institution's **data-protection rules** for handling
      personal data in student projects (consent wording, retention).
- [ ] The **local time zone** to configure (e.g., `Africa/Lagos`,
      `Europe/London`, `Asia/Kuala_Lumpur`) — confirm with your supervisor.
- [ ] Whether your supervisor expects the **optional ML extension** or is
      satisfied with the PHP rule engine alone.
- [ ] Whether a **live demo with cron** is expected or a manual monitoring
      run is acceptable in the lab.
- [ ] Your local **emergency services number** for the disclaimer page.

---

## 10. Key terms (Year 2 glossary for this document)

| Term | Plain-English meaning |
|---|---|
| **Check-in** | A recorded confirmation that contact was made ("Mrs A is OK at 09:15"). |
| **Schedule** | An *expected* check-in: who, when, how much lateness is allowed (grace). |
| **Grace period** | Extra minutes after the expected time before the system calls it overdue. |
| **Overdue** | Expected time + grace has passed with no valid check-in recorded. |
| **Missed** | Overdue *and* confirmed by the monitoring run (status is stored). |
| **Alert** | A work item created for a missed check-in or concerning pattern. |
| **Escalation** | Notifying a second authorized person because the first alert was ignored too long. |
| **RBAC** | Role-Based Access Control — the server checks your role before every action. |
| **Audit log** | Append-only history of who did what and when. |
| **Idempotent** | Safe to run twice — the second run creates no duplicates. The monitor must be idempotent. |

---

*Next: `02-requirements.md` — functional and non-functional requirements
with a roles × permissions matrix.*
