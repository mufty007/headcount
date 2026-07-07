-- Per-automation email template assignments for event reminders.
-- reminder_custom_schedule JSON may include optional template_id per step:
--   { "value": 3, "unit": "days", "template_id": 42 }

ALTER TABLE `organizations`
  ADD COLUMN `reminder_milestone_templates` JSON NULL DEFAULT NULL AFTER `reminder_custom_schedule`;

-- Allow custom dedup keys (custom_days_N, custom_hours_N) without ENUM drift.
ALTER TABLE `reminders`
  MODIFY COLUMN `reminder_type` VARCHAR(32) NOT NULL;
