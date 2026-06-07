-- ============================================
-- Create Admin User in Database
-- Run this in phpMyAdmin after importing schema
-- ============================================

-- Step 1: Check if organization exists, create if not
-- Replace 'Your Organization Name' with your actual organization name
INSERT INTO `organizations` (`name`, `slug`, `timezone`, `created_at`, `updated_at`)
VALUES ('IMCAIN', 'imcain', 'America/Indiana/Indianapolis', NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = `name`;

-- Get the organization ID (you'll need this for the user)
-- Run this query separately to get the organization ID:
-- SELECT id FROM organizations WHERE slug = 'imcain' LIMIT 1;

-- Step 2: Create or update admin user (same org + email)
-- If you see #1062 duplicate key on unique_org_email, that user already exists — use the upsert
-- below (ON DUPLICATE KEY UPDATE) so the script is safe to re-run; it refreshes password/name/role.
--
-- Replace these values:
-- - organization_id (example uses 1)
-- - email, password_hash, first_name, last_name

INSERT INTO `users` (
    `organization_id`,
    `email`,
    `password_hash`,
    `first_name`,
    `last_name`,
    `role`,
    `status`,
    `created_at`,
    `updated_at`
) VALUES (
    1,
    'it@imcaindy.org',
    '$2a$12$AkD8uxkd0./xrp6vILgO0O0vNFbY/I4PDyW1N7VZdIuQ/NLUdP7Ha',
    'Muhammad',
    'Tomasiewicz',
    'admin',
    'active',
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    `password_hash` = VALUES(`password_hash`),
    `first_name` = VALUES(`first_name`),
    `last_name` = VALUES(`last_name`),
    `role` = VALUES(`role`),
    `status` = VALUES(`status`),
    `updated_at` = VALUES(`updated_at`);

-- ============================================
-- HOW TO GENERATE PASSWORD HASH:
-- ============================================
-- Option 1: Create a temporary PHP file on your server:
-- <?php
-- $password = 'YourSecurePassword123!';
-- echo password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
-- ?>
-- Then run it and copy the output

-- Option 2: Use online bcrypt generator:
-- https://bcrypt-generator.com/
-- Enter your password and cost = 12, copy the hash

-- Option 3: Use MySQL (if available):
-- SELECT PASSWORD('YourSecurePassword123!'); -- Note: This uses old MySQL password hashing, not recommended
-- Better to use PHP method above

-- ============================================
-- COMPLETE EXAMPLE (all in one):
-- ============================================
-- First, create/get organization:
SET @org_id = 1;  -- Or get from: SELECT id FROM organizations LIMIT 1;

-- Then create or update admin user (re-run safe: updates existing same org+email):
INSERT INTO `users` (
    `organization_id`,
    `email`,
    `password_hash`,
    `first_name`,
    `last_name`,
    `role`,
    `status`,
    `created_at`,
    `updated_at`
) VALUES (
    @org_id,
    'admin@imcaindy.org',
    '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5GyYqJ5X5X5X5u',  -- REPLACE THIS with your generated hash
    'Admin',
    'User',
    'admin',
    'active',
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    `password_hash` = VALUES(`password_hash`),
    `first_name` = VALUES(`first_name`),
    `last_name` = VALUES(`last_name`),
    `role` = VALUES(`role`),
    `status` = VALUES(`status`),
    `updated_at` = VALUES(`updated_at`);
