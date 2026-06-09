-- Community Events & Attendance Management Platform
-- Database Schema (Based on Database Architect Specifications)
-- Database: headcount_dev

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================
-- 1. ORGANIZATIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `organizations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `logo_path` VARCHAR(500) NULL,
  `primary_color` VARCHAR(7) DEFAULT '#3B82F6',
  `timezone` VARCHAR(50) DEFAULT 'America/New_York',
  `date_format` VARCHAR(20) DEFAULT 'Y-m-d',
  `time_format` VARCHAR(20) DEFAULT 'H:i',
  `stripe_publishable_key` VARCHAR(255) NULL,
  `stripe_secret_key_encrypted` TEXT NULL,
  `stripe_test_mode` BOOLEAN DEFAULT TRUE,
  `smtp_api_key_encrypted` TEXT NULL,
  `smtp_from_email` VARCHAR(255) NULL,
  `smtp_from_name` VARCHAR(255) NULL,
  `smtp_reply_to` VARCHAR(255) NULL,
  `kiosk_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `kiosk_mode` VARCHAR(20) NOT NULL DEFAULT 'board',
  `kiosk_days` INT UNSIGNED NOT NULL DEFAULT 7,
  `kiosk_interval` INT UNSIGNED NOT NULL DEFAULT 8,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_slug` (`slug`),
  INDEX `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. USERS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NULL,
  `phone` VARCHAR(20) NULL,
  `gender` ENUM('male', 'female', 'other') NULL,
  `password_hash` VARCHAR(255) NULL COMMENT 'NULL for members, hashed for admins',
  `role` ENUM('admin', 'coordinator', 'member') NOT NULL DEFAULT 'member',
  `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=org owner, always full access, cannot be locked out',
  `status` ENUM('active', 'inactive', 'deleted') NOT NULL DEFAULT 'active',
  `last_login_at` TIMESTAMP NULL,
  `failed_login_attempts` INT UNSIGNED DEFAULT 0,
  `locked_until` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  INDEX `idx_organization` (`organization_id`),
  INDEX `idx_email` (`email`),
  INDEX `idx_phone` (`phone`),
  INDEX `idx_name` (`first_name`, `last_name`),
  INDEX `idx_status` (`status`),
  UNIQUE KEY `unique_org_email` (`organization_id`, `email`)
  -- Note: Phone numbers are NOT unique - multiple members can share the same phone (e.g., family members)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. EVENTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `event_date` DATE NOT NULL,
  `start_time` TIME NULL,
  `end_time` TIME NULL,
  `location` VARCHAR(500) NOT NULL,
  `facility_id` INT UNSIGNED NULL,
  `category` VARCHAR(50) DEFAULT 'other',
  `capacity` INT UNSIGNED NULL,
  `ticket_price` DECIMAL(10, 2) DEFAULT 0.00,
  `pricing_model` ENUM('per_person', 'headcount_tier') NOT NULL DEFAULT 'per_person',
  `headcount_pricing_tiers` JSON NULL,
  `registration_required` BOOLEAN DEFAULT FALSE,
  `registration_deadline` DATETIME NULL,
  `status` ENUM('draft', 'published', 'cancelled', 'completed') NOT NULL DEFAULT 'draft',
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  INDEX `idx_organization` (`organization_id`),
  INDEX `idx_date` (`event_date`),
  INDEX `idx_status` (`status`),
  INDEX `idx_category` (`category`),
  INDEX `idx_created_by` (`created_by`),
  INDEX `idx_events_facility_date` (`facility_id`, `event_date`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. RSVPS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `rsvps` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('yes', 'no', 'maybe') NOT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_event_user` (`event_id`, `user_id`),
  INDEX `idx_event` (`event_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. ATTENDANCE TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `checked_in_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `checked_in_by` INT UNSIGNED NOT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  UNIQUE KEY `unique_event_user` (`event_id`, `user_id`),
  INDEX `idx_event` (`event_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_checked_in` (`checked_in_at`),
  INDEX `idx_event_date` (`event_id`, `checked_in_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. PAYMENTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `attendance_id` INT UNSIGNED NULL,
  `stripe_payment_intent_id` VARCHAR(255) NOT NULL,
  `stripe_checkout_session_id` VARCHAR(255) NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `currency` VARCHAR(3) DEFAULT 'USD',
  `status` ENUM('pending', 'paid', 'refunded', 'failed') DEFAULT 'pending',
  `refund_amount` DECIMAL(10, 2) DEFAULT 0.00,
  `refunded_at` TIMESTAMP NULL,
  `refund_reason` TEXT NULL,
  `checkout_pending_json` LONGTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE SET NULL,
  INDEX `idx_event` (`event_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_stripe_payment_intent` (`stripe_payment_intent_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. EMAIL LOGS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `event_id` INT UNSIGNED NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `recipient_user_id` INT UNSIGNED NULL,
  `subject` VARCHAR(500) NOT NULL,
  `email_type` ENUM('announcement', 'reminder', 'confirmation', 'receipt', 'cancellation', 'custom') NOT NULL,
  `status` ENUM('sent', 'failed', 'queued') DEFAULT 'queued',
  `error_message` TEXT NULL,
  `sent_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  INDEX `idx_organization` (`organization_id`),
  INDEX `idx_event` (`event_id`),
  INDEX `idx_recipient` (`recipient_email`),
  INDEX `idx_status` (`status`),
  INDEX `idx_sent_at` (`sent_at`),
  INDEX `idx_email_type` (`email_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. EMAIL TEMPLATES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `template_type` ENUM('announcement', 'reminder_1week', 'reminder_1day', 'reminder_2hours', 'confirmation', 'receipt', 'cancellation', 'custom') NOT NULL,
  `subject` VARCHAR(500) NOT NULL,
  `body_html` TEXT NOT NULL,
  `body_text` TEXT NULL,
  `is_default` BOOLEAN DEFAULT FALSE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  INDEX `idx_organization` (`organization_id`),
  INDEX `idx_template_type` (`template_type`),
  UNIQUE KEY `unique_org_template` (`organization_id`, `template_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. REMINDERS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `reminders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `reminder_type` ENUM('1week', '1day', '2hours') NOT NULL,
  `scheduled_for` DATETIME NOT NULL,
  `status` ENUM('pending', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
  `sent_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  INDEX `idx_event` (`event_id`),
  INDEX `idx_scheduled_for` (`scheduled_for`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. AUDIT LOGS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `old_values` JSON NULL,
  `new_values` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  INDEX `idx_organization` (`organization_id`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_entity` (`entity_type`, `entity_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. MIGRATIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration_name` VARCHAR(255) NOT NULL,
  `batch` INT UNSIGNED NOT NULL,
  `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_migration` (`migration_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 12. PASSWORD RESETS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `used_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_token` (`token`),
  INDEX `idx_user` (`user_id`),
  INDEX `idx_expires` (`expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 13. ROLE PERMISSIONS TABLE (granular access control - per-role overrides)
-- ============================================
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `role` ENUM('admin', 'coordinator') NOT NULL,
  `permission_key` VARCHAR(64) NOT NULL,
  `granted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_org_role_perm` (`organization_id`, `role`, `permission_key`),
  INDEX `idx_org_role` (`organization_id`, `role`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 14. USER PERMISSIONS TABLE (granular access control - per-user overrides)
-- ============================================
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `permission_key` VARCHAR(64) NOT NULL,
  `granted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_perm` (`user_id`, `permission_key`),
  INDEX `idx_org_user` (`organization_id`, `user_id`),
  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ADDITIONAL COMPOSITE INDEXES FOR PERFORMANCE
-- ============================================
-- Note: These indexes are optional performance optimizations
-- If you get "Duplicate key name" errors, the indexes already exist
-- You can safely comment out or skip these lines if running the schema multiple times
-- Or use the migration file: database/migrations/001_add_performance_indexes.sql

-- Member search optimization (composite index for fast member searches)
-- CREATE INDEX `idx_member_search` ON `users`(`organization_id`, `first_name`, `last_name`, `email`, `phone`);

-- Event attendance with user info (optimizes attendance queries)
-- CREATE INDEX `idx_attendance_event_user` ON `attendance`(`event_id`, `user_id`, `checked_in_at`);

-- Email logs by organization and date (optimizes email log queries)
-- CREATE INDEX `idx_email_org_date` ON `email_logs`(`organization_id`, `sent_at`);

-- ============================================
-- END OF SCHEMA
-- ============================================
