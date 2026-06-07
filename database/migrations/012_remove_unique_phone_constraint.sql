-- Remove unique constraint on phone numbers
-- Migration: 012_remove_unique_phone_constraint.sql
-- 
-- Reason: Multiple users (e.g., children) may share the same phone number (parent's number)
-- This allows families to use a single contact number for multiple members

-- Drop the unique constraint on phone numbers
ALTER TABLE `users` DROP INDEX IF EXISTS `unique_org_phone`;

-- Note: Email addresses remain unique per organization
-- The unique_org_email constraint is still in place
