-- Internal event checklist: roles, templates, leadership assignments, task items.
-- Also adds planning fields on events (target_attendance, budget).

ALTER TABLE `events`
  ADD COLUMN `target_attendance` INT UNSIGNED NULL AFTER `capacity`,
  ADD COLUMN `budget` DECIMAL(12,2) NULL AFTER `target_attendance`;

CREATE TABLE IF NOT EXISTS `checklist_roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `role_key` VARCHAR(64) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_org_role_key` (`organization_id`, `role_key`),
  KEY `idx_org_sort` (`organization_id`, `sort_order`),
  CONSTRAINT `fk_checklist_roles_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `checklist_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `name` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_org_category` (`organization_id`, `category_id`),
  CONSTRAINT `fk_checklist_templates_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_checklist_templates_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `checklist_template_tasks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `phase` ENUM('pre','day_of','post') NOT NULL DEFAULT 'pre',
  `section` VARCHAR(128) NOT NULL DEFAULT '',
  `default_role_id` INT UNSIGNED NULL,
  `due_offset_days` INT NOT NULL DEFAULT -7,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_template_sort` (`template_id`, `phase`, `sort_order`),
  CONSTRAINT `fk_checklist_template_tasks_template` FOREIGN KEY (`template_id`) REFERENCES `checklist_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_checklist_template_tasks_role` FOREIGN KEY (`default_role_id`) REFERENCES `checklist_roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_leadership` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_role` (`event_id`, `role_id`),
  KEY `idx_event_user` (`event_id`, `user_id`),
  CONSTRAINT `fk_event_leadership_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_leadership_role` FOREIGN KEY (`role_id`) REFERENCES `checklist_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_leadership_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_checklist_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` INT UNSIGNED NOT NULL,
  `template_task_id` INT UNSIGNED NULL,
  `title` VARCHAR(500) NOT NULL,
  `phase` ENUM('pre','day_of','post') NOT NULL DEFAULT 'pre',
  `section` VARCHAR(128) NOT NULL DEFAULT '',
  `role_id` INT UNSIGNED NULL,
  `assignee_user_id` INT UNSIGNED NULL,
  `status` ENUM('not_started','in_progress','complete') NOT NULL DEFAULT 'not_started',
  `due_date` DATE NULL,
  `notes` TEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `completed_at` TIMESTAMP NULL,
  `completed_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_phase_sort` (`event_id`, `phase`, `sort_order`),
  KEY `idx_assignee_status` (`assignee_user_id`, `status`),
  CONSTRAINT `fk_event_checklist_items_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_checklist_items_role` FOREIGN KEY (`role_id`) REFERENCES `checklist_roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_event_checklist_items_assignee` FOREIGN KEY (`assignee_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_event_checklist_items_completed_by` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend notifications type for checklist assignments
ALTER TABLE `notifications`
  MODIFY COLUMN `type` ENUM(
    'event_reminder',
    'new_rsvp',
    'event_cancelled',
    'payment_received',
    'member_added',
    'system',
    'info',
    'checklist_assigned'
  ) NOT NULL DEFAULT 'info';
