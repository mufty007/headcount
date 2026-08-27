-- Program request workflow (mirrors event requests) plus program_request notifications.

CREATE TABLE IF NOT EXISTS `program_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `submitted_by` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `starts_on` DATE NOT NULL,
  `session_start_time` TIME NULL,
  `session_end_time` TIME NULL,
  `location` VARCHAR(500) NULL,
  `facility_ids` TEXT NULL COMMENT 'JSON array of facility IDs',
  `notes` TEXT NULL,
  `status` ENUM('pending', 'changes_requested', 'approved', 'declined') NOT NULL DEFAULT 'pending',
  `reviewed_by` INT UNSIGNED NULL,
  `reviewed_at` DATETIME NULL,
  `reviewer_comment` TEXT NULL,
  `program_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_program_requests_org_status` (`organization_id`, `status`),
  KEY `idx_program_requests_submitter` (`submitted_by`),
  KEY `idx_program_requests_program` (`program_id`),
  CONSTRAINT `fk_program_requests_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_program_requests_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_program_requests_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_program_requests_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `program_request_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(32) NOT NULL,
  `message` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_program_request_comments_request` (`request_id`),
  CONSTRAINT `fk_program_request_comments_request` FOREIGN KEY (`request_id`) REFERENCES `program_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_program_request_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
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
    'event_request',
    'program_request'
  ) NOT NULL DEFAULT 'info';
