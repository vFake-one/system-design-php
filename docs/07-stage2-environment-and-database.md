# Stage 2 — Environment & Database (XAMPP + `elderly_welfare.sql` + PDO)

> **Stage 2 deliverable.** Follow parts A → E in order. Estimated time on a
> fresh machine: 45–90 minutes (mostly the XAMPP download).
> Level: Year 2, Semester 1 — every new term is explained where it appears.

**Files created in this stage** (exact paths — all committed):

| File | Purpose |
|---|---|
| `database/elderly_welfare.sql` | Creates `elderly_welfare_db`: 11 tables + settings + synthetic seed + demo accounts |
| `config/database.php` | The ONE shared PDO connection (XAMPP defaults; every later page uses it) |
| `config/database.example.php` | Safe template copy — how to re-create the connection file |
| `test_db.php` (project root) | **TEMPORARY** green/red verification page — delete after it passes |
| `docs/07-stage2-environment-and-database.md` | This guide |

---

## A. Install and configure XAMPP

**Key terms.** *XAMPP* = a free bundle (Apache web server + MySQL/MariaDB
database + PHP + phpMyAdmin) that turns your laptop into a local server.
*Apache* runs PHP pages; *MySQL/MariaDB* stores the data; *phpMyAdmin* is a
browser tool for managing the database; *htdocs* is the folder Apache
publishes as `http://localhost/`.

### A1. Windows (most common in class)

1. Download the current XAMPP **Windows** installer (PHP 8.x) from the
   official Apache Friends site and run it as Administrator.
2. Components: keep **Apache, MySQL, PHP, phpMyAdmin** selected
   (FileZilla/Mercury/Tomcat are not needed — untick them).
3. Install to the default `C:\xampp\` (guides and cron examples assume this).
4. Open **XAMPP Control Panel** → click **Start** on **Apache**, then on
   **MySQL**. Both rows should go green.
5. Verify in your browser:
   - `http://localhost/dashboard/` → XAMPP welcome page (Apache works).
   - `http://localhost/phpmyadmin/` → phpMyAdmin (MySQL works).
6. Check the PHP version: in phpMyAdmin's home tab it lists e.g.
   "PHP version: 8.x.x". Anything **8.0+** is fine for this project.

### A2. Linux (e.g., Ubuntu lab machines)

1. Download the Linux installer, then:
   ```bash
   chmod +x xampp-linux-*-installer.run
   sudo ./xampp-linux-*-installer.run
   ```
2. Start: `sudo /opt/lampp/lampp start` (stop: `sudo /opt/lampp/lampp stop`).
3. Verify the same two URLs as above.
4. Web root is `/opt/lampp/htdocs/`; PHP binary is `/opt/lampp/bin/php`.

### A3. macOS

Same as Linux but the app lives in `/Applications/XAMPP/`; manage servers
from **manager-osx**, web root `/Applications/XAMPP/htdocs/`.

### A4. Place the project in htdocs

Apache only serves files inside its web root. Put this project there:

| OS | Target folder |
|---|---|
| Windows | `C:\xampp\htdocs\elderly-welfare-system\` |
| Linux | `/opt/lampp/htdocs/elderly-welfare-system/` |
| macOS | `/Applications/XAMPP/htdocs/elderly-welfare-system/` |

Either `git clone` the repo there or copy the folder. Verify you can open
`http://localhost/elderly-welfare-system/test_db.php` **after** part B
(it fails before the database exists — that is expected).

> **Port conflicts (Windows):** if Apache won't start, Skype/ISS/another
> MySQL may hold port 80/443/3306. Either stop the other program or, in
> XAMPP Control Panel → Apache **Config** → `httpd.conf`, change
> `Listen 80` to `Listen 8080` and use `http://localhost:8080/...`.

---

## B. Create the database (import the SQL script)

You have two equivalent options — **B1 (phpMyAdmin)** is recommended for
first-time import because you see the result visually.

### B1. Import via phpMyAdmin (recommended)

