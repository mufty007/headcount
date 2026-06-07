-- Tiered / bundle pricing by headcount (registrant + guests) for simple ticket_price checkout
ALTER TABLE `events`
  ADD COLUMN `pricing_model` ENUM('per_person', 'headcount_tier') NOT NULL DEFAULT 'per_person' AFTER `ticket_price`,
  ADD COLUMN `headcount_pricing_tiers` JSON NULL DEFAULT NULL AFTER `pricing_model`;
