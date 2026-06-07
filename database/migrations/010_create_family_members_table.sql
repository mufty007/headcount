-- ============================================
-- FAMILY MEMBERS TABLE
-- ============================================
-- Allows members to register family members under their account
CREATE TABLE IF NOT EXISTS `family_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_user_id` INT UNSIGNED NOT NULL COMMENT 'The main account holder',
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `date_of_birth` DATE NULL,
  `relationship` VARCHAR(50) NULL COMMENT 'spouse, child, sibling, parent, other',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`parent_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  INDEX `idx_parent_user` (`parent_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
