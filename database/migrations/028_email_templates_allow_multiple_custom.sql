-- Allow multiple custom email templates per organization.
-- Drop unique constraint; duplicate (org, template_type) for non-custom types
-- is enforced in application logic (public/api/email-templates.php).
ALTER TABLE `email_templates`
  DROP KEY `unique_org_template`;
