-- Store actual Stripe checkout amount on program registrations for admin Payments reporting.
ALTER TABLE `program_registrations`
  ADD COLUMN `amount_paid` DECIMAL(10,2) NULL AFTER `stripe_subscription_id`,
  ADD COLUMN `currency` VARCHAR(3) NULL DEFAULT 'USD' AFTER `amount_paid`;
