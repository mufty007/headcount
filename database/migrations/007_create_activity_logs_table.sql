-- ============================================
-- ACTIVITY LOGS TABLE
-- ============================================
-- Tracks all user activities, emails sent, changes, and system events
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL COMMENT 'User who performed the action (NULL for system events)',
  `action_type` VARCHAR(50) NOT NULL COMMENT 'Type of action: email_sent, user_created, user_updated, user_deleted, event_created, event_updated, checkin, payment, login, logout, etc.',
  `entity_type` VARCHAR(50) NULL COMMENT 'Type of entity affected: user, event, email, payment, etc.',
  `entity_id` INT UNSIGNED NULL COMMENT 'ID of the affected entity',
  `description` TEXT NOT NULL COMMENT 'Human-readable description of the activity',
  `metadata` JSON NULL COMMENT 'Additional data about the activity (email details, changes made, etc.)',
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  INDEX `idx_organization` (`organization_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_action_type` (`action_type`),
  INDEX `idx_entity` (`entity_type`, `entity_id`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_org_created` (`organization_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
