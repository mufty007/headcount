-- TICKET-005: Add campaign tracking to email_logs and marketing fields to email_templates
-- Run after 030_email_campaigns.sql (email_campaigns table must exist).

-- email_logs: link to campaign and store SMTP message id for webhook correlation
ALTER TABLE `email_logs`
  ADD COLUMN `campaign_id` INT UNSIGNED NULL AFTER `event_id`,
  ADD COLUMN `smtp_message_id` VARCHAR(255) NULL AFTER `error_message`,
  ADD INDEX `idx_campaign_id` (`campaign_id`);

ALTER TABLE `email_logs`
  ADD CONSTRAINT `fk_email_logs_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE SET NULL;

-- email_templates: name and thumbnail for marketing template gallery; design_json for Unlayer
ALTER TABLE `email_templates`
  ADD COLUMN `name` VARCHAR(255) NULL AFTER `subject`,
  ADD COLUMN `thumbnail_path` VARCHAR(500) NULL AFTER `body_html`,
  ADD COLUMN `design_json` LONGTEXT NULL COMMENT 'Unlayer design JSON' AFTER `thumbnail_path`;
