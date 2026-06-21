-- Program weeks: selective enrollment, bundle pricing, prayer end times, breaks, guest registration.

CREATE TABLE IF NOT EXISTS `program_weeks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `price_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `capacity` INT UNSIGNED NULL,
  `session_dates` TEXT NULL COMMENT 'JSON array of YYYY-MM-DD session dates for this week',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_program_weeks_program` (`program_id`),
  FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `program_registration_weeks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `registration_id` INT UNSIGNED NOT NULL,
  `week_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reg_week` (`registration_id`, `week_id`),
  INDEX `idx_prw_week` (`week_id`),
  FOREIGN KEY (`registration_id`) REFERENCES `program_registrations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`week_id`) REFERENCES `program_weeks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `programs` ADD COLUMN `registration_mode` ENUM('whole_program','select_weeks') NOT NULL DEFAULT 'whole_program';
ALTER TABLE `programs` ADD COLUMN `bundle_all_weeks_price` DECIMAL(10,2) NULL;
ALTER TABLE `programs` ADD COLUMN `allow_guest_registration` BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE `programs` ADD COLUMN `session_end_time_mode` ENUM('clock','prayer') NOT NULL DEFAULT 'clock';
ALTER TABLE `programs` ADD COLUMN `session_end_prayer_name` VARCHAR(20) NULL;
ALTER TABLE `programs` ADD COLUMN `session_end_prayer_offset` INT NOT NULL DEFAULT 0;
ALTER TABLE `programs` ADD COLUMN `break_start_time` TIME NULL;
ALTER TABLE `programs` ADD COLUMN `break_end_time` TIME NULL;

ALTER TABLE `program_sessions` ADD COLUMN `week_id` INT UNSIGNED NULL;
ALTER TABLE `program_sessions` ADD COLUMN `break_start_time` TIME NULL;
ALTER TABLE `program_sessions` ADD COLUMN `break_end_time` TIME NULL;
