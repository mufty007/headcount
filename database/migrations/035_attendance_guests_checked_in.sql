-- Add guests_checked_in to attendance (how many guests came with this attendee at check-in)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'guests_checked_in');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance` ADD COLUMN `guests_checked_in` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `notes`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
