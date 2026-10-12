# Stage 4 — Household & Caregiver Management

> **Stage 4 deliverable.** Admin registers households and user accounts and
> links them; caregivers see only their own assignments (the 🔒 matrix check
> goes live on a real page for the first time).
> Prerequisite: Stages 2–3 working (login as `admin@example.local`).

**Files created/changed in this stage:**

| File | Purpose |
|---|---|
| `includes/functions.php` | **Extended:** `valid_*` validators, `temp_password()`, `assigned_elderly_ids()`, `can_access_elderly()`, `pager()` + `pager_links()` |
| `includes/header.php` | **Extended:** Households / Caregivers / Assignments / My-households nav |
| `admin/elderly.php` | Household list: search, status filter, pagination, archive/restore |
| `admin/elderly_form.php` | Register + edit household (sticky form, per-field errors) |
| `admin/elderly_view.php` | **Shared** profile + assignments (admin full, caregiver scoped read-only) |
| `admin/caregivers.php` | Account list by role tab + suspend/reactivate + one-time temp-password reset |
| `admin/caregiver_form.php` | Create + edit accounts (role locked after creation; elderly logins link a household) |
| `caregiver/households.php` | Caregiver's own assigned households (query-scoped, links to shared view) |
| `docs/09-…` | This guide |

**Small structural notes** (brief §15 allows improvements with explanation):
1. The specified `admin/elderly.php` / `admin/caregivers.php` are split into
   **list + form (+ view)** files so each file has one job and stays
   reviewable (~100–330 lines each). The URLs still match the brief's names
   for the list pages.
2. `caregiver/households.php` is new: caregivers need a navigation entry to
   their scoped profiles; Stage 5's `checkins.php` builds on the same query.
3. `admin/elderly_view.php` is deliberately shared across roles instead of
   duplicating a caregiver copy — one page, one ownership check, no drift.

---

## A. How it works (concepts)

1. **Validate on the server, decorate on the client.** `required`,
   `maxlength`, `type=date` help honest users; PHP's `valid_*` helpers are
   the real gate (a forged POST bypasses the browser but not the server).
   Try it: remove `required` in DevTools, submit empty → server still refuses.
2. **Sticky forms + per-field errors.** On failure the page re-renders (no
   redirect) with `$form` values and `$errors['field']`; on success it
   redirects (POST-redirect-GET) so refresh never double-submits.
3. **Archive, don't delete.** `status → archived` hides rows from default
   lists while check-ins/alerts/visits stay intact for reports and audit.
4. **Assignments are re-activated, not duplicated.** The `uq_assignment_pair`
   key allows one row per pair forever; ending sets `status='ended'`, and
   re-assigning flips the same row back (TC-4.15 proves the row id is stable).
5. **Primary switches are transactional.** `BEGIN → demote old → promote new
   → COMMIT` (with `ROLLBACK` on any error) means two primaries can never
   exist, even if two admins click at once. `set_primary` refuses ended rows.
