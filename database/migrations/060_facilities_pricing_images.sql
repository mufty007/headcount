-- Facilities: paid hourly booking, images gallery, discounts, admin availability hours
-- Run one statement at a time; skip any that report duplicate column.

ALTER TABLE `facilities` ADD COLUMN `is_paid` BOOLEAN NOT NULL DEFAULT FALSE AFTER `allow_guest_booking`;
ALTER TABLE `facilities` ADD COLUMN `hourly_rate` DECIMAL(10,2) NULL AFTER `is_paid`;
ALTER TABLE `facilities` ADD COLUMN `discount_percent` DECIMAL(5,2) NULL DEFAULT 0 AFTER `hourly_rate`;
ALTER TABLE `facilities` ADD COLUMN `discount_label` VARCHAR(120) NULL AFTER `discount_percent`;
ALTER TABLE `facilities` ADD COLUMN `images` JSON NULL COMMENT 'Array of image paths under uploads/' AFTER `image`;
ALTER TABLE `facilities` ADD COLUMN `operating_hours` JSON NULL COMMENT 'Admin availability: day 0-6 => {open, close, closed}' AFTER `guest_operating_hours`;

ALTER TABLE `facility_bookings` ADD COLUMN `hours_booked` DECIMAL(8,2) NULL AFTER `end_datetime`;
ALTER TABLE `facility_bookings` ADD COLUMN `hourly_rate` DECIMAL(10,2) NULL AFTER `hours_booked`;
ALTER TABLE `facility_bookings` ADD COLUMN `discount_percent` DECIMAL(5,2) NULL AFTER `hourly_rate`;
ALTER TABLE `facility_bookings` ADD COLUMN `subtotal_amount` DECIMAL(10,2) NULL AFTER `discount_percent`;
ALTER TABLE `facility_bookings` ADD COLUMN `total_amount` DECIMAL(10,2) NULL AFTER `subtotal_amount`;
