-- ============================================
-- MAGIC LINK TOKENS TABLE
-- ============================================
-- Stores passwordless authentication tokens for member portal
CREATE TABLE IF NOT EXISTS `magic_link_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `used_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  INDEX `idx_token` (`token`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_expires` (`expires_at`),
  INDEX `idx_used` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
