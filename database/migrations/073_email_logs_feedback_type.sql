-- Distinct email type for feedback request sends
ALTER TABLE `email_logs`
  MODIFY COLUMN `email_type` ENUM(
    'announcement', 'reminder', 'confirmation', 'receipt', 'cancellation', 'custom', 'event_feedback', 'follow_up'
  ) NOT NULL;
