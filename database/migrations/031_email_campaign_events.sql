-- TICKET-005: Email Marketing Module — email_campaign_events for webhook tracking
CREATE TABLE IF NOT EXISTS `email_campaign_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NOT NULL,
  `email_log_id` INT UNSIGNED NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `event_type` ENUM('delivered', 'opened', 'clicked', 'bounced', 'unsubscribed') NOT NULL,
  `event_at` DATETIME NOT NULL,
  `link_url` VARCHAR(2048) NULL COMMENT 'For click events',
  `smtp_message_id` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`email_log_id`) REFERENCES `email_logs` (`id`) ON DELETE SET NULL,
  INDEX `idx_campaign_id` (`campaign_id`),
  INDEX `idx_email_log_id` (`email_log_id`),
  INDEX `idx_recipient_email` (`recipient_email`),
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_event_at` (`event_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
