# Installation guide (XAMPP, Windows)

Target: a stock Windows + XAMPP machine, e.g. the lab PC or the examiner's
laptop. Time needed: ~20 minutes. No internet required after XAMPP itself
is installed (Bootstrap and Chart.js are bundled in `assets/`).

---

## 1. Prerequisites

- **XAMPP** (PHP 8.x, MySQL/MariaDB, Apache) installed and running —
  see `docs/07 §A` if you need the full walkthrough.
- This project's folder, placed at:
  `C:\xampp\htdocs\elderly-welfare-system\`
  (the app URL becomes `http://localhost/elderly-welfare-system/`).

## 2. Create the database

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin` → **New** → name it exactly
   `elderly_welfare_db` → collation `utf8mb4_unicode_ci` → **Create**.
3. Select the new database → **Import** → choose
   `database/elderly_welfare.sql` → **Go**.
4. You should see 12 tables plus green "success" notices. Click
   `elderly_profiles` — several sample households confirm the seed ran.

## 3. Configure the connection (usually: nothing to do)

`config/database.php` already matches stock XAMPP
(host `127.0.0.1`, user `root`, empty password, database
`elderly_welfare_db`, timezone `Africa/Lagos` / `+03:00`).
Only edit it if your MySQL uses a password or a different host.

## 4. Verify the install (5 minutes)

1. Open `http://localhost/elderly-welfare-system/` → you land on login.
2. Log in as `admin@example.local` / `Admin123!` → admin dashboard with
   live tiles and two charts.
3. Log out; log in as `ruth.caregiver@example.local` / `Care123!` →
   caregiver dashboard shows only her households.
4. Log out; log in as `mary.elderly@example.local` / `Elder123!` →
   big-button check-in page.
5. All five demo accounts (see `docs/07` §C or README) log in → install OK.

## 5. Set up the monitor (automatic checks)

The monitor (`cron/monitor_checkins.php`) marks overdue schedules missed
and creates/escalates alerts. Three ways to run it, easiest first:

1. **Manually** (demos, marking sessions): as admin → **Settings** →
   **Run monitor now**. Always works; use this in your viva.
2. **Windows Task Scheduler** (leave running on a lab PC): run every
   15 minutes —
   `C:\xampp\php\php.exe C:\xampp\htdocs\elderly-welfare-system\cron\monitor_checkins.php`
3. **Linux cron** (if deployed on Linux):
   `*/15 * * * * /usr/bin/php /path/to/cron/monitor_checkins.php`

The monitor is idempotent (re-runs change nothing) and refuses to run
twice at once (lock file), so overlapping schedules are harmless.

## 6. Troubleshooting

| Symptom | Fix |
|---|---|
| "Database connection failed" | MySQL not running (start it in XAMPP), or DB name ≠ `elderly_welfare_db`, or edited credentials wrong |
| Blank page / HTTP 500 | Read `C:\xampp\apache\logs\error.log` (last lines name the file + line); most often a wrong folder name |
| Login says "Invalid email or password" for seed users | Seed didn't import — re-do §2; check `users` table has 5+ rows |
| Charts don't render | `assets/js/chart.umd.min.js` missing from your copy — re-copy `assets/` |
| CSS unstyled | Same, for `assets/css/bootstrap.min.css` |
| Monitor "already running" forever | Delete the stale lock file named in its message (it cleans up after crashes; a killed run can leave it) |
| Times look off by hours | MySQL session zone is set by the app (`+03:00`); don't change server zones — check the PC clock instead |

## 7. Fresh database for marking (examiner reset)

To reset to the exact seeded state: in phpMyAdmin, drop
`elderly_welfare_db`, then repeat §2. Relative seed timestamps
(`NOW() ± INTERVAL`) mean the demo always has due-soon, overdue and
history rows whatever day it is imported.
