<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Members</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Maintain your community directory: profiles, tags, groups, families, portal access, and bulk CSV import. Member activity feeds RSVPs, attendance, and payments.
</p>

<h3 id="members-add" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Add a member</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Go to <strong>Members</strong> and click <strong>Add Member</strong>.</li>
  <li>Enter first name, last name, and email (required).</li>
  <li>Optionally add phone, gender, and date of birth.</li>
  <li>Save — the member appears in search and can be invited to events or enrolled in programs.</li>
</ol>

<h3 id="members-search" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Search, filter, tags &amp; groups</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Search by name, email, or phone.</li>
  <li>Filter by status, tag, or group.</li>
  <li>Assign <strong>tags</strong> and <strong>group memberships</strong> for targeting campaigns and organization.</li>
  <li>Use bulk actions on the members list when available (email, tag, etc.).</li>
</ul>

<h3 id="members-details" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Member profile &amp; family</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open a member to see profile, attendance rate, recent RSVPs/no-shows, payments, and activity.</li>
  <li>Manage <strong>family relationships</strong> so households can RSVP and check in together where supported.</li>
  <li><strong>Generate portal login credentials</strong> when a member needs to sign in to the member portal.</li>
  <li>Edit or deactivate/delete according to your org’s policy and permissions.</li>
</ol>

<h3 id="members-import" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Import members from CSV</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>On Members, open <strong>Import CSV</strong> (requires <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">members.import</code>).</li>
  <li>Upload your CSV file.</li>
  <li><strong>Map columns</strong> to Headcount fields and preview rows.</li>
  <li>Review validation errors.</li>
  <li>Choose how to handle duplicates matched by email or phone: skip, update, or create.</li>
  <li>Confirm the import and review the summary.</li>
</ol>
<div class="doc-callout doc-callout-tip">
  Clean your CSV first (one email per row, consistent phone formats). Mapping is easier when header names match common fields like first_name, last_name, email, phone.
</div>

<a href="<?= e($docNav['members'] ?? ($docAdminBase . '/?page=members')) ?>" class="doc-goto page-header-btn-primary">Members</a>
<a href="<?= e($docNav['member-add'] ?? ($docAdminBase . '/?page=member-add')) ?>" class="doc-goto page-header-btn-secondary">Add Member</a>
<a href="<?= e($docAdminBase . '/?page=member-import') ?>" class="doc-goto page-header-btn-secondary">Import CSV</a>
