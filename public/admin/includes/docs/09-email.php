<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Email &amp; campaigns</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Headcount sends transactional email (confirmations, receipts, invites) and staff-composed campaigns. Configure SMTP2GO in Settings, build reusable templates, then compose campaigns or rely on automated reminders.
</p>

<h3 id="email-setup" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Email setup</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open <strong>Settings</strong> and enter your SMTP2GO API key, sender email, and sender name.</li>
  <li>Send a <strong>test email</strong> from Settings to verify delivery.</li>
  <li>Ask your technical admin to confirm delivery webhooks if you rely on delivery event logging.</li>
</ol>

<h3 id="email-templates" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Email templates</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Go to <strong>System → Email Templates</strong>.</li>
  <li>Create or edit a template with the WYSIWYG editor.</li>
  <li>Preview, duplicate, or delete templates as needed.</li>
  <li>Send a test email from the template editor.</li>
  <li>Default types often include announcement, reminder, confirmation, receipt, follow-up, feedback, and schedule-change styles.</li>
</ol>

<h3 id="email-campaigns" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Send a campaign</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open <strong>System → Campaigns</strong>.</li>
  <li>Compose a subject and rich HTML body (optionally start from a saved template).</li>
  <li>Choose an <strong>audience</strong>: all members, event participants, one person in an event, manual email list, or a group segment.</li>
  <li>Insert <strong>merge tags</strong> for personalization.</li>
  <li><strong>Preview</strong> the message.</li>
  <li>Save as draft, <strong>schedule</strong>, or <strong>send now</strong>.</li>
  <li>Review campaign history; duplicate or cancel a scheduled campaign when needed.</li>
</ol>

<h3 id="email-reminders" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Automated event reminders</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  Event reminders can be enabled with milestones such as one week before, 24 hours before, two hours before, and custom days/hours-before schedules. Each milestone can use its own template. Background cron jobs (configured by your technical admin) must be running for automation to fire on time.
</p>

<h3 id="email-other" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Other automatic emails</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>RSVP confirmations and cancellations</li>
  <li>Payment receipts</li>
  <li>Event invitations and guest account completion links</li>
  <li>Event schedule-change notices</li>
  <li>Post-event feedback requests</li>
  <li>Program announcements, session reminders, payment reminders, sponsored enrollment notices</li>
  <li>Facility booking notifications</li>
</ul>
<div class="doc-callout doc-callout-info">
  Per-event announcements and resends are also available on the <strong>event details</strong> page. Use Campaigns for broader broadcasts.
</div>

<a href="<?= e($docNav['email-templates'] ?? ($docAdminBase . '/?page=email-templates')) ?>" class="doc-goto page-header-btn-primary">Email Templates</a>
<a href="<?= e($docNav['campaigns'] ?? ($docAdminBase . '/?page=email-campaigns')) ?>" class="doc-goto page-header-btn-secondary">Campaigns</a>
<a href="<?= e($docNav['settings'] ?? ($docAdminBase . '/?page=settings')) ?>" class="doc-goto page-header-btn-secondary">Settings (Email)</a>
