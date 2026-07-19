<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Creating &amp; managing events</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Events are one-time or recurring gatherings with RSVPs, optional tickets, check-in, and communications. Use the multi-step Create Event wizard, then manage everything from the event details page.
</p>

<h3 id="events-list" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Find and manage events</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open <strong>Events → All Events</strong>.</li>
  <li>Search by title, filter by status or category, and page through results.</li>
  <li>For recurring series, switch between grouped series view and expanded individual sessions.</li>
  <li>Use <strong>Events → Calendar</strong> for month/week/day scheduling views.</li>
  <li>From a row you can open details, edit, duplicate, start check-in, or change status.</li>
</ol>

<h3 id="events-create" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Create an event (wizard)</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Go to <strong>Events → Create Event</strong> (or the Create Event button on All Events).</li>
  <li>Complete each step below, then review and submit.</li>
</ol>

<h3 id="events-basics" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Step 1 — Basics</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Title</strong> (required) and rich <strong>Description</strong>.</li>
  <li><strong>Banner image</strong> for the portal and share pages.</li>
  <li><strong>Visibility</strong>:
    <ul>
      <li><em>Public</em> — listed for members (and guests if guest RSVP is allowed).</li>
      <li><em>Internal</em> — visible to logged-in members according to your org rules.</li>
      <li><em>Invite-only</em> — only invited members/guests can register; manage invites on the event details page after create.</li>
    </ul>
  </li>
  <li><strong>Categories</strong> — assign one or more event categories (managed in Settings).</li>
</ul>

<h3 id="events-schedule" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Step 2 — Schedule &amp; location</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Date</strong> and fixed start/end times, or <strong>prayer-relative</strong> start (Fajr, Dhuhr, Asr, Maghrib, Isha) plus an offset.</li>
  <li>Mark as <strong>virtual</strong> when there is no physical venue.</li>
  <li>Enter <strong>location</strong> and optional extra details.</li>
  <li>Optionally <strong>link a facility</strong> — when the event is published, that facility slot is blocked for bookings.</li>
  <li>Set custom <strong>check-in open/close</strong> times if you do not want check-in for the whole event window.</li>
</ul>

<h3 id="events-recurrence" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Recurring series</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">When creating or editing, you can define a series:</p>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Patterns: daily, weekly, monthly by date, monthly by weekday, yearly, or explicit dates.</li>
  <li>Interval (every N periods), and end after a count, on a date, or open-ended (system-limited generation).</li>
  <li><strong>Registration modes</strong>:
    <ul>
      <li>Independent RSVP per session</li>
      <li>Choose one session</li>
      <li>One RSVP covers every published session</li>
    </ul>
  </li>
</ul>

<h3 id="events-pricing" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Step 3 — Registration &amp; pricing</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Capacity</strong> and <strong>registration deadline</strong>.</li>
  <li>Require RSVP, allow <strong>unauthenticated guest RSVP</strong>, allow registrants to <strong>bring guests</strong>.</li>
  <li><strong>Age / gender eligibility</strong> — optionally enforced at check-in.</li>
  <li><strong>Potluck</strong> signup and allowed food categories.</li>
  <li><strong>Post-event feedback</strong> collection.</li>
  <li><strong>Pricing options</strong>:
    <ul>
      <li>Free registration</li>
      <li>Single per-person ticket price</li>
      <li>Headcount-tier package pricing</li>
      <li><strong>Named ticket types</strong> — name, price, quantity limit, sale start/end (early bird), and package groups (pick one option per group). Named ticket types override headcount-tier pricing when used.</li>
    </ul>
  </li>
</ul>
<div class="doc-callout doc-callout-warn">
  Paid events require Stripe to be configured under Settings. Without keys and a working webhook, checkout and payment status updates will fail.
</div>

<h3 id="events-publish" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Step 4 — Publishing</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Draft</strong> — not visible for registration on the portal.</li>
  <li><strong>Publish</strong> — open according to visibility and registration settings.</li>
  <li>You can also leave draft now and publish later from edit or the events list.</li>
</ul>

<h3 id="events-questions" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Step 5 — Custom questions</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Question types: text, number, checkbox, radio, dropdown, multi-choice.</li>
  <li>Mark questions required; add options for choice types.</li>
  <li>Use conditional logic (“show only when…”) to reveal follow-up questions.</li>
</ul>

<h3 id="events-review" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Step 6 — Review &amp; submit</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  Confirm all details, then create the event. You will land on the event details hub to share links, manage invites, and monitor RSVPs.
</p>

<h3 id="events-statuses" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Event statuses</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Draft</strong> — hidden from public registration.</li>
  <li><strong>Published</strong> — live for eligible members/guests.</li>
  <li><strong>Cancelled</strong> — no longer open; communicate with attendees as needed.</li>
  <li><strong>Completed</strong> — finished event (used for history and reporting).</li>
</ul>

<h3 id="events-duplicate" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Duplicate, edit, cancel</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>On All Events, use <strong>Duplicate</strong> to clone an event, then edit dates and details.</li>
  <li>Use <strong>Edit</strong> to reopen the wizard for an existing event.</li>
  <li>Cancel or delete carefully — cancelled events stop new registration; deletion removes the event (use only when appropriate).</li>
</ol>

<a href="<?= e($docNav['event-create'] ?? ($docAdminBase . '/?page=event-create')) ?>" class="doc-goto page-header-btn-primary">Create Event</a>
<a href="<?= e($docNav['events'] ?? ($docAdminBase . '/?page=events')) ?>" class="doc-goto page-header-btn-secondary">All Events</a>
<a href="<?= e($docNav['events-calendar'] ?? ($docAdminBase . '/?page=events-calendar')) ?>" class="doc-goto page-header-btn-secondary">Events Calendar</a>
