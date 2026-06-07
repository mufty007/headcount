-- ============================================
-- ADD LINKED USER TO FAMILY MEMBERS
-- ============================================
-- Allows family members to be linked to existing user accounts
-- when their full name matches

ALTER TABLE `family_members`
ADD COLUMN `linked_user_id` INT UNSIGNED NULL COMMENT 'Link to existing user account if name matches' AFTER `relationship`,
ADD INDEX `idx_linked_user` (`linked_user_id`),
ADD CONSTRAINT `fk_family_linked_user` 
  FOREIGN KEY (`linked_user_id`) 
  REFERENCES `users` (`id`) 
  ON DELETE SET NULL;
