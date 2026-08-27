-- Presenter login role and link from program_presenters to users.

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin', 'coordinator', 'member', 'presenter') NOT NULL DEFAULT 'member';

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_presenters' AND COLUMN_NAME = 'user_id');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `program_presenters` ADD COLUMN `user_id` INT UNSIGNED NULL AFTER `program_id`, ADD KEY `idx_program_presenters_user` (`user_id`), ADD CONSTRAINT `fk_program_presenters_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT 1 AS _skip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
