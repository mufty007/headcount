<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Getting started</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Headcount helps your organization run events, programs, facility bookings, check-in, payments, and member communications from one admin area.
</p>

<h3 id="getting-started-login" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Sign in</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open the admin URL (usually <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">/admin/</code>).</li>
  <li>Enter your staff email and password.</li>
  <li>Optionally enable “Remember me” to stay signed in longer.</li>
  <li>Use <strong>Forgot password</strong> on the login screen if you need a reset link emailed to you.</li>
</ol>

<h3 id="getting-started-roles" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Roles &amp; permissions</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Super admin / owner</strong> — full access; cannot be locked out by permission overrides.</li>
  <li><strong>Admin</strong> — full admin area by default; capabilities can be customized in Settings → Team.</li>
  <li><strong>Coordinator</strong> — limited staff access (often check-in and selected areas). Extra rights are granted per role or per user.</li>
  <li><strong>Member</strong> — member portal only (RSVP, programs, profile, QR). Members do not use this admin Documentation page.</li>
</ul>
<div class="doc-callout doc-callout-info">
  If a sidebar link is missing, your account may lack that capability (for example <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">events.manage</code> or <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">reports.view</code>). Ask an admin with Settings access to adjust permissions.
</div>

<h3 id="getting-started-dashboard" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Dashboard</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  After login you land on the Dashboard. It shows upcoming activity, member and attendance KPIs, trends, and quick actions such as create event, start check-in, and add member.
</p>

<h3 id="getting-started-nav" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">How the sidebar is organized</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Menu</strong> — Dashboard, Events, Programs, Facilities, Members, Check-In.</li>
  <li><strong>Reports &amp; Finance</strong> — Reports, Payments, Refund Requests.</li>
  <li><strong>System</strong> — Documentation (this page), Notifications, Activity Log, Email Templates, Campaigns, Settings.</li>
</ul>
<p class="text-sm text-gray-700 dark:text-gray-300">
  Use the search box above to jump to a topic, or click a section in Contents. Deep links use anchors such as <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">#events</code> or <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">#checkin</code>.
</p>

<a href="<?= e($docNav['dashboard'] ?? ($docAdminBase . '/?page=dashboard')) ?>" class="doc-goto page-header-btn-secondary">Go to Dashboard</a>
<a href="<?= e($docNav['settings'] ?? ($docAdminBase . '/?page=settings')) ?>" class="doc-goto page-header-btn-secondary">Go to Settings</a>
