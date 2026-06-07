-- Persist guest-checkout extras until Stripe webhook completes (RSVP + question answers)
ALTER TABLE `payments` ADD COLUMN `checkout_pending_json` LONGTEXT NULL;
