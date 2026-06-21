-- Event-level dedup for post-event feedback emails
ALTER TABLE `reminders`
  MODIFY COLUMN `reminder_type` ENUM('1week', '1day', '2hours', 'feedback_1day') NOT NULL;