6. **Ownership is checked in SQL + PHP.** The caregiver list query joins
   `caregiver_assignments` (can't *list* others), and `elderly_view.php`
   calls `can_access_elderly()` (can't *open* others by URL either).
7. **Temporary passwords are shown once.** `temp_password()` (12 unambiguous
   chars) is hashed immediately; the plain value appears in exactly one flash
   message and is never written to `audit_logs` (only the reset *fact* is).
8. **Role is locked after creation** and you cannot suspend yourself — two
   cheap guards against locking every admin out of the system.

---

## B. Test table (run on XAMPP — record results for Stage 9)

| ID | Feature | Preconditions | Input / action | Expected result | Actual | Pass? |
|---|---|---|---|---|---|---|
| TC-4.01 | Register household (valid) | Admin logged in | `elderly_form.php`: name "Test Nalongo", phone "+256 700 111222", living "lives_alone", secondary Ruth, rest blank → submit | Redirect to new profile view + "Household registered."; row in DB with `created_by` = admin | | |
| TC-4.02 | Register (missing name) | Admin | Submit with empty name | Same page, "Full name is required.", other inputs kept (sticky) | | |
| TC-4.03 | Register (bad phone/DOB) | Admin | Phone "abc", DOB tomorrow | Both field errors shown; nothing inserted | | |
| TC-4.04 | Edit household | TC-4.01 done | Change phone → save | "Household updated." + new value shown; `update_elderly` audit row | | |
| TC-4.05 | Archive / restore | TC-4.01 done | Archive → list hides it under default filter; Restore → back | Status flips; history intact; audit rows `archive_elderly`/`restore_elderly` | | |
| TC-4.06 | Search + filter + pages | ≥3 households | Search "mary", filter Archived, click page links | Correct subsets; pager preserves `q`/`status` in URLs | | |
| TC-4.07 | Create caregiver | Admin | `caregiver_form.php`: new name/email, role caregiver, pw `Caregiver1` ×2 → submit | "Account created."; login with it works (TC-3.02 pattern) | | |
| TC-4.08 | Duplicate email | Admin | New account with `ruth.caregiver@example.local` | "That email is already registered."; no row added | | |
| TC-4.09 | Weak / mismatched pw | Admin | `short` then `Password1` vs `Password2` | Respective password errors; no row added | | |
| TC-4.10 | Elderly login link | Admin | Role elderly + household Mary → create; log in as it | Lands on `elderly/checkin.php`; `elderly_profile_id` set in DB | | |
| TC-4.11 | Suspend / reactivate | TC-4.07 account | Suspend → its login fails; Reactivate → works | Status flips + audit rows; suspended session dies mid-use (Stage 3) | | |
| TC-4.12 | Self-suspend guard | Admin | Suspend your own admin row | Refused: "You cannot suspend… your own account." | | |
| TC-4.13 | Temp password reset | TC-4.07 account | Reset pw → copy shown password → log in with it | One-time password displayed once; login works; audit has fact but NOT the password (`SELECT details FROM audit_logs WHERE action='reset_password'`) | | |
| TC-4.14 | Assign + primary | Admin | Assign TC-4.07 caregiver to TC-4.01 household as primary | Appears in list with primary badge; profile view shows it | | |
| TC-4.15 | Duplicate assign | TC-4.14 active | Assign same pair again | "already assigned"; still ONE row for the pair in DB | | |
| TC-4.16 | Make primary switch | 2 caregivers on one household | Make the secondary primary | Badges swap; exactly one `is_primary=1` active row (`SELECT elderly_id, SUM(is_primary) FROM caregiver_assignments WHERE status='active' GROUP BY elderly_id` → all 1) | | |
| TC-4.17 | End + reactivate | TC-4.14 | End → caregiver loses access (TC-4.19); Reactivate → restored | Statuses flip; `unassigned_at` set then cleared; no row deleted | | |
| TC-4.18 | Caregiver own list | Ruth logged in | Open My households | Only Ruth's active households (Mary, John); David's-only household absent | | |
| TC-4.19 | Caregiver view own/others | Ruth logged in | Open Mary's view (200) vs Grace's view URL with Grace unassigned to Ruth | Own: full profile read-only, no Edit button. Other's: 403 + `access_denied` audit | | |
| TC-4.20 | Audit coverage | After above | `SELECT action, COUNT(*) c FROM audit_logs GROUP BY action` | Contains create/update/archive/restore_elderly, create/update/suspend/reactivate_user, reset_password, *_assignment rows | | |
| TC-4.21 | Mobile tables | Admin | Household/accounts/assignment pages at 375 px | Tables scroll horizontally inside `.table-responsive`; no page breakage | | |

Cleanup after testing (keeps seed pristine for later demos):
```sql
DELETE FROM caregiver_assignments WHERE elderly_id > 3;
DELETE FROM elderly_profiles WHERE id > 3;
DELETE FROM users WHERE id > 5;
```

---

## C. Troubleshooting

| Symptom | Cause → fix |
|---|---|
| "Household not found" right after Register | You were redirected with a bad id — check `elderly_profiles` has the row; usually a filter hiding it (Archived/All). |
| Secondary-contact dropdown empty | No active staff besides you — it lists active admins/caregivers only by design. |
| "already assigned" but list shows nothing | The pair is `ended` — re-assigning reactivates it (TC-4.15/17); filter shows ended rows in the same table. |
| Caregiver sees empty My households | No *active* assignment, or household not `active` — check `assignments.php` filters. |
| Temp password flash vanished | It shows exactly once by design — issue another Reset if you lost it. |
| Duplicate-primary badges | Should be impossible (transaction + demote-others); if ever seen, report the steps — the check query in TC-4.16 is the proof. |

---

## D. Decisions locked in Stage 4 (append to Stage 1 D-list)

| # | Decision |
|---|---|
| D-15 | Escalation secondary contact must be active **staff** (admin/caregiver); family logins are excluded (escalation needs a responsible responder). |
| D-16 | Account **role is immutable** after creation; email/name/phone/status editable. |
| D-17 | Self-suspension is refused (for status flips; you may still reset your own password). |
| D-18 | Re-assigning an ended pair **reactivates** the same row (new `assigned_at`); never a second row. |
| D-19 | Reactivated rows keep `primary` only if no other active primary exists for that household. |
| D-20 | `elderly_view.php` is role-shared (admin + scoped caregiver) rather than duplicated. |

---

## E. Stage 4 checklist & gate

- [x] Household register/edit/archive + search/filter/pagination (FR-09–12)
- [x] Staff + elderly/family accounts, unique emails, locked roles (FR-02/08/13)
- [x] Assignments with primary discipline, end/reactivate, transactions (FR-13–15)
- [x] Server validation everywhere + sticky errors + PRG + audit (FR-11/46)
- [x] Caregiver query + page scoping live (`can_access_elderly`) (FR-04/12)
- [x] Static verification here (see commit); live TC-4.01–4.21 are YOUR runs
- [ ] **Student action:** run TC-4.01–4.21, fill Actual/Pass, then run the cleanup SQL

**Stage 5 preview (Check-in management):** `admin/settings.php` is skipped
until Stage 6 (it configures grace/escalation the monitor needs) — next is
schedules + submissions: `caregiver/checkins.php` (due/overdue lists +
record-on-behalf), `elderly/checkin.php` full rewrite (big-button submit +
assistance request), `elderly/profile.php` (own schedule + history),
history section inside `elderly_view.php`, overdue derivation. Reply
**"Approved — proceed to Stage 5"** when TC-4.01–4.21 pass (or paste failures).
