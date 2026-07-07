-- Sponsored admin enrollments and incomplete-payment reminder tracking.
ALTER TABLE `program_registrations`
  ADD COLUMN `enrollment_source` ENUM('self','sponsored') NOT NULL DEFAULT 'self' AFTER `coupon_code`,
  ADD COLUMN `sponsored_note` VARCHAR(500) NULL AFTER `enrollment_source`,
  ADD COLUMN `payment_reminder_sent_at` TIMESTAMP NULL AFTER `sponsored_note`,
  ADD COLUMN `added_by_user_id` INT UNSIGNED NULL AFTER `payment_reminder_sent_at`;
