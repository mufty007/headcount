-- Facility booking food-safety waiver (member/guest bookings).
-- Org-configurable text; acceptance stored on facility_bookings.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'facility_waiver_enabled');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `organizations` ADD COLUMN `facility_waiver_enabled` TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'facility_waiver_checkbox_label');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `organizations` ADD COLUMN `facility_waiver_checkbox_label` VARCHAR(500) NOT NULL DEFAULT ''I have read, understood, and agree to this waiver'' AFTER `facility_waiver_enabled`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'facility_waiver_full_text');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `organizations` ADD COLUMN `facility_waiver_full_text` MEDIUMTEXT NULL AFTER `facility_waiver_checkbox_label`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_bookings' AND COLUMN_NAME = 'waiver_accepted_at');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `facility_bookings` ADD COLUMN `waiver_accepted_at` DATETIME NULL DEFAULT NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_bookings' AND COLUMN_NAME = 'waiver_contact_person');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `facility_bookings` ADD COLUMN `waiver_contact_person` VARCHAR(255) NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_bookings' AND COLUMN_NAME = 'waiver_phone');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `facility_bookings` ADD COLUMN `waiver_phone` VARCHAR(50) NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_bookings' AND COLUMN_NAME = 'waiver_setup_location');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `facility_bookings` ADD COLUMN `waiver_setup_location` ENUM(''indoor_foyer'',''outdoor_canopy'',''other'') NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_bookings' AND COLUMN_NAME = 'waiver_setup_other');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `facility_bookings` ADD COLUMN `waiver_setup_other` VARCHAR(255) NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facility_bookings' AND COLUMN_NAME = 'waiver_applicant_signature');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `facility_bookings` ADD COLUMN `waiver_applicant_signature` VARCHAR(255) NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
