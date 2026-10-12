-- =====================================================================
-- File: database/elderly_welfare.sql
-- Project: AI-Powered Elderly Household Welfare Check-In System
-- Database: elderly_welfare_db | Engine: InnoDB | Charset: utf8mb4
-- Stage: 2 (Environment and database)
-- App time zone: Africa/Lagos (EAT = UTC+03:00, no daylight saving)
--
-- WHAT THIS SCRIPT DOES
--   1. Creates the `elderly_welfare_db` database (if it does not exist).
--   2. Drops old project tables so the script is safe to re-run.
--   3. Creates 11 tables in dependency order (Stage 1, docs/04, section 8).
--   4. Seeds configurable settings + clearly labelled SYNTHETIC demo data.
--
-- SYNTHETIC DATA NOTICE (read before demonstrating or submitting)
--   Every name, address, phone number and email below is FICTIONAL and was
--   invented only so the application can be demonstrated and tested.
--   Any resemblance to real persons is coincidental.
--
-- HOW TO IMPORT (details in docs/07-stage2-environment-and-database.md)
--   phpMyAdmin: select server > Import > choose this file > Go.
--   CLI: mysql -u root < database/elderly_welfare.sql
--
-- DEMO ACCOUNTS (LOCAL TESTING ONLY - change these passwords afterwards)
--   admin@example.local            / Admin123!  (administrator)
--   ruth.caregiver@example.local   / Care123!   (caregiver, primary for Mary + John)
--   david.caregiver@example.local  / Care123!   (caregiver, primary for Grace)
--   mary.elderly@example.local     / Elder123!  (elderly login -> Mary Namono)
--   peter.family@example.local     / Elder123!  (family login  -> John Okello)
--   Passwords are stored ONLY as bcrypt hashes (cost 10). No plain text.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. Database + session setup
-- ---------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS elderly_welfare_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE elderly_welfare_db;

-- Use a UTC offset instead of the zone NAME because stock XAMPP MySQL on
-- Windows does not ship the time-zone name tables. '+03:00' is the same
-- instant as Africa/Lagos all year (East Africa has no daylight saving).
-- PHP mirrors it with date_default_timezone_set('Africa/Lagos').
SET time_zone = '+03:00';

-- Allow dropping in any order for a clean re-run (creation below still
-- follows the correct parent-before-child order from the Stage 1 design).
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS caregiver_visits;
DROP TABLE IF EXISTS followup_actions;
DROP TABLE IF EXISTS alert_acknowledgements;
DROP TABLE IF EXISTS alerts;
DROP TABLE IF EXISTS checkin_records;
DROP TABLE IF EXISTS checkin_schedules;
DROP TABLE IF EXISTS caregiver_assignments;
DROP TABLE IF EXISTS elderly_profiles;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS system_settings;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. users - all login accounts (FK to elderly_profiles added in step 3
--    via ALTER TABLE, because the two tables reference each other)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name          VARCHAR(100) NOT NULL,
  email              VARCHAR(150) NOT NULL,
  phone              VARCHAR(20) NULL DEFAULT NULL,
  password_hash      VARCHAR(255) NOT NULL COMMENT 'bcrypt hash only, never plain text',
  role               ENUM('admin','caregiver','elderly') NOT NULL
                     COMMENT 'elderly = elderly person OR authorized family member',
  elderly_profile_id INT UNSIGNED NULL DEFAULT NULL
                     COMMENT 'set only for role=elderly; links login to permitted profile',
  status             ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  INDEX idx_users_role (role),
  INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. elderly_profiles - household welfare profiles
