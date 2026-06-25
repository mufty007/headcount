-- Schedule change notification template type
ALTER TABLE `email_templates`
  MODIFY COLUMN `template_type` ENUM(
    'announcement', 'reminder_1week', 'reminder_1day', 'reminder_2hours',
    'confirmation', 'receipt', 'cancellation', 'custom', 'follow_up', 'event_feedback', 'schedule_change'
  ) NOT NULL;

ALTER TABLE `email_logs`
  MODIFY COLUMN `email_type` ENUM(
    'announcement', 'reminder', 'confirmation', 'receipt', 'cancellation', 'custom', 'event_feedback', 'follow_up', 'schedule_change'
  ) NOT NULL;
