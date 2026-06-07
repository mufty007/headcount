-- Organization refund and coordinator permission settings
-- coordinators_can_refund: when 0, only admins can process refunds; when 1, coordinators can too
-- refund_request_days_after_event: max days after event date that users can submit refund requests (NULL = no limit)

ALTER TABLE `organizations`
  ADD COLUMN `coordinators_can_refund` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=coordinators can refund, 0=admin only',
  ADD COLUMN `refund_request_days_after_event` INT UNSIGNED NULL COMMENT 'Max days after event for user refund requests; NULL=no limit';
