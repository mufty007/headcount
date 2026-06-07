-- Household RSVP alignment, attendance per family slot, age/gender event restrictions

-- family_members.gender (same vocabulary as users.gender)
SET @fm_gender = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'family_members' AND COLUMN_NAME = 'gender');
SET @sql = IF(@fm_gender = 0,
  'ALTER TABLE `family_members` ADD COLUMN `gender` ENUM(''male'', ''female'', ''other'') NULL AFTER `date_of_birth`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- events: restrictions
SET @e_min = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'min_age');
SET @sql = IF(@e_min = 0,
  'ALTER TABLE `events` ADD COLUMN `min_age` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT ''Minimum age at event date; NULL = no min'' AFTER `registration_deadline`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @e_max = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'max_age');
SET @sql = IF(@e_max = 0,
  'ALTER TABLE `events` ADD COLUMN `max_age` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT ''Maximum age at event date; NULL = no max'' AFTER `min_age`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @e_gr = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'gender_restriction');
SET @sql = IF(@e_gr = 0,
  'ALTER TABLE `events` ADD COLUMN `gender_restriction` ENUM(''none'', ''male'', ''female'', ''other'') NOT NULL DEFAULT ''none'' AFTER `max_age`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @e_enf = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'enforce_restrictions_at_checkin');
SET @sql = IF(@e_enf = 0,
  'ALTER TABLE `events` ADD COLUMN `enforce_restrictions_at_checkin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `gender_restriction`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- rsvp_family_members junction
CREATE TABLE IF NOT EXISTS `rsvp_family_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rsvp_id` INT UNSIGNED NOT NULL,
  `family_member_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rsvp_family` (`rsvp_id`, `family_member_id`),
  KEY `idx_family_member` (`family_member_id`),
  CONSTRAINT `fk_rfm_rsvp` FOREIGN KEY (`rsvp_id`) REFERENCES `rsvps` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rfm_family` FOREIGN KEY (`family_member_id`) REFERENCES `family_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- attendance.family_member_id + composite uniqueness (party slot 0 = primary attendee)
SET @a_fmid = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'family_member_id');
SET @sql = IF(@a_fmid = 0,
  'ALTER TABLE `attendance` ADD COLUMN `family_member_id` INT UNSIGNED NULL COMMENT ''Non-null = check-in for this family member under user_id (parent)'' AFTER `user_id`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @a_fk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND CONSTRAINT_NAME = 'fk_attendance_family_member');
SET @sql = IF(@a_fk = 0,
  'ALTER TABLE `attendance` ADD CONSTRAINT `fk_attendance_family_member` FOREIGN KEY (`family_member_id`) REFERENCES `family_members` (`id`) ON DELETE SET NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @a_slot = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'attendance_party_slot');
SET @sql = IF(@a_slot = 0,
  'ALTER TABLE `attendance` ADD COLUMN `attendance_party_slot` INT UNSIGNED GENERATED ALWAYS AS (IFNULL(`family_member_id`, 0)) STORED',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_old = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'unique_event_user');
SET @sql = IF(@idx_old > 0,
  'ALTER TABLE `attendance` DROP INDEX `unique_event_user`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_new = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'unique_event_user_party');
SET @sql = IF(@idx_new = 0,
  'ALTER TABLE `attendance` ADD UNIQUE KEY `unique_event_user_party` (`event_id`, `user_id`, `attendance_party_slot`)',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
