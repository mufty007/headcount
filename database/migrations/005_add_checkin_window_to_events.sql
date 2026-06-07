-- Migration: Add check-in window fields to events table
-- This allows admins to set custom check-in windows when creating events
-- If not set, defaults to 1 hour before event start time

ALTER TABLE `events` 
ADD COLUMN `checkin_window_start` TIME NULL AFTER `start_time`,
ADD COLUMN `checkin_window_end` TIME NULL AFTER `checkin_window_start`;

-- Add index for check-in window queries
CREATE INDEX `idx_checkin_window` ON `events`(`event_date`, `checkin_window_start`, `checkin_window_end`);
