-- Post-event attendance correction: coordinators need org flag; admins always allowed in app logic
-- coordinators_can_correct_checkins: when 0, only admins can correct attendance after the event day

ALTER TABLE `organizations`
  ADD COLUMN `coordinators_can_correct_checkins` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=coordinators can correct past-event check-ins, 0=admin only';
