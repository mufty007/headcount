-- ============================================
-- ADD CASH PAYMENT SUPPORT TO PAYMENTS TABLE
-- ============================================
-- payment_method: 'stripe' | 'cash'
-- recorded_by: user_id of admin/coordinator who recorded cash payment (audit)
-- stripe_* columns become nullable for cash payments

ALTER TABLE `payments`
  ADD COLUMN `payment_method` ENUM('stripe','cash') NOT NULL DEFAULT 'stripe' AFTER `attendance_id`,
  ADD COLUMN `recorded_by` INT UNSIGNED NULL AFTER `refund_reason`,
  ADD INDEX `idx_payment_method` (`payment_method`);

-- Make Stripe columns nullable (cash payments have no Stripe IDs)
ALTER TABLE `payments`
  MODIFY COLUMN `stripe_payment_intent_id` VARCHAR(255) NULL,
  MODIFY COLUMN `stripe_checkout_session_id` VARCHAR(255) NULL;

ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
