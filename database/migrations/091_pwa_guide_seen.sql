-- First-login PWA install guide (survives devices).

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'pwa_guide_seen_at');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `pwa_guide_seen_at` DATETIME NULL DEFAULT NULL AFTER `last_login_at`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
