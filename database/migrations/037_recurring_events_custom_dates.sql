-- Extra session dates for recurrence_type = 'custom' (JSON array of YYYY-MM-DD, excluding parent event_date)
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recurring_events' AND COLUMN_NAME = 'custom_dates'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `recurring_events` ADD COLUMN `custom_dates` TEXT NULL COMMENT ''JSON array of additional session dates (Y-m-d)'' AFTER `days_of_week`',
  'SELECT 1 AS _skip_custom_dates'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
