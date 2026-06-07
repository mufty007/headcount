-- Per-event subset of potluck category slugs (JSON array). NULL or empty = all categories (legacy behavior).

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'potluck_allowed_slugs');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `events` ADD COLUMN `potluck_allowed_slugs` JSON NULL DEFAULT NULL COMMENT ''JSON array of potluck category slugs; null/empty = all''',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
