-- ============================================
-- ADD BANNER IMAGE TO EVENTS
-- ============================================
-- Adds banner_image column to events table for event banner images
ALTER TABLE `events` 
ADD COLUMN `banner_image` VARCHAR(500) NULL AFTER `description`;
