-- Liability waiver for event RSVPs and program registration.

ALTER TABLE `organizations`
  ADD COLUMN `rsvp_waiver_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `coordinators_can_correct_checkins`,
  ADD COLUMN `rsvp_waiver_checkbox_label` VARCHAR(500) NOT NULL DEFAULT 'I agree to the liability waiver and release' AFTER `rsvp_waiver_enabled`,
  ADD COLUMN `rsvp_waiver_full_text` MEDIUMTEXT NULL AFTER `rsvp_waiver_checkbox_label`;

ALTER TABLE `rsvps`
  ADD COLUMN `waiver_accepted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;

ALTER TABLE `program_registrations`
  ADD COLUMN `waiver_accepted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
