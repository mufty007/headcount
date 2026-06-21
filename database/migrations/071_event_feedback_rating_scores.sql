ALTER TABLE `event_feedback`
  ADD COLUMN `rating_scores` JSON NULL COMMENT 'Fixed keys: overall, content, venue, recommend',
  ADD COLUMN `submitted_via` ENUM('portal', 'email_link') NOT NULL DEFAULT 'portal';
