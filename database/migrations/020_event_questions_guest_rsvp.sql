-- Event custom questions, RSVP guest count, and allow guest RSVP
-- Run after events and rsvps tables exist.

-- ============================================
-- 1. EVENT_QUESTIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `event_questions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `question_text` VARCHAR(500) NOT NULL,
  `question_type` ENUM('text', 'short_text', 'checkbox', 'number') NOT NULL DEFAULT 'short_text',
  `is_required` BOOLEAN NOT NULL DEFAULT FALSE,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  INDEX `idx_event_questions_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. RSVP_QUESTION_ANSWERS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `rsvp_question_answers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rsvp_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `answer_text` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`rsvp_id`) REFERENCES `rsvps` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`question_id`) REFERENCES `event_questions` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_rsvp_question` (`rsvp_id`, `question_id`),
  INDEX `idx_rsvp_answers_rsvp` (`rsvp_id`),
  INDEX `idx_rsvp_answers_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. ADD guest_count TO rsvps
-- ============================================
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rsvps' AND COLUMN_NAME = 'guest_count');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rsvps` ADD COLUMN `guest_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `notes`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 4. ADD allow_guest_rsvp TO events
-- ============================================
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'allow_guest_rsvp');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `events` ADD COLUMN `allow_guest_rsvp` BOOLEAN NOT NULL DEFAULT FALSE AFTER `registration_deadline`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
