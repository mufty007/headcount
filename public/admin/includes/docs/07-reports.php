<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Reports &amp; analytics</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  The Reports page provides multi-tab analytics with filters, automatic insights, and CSV / branded PDF / printable HTML exports.
</p>

<h3 id="reports-open" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Open reports</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Go to <strong>Reports &amp; Finance → Reports</strong>.</li>
  <li>Pick a tab (Overview, Events, RSVP, Members, Revenue, Feedback, Facilities, Programs).</li>
  <li>Set the date range and optional comparison to the previous period.</li>
  <li>Apply filters (category, event, facility, program, thresholds, paid-only revenue, etc.).</li>
  <li>Read the insight callouts at the top for quick takeaways.</li>
</ol>

<h3 id="reports-tabs" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">What each tab shows</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Overview</strong> — attendance, RSVP Yes, no-show rate, members, revenue; trends; attendance by category; RSVP vs attendance; new vs returning; top events and check-ins.</li>
  <li><strong>Events</strong> — event-level performance and no-show rates.</li>
  <li><strong>RSVP</strong> — RSVP and no-show analysis with thresholds.</li>
  <li><strong>Members</strong> — member growth (new signups per month and cumulative total by account created date), plus engagement: RSVP, attendance, no-show, attendance rate.</li>
  <li><strong>Revenue</strong> — revenue by event and monthly trends (paid and optionally pending).</li>
  <li><strong>Feedback</strong> — averages, response trends, and per-event feedback results.</li>
  <li><strong>Facilities</strong> — booking status, usage, and facility revenue.</li>
  <li><strong>Programs</strong> — registrations, sessions, attendance, and program revenue.</li>
</ul>

<h3 id="reports-export" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Export</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Apply the filters you want reflected in the export.</li>
  <li>Choose <strong>CSV</strong> for spreadsheets, <strong>PDF</strong> for branded sharing, or print/HTML for a printable view.</li>
  <li>Download or print from the browser dialog.</li>
</ol>
<div class="doc-callout doc-callout-info">
  Report access follows your role capabilities. If Reports is missing from the sidebar, ask an admin to grant report viewing permission.
</div>

<a href="<?= e($docNav['reports'] ?? ($docAdminBase . '/?page=reports')) ?>" class="doc-goto page-header-btn-primary">Open Reports</a>
