<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Event day-to-day operations</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  After an event is created, the <strong>event details</strong> page is your operations hub: invites, RSVPs, sharing, communications, payments, feedback, and attendance corrections.
</p>

<h3 id="event-ops-open" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Open an event</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Go to <strong>Events → All Events</strong>.</li>
  <li>Click the event title (or Details) to open the hub.</li>
  <li>For recurring series, use the session selector to work on a specific occurrence.</li>
</ol>

<h3 id="event-ops-share" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Share the portal link &amp; QR</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Copy the <strong>portal link</strong> from the event details page.</li>
  <li>Download or display the <strong>share QR code</strong> for posters and messaging.</li>
  <li>Invite-only events still need invites before people can register.</li>
</ul>

<h3 id="event-ops-invites" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Invites (invite-only &amp; guests)</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>On event details, open the invites / invitees section.</li>
  <li>Add existing <strong>members</strong> from your directory.</li>
  <li>Invite a <strong>guest by email</strong> when they are not yet in the member list.</li>
  <li>Invitees receive email (when email is configured) and can register via their invite link.</li>
</ol>

<h3 id="event-ops-rsvps" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Monitor RSVPs</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Review the RSVP response table (yes / no / pending as applicable).</li>
  <li><strong>Export CSV</strong> for offline lists or spreadsheets.</li>
  <li>Review custom-question responses and summaries on the same hub.</li>
  <li>For paid events, confirm payment status alongside the RSVP.</li>
</ul>

<h3 id="event-ops-comms" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Announcements &amp; reminders</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Compose an <strong>announcement</strong> or reminder from the event details communications tools.</li>
  <li><strong>Resend RSVP confirmations</strong> when someone did not receive theirs.</li>
  <li>Check the <strong>email activity log</strong> on the event for delivery history related to that event.</li>
  <li>For org-wide or segmented broadcasts, use <strong>System → Campaigns</strong> instead.</li>
</ol>

<h3 id="event-ops-cash" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Record cash payments</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  When someone pays in person, record a <strong>manual cash payment</strong> on the event against their RSVP so reports and attendance stay accurate. You can also remove a mistaken cash entry when permitted.
</p>

<h3 id="event-ops-potluck-feedback" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Potluck &amp; feedback</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Potluck</strong> — view signups by category, serving side, and item when potluck is enabled on the event.</li>
  <li><strong>Feedback</strong> — after the event, review average scores and individual responses when feedback collection was enabled.</li>
</ul>

<h3 id="event-ops-corrections" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Attendance corrections &amp; walk-ins</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>After (or during) an event, authorized staff can add walk-ins or edit check-ins from the event hub.</li>
  <li>Set check-in time and guests checked in as needed.</li>
  <li>Enter a <strong>mandatory correction reason</strong> — this is stored in the audit trail.</li>
</ol>
<div class="doc-callout doc-callout-info">
  Correcting historical attendance typically requires the <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">attendance.correct</code> capability. Live check-in uses the Check-In page (see the Check-in section).
</div>

<h3 id="event-ops-exports" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Exports</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>RSVP CSV from the RSVP table.</li>
  <li>Attendance / check-in export from the check-in table on the event.</li>
  <li>Org-wide analytics and branded PDF from <strong>Reports</strong>.</li>
</ul>

<a href="<?= e($docNav['events'] ?? ($docAdminBase . '/?page=events')) ?>" class="doc-goto page-header-btn-primary">All Events</a>
<a href="<?= e($docNav['checkin'] ?? ($docAdminBase . '/?page=checkin')) ?>" class="doc-goto page-header-btn-secondary">Open Check-In</a>
<a href="<?= e($docNav['campaigns'] ?? ($docAdminBase . '/?page=email-campaigns')) ?>" class="doc-goto page-header-btn-secondary">Campaigns</a>
