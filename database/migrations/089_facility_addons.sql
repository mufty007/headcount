-- Facility add-ons / packages and booking line-item snapshots.

CREATE TABLE IF NOT EXISTS `facility_addons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `facility_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `kind` ENUM('addon','package') NOT NULL DEFAULT 'addon',
  `package_items` TEXT NULL COMMENT 'JSON list of included items for packages',
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_facility_addons_facility` (`facility_id`, `active`, `sort_order`),
  CONSTRAINT `fk_facility_addons_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `facility_booking_addons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` INT UNSIGNED NOT NULL,
  `addon_id` INT UNSIGNED NULL,
  `title` VARCHAR(255) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_facility_booking_addons_booking` (`booking_id`),
  CONSTRAINT `fk_facility_booking_addons_booking` FOREIGN KEY (`booking_id`) REFERENCES `facility_bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_facility_booking_addons_addon` FOREIGN KEY (`addon_id`) REFERENCES `facility_addons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
