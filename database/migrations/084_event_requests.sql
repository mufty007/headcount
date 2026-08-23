-- Event request approval workflow: staff proposals, review comments, draft event on approve.

CREATE TABLE IF NOT EXISTS `event_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `submitted_by` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `event_date` DATE NOT NULL,
  `start_time` TIME NULL,
  `end_time` TIME NULL,
  `location` VARCHAR(500) NULL,
  `category` VARCHAR(50) NULL,
  `budget` DECIMAL(12,2) NULL,
  `target_attendance` INT UNSIGNED NULL,
  `target_audience` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `status` ENUM('pending', 'changes_requested', 'approved', 'declined') NOT NULL DEFAULT 'pending',
  `reviewed_by` INT UNSIGNED NULL,
  `reviewed_at` DATETIME NULL,
  `reviewer_comment` TEXT NULL,
  `event_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_requests_org_status` (`organization_id`, `status`),
  KEY `idx_event_requests_submitter` (`submitted_by`),
  KEY `idx_event_requests_event` (`event_id`),
  CONSTRAINT `fk_event_requests_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_requests_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_event_requests_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_event_requests_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_request_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(32) NOT NULL,
  `message` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_request_comments_request` (`request_id`, `created_at`),
  CONSTRAINT `fk_event_request_comments_request` FOREIGN KEY (`request_id`) REFERENCES `event_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_request_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `notifications`
  MODIFY COLUMN `type` ENUM(
    'event_reminder',
    'new_rsvp',
    'event_cancelled',
    'payment_received',
    'member_added',
    'system',
    'info',
    'checklist_assigned',
    'event_request'
  ) NOT NULL DEFAULT 'info';
