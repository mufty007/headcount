-- Potluck extensions: quantity, serving side, party adults/children on rsvps.
-- Idempotent; run after 049_event_potluck.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rsvps' AND COLUMN_NAME = 'potluck_quantity');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rsvps` ADD COLUMN `potluck_quantity` SMALLINT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rsvps' AND COLUMN_NAME = 'potluck_serving_side');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rsvps` ADD COLUMN `potluck_serving_side` VARCHAR(16) NULL DEFAULT NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rsvps' AND COLUMN_NAME = 'potluck_party_adults');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rsvps` ADD COLUMN `potluck_party_adults` SMALLINT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rsvps' AND COLUMN_NAME = 'potluck_party_children');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rsvps` ADD COLUMN `potluck_party_children` SMALLINT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill existing potluck rows
UPDATE `rsvps`
SET `potluck_quantity` = 1
WHERE `potluck_category` IS NOT NULL AND TRIM(`potluck_category`) <> ''
  AND `potluck_quantity` IS NULL;

UPDATE `rsvps`
SET `potluck_serving_side` = 'both'
WHERE `potluck_category` IS NOT NULL AND TRIM(`potluck_category`) <> ''
  AND (`potluck_serving_side` IS NULL OR TRIM(`potluck_serving_side`) = '');

UPDATE `rsvps`
SET `potluck_party_adults` = 1 + COALESCE(`guest_count`, 0),
    `potluck_party_children` = 0
WHERE `potluck_category` IS NOT NULL AND TRIM(`potluck_category`) <> ''
  AND `potluck_party_adults` IS NULL;
