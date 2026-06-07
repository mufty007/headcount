-- ============================================
-- Migration: Add Performance Indexes
-- ============================================
-- This migration adds composite indexes for better query performance
-- Run this only if the indexes don't already exist

-- Drop indexes if they exist (safe to run multiple times)
SET @dbname = DATABASE();

-- Drop idx_member_search if exists
SET @preparedStatement = CONCAT(
  'SELECT COUNT(*) INTO @index_exists FROM INFORMATION_SCHEMA.STATISTICS ',
  'WHERE table_schema = ''', @dbname, ''' ',
  'AND table_name = ''users'' ',
  'AND index_name = ''idx_member_search'''
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @preparedStatement = IF(
  @index_exists > 0,
  'SELECT 1',
  'CREATE INDEX `idx_member_search` ON `users`(`organization_id`, `first_name`, `last_name`, `email`, `phone`)'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop idx_attendance_event_user if exists
SET @preparedStatement = CONCAT(
  'SELECT COUNT(*) INTO @index_exists FROM INFORMATION_SCHEMA.STATISTICS ',
  'WHERE table_schema = ''', @dbname, ''' ',
  'AND table_name = ''attendance'' ',
  'AND index_name = ''idx_attendance_event_user'''
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @preparedStatement = IF(
  @index_exists > 0,
  'SELECT 1',
  'CREATE INDEX `idx_attendance_event_user` ON `attendance`(`event_id`, `user_id`, `checked_in_at`)'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop idx_email_org_date if exists
SET @preparedStatement = CONCAT(
  'SELECT COUNT(*) INTO @index_exists FROM INFORMATION_SCHEMA.STATISTICS ',
  'WHERE table_schema = ''', @dbname, ''' ',
  'AND table_name = ''email_logs'' ',
  'AND index_name = ''idx_email_org_date'''
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @preparedStatement = IF(
  @index_exists > 0,
  'SELECT 1',
  'CREATE INDEX `idx_email_org_date` ON `email_logs`(`organization_id`, `sent_at`)'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
