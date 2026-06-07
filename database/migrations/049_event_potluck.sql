-- Potluck / food signup: flag on events, category + note on rsvps.
-- Run after events and rsvps exist.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'is_potluck');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `events` ADD COLUMN `is_potluck` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rsvps' AND COLUMN_NAME = 'potluck_category');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rsvps` ADD COLUMN `potluck_category` VARCHAR(64) NULL DEFAULT NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rsvps' AND COLUMN_NAME = 'potluck_item_note');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rsvps` ADD COLUMN `potluck_item_note` VARCHAR(500) NULL DEFAULT NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
