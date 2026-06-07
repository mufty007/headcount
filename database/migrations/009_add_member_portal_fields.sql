-- ============================================
-- ADD MEMBER PORTAL FIELDS TO USERS TABLE
-- ============================================
-- Add fields for member portal features: profile photo, preferences, QR code

ALTER TABLE `users` 
ADD COLUMN `profile_photo_path` VARCHAR(500) NULL AFTER `phone`,
ADD COLUMN `email_preferences` JSON NULL AFTER `profile_photo_path`,
ADD COLUMN `communication_preferences` JSON NULL AFTER `email_preferences`,
ADD COLUMN `qr_code_secret` VARCHAR(255) NULL AFTER `communication_preferences`;

-- Add index for QR code lookups
CREATE INDEX `idx_qr_code_secret` ON `users`(`qr_code_secret`);
