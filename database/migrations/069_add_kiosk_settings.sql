/* Kiosk / digital-signage display settings, stored per organization.
   Managed by the org owner (super-admin) under Settings -> Kiosk.
   The public display lives at /portal/kiosk.php?org=<slug>. */

ALTER TABLE `organizations`
  ADD COLUMN `kiosk_enabled`  TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '1=public kiosk display is live',
  ADD COLUMN `kiosk_mode`     VARCHAR(20)  NOT NULL DEFAULT 'board' COMMENT 'board | slideshow',
  ADD COLUMN `kiosk_days`     INT UNSIGNED NOT NULL DEFAULT 7  COMMENT 'forward window of days to show',
  ADD COLUMN `kiosk_interval` INT UNSIGNED NOT NULL DEFAULT 8  COMMENT 'slideshow seconds per event';
