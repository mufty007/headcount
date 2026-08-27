-- Events and programs can link to multiple facilities. Pivot is the source of truth;
-- events.facility_id remains as a denormalized first facility for older queries.

CREATE TABLE IF NOT EXISTS `event_facilities` (
  `event_id` INT UNSIGNED NOT NULL,
  `facility_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`, `facility_id`),
  KEY `idx_event_facilities_facility` (`facility_id`),
  CONSTRAINT `fk_event_facilities_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_facilities_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `program_facilities` (
  `program_id` INT UNSIGNED NOT NULL,
  `facility_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`program_id`, `facility_id`),
  KEY `idx_program_facilities_facility` (`facility_id`),
  CONSTRAINT `fk_program_facilities_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_program_facilities_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `event_facilities` (`event_id`, `facility_id`)
SELECT `id`, `facility_id`
FROM `events`
WHERE `facility_id` IS NOT NULL AND `facility_id` > 0;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_requests' AND COLUMN_NAME = 'facility_ids');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `event_requests` ADD COLUMN `facility_ids` TEXT NULL COMMENT ''JSON array of facility IDs'' AFTER `location`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
