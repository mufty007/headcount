-- Migration: 045_allow_bring_guests.sql
-- Adds allow_bring_guests column to events table.
-- Controls whether attendees see a "Number of additional guests" field in the RSVP modal.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'allow_bring_guests'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE `events` ADD COLUMN `allow_bring_guests` BOOLEAN NOT NULL DEFAULT FALSE AFTER `allow_guest_rsvp`',
    'SELECT "Column already exists, skipping."'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
