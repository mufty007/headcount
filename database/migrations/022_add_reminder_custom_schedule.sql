-- Custom reminder schedule per organization (JSON array of { value, unit: 'days'|'hours' })
-- Run after 021_add_email_automation_to_organizations.sql. Safe to skip if column exists.

ALTER TABLE `organizations`
  ADD COLUMN `reminder_custom_schedule` JSON NULL DEFAULT NULL AFTER `reminder_2hours`;
