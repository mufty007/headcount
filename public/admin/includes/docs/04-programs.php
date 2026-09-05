<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Programs</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Programs are ongoing classes, courses, or recurring activities with generated sessions, enrollment, optional pricing, and per-session attendance. They are separate from events — not just an event category.
</p>

<h3 id="programs-vs-events" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Program vs event</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Event</strong> — a gathering (one-off or series) with RSVP and event check-in.</li>
  <li><strong>Program</strong> — a multi-session offering with registration/enrollment, session roster, and present/absent/excused attendance.</li>
</ul>

<h3 id="programs-create" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Create a program</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Go to <strong>Programs → All Programs</strong> and click <strong>Create program</strong> (or open program edit).</li>
  <li>Enter title, description, category, and status (<em>draft</em>, <em>published</em>, <em>cancelled</em>, or <em>archived</em>).</li>
  <li>Set physical or virtual location and public-site visibility.</li>
  <li>Configure <strong>schedule</strong>: weekly, biweekly, monthly, or non-recurring; clock times or prayer-relative start/end; optional daily break windows.</li>
  <li>Generate sessions (the system can generate sessions ahead from the schedule settings).</li>
  <li>Set <strong>capacity</strong>, enrollment dates, and whether enrollment is whole-program or <strong>selectable weeks</strong>.</li>
  <li>Configure <strong>pricing</strong>: free, one-time, or recurring (weekly/biweekly/monthly); per-week prices and all-weeks bundle when using selectable weeks; coupons if your org uses them.</li>
  <li>Allow guest registration if needed; add <strong>presenters</strong> (name, title, image) and custom registration questions.</li>
  <li>Save as draft or publish when ready.</li>
</ol>

<h3 id="programs-share" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Share registration links</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>On program details, copy the <strong>member</strong> and/or <strong>guest</strong> share links.</li>
  <li>Download the QR codes for flyers and messaging.</li>
  <li>Archiving removes a program from the portal but keeps registrations and history.</li>
</ul>

<h3 id="programs-registrants" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Registrants &amp; payments</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open the program → <strong>Registrants</strong> (confirmed enrollments).</li>
  <li>Review incomplete / pending payment registrations separately.</li>
  <li>Use <strong>sponsored enrollment</strong> to enroll an existing member or a new guest when staff covers or waives payment.</li>
  <li>Use <strong>Remove</strong> to take someone off the program, or <strong>Replace</strong> to give their seat to another person (existing member or someone new). The replacement is enrolled and should attend; this does not refund or move Stripe billing.</li>
  <li>Export registrants to CSV when needed.</li>
  <li>Review registration-question response summaries on the program hub.</li>
</ol>

<h3 id="programs-attendance" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Session attendance</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open <strong>Programs → Attendance</strong>, or the attendance area on program details.</li>
  <li>Select the program and session.</li>
  <li>Mark each registrant <strong>present</strong>, <strong>absent</strong>, or <strong>excused</strong>.</li>
  <li>Save — attendance feeds program reports and engagement metrics.</li>
</ol>

<h3 id="programs-announce" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Announcements</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  Send program announcements to registrants from the program details page. Session and payment reminders can also be driven by your email automation settings when configured.
</p>

<a href="<?= e($docNav['programs'] ?? ($docAdminBase . '/?page=programs')) ?>" class="doc-goto page-header-btn-primary">All Programs</a>
<a href="<?= e($docAdminBase . '/?page=program-edit') ?>" class="doc-goto page-header-btn-secondary">Create / Edit Program</a>
<a href="<?= e($docNav['program-attendance'] ?? ($docAdminBase . '/?page=program-attendance')) ?>" class="doc-goto page-header-btn-secondary">Program Attendance</a>
