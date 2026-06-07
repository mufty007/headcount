-- Programs domain: classes, halaqahs, member-only registration, sessions, attendance, coupons
-- Run after organizations and users exist.

-- ============================================
-- PROGRAM CATEGORIES
-- ============================================
CREATE TABLE IF NOT EXISTS `program_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_org_slug` (`organization_id`, `slug`),
  INDEX `idx_org` (`organization_id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROGRAMS
-- ============================================
CREATE TABLE IF NOT EXISTS `programs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `banner_image` VARCHAR(500) NULL,
  `status` ENUM('draft','published','cancelled','archived') NOT NULL DEFAULT 'draft',
  `show_on_public_site` BOOLEAN NOT NULL DEFAULT TRUE,
  `location` VARCHAR(500) NULL,
  `is_virtual` BOOLEAN NOT NULL DEFAULT FALSE,
  `capacity` INT UNSIGNED NULL,
  `pricing_type` ENUM('free','one_time','recurring') NOT NULL DEFAULT 'free',
  `price_amount` DECIMAL(10,2) NULL,
  `billing_interval` ENUM('once','week','week_2','month') NOT NULL DEFAULT 'once',
  `recurrence_type` ENUM('none','weekly','biweekly','monthly') NOT NULL DEFAULT 'weekly',
  `session_start_time` TIME NULL,
  `session_end_time` TIME NULL,
  `session_days_of_week` VARCHAR(64) NULL COMMENT 'JSON array 0-6 Sun-Sat',
  `starts_on` DATE NULL,
  `ends_on` DATE NULL,
  `enrollment_starts_at` DATETIME NULL,
  `enrollment_ends_at` DATETIME NULL,
  `sessions_generated_until` DATE NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_org_status` (`organization_id`, `status`),
  INDEX `idx_category` (`category_id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `program_categories` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROGRAM SESSIONS
-- ============================================
CREATE TABLE IF NOT EXISTS `program_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` INT UNSIGNED NOT NULL,
  `session_date` DATE NOT NULL,
  `start_time` TIME NULL,
  `end_time` TIME NULL,
  `status` ENUM('scheduled','cancelled','completed') NOT NULL DEFAULT 'scheduled',
  `generated` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_program_date` (`program_id`, `session_date`),
  INDEX `idx_session_date` (`session_date`),
  FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROGRAM REGISTRATIONS
-- ============================================
CREATE TABLE IF NOT EXISTS `program_registrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('pending','active','cancelled','waitlist') NOT NULL DEFAULT 'pending',
  `stripe_checkout_session_id` VARCHAR(255) NULL,
  `stripe_payment_intent_id` VARCHAR(255) NULL,
  `stripe_subscription_id` VARCHAR(255) NULL,
  `coupon_code` VARCHAR(64) NULL,
  `joined_at` TIMESTAMP NULL,
  `cancelled_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_program_user` (`program_id`, `user_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROGRAM QUESTIONS & ANSWERS
-- ============================================
CREATE TABLE IF NOT EXISTS `program_questions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` INT UNSIGNED NOT NULL,
  `question_text` VARCHAR(500) NOT NULL,
  `question_type` ENUM('text','short_text','checkbox','number','radio','dropdown','multi_checkbox') NOT NULL DEFAULT 'short_text',
  `is_required` BOOLEAN NOT NULL DEFAULT FALSE,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_program` (`program_id`),
  FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `program_registration_answers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `registration_id` INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `answer_text` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reg_q` (`registration_id`, `question_id`),
  FOREIGN KEY (`registration_id`) REFERENCES `program_registrations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`question_id`) REFERENCES `program_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SESSION ATTENDANCE
-- ============================================
CREATE TABLE IF NOT EXISTS `program_session_attendance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_session_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('present','absent','excused') NOT NULL DEFAULT 'present',
  `recorded_by` INT UNSIGNED NOT NULL,
  `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_session_user` (`program_session_id`, `user_id`),
  INDEX `idx_user` (`user_id`),
  FOREIGN KEY (`program_session_id`) REFERENCES `program_sessions` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROGRAM STAFF (lead / coordinator per program)
-- ============================================
CREATE TABLE IF NOT EXISTS `program_staff` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('lead','coordinator') NOT NULL DEFAULT 'coordinator',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_program_user_role` (`program_id`, `user_id`),
  INDEX `idx_user` (`user_id`),
  FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROGRAM COUPONS (org-scoped)
-- ============================================
CREATE TABLE IF NOT EXISTS `program_coupons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `program_id` INT UNSIGNED NULL COMMENT 'NULL = all programs',
  `code` VARCHAR(64) NOT NULL,
  `percent_off` DECIMAL(5,2) NULL,
  `amount_off` DECIMAL(10,2) NULL,
  `valid_from` DATE NULL,
  `valid_until` DATE NULL,
  `max_redemptions` INT UNSIGNED NULL,
  `redemptions_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `stripe_coupon_id` VARCHAR(255) NULL,
  `active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_org_code` (`organization_id`, `code`),
  INDEX `idx_program` (`program_id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PROGRAM EMAIL RULES (automation hooks)
-- ============================================
CREATE TABLE IF NOT EXISTS `program_email_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` INT UNSIGNED NOT NULL,
  `trigger_type` VARCHAR(64) NOT NULL,
  `enabled` BOOLEAN NOT NULL DEFAULT TRUE,
  `offset_hours` INT NULL COMMENT 'e.g. reminder_24h before session',
  `subject_template` VARCHAR(500) NULL,
  `body_template` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_program` (`program_id`),
  FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- email_logs: optional program_id
-- ============================================
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_logs' AND COLUMN_NAME = 'program_id');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `email_logs` ADD COLUMN `program_id` INT UNSIGNED NULL AFTER `event_id`, ADD INDEX `idx_email_logs_program` (`program_id`)',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'email_logs' AND CONSTRAINT_NAME = 'fk_email_logs_program');
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `email_logs` ADD CONSTRAINT `fk_email_logs_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
