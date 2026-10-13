# User manual

Three roles, three home pages. Everyone logs in at
`http://localhost/elderly-welfare-system/login.php` and can change their
password under **My account** (top-right). Sessions expire after 30 idle
minutes — log in again if that happens.

---

## 1. Administrator (`admin@example.local` / `Admin123!`)

**Dashboard** — system-wide live tiles (households, due/overdue, alerts,
visits), a 14-day check-in chart and the top active alerts. Click any tile
to jump to its working page.

**Households** — register a household (name + optional contacts), edit it,
or open its profile: schedules, check-in history, alerts, visits and
follow-ups in one place.

**Caregivers** — create accounts (role locked after creation), suspend /
reactivate, reset passwords (a temporary password is shown once — pass it
to the person securely).

**Assignments** — attach caregivers to households; mark one as **primary**
(the first to be alerted). Only actively-assigned caregivers see a
household anywhere.

**Schedules** — expected check-ins: create (household, date/time, grace),
edit, cancel. Filters split *scheduled* from *overdue* (derived: expected
time + grace passed with no submission).

**Alerts + Review** (shared with caregivers) — the worklists. You see
*every* alert; caregivers see only their households'. You alone can
**Dismiss** an alert. **Review** shows the worst open alert per household,
worst-first, with its scoring evidence; **Re-score** recomputes all open
alerts with current rules/thresholds.

**Reports** — five tabs (completion, missed, alert response, visits,
follow-ups) with household + date filters and **CSV export** of exactly
what's on screen.

**Audit** — read-only trail of who did what, newest first; filter by actor,
action or date (defaults to the last 30 days — widen it for older rows).

**Settings** — tune the scoring thresholds (Urgent must exceed Attention),
grace/escalation defaults, and **run the monitor now** (marks overdue
schedules missed, creates and escalates alerts).

## 2. Caregiver (`ruth.caregiver@example.local` / `Care123!`)

**Dashboard** — same layout as the admin's, confined to *your assigned*
households. Nothing assigned yet → zeros plus an explanatory note.

**Check-ins** — your workspace: due-now and overdue schedules (log a
submission or phone result), ad-hoc check-ins, and per-household history.

**Alerts** — acknowledge (say what you did) → act → resolve. Resolve is
*refused* until a completed follow-up with a written outcome exists.

**Review** — your households' worst open alerts with scoring evidence.

**Visits** — log physical visits (date, type, outcome, notes); history
survives even if an account is later removed.

**Follow-ups** — log calls/visits/referrals per alert with outcomes and
next steps; `planned` ones stay on the radar with days-open counts.

**My households** — read-only list of who's assigned to you, linking to
their profiles. **My reports** — the same five reports as the admin's,
confined to your households.

## 3. Elderly person / family (`mary.elderly@example.local` / `Elder123!`)

**My check-ins** — big buttons: **I'm OK** (green), **I need help** (red),
**I feel unwell** (amber), plus an optional note. "I need help" opens an
URGENT alert for your caregiver immediately. Below: your upcoming
schedules and recent history.

**My profile** — your summary, contacts and history (read-only — ask your
caregiver or the administrator to change anything).

> Safety note shown in the app footer: this system supports welfare
> check-ins; it is not a substitute for emergency services.

## 4. Everyday flows (cheat sheet)

| I want to… | Role | Path |
|---|---|---|
| See what's due now | Caregiver | Dashboard → Check-ins |
| Respond to an alert | Caregiver | Alerts → Acknowledge → Follow-ups → Resolve |
| Record a home visit | Caregiver | Visits → Log visit |
| Check the worst cases first | Any staff | Review (worst-first, evidence shown) |
| Onboard a new household | Admin | Households → Register → Assignments → Schedules |
| Export last month's completion | Admin | Reports → Check-in completion → filter → Export CSV |
| Find who changed a schedule | Admin | Audit → filter by action/date |
| Change my password | Anyone | My account → Change password |
