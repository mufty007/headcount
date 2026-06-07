-- TICKET-005: Email Marketing Module — email_unsubscribes for CAN-SPAM
CREATE TABLE IF NOT EXISTS `email_unsubscribes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `campaign_id` INT UNSIGNED NULL,
  `unsubscribed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_org_email` (`organization_id`, `email`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE SET NULL,
  INDEX `idx_organization` (`organization_id`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
