-- ============================================
-- MEMBER RELATIONSHIPS TABLE
-- ============================================
-- Tracks family relationships between existing members (bidirectional)
-- Examples: spouse, parent/child, siblings, guardian/ward

CREATE TABLE IF NOT EXISTS `member_relationships` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT UNSIGNED NOT NULL COMMENT 'The member who has this relationship',
  `related_member_id` INT UNSIGNED NOT NULL COMMENT 'The related family member',
  `relationship_type` ENUM('spouse', 'parent', 'child', 'sibling', 'guardian', 'ward', 'other') NOT NULL,
  `notes` TEXT NULL COMMENT 'Optional notes about the relationship',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED NULL COMMENT 'Admin who created this relationship',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`related_member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  UNIQUE KEY `unique_relationship` (`member_id`, `related_member_id`, `relationship_type`),
  INDEX `idx_member` (`member_id`),
  INDEX `idx_related_member` (`related_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: This table stores BOTH directions of each relationship
-- Example: When John is spouse of Mary, we create:
--   1. member_id=John, related_member_id=Mary, type=spouse
--   2. member_id=Mary, related_member_id=John, type=spouse
