-- Link events to facilities for automatic booking blocks when published
-- Run after 062_facility_booking_stripe.sql (facilities table must exist)

ALTER TABLE `events`
  ADD COLUMN `facility_id` INT UNSIGNED NULL AFTER `location`,
  ADD INDEX `idx_events_facility_date` (`facility_id`, `event_date`, `status`),
  ADD CONSTRAINT `fk_events_facility`
    FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE SET NULL;
