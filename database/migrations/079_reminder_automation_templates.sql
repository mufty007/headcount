-- Per-automation email template assignments for event reminders.
-- reminder_custom_schedule JSON may include optional template_id per step:
--   { "value": 3, "unit": "days", "template_id": 42 }
-- Idempotent: safe to re-run (ensureAutomationSchema may have added the column already).

SET @db = DATABASE();

SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'reminder_milestone_templates');
SET @sql = IF(@exists = 0,
  'ALTER TABLE `organizations` ADD COLUMN `reminder_milestone_templates` JSON NULL DEFAULT NULL AFTER `reminder_custom_schedule`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Allow custom dedup keys (custom_days_N, custom_hours_N) without ENUM drift.
ALTER TABLE `reminders`
  MODIFY COLUMN `reminder_type` VARCHAR(32) NOT NULL;
