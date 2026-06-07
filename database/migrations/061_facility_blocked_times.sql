-- Date-specific blocked / reserved times (internal events, maintenance, etc.)
-- Run after 060_facilities_pricing_images.sql

ALTER TABLE `facilities`
  ADD COLUMN `blocked_times` JSON NULL
  COMMENT 'Array of {date, start_time, end_time, reason, block_member, block_guest}'
  AFTER `operating_hours`;
