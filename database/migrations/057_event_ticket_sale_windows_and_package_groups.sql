-- Per-ticket sale windows (early bird) and optional package_group for exclusive tier selection.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_ticket_types' AND COLUMN_NAME = 'sale_starts_at');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `event_ticket_types` ADD COLUMN `sale_starts_at` DATETIME NULL DEFAULT NULL AFTER `sort_order`',
  'SELECT 1 AS _skip_sale_starts_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_ticket_types' AND COLUMN_NAME = 'sale_ends_at');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `event_ticket_types` ADD COLUMN `sale_ends_at` DATETIME NULL DEFAULT NULL AFTER `sale_starts_at`',
  'SELECT 1 AS _skip_sale_ends_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_ticket_types' AND COLUMN_NAME = 'package_group');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `event_ticket_types` ADD COLUMN `package_group` VARCHAR(64) NULL DEFAULT NULL AFTER `sale_ends_at`',
  'SELECT 1 AS _skip_package_group');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_ticket_types' AND INDEX_NAME = 'idx_event_ticket_types_event_package');
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX `idx_event_ticket_types_event_package` ON `event_ticket_types` (`event_id`, `package_group`)',
  'SELECT 1 AS _skip_idx');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
