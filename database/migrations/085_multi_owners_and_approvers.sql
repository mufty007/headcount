-- Multiple org owners (max 3) and selected request approvers.
-- Existing owners keep approval so current request queues still have a reviewer.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'can_approve_requests');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `can_approve_requests` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1=selected owner: notified and may approve event/program requests'' AFTER `is_super_admin`',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `users`
SET `can_approve_requests` = 1
WHERE `is_super_admin` = 1
  AND `role` = 'admin'
  AND `can_approve_requests` = 0;

-- New product defaults: only owners create/publish; only selected owners approve.
SET @has_rp = (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_permissions');
SET @sql = IF(@has_rp > 0,
  "UPDATE `role_permissions` SET `granted` = 0 WHERE `role` = 'admin' AND `permission_key` IN ('events.manage', 'events.approve_requests', 'programs.manage')",
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_up = (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_permissions');
SET @sql = IF(@has_up > 0,
  "DELETE FROM `user_permissions` WHERE `permission_key` IN ('events.manage', 'events.approve_requests', 'programs.manage', 'programs.approve_requests')",
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
