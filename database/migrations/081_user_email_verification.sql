-- ============================================
-- USER EMAIL VERIFICATION
-- ============================================
-- Portal self-registration must verify email before login.
-- Existing users are backfilled as already verified.

ALTER TABLE `users`
  ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT NULL AFTER `status`;

UPDATE `users`
SET `email_verified_at` = `created_at`
WHERE `email_verified_at` IS NULL;

CREATE TABLE IF NOT EXISTS `email_verification_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `used_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_token` (`token`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_expires` (`expires_at`),
  INDEX `idx_used` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
