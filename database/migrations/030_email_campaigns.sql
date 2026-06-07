-- TICKET-005: Email Marketing Module — email_campaigns table
CREATE TABLE IF NOT EXISTS `email_campaigns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL DEFAULT '',
  `subject` VARCHAR(500) NOT NULL,
  `body_html` LONGTEXT NOT NULL,
  `design_json` LONGTEXT NULL COMMENT 'Unlayer design JSON',
  `status` ENUM('draft', 'scheduled', 'sending', 'sent') NOT NULL DEFAULT 'draft',
  `scheduled_at` DATETIME NULL,
  `sent_at` DATETIME NULL,
  `audience_type` ENUM('all_members', 'event', 'manual', 'segment') NOT NULL DEFAULT 'all_members',
  `audience_config` JSON NULL COMMENT 'event_id, manual_emails[], group_id for segment',
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  INDEX `idx_organization` (`organization_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_sent_at` (`sent_at`),
  INDEX `idx_scheduled_at` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
