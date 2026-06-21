-- Per-event toggle: send feedback request email to checked-in attendees
ALTER TABLE `events`
  ADD COLUMN `collect_feedback` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Send feedback request email to checked-in attendees';
