-- Facility booking Stripe: authorize on request, capture on approve
-- Run one statement at a time; skip any that report duplicate column.

ALTER TABLE `facility_bookings` ADD COLUMN `stripe_checkout_session_id` VARCHAR(255) NULL AFTER `total_amount`;
ALTER TABLE `facility_bookings` ADD COLUMN `stripe_payment_intent_id` VARCHAR(255) NULL AFTER `stripe_checkout_session_id`;
ALTER TABLE `facility_bookings` ADD COLUMN `payment_status` ENUM('not_required','awaiting_checkout','authorized','captured','released','failed') NOT NULL DEFAULT 'not_required' AFTER `stripe_payment_intent_id`;
ALTER TABLE `facility_bookings` ADD COLUMN `payment_authorized_at` DATETIME NULL AFTER `payment_status`;
ALTER TABLE `facility_bookings` ADD COLUMN `payment_captured_at` DATETIME NULL AFTER `payment_authorized_at`;
ALTER TABLE `facility_bookings` ADD COLUMN `payment_released_at` DATETIME NULL AFTER `payment_captured_at`;
ALTER TABLE `facility_bookings` ADD COLUMN `checkout_pending_json` LONGTEXT NULL AFTER `payment_released_at`;