1. Open `http://localhost/phpmyadmin/`.
2. Click the **Import** tab (top menu — do NOT create the database first;
   the script's `CREATE DATABASE` does it).
3. **Choose file** → select `database/elderly_welfare.sql` from the project.
4. Leave format = SQL, character set = utf8mb4, click **Go**.
5. Success looks like: *"Import has been successfully finished"* plus the
   `elderly_welfare_db` database appearing in the left panel with **11 tables**.

### B2. Import via command line (alternative)

```bash
# Windows (in the project folder):
C:\xampp\mysql\bin\mysql.exe -u root < database\elderly_welfare.sql
# Linux/macOS:
/opt/lampp/bin/mysql -u root < database/elderly_welfare.sql
```

### B3. Verify the import (do this regardless of method)

In phpMyAdmin, select `elderly_welfare_db` → **SQL** tab, run the block at
the bottom of the script (or these two quick ones):

```sql
SELECT COUNT(*) AS tables FROM information_schema.tables
WHERE table_schema = 'elderly_welfare_db';   -- expect 11
SELECT email, role, status FROM users ORDER BY id;  -- expect 5 demo accounts
```

Expected: **11 tables**; **5 users, 3 profiles, 4 assignments, 7 schedules
(4 scheduled / 2 completed / 1 missed), 3 check-ins, 3 visits, 2 alerts
(1 open / 1 resolved), 1 acknowledgement, 1 follow-up, 2 audit rows,
6 settings**. If counts differ, re-import (the script drops and recreates,
so re-running is safe).

### B4. Seed map (what the synthetic rows demonstrate)

| Seed rows | Purpose in later demos |
|---|---|
| S1 due in ~2h (`scheduled`) | "Due today" dashboard count; check-in submission target (Stage 5) |
| **S2, S5 overdue-but-`scheduled`** | **Stage 6 monitor demo:** run the monitor → they flip to `missed` + raise alerts |
| S3, S6 (`completed` + R1, R2) | History display; one self-submitted, one caregiver-entered (shows `submitted_by` tracking) |
| R3 ad-hoc for Mary | Ad-hoc check-ins exist without schedules |
| A1 open `pattern_flag` (Grace) | Open-alert dashboard count; acknowledge/resolve workflow target |
| A2 resolved + ack + follow-up (John/S7) | Finished lifecycle example for reports |
| V1–V3 visits | "Days since last visit" risk input; visit history |
| 5 demo logins | Stage 3 login tests per role (passwords below) |

---

## C. Configure the connection (`config/database.php`)

On **stock XAMPP, nothing needs changing** — the committed file already
matches (`127.0.0.1` / `elderly_welfare_db` / `root` / empty password).
Only edit the `const` block in section 2 if your setup differs:

| Your situation | Change |
|---|---|
| You set a MySQL root password | `DB_PASS` to that password |
| MySQL on another host/port | `DB_HOST` (e.g., `'127.0.0.1:3307'`) |
| Different database name | `DB_NAME` (and the `CREATE DATABASE` line in SQL) |

**Security rules (examined in your viva):**

- This file holds **local XAMPP defaults only** — the best-known
  username/password in the world (`root`/empty), which is why committing it
  for a classroom project is acceptable. It is **not** a real secret.
- If you ever deploy beyond your laptop: put the real password only in the
  server's copy, keep `config/database.example.php` as the committed
  template, and add the real file to `.gitignore`.
- `test_db.php` shows table/row details → **delete it after verification**
  (and definitely before any deployment or submission).

---

## D. Test the connection (`test_db.php`)

1. Ensure Apache + MySQL are **Started** (green) and the SQL is imported.
2. Visit **`http://localhost/elderly-welfare-system/test_db.php`**
   (add `:8080` if you moved Apache's port in A4).
3. **Expected: every row green, "ALL CHECKS PASSED"** — connection, matching
   time zones, 11 tables, per-table row counts, 5 demo accounts, schedule mix.
4. Then **delete `test_db.php`** (or rename to `test_db.DONE.php.off` if you
   want evidence for your report appendix — either way it must not remain
   executable in the final submission).

### Troubleshooting (symptom → cause → fix)

| What you see | Likely cause | Fix |
|---|---|---|
| `Database connection failed` (all red) | MySQL not started | XAMPP Control Panel → Start MySQL (green) |
| `Unknown database 'elderly_welfare_db'` in Apache error log | SQL not imported | Repeat B1/B2; check for import errors |
| `Access denied for user 'root'` in error log | Root has a password | Set `DB_PASS` in `config/database.php` (§C) |
| `Connection refused` | Wrong host/port or MySQL on 3307 | Check `DB_HOST`; XAMPP MySQL **Config** → `my.ini` port |
| `Tables present` shows ≠ 11 | Partial import (max upload/time) | Re-import; CLI method (B2) has no upload limit |
| Counts lower than §B3 | Old database + new script mixed | Script drops first — just re-run the import fully |
| `session zone` check fails | `SET time_zone` rejected | Stock XAMPP accepts `+03:00`; if yours errors, check MySQL ≥ 5.6 / MariaDB ≥ 10 |
| `http://localhost/...` 404 | Project not in htdocs | Recheck A4 folder path and spelling |
| Blank page | PHP fatal (typo while editing config) | Undo edit; check `apache/logs/error.log` / `error_log` |

> Where are the logs? Windows: `C:\xampp\apache\logs\error.log`;
> Linux: `/opt/lampp/logs/error_log`. The app deliberately shows users a
> generic message — the REAL error is only in these files (that's a
> security feature, NFR-01).

---

## E. Concepts you should now be able to explain (viva prep)

1. **PDO** — PHP Data Objects: PHP's standard, database-neutral way to talk
   to MySQL. One `$pdo` object per request, created in `config/database.php`.
2. **Prepared statements** (used from Stage 3 on): SQL with placeholders
   (`:id`) + separately bound values. The database never confuses data with
   code → immune to SQL injection. `ATTR_EMULATE_PREPARES => false` forces
   the database server (not PHP) to do the separation — strictly safer.
3. **DSN** — Data Source Name string (`mysql:host=...;dbname=...;charset=...`):
   the "address" PDO connects to.
4. **Why one time zone in two places?** PHP computes display times;
   MySQL computes deadline comparisons (`expected_at + grace < NOW()`).
   If they disagreed, "overdue" would be wrong near midnight — so PHP uses
   `Africa/Lagos` and MySQL uses the identical instant `+03:00`.
5. **InnoDB + foreign keys** — the engine that enforces relationships
   (e.g., you cannot insert a schedule for a non-existent profile).
   Confirm: `SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE table_schema='elderly_welfare_db';` → all `InnoDB`.
6. **UNIQUE as a safety net** — try inserting a second check-in for schedule
   3 in phpMyAdmin: it FAILS (`uq_checkin_schedule`). That failure is the
   database protecting the one-check-in-per-schedule rule even if PHP had a bug.

---

## F. Stage 2 checklist & gate

- [x] XAMPP install/config documented (Windows + Linux + macOS)
- [x] Project folder placement documented (`htdocs/elderly-welfare-system`)
- [x] `database/elderly_welfare.sql` written: 11 tables in Stage 1 order, keys/indexes/constraints per `docs/04`, synthetic seed + 5 demo accounts (bcrypt, cost 10)
- [x] `config/database.php` + `config/database.example.php` written (PDO, exceptions, assoc fetch, real prepares, zone sync, safe error handling)
- [x] `test_db.php` verification page written (delete-after-use warning built in)
- [x] Static verification done here: table order/FK targets/unique keys/seed counts machine-checked (see commit); live import + green page must be confirmed by YOU on XAMPP (claimed only after you run it — Stage 9 records the result)
- [ ] **Student action:** import → green `test_db.php` → screenshot for report appendix → delete the file
- [ ] **Student decision:** nothing blocking — Stage 3 needs no new answers

**Demo accounts (LOCAL ONLY — change after testing):**
`admin@example.local` / `Admin123!` ·
`ruth.caregiver@example.local` / `Care123!` ·
`david.caregiver@example.local` / `Care123!` ·
`mary.elderly@example.local` / `Elder123!` ·
`peter.family@example.local` / `Elder123!`

**Stage 3 preview (Authentication & roles):** `login.php`, `logout.php`,
`index.php` landing, `includes/auth.php` (session + `requireRole()`),
`includes/csrf.php`, `includes/functions.php` (`e()` escaper), role home
redirects, and the login/RBAC test table. Reply **"Approved — proceed to
Stage 3"** when your `test_db.php` is green (or ask for fixes if it isn't).
