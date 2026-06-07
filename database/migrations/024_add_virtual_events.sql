-- ============================================
-- ADD VIRTUAL EVENTS SUPPORT
-- ============================================
-- is_virtual: when 1, location holds join URL (Zoom/Google Meet etc.)
-- extra_details: optional admin-only content for event details page
ALTER TABLE `events`
ADD COLUMN `is_virtual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `location`,
ADD COLUMN `extra_details` TEXT NULL AFTER `is_virtual`;
