-- Question options (choices) and conditional visibility for event RSVP questions.
-- Run after 020_event_questions_guest_rsvp.sql.

-- ============================================
-- 1. EVENT_QUESTION_OPTIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `event_question_options` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_id` INT UNSIGNED NOT NULL,
  `option_label` VARCHAR(255) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`question_id`) REFERENCES `event_questions` (`id`) ON DELETE CASCADE,
  INDEX `idx_question_options_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. EXTEND question_type AND ADD CONDITIONAL COLUMNS
-- ============================================
ALTER TABLE `event_questions`
  MODIFY COLUMN `question_type` ENUM('text', 'short_text', 'checkbox', 'number', 'radio', 'dropdown', 'multi_checkbox') NOT NULL DEFAULT 'short_text',
  ADD COLUMN `depends_on_question_id` INT UNSIGNED NULL DEFAULT NULL AFTER `sort_order`,
  ADD COLUMN `depends_on_value` VARCHAR(500) NULL DEFAULT NULL AFTER `depends_on_question_id`;

ALTER TABLE `event_questions`
  ADD INDEX `idx_event_questions_depends_on` (`depends_on_question_id`),
  ADD CONSTRAINT `fk_event_questions_depends_on`
  FOREIGN KEY (`depends_on_question_id`) REFERENCES `event_questions` (`id`) ON DELETE SET NULL;
