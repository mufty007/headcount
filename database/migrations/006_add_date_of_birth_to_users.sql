-- Migration: Add date of birth field to users table
-- This allows storing member date of birth for age calculations and demographics

ALTER TABLE `users` 
ADD COLUMN `date_of_birth` DATE NULL AFTER `gender`;

-- Add index for date of birth queries (useful for age-based filtering)
CREATE INDEX `idx_date_of_birth` ON `users`(`date_of_birth`);
