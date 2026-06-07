-- Add body_blocks JSON column for block-based email editor (TICKET-005)
ALTER TABLE `email_templates`
  ADD COLUMN `body_blocks` JSON NULL AFTER `body_html`;