-- ---------------------------------------------------------------------
CREATE TABLE elderly_profiles (
  id                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name                    VARCHAR(100) NOT NULL,
  date_of_birth                DATE NULL DEFAULT NULL,
  gender                       ENUM('male','female','other','prefer_not_to_say') NULL DEFAULT NULL,
  address                      TEXT NULL DEFAULT NULL,
  phone                        VARCHAR(20) NULL DEFAULT NULL,
  emergency_contact_name       VARCHAR(100) NULL DEFAULT NULL,
  emergency_contact_phone      VARCHAR(20) NULL DEFAULT NULL,
  emergency_contact_relationship VARCHAR(50) NULL DEFAULT NULL,
  secondary_contact_user_id    INT UNSIGNED NULL DEFAULT NULL
                               COMMENT 'authorized escalation recipient (FK users)',
  living_situation             VARCHAR(100) NULL DEFAULT NULL,
  welfare_notes                TEXT NULL DEFAULT NULL
                               COMMENT 'non-diagnostic welfare notes only',
  status                       ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_by                   INT UNSIGNED NULL DEFAULT NULL,
  created_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_elderly_status (status),
  INDEX idx_elderly_name (full_name),
  INDEX idx_elderly_secondary (secondary_contact_user_id),
  CONSTRAINT fk_elderly_secondary FOREIGN KEY (secondary_contact_user_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_elderly_created_by FOREIGN KEY (created_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Deferred circular FK: users.elderly_profile_id -> elderly_profiles(id)
-- ---------------------------------------------------------------------
ALTER TABLE users
  ADD INDEX idx_users_elderly (elderly_profile_id),
  ADD CONSTRAINT fk_users_elderly_profile FOREIGN KEY (elderly_profile_id)
    REFERENCES elderly_profiles (id) ON DELETE SET NULL ON UPDATE CASCADE;

-- ---------------------------------------------------------------------
-- 4. caregiver_assignments - which caregivers serve which elderly (M:N)
-- ---------------------------------------------------------------------
CREATE TABLE caregiver_assignments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  elderly_id    INT UNSIGNED NOT NULL,
  caregiver_id  INT UNSIGNED NOT NULL,
  is_primary    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = primary (first to be alerted)',
  status        ENUM('active','ended') NOT NULL DEFAULT 'active',
  assigned_by   INT UNSIGNED NULL DEFAULT NULL,
  assigned_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unassigned_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assignment_pair (elderly_id, caregiver_id),
  INDEX idx_assignment_elderly (elderly_id, status),
  INDEX idx_assignment_caregiver (caregiver_id, status),
  CONSTRAINT fk_assignment_elderly FOREIGN KEY (elderly_id)
    REFERENCES elderly_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_assignment_caregiver FOREIGN KEY (caregiver_id)
    REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_assignment_by FOREIGN KEY (assigned_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. checkin_schedules - expected check-ins (one row = one occurrence).
--    status has NO 'overdue': overdue is DERIVED as
--    status='scheduled' AND expected_at + grace_minutes < NOW().
-- ---------------------------------------------------------------------
CREATE TABLE checkin_schedules (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  elderly_id         INT UNSIGNED NOT NULL,
  caregiver_id       INT UNSIGNED NULL DEFAULT NULL
                     COMMENT 'responsible caregiver snapshot at creation',
  title              VARCHAR(150) NULL DEFAULT 'Welfare check-in',
  expected_at        DATETIME NOT NULL,
  grace_minutes      INT UNSIGNED NOT NULL DEFAULT 120,
  escalation_minutes INT UNSIGNED NOT NULL DEFAULT 240,
  status             ENUM('scheduled','completed','missed','cancelled') NOT NULL DEFAULT 'scheduled',
  notes              TEXT NULL DEFAULT NULL,
  created_by         INT UNSIGNED NULL DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_schedule_elderly_expected (elderly_id, expected_at),
  INDEX idx_schedule_status_expected (status, expected_at),
  INDEX idx_schedule_caregiver (caregiver_id),
  CONSTRAINT fk_schedule_elderly FOREIGN KEY (elderly_id)
    REFERENCES elderly_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_schedule_caregiver FOREIGN KEY (caregiver_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_schedule_created_by FOREIGN KEY (created_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. checkin_records - actual submissions.
--    UNIQUE(schedule_id) allows many NULLs (ad-hoc) but only ONE record
--    per schedule (MySQL treats each NULL as distinct). Exactly the rule.
-- ---------------------------------------------------------------------
CREATE TABLE checkin_records (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_id        INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = ad-hoc check-in',
  elderly_id         INT UNSIGNED NOT NULL,
  submitted_by       INT UNSIGNED NULL DEFAULT NULL COMMENT 'who clicked submit',
  submitted_by_role  VARCHAR(20) NULL DEFAULT NULL
                     COMMENT 'role snapshot at submit time (audit)',
  checkin_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  method             ENUM('self_online','caregiver_entry','phone_logged','visit_logged')
                     NOT NULL DEFAULT 'self_online',
  wellbeing_status   ENUM('ok','needs_assistance','unwell')
                     NOT NULL DEFAULT 'ok' COMMENT 'self-reported flag, not a diagnosis',
  notes              TEXT NULL DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_checkin_schedule (schedule_id),
  INDEX idx_checkin_elderly_at (elderly_id, checkin_at),
  INDEX idx_checkin_submitter (submitted_by),
  CONSTRAINT fk_checkin_schedule FOREIGN KEY (schedule_id)
    REFERENCES checkin_schedules (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_checkin_elderly FOREIGN KEY (elderly_id)
    REFERENCES elderly_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_checkin_submitter FOREIGN KEY (submitted_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. alerts - prioritized worklist. Also stores the risk assessment
--    (priority + risk_score + reason + observations JSON) per Stage 1 D-7.
--    UNIQUE(schedule_id, alert_type): at most ONE missed_checkin alert
--    per schedule -> the monitor is idempotent by construction.
-- ---------------------------------------------------------------------
CREATE TABLE alerts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  elderly_id    INT UNSIGNED NOT NULL,
  schedule_id   INT UNSIGNED NULL DEFAULT NULL COMMENT 'set for missed_checkin; NULL for pattern/assistance',
  caregiver_id  INT UNSIGNED NULL DEFAULT NULL COMMENT 'notified caregiver (usually primary)',
  alert_type    ENUM('missed_checkin','pattern_flag','assistance_request','escalation') NOT NULL,
  priority      ENUM('routine','attention','urgent') NOT NULL DEFAULT 'attention'
                COMMENT 'Routine / Attention required / Urgent review (operational only)',
  risk_score    TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '0-100 transparent score',
  title         VARCHAR(150) NOT NULL,
  message       TEXT NOT NULL,
  reason        TEXT NULL DEFAULT NULL COMMENT 'human-readable scoring reasons',
  observations  TEXT NULL DEFAULT NULL COMMENT 'JSON snapshot of scoring inputs',
  status        ENUM('open','acknowledged','escalated','resolved','dismissed')
                NOT NULL DEFAULT 'open',
  escalated_to  INT UNSIGNED NULL DEFAULT NULL,
  escalated_at  DATETIME NULL DEFAULT NULL COMMENT 'set once; never overwritten',
  resolved_at   DATETIME NULL DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alert_schedule_type (schedule_id, alert_type),
  INDEX idx_alerts_status_priority (status, priority),
  INDEX idx_alerts_elderly_created (elderly_id, created_at),
  INDEX idx_alerts_caregiver_status (caregiver_id, status),
  CONSTRAINT fk_alerts_elderly FOREIGN KEY (elderly_id)
    REFERENCES elderly_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_alerts_schedule FOREIGN KEY (schedule_id)
    REFERENCES checkin_schedules (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_alerts_caregiver FOREIGN KEY (caregiver_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_alerts_escalated_to FOREIGN KEY (escalated_to)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. alert_acknowledgements - who confirmed which alert, when
-- ---------------------------------------------------------------------
CREATE TABLE alert_acknowledgements (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_id        INT UNSIGNED NOT NULL,
  acknowledged_by INT UNSIGNED NULL DEFAULT NULL,
  acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  action_taken    VARCHAR(100) NULL DEFAULT NULL,
  notes           TEXT NULL DEFAULT NULL,
  PRIMARY KEY (id),
  INDEX idx_ack_alert (alert_id),
  INDEX idx_ack_user (acknowledged_by),
  CONSTRAINT fk_ack_alert FOREIGN KEY (alert_id)
    REFERENCES alerts (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ack_user FOREIGN KEY (acknowledged_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. followup_actions - calls/visits/referrals per alert with outcomes.
--    elderly_id duplicates alerts.elderly_id (documented denormalization,
--    Stage 1 D-8) to keep reporting queries simple.
-- ---------------------------------------------------------------------
CREATE TABLE followup_actions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_id     INT UNSIGNED NOT NULL,
  elderly_id   INT UNSIGNED NOT NULL,
  caregiver_id INT UNSIGNED NULL DEFAULT NULL,
  action_type  ENUM('phone_call','home_visit','family_contact','referral','other') NOT NULL,
  action_date  DATETIME NOT NULL,
  outcome      TEXT NULL DEFAULT NULL COMMENT 'required before parent alert can resolve',
  next_steps   TEXT NULL DEFAULT NULL,
  status       ENUM('planned','completed','cancelled') NOT NULL DEFAULT 'completed',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_followup_alert (alert_id),
  INDEX idx_followup_elderly (elderly_id),
  CONSTRAINT fk_followup_alert FOREIGN KEY (alert_id)
    REFERENCES alerts (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_followup_elderly FOREIGN KEY (elderly_id)
    REFERENCES elderly_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_followup_caregiver FOREIGN KEY (caregiver_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. caregiver_visits - physical-visit log (history survives account
--     removal: caregiver_id is nullable with SET NULL)
-- ---------------------------------------------------------------------
CREATE TABLE caregiver_visits (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  elderly_id   INT UNSIGNED NOT NULL,
  caregiver_id INT UNSIGNED NULL DEFAULT NULL,
  alert_id     INT UNSIGNED NULL DEFAULT NULL COMMENT 'optional link to prompting alert',
  visit_date   DATETIME NOT NULL,
  visit_type   ENUM('routine','follow_up','emergency') NOT NULL DEFAULT 'routine',
  outcome      ENUM('well','needs_followup','referred','no_answer','other') NOT NULL DEFAULT 'well',
  notes        TEXT NULL DEFAULT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_visits_elderly_date (elderly_id, visit_date),
  INDEX idx_visits_caregiver (caregiver_id),
  CONSTRAINT fk_visits_elderly FOREIGN KEY (elderly_id)
    REFERENCES elderly_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_visits_caregiver FOREIGN KEY (caregiver_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_visits_alert FOREIGN KEY (alert_id)
    REFERENCES alerts (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. audit_logs - append-only trail (BIGINT id: this table grows fastest)
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = system/cron action',
  action      VARCHAR(100) NOT NULL COMMENT 'fixed vocabulary, e.g. login, acknowledge_alert',
  entity_type VARCHAR(50) NULL DEFAULT NULL,
  entity_id   VARCHAR(36) NULL DEFAULT NULL COMMENT 'text so INT and BIGINT ids both fit',
  details     TEXT NULL DEFAULT NULL COMMENT 'never passwords, hashes or tokens',
  ip_address  VARCHAR(45) NULL DEFAULT NULL COMMENT 'IPv4/IPv6, or cli for cron',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_audit_user (user_id),
  INDEX idx_audit_action (action),
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. system_settings - tunable defaults (the one justified extra table)
-- ---------------------------------------------------------------------
CREATE TABLE system_settings (
  setting_key VARCHAR(50) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  description TEXT NULL DEFAULT NULL,
  updated_by  INT UNSIGNED NULL DEFAULT NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SEED DATA - 100% SYNTHETIC, RELATIVE TIMESTAMPS (NOW() +/- INTERVAL)
-- Relative times mean the demo works whenever you import: there is always
-- one schedule due soon, overdue schedules for the monitor demo, and
-- history rows for dashboards. See docs/07 for the seed map.
-- =====================================================================

-- ---- Settings (Stage 1 confirmed defaults) ----
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
  ('default_grace_minutes',      '120',          'Lateness allowed before a schedule counts as overdue (minutes).'),
  ('default_escalation_minutes', '240',          'Unacknowledged time before escalation to the secondary contact (minutes).'),
  ('monitor_window_days',        '30',           'How far back the risk engine looks for history (days).'),
  ('urgent_threshold',           '60',           'Score >= this => Urgent review.'),
  ('attention_threshold',        '30',           'Score >= this => Attention required (else Routine).'),
  ('app_timezone_label',         'Africa/Lagos', 'Display label for the configured local time zone.');

-- ---- Users 1-3: admin + caregivers (no profile link) ----
-- Hashes are bcrypt cost 10 for: Admin123! / Care123! / Elder123!.
INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES
  ('System Administrator', 'admin@example.local', NULL,
   '$2b$10$2IJFwBifku0fCXKkFW4pnOkX/Wii7urOS.k66AV7ZBCpMtpHjDsEm',
   'admin', 'active'),
  ('Ruth Nabirye', 'ruth.caregiver@example.local', '+256 700 000101',
   '$2b$10$.izf4PKoy.t7yuwax.r.3.dD.YG3P8c03AlsAx.tCfE.jUyhn8gsW',
   'caregiver', 'active'),
  ('David Ochieng', 'david.caregiver@example.local', '+256 700 000102',
   '$2b$10$.izf4PKoy.t7yuwax.r.3.dD.YG3P8c03AlsAx.tCfE.jUyhn8gsW',
   'caregiver', 'active');

-- ---- Elderly profiles (all details FICTIONAL) ----
INSERT INTO elderly_profiles
  (full_name, date_of_birth, gender, address, phone,
   emergency_contact_name, emergency_contact_phone, emergency_contact_relationship,
   secondary_contact_user_id, living_situation, welfare_notes, status, created_by)
VALUES
  ('Mary Namono', '1948-03-14', 'female',
   'Plot 12, Ntinda Road, Kampala (FICTIONAL ADDRESS)', '+256 700 000201',
   'Peter Ssemakula', '+256 700 000202', 'son',
   3, 'lives_alone',
   'Uses a walking frame; prefers morning visits. (Synthetic demo notes.)',
   'active', 1),
  ('John Okello', '1945-11-02', 'male',
   'Plot 7, Gulu Avenue, Kampala (FICTIONAL ADDRESS)', '+256 700 000203',
   'Sarah Okello', '+256 700 000204', 'daughter',
   3, 'with_spouse',
   'Hard of hearing; speak clearly. (Synthetic demo notes.)',
   'active', 1),
  ('Grace Achieng', '1952-06-28', 'female',
   'Plot 3, Entebbe Road, Kampala (FICTIONAL ADDRESS)', '+256 700 000205',
   'Alice Auma', '+256 700 000206', 'neighbour',
   2, 'lives_alone',
   'Speaks Luo and English. (Synthetic demo notes.)',
   'active', 1);

-- ---- Users 4-5: elderly/family logins (linked to their ONE profile) ----
INSERT INTO users (full_name, email, phone, password_hash, role, elderly_profile_id, status) VALUES
  ('Mary Namono (Elderly)', 'mary.elderly@example.local', '+256 700 000201',
   '$2b$10$ZU1/S1HJAGDKjLWlCL6GfuBdgCq8ogXlAwvLmiEBz8N.00jZG8l6y',
   'elderly', 1, 'active'),
  ('Peter Ssemakula (Family)', 'peter.family@example.local', '+256 700 000202',
   '$2b$10$ZU1/S1HJAGDKjLWlCL6GfuBdgCq8ogXlAwvLmiEBz8N.00jZG8l6y',
   'elderly', 2, 'active');

-- ---- Assignments: Ruth -> Mary (primary) + John (primary);
--      David -> Mary (secondary) + Grace (primary) ----
INSERT INTO caregiver_assignments (elderly_id, caregiver_id, is_primary, status, assigned_by) VALUES
  (1, 2, 1, 'active', 1),
  (2, 2, 1, 'active', 1),
  (1, 3, 0, 'active', 1),
  (3, 3, 1, 'active', 1);

-- ---- Schedules (ids 1-7; relative to import time) ----
-- S2 and S5 are OVERDUE-but-still-scheduled on purpose: the Stage 6
-- monitor demo will mark them missed and raise alerts for them.
INSERT INTO checkin_schedules
  (elderly_id, caregiver_id, title, expected_at, grace_minutes, escalation_minutes, status, notes, created_by)
VALUES
  (1, 2, 'Morning check-in', NOW() + INTERVAL 2 HOUR, 120, 240, 'scheduled',
   'Due soon after import. (Synthetic.)', 1),                                            -- S1
  (1, 2, 'Evening check-in', NOW() - INTERVAL 5 HOUR, 120, 240, 'scheduled',
   'OVERDUE seed row: monitor demo will mark missed. (Synthetic.)', 1),                  -- S2
  (2, 2, 'Morning check-in', NOW() - INTERVAL 1 DAY, 120, 240, 'completed',
   'Completed seed row; see checkin_records. (Synthetic.)', 1),                           -- S3
  (2, 2, 'Morning check-in', NOW() + INTERVAL 1 DAY, 120, 240, 'scheduled',
   'Future schedule. (Synthetic.)', 1),                                                  -- S4
  (3, 3, 'Morning check-in', NOW() - INTERVAL 3 HOUR, 120, 240, 'scheduled',
   'OVERDUE seed row: monitor demo will mark missed. (Synthetic.)', 1),                  -- S5
  (3, 3, 'Morning check-in', NOW() - INTERVAL 2 DAY, 120, 240, 'completed',
   'Completed seed row; see checkin_records. (Synthetic.)', 1),                           -- S6
  (2, 2, 'Morning check-in', NOW() - INTERVAL 8 DAY, 120, 240, 'missed',
   'Historical missed row with a RESOLVED alert (full lifecycle demo). (Synthetic.)', 1);-- S7

-- ---- Check-in records: R1 completes S3, R2 completes S6, R3 is ad-hoc ----
INSERT INTO checkin_records
  (schedule_id, elderly_id, submitted_by, submitted_by_role, checkin_at, method, wellbeing_status, notes)
VALUES
  (3, 2, 5, 'elderly', NOW() - INTERVAL 23 HOUR, 'self_online', 'ok',
   'All well. Submitted by family login. (Synthetic.)'),
  (6, 3, 3, 'caregiver', NOW() - INTERVAL 47 HOUR, 'caregiver_entry', 'ok',
   'Recorded by caregiver during routine visit. (Synthetic.)'),
  (NULL, 1, 4, 'elderly', NOW() - INTERVAL 26 HOUR, 'self_online', 'ok',
   'Ad-hoc check-in from elderly page. (Synthetic.)');

-- ---- Visits ----
INSERT INTO caregiver_visits (elderly_id, caregiver_id, alert_id, visit_date, visit_type, outcome, notes) VALUES
  (1, 2, NULL, NOW() - INTERVAL 5 DAY, 'routine', 'well',
   'Routine visit; all well. (Synthetic.)'),
  (2, 2, NULL, NOW() - INTERVAL 10 DAY, 'routine', 'well',
   'Routine visit. (Synthetic.)'),
  (3, 3, NULL, NOW() - INTERVAL 2 DAY, 'routine', 'well',
   'Routine visit; recorded check-in R2. (Synthetic.)');

-- ---- Alerts ----
-- A1: OPEN pattern flag for Grace (dashboard demo + unacknowledged input).
-- A2: RESOLVED missed-check-in for S7 (full lifecycle demo w/ ack + follow-up).
INSERT INTO alerts
  (elderly_id, schedule_id, caregiver_id, alert_type, priority, risk_score,
   title, message, reason, observations, status, resolved_at)
VALUES
  (3, NULL, 3, 'pattern_flag', 'attention', 40,
   'Pattern change: fewer check-ins than usual',
   'Grace Achieng submitted fewer check-ins this week than her usual pattern. Please review her history and consider a call. (Synthetic seed demonstration row.)',
   'Check-in pattern changed vs usual; 1 missed check-in in 7 days',
   '{"missed_7d": 1, "hours_since_success": 47, "overdue_rate_30d": 0.2, "deviation_flag": 1, "days_since_visit": 2, "has_unacked": 0, "help_flag": 0, "scored_at": "seed-data", "note": "Synthetic seed demonstration row"}',
   'open', NULL),
  (2, 7, 2, 'missed_checkin', 'attention', 35,
   'Missed check-in: Morning check-in',
   'No valid check-in was recorded for the expected morning check-in (8 days before import). The case was followed up and resolved. (Synthetic seed history.)',
   '1 missed check-in in 7 days; No successful check-in for 60h (at scoring time)',
   '{"missed_7d": 1, "hours_since_success": 60, "overdue_rate_30d": 0.25, "deviation_flag": 0, "days_since_visit": 3, "has_unacked": 0, "help_flag": 0, "scored_at": "seed-data", "note": "Synthetic seed history"}',
   'resolved', NOW() - INTERVAL 7 DAY);

-- ---- Acknowledgement + follow-up completing the A2 lifecycle ----
INSERT INTO alert_acknowledgements (alert_id, acknowledged_by, acknowledged_at, action_taken, notes) VALUES
  (2, 2, NOW() - INTERVAL 7 DAY + INTERVAL 1 HOUR, 'Called elderly person',
   'Spoke on the phone; all well, had travelled to family. (Synthetic.)');

INSERT INTO followup_actions
  (alert_id, elderly_id, caregiver_id, action_type, action_date, outcome, next_steps, status)
VALUES
  (2, 2, 2, 'phone_call', NOW() - INTERVAL 7 DAY + INTERVAL 1 HOUR,
   'Reached by phone; no assistance needed.',
   'Continue routine schedule. (Synthetic.)', 'completed');

-- ---- Audit trail seed (labelled synthetic; real rows start at Stage 3) ----
INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES
  (NULL, 'seed', 'database', NULL,
   'Synthetic demonstration data loaded by elderly_welfare.sql. (Synthetic.)', 'cli'),
  (1, 'create_elderly', 'elderly_profiles', '1',
   'Seed example entry: profile created. (Synthetic.)', '127.0.0.1');

-- =====================================================================
-- VERIFICATION (run these after import; expected counts in brackets).
-- Copy-paste into phpMyAdmin > SQL to confirm the seed loaded:
--
--   SELECT COUNT(*) AS users FROM users;                               -- 5
--   SELECT COUNT(*) AS profiles FROM elderly_profiles;                 -- 3
--   SELECT COUNT(*) AS assignments FROM caregiver_assignments;         -- 4
--   SELECT status, COUNT(*) FROM checkin_schedules GROUP BY status;    -- scheduled 4, completed 2, missed 1
--   SELECT COUNT(*) AS checkins FROM checkin_records;                  -- 3
--   SELECT status, COUNT(*) FROM alerts GROUP BY status;               -- open 1, resolved 1
--   SELECT setting_key, setting_value FROM system_settings;            -- 6 rows
--   SELECT email, role, status FROM users ORDER BY id;                 -- 5 demo accounts
--   SELECT @@session.time_zone AS session_tz, NOW() AS now_local;      -- +03:00 + local time
-- =====================================================================
