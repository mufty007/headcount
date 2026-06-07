-- Optional Yes/No step before potluck dish fields on RSVP.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'potluck_show_bringing_prompt');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `events` ADD COLUMN `potluck_show_bringing_prompt` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1=show Yes/No; 0=skip to dish fields''',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
