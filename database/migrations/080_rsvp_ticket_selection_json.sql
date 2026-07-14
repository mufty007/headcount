-- Store ticket type selections on RSVPs (free and paid optional-pricing events).
-- Idempotent: safe to re-run.

SET @db = DATABASE();

SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'rsvps' AND COLUMN_NAME = 'ticket_selection_json');
SET @sql = IF(@exists = 0,
  'ALTER TABLE `rsvps` ADD COLUMN `ticket_selection_json` LONGTEXT NULL DEFAULT NULL AFTER `guest_count`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
