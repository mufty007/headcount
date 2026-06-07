-- Session registration mode for recurring series (parent event row)
-- independent: per-session RSVP as today
-- choose_one: at most one yes RSVP in the series per user
-- all_sessions: one action RSVPs yes to all published sessions in the series

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'session_registration_mode'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `events` ADD COLUMN `session_registration_mode` ENUM(''independent'',''choose_one'',''all_sessions'') NOT NULL DEFAULT ''independent''',
  'SELECT 1 AS _skip_session_mode'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
