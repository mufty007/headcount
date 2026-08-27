-- Unified org-level coupons (events, programs, facilities) plus analytics.

CREATE TABLE IF NOT EXISTS `coupons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(64) NOT NULL,
  `percent_off` DECIMAL(5,2) NULL,
  `amount_off` DECIMAL(10,2) NULL,
  `applies_to_events` TINYINT(1) NOT NULL DEFAULT 0,
  `applies_to_programs` TINYINT(1) NOT NULL DEFAULT 0,
  `applies_to_facilities` TINYINT(1) NOT NULL DEFAULT 0,
  `valid_from` DATE NULL,
  `valid_until` DATE NULL,
  `max_redemptions` INT UNSIGNED NULL,
  `max_per_user` INT UNSIGNED NULL,
  `redemptions_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_coupons_org_code` (`organization_id`, `code`),
  KEY `idx_coupons_org_active` (`organization_id`, `active`),
  CONSTRAINT `fk_coupons_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_targets` (
  `coupon_id` INT UNSIGNED NOT NULL,
  `entity_type` ENUM('event','program','facility') NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`coupon_id`, `entity_type`, `entity_id`),
  CONSTRAINT `fk_coupon_targets_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_users` (
  `coupon_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`coupon_id`, `user_id`),
  CONSTRAINT `fk_coupon_users_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coupon_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_redemptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` INT UNSIGNED NOT NULL,
  `organization_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `entity_type` VARCHAR(32) NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `discounted_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `used_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coupon_redemptions_coupon` (`coupon_id`),
  KEY `idx_coupon_redemptions_org` (`organization_id`),
  CONSTRAINT `fk_coupon_redemptions_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coupon_redemptions_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coupon_redemptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `coupons` (
  `organization_id`, `code`, `percent_off`, `amount_off`,
  `applies_to_events`, `applies_to_programs`, `applies_to_facilities`,
  `valid_from`, `valid_until`, `max_redemptions`, `redemptions_count`, `active`
)
SELECT
  `organization_id`, UPPER(TRIM(`code`)), `percent_off`, `amount_off`,
  0, 1, 0,
  `valid_from`, `valid_until`, `max_redemptions`, `redemptions_count`, `active`
FROM `program_coupons`
WHERE `code` IS NOT NULL AND TRIM(`code`) <> '';

INSERT IGNORE INTO `coupon_targets` (`coupon_id`, `entity_type`, `entity_id`)
SELECT c.id, 'program', pc.program_id
FROM program_coupons pc
INNER JOIN coupons c
  ON c.organization_id = pc.organization_id AND c.code = UPPER(TRIM(pc.code))
WHERE pc.program_id IS NOT NULL AND pc.program_id > 0;
