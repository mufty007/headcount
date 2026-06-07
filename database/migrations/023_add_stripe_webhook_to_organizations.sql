-- Add Stripe webhook secret column to organizations (used by Admin Settings and portal webhooks).
-- Run when organizations table exists. Skip if you get "Duplicate column" (column already added).

ALTER TABLE `organizations`
  ADD COLUMN `stripe_webhook_secret_encrypted` TEXT NULL AFTER `stripe_secret_key_encrypted`;
