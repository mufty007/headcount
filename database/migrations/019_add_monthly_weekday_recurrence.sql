-- Add monthly_weekday recurrence type (e.g. "last Friday of month", "first Monday")
-- week_of_month: 1=first, 2=second, 3=third, 4=fourth, 5=last. days_of_week: single digit 0-6 (Sun-Sat).
-- Only runs if recurring_events exists (run 004_create_recurring_events_table.sql first if you see "table doesn't exist").

SET @table_exists = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'recurring_events'
);

SET @sql = IF(@table_exists > 0,
  'ALTER TABLE `recurring_events` MODIFY COLUMN `recurrence_type` ENUM(''daily'', ''weekly'', ''monthly'', ''monthly_weekday'', ''yearly'', ''custom'') NOT NULL',
  'SELECT ''Skipped: recurring_events table not found. Run migration 004 first.'' AS msg'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
