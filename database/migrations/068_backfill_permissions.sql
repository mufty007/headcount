/* Backfill for granular permissions (depends on 067).
   1. Mark the lowest-id admin in each org as the protected super-admin (owner).
   2. If the legacy org-level coordinator flags exist (added in 029/030), migrate
      their values into role_permissions so behaviour is preserved exactly:
      coordinators_can_refund -> refunds.process,
      coordinators_can_correct_checkins -> attendance.correct.
   The flag migration is guarded so it no-ops cleanly on databases that never had
   those columns (the code-level role defaults already match the old column defaults). */

UPDATE `users` u
JOIN (
  SELECT `organization_id`, MIN(`id`) AS min_id
  FROM `users`
  WHERE `role` = 'admin'
  GROUP BY `organization_id`
) m ON u.`id` = m.min_id
SET u.`is_super_admin` = 1;

SET @has_refund = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'coordinators_can_refund');
SET @sql = IF(@has_refund > 0,
  "INSERT IGNORE INTO role_permissions (organization_id, role, permission_key, granted) SELECT id, 'coordinator', 'refunds.process', CASE WHEN coordinators_can_refund = 1 THEN 1 ELSE 0 END FROM organizations",
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_cc = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'coordinators_can_correct_checkins');
SET @sql = IF(@has_cc > 0,
  "INSERT IGNORE INTO role_permissions (organization_id, role, permission_key, granted) SELECT id, 'coordinator', 'attendance.correct', CASE WHEN coordinators_can_correct_checkins = 1 THEN 1 ELSE 0 END FROM organizations",
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
