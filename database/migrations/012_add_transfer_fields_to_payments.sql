-- ============================================
-- ADD TRANSFER FIELDS TO PAYMENTS TABLE
-- ============================================
-- Adds fields to track Stripe transfers/payouts for payments

ALTER TABLE `payments` 
ADD COLUMN `transfer_id` VARCHAR(255) NULL AFTER `refund_reason`,
ADD COLUMN `transferred_at` TIMESTAMP NULL AFTER `transfer_id`,
ADD INDEX `idx_transfer_id` (`transfer_id`);
