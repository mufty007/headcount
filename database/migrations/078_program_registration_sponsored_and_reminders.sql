-- Sponsored admin enrollments and incomplete-payment reminder tracking.
-- Idempotent: safe to re-run if columns were added manually or a prior run partially applied.

SET @db = DATABASE();

SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'program_registrations' AND COLUMN_NAME = 'enrollment_source');
SET @sql = IF(@exists = 0,
  'ALTER TABLE `program_registrations` ADD COLUMN `enrollment_source` ENUM(''self'',''sponsored'') NOT NULL DEFAULT ''self'' AFTER `coupon_code`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'program_registrations' AND COLUMN_NAME = 'sponsored_note');
SET @sql = IF(@exists = 0,
  'ALTER TABLE `program_registrations` ADD COLUMN `sponsored_note` VARCHAR(500) NULL AFTER `enrollment_source`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'program_registrations' AND COLUMN_NAME = 'payment_reminder_sent_at');
SET @sql = IF(@exists = 0,
  'ALTER TABLE `program_registrations` ADD COLUMN `payment_reminder_sent_at` TIMESTAMP NULL AFTER `sponsored_note`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'program_registrations' AND COLUMN_NAME = 'added_by_user_id');
SET @sql = IF(@exists = 0,
  'ALTER TABLE `program_registrations` ADD COLUMN `added_by_user_id` INT UNSIGNED NULL AFTER `payment_reminder_sent_at`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
