-- ============================================
-- ADD COORDINATOR ROLE TO USERS
-- ============================================
-- Extends user role enum so coordinators can check in attendees and view
-- attendance; admins manage coordinators from Settings.

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin', 'coordinator', 'member') NOT NULL DEFAULT 'member';
