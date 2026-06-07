-- Event speakers/organisers and program public presenters (name, title, image).
-- Run after events and programs tables exist.

CREATE TABLE IF NOT EXISTS `event_people` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `role` ENUM('speaker','organiser') NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `display_name` VARCHAR(255) NOT NULL,
  `title` VARCHAR(255) NULL,
  `image_path` VARCHAR(500) NULL COMMENT 'Relative upload path or absolute URL',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_event_role_sort` (`event_id`, `role`, `sort_order`),
  CONSTRAINT `fk_event_people_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `program_presenters` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` INT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `display_name` VARCHAR(255) NOT NULL,
  `title` VARCHAR(255) NULL,
  `image_path` VARCHAR(500) NULL COMMENT 'Relative upload path or absolute URL',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_program_sort` (`program_id`, `sort_order`),
  CONSTRAINT `fk_program_presenters_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
