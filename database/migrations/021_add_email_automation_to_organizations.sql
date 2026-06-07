-- Email automation toggles per organization (Admin > Email > Automation)
-- Run when organizations table exists. Safe to skip if columns already exist.

ALTER TABLE `organizations`
  ADD COLUMN `email_reminders_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `smtp_reply_to`,
  ADD COLUMN `reminder_1week` TINYINT(1) NOT NULL DEFAULT 1 AFTER `email_reminders_enabled`,
  ADD COLUMN `reminder_1day` TINYINT(1) NOT NULL DEFAULT 1 AFTER `reminder_1week`,
  ADD COLUMN `reminder_2hours` TINYINT(1) NOT NULL DEFAULT 0 AFTER `reminder_1day`;
