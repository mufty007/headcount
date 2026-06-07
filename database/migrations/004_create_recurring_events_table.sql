-- Create recurring_events table for recurring event patterns
-- Migration: 004_create_recurring_events_table.sql

CREATE TABLE IF NOT EXISTS `recurring_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_event_id` INT UNSIGNED NOT NULL COMMENT 'The original/template event',
  `organization_id` INT UNSIGNED NOT NULL,
  `recurrence_type` ENUM('daily', 'weekly', 'monthly', 'yearly', 'custom') NOT NULL,
  `interval` INT UNSIGNED DEFAULT 1 COMMENT 'Every N days/weeks/months/years',
  `end_type` ENUM('never', 'after_count', 'on_date') NOT NULL DEFAULT 'never',
  `end_after_count` INT UNSIGNED NULL COMMENT 'Number of occurrences',
  `end_date` DATE NULL COMMENT 'End date for recurrence',
  `days_of_week` VARCHAR(20) NULL COMMENT 'Comma-separated: 0=Sunday, 1=Monday, etc. For weekly recurrence',
  `day_of_month` INT UNSIGNED NULL COMMENT 'Day of month (1-31) for monthly recurrence',
  `week_of_month` INT UNSIGNED NULL COMMENT 'Week of month (1-5) for monthly recurrence',
  `month_of_year` INT UNSIGNED NULL COMMENT 'Month (1-12) for yearly recurrence',
  `is_active` BOOLEAN DEFAULT TRUE,
  `last_generated_date` DATE NULL COMMENT 'Last date for which events were generated',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`parent_event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  INDEX `idx_parent_event` (`parent_event_id`),
  INDEX `idx_organization` (`organization_id`),
  INDEX `idx_active` (`is_active`),
  INDEX `idx_last_generated` (`last_generated_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add parent_event_id and is_recurring_instance to events table (only if missing)
SET @add_parent = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'parent_event_id');
SET @sql = IF(@add_parent = 0, 'ALTER TABLE `events` ADD COLUMN `parent_event_id` INT UNSIGNED NULL AFTER `id`', 'SELECT 1 AS _skip');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_instance = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'is_recurring_instance');
SET @sql = IF(@add_instance = 0, 'ALTER TABLE `events` ADD COLUMN `is_recurring_instance` BOOLEAN DEFAULT FALSE AFTER `parent_event_id`', 'SELECT 1 AS _skip');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND INDEX_NAME = 'idx_parent_event');
SET @sql = IF(@add_idx = 0, 'ALTER TABLE `events` ADD INDEX `idx_parent_event` (`parent_event_id`)', 'SELECT 1 AS _skip');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'parent_event_id' AND REFERENCED_TABLE_NAME = 'events');
SET @sql = IF(@has_fk = 0, 'ALTER TABLE `events` ADD CONSTRAINT `fk_events_parent_event` FOREIGN KEY (`parent_event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE', 'SELECT 1 AS _skip');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
