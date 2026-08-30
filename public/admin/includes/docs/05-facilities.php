<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Facilities &amp; bookings</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Facilities are bookable rooms or spaces. Members and guests can request time slots; staff approve requests, manage calendars, and optionally collect payment via Stripe authorization holds.
</p>

<h3 id="facilities-create" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Create a facility</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Go to <strong>Facilities → All Facilities</strong> and create/edit a facility.</li>
  <li>Enter name, description, capacity, images, and status.</li>
  <li>Set <strong>hourly pricing</strong> and any discounts.</li>
  <li>Define <strong>operating hours</strong>.</li>
  <li>Add <strong>blocked periods</strong> for maintenance, internal events, or reserved time.</li>
  <li>Configure separate <strong>member</strong> and <strong>guest</strong> booking limits and whether each audience may book.</li>
  <li>Assign <strong>facility managers</strong> who help oversee that space.</li>
  <li>Save — the facility becomes available according to its status and rules.</li>
</ol>

<h3 id="facilities-bookings" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Review booking requests</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open <strong>Facilities → Bookings</strong>.</li>
  <li>Filter by pending, approved, rejected, or cancelled.</li>
  <li><strong>Approve</strong> a request to confirm the slot (and capture payment if a hold exists).</li>
  <li><strong>Reject</strong> or <strong>cancel</strong> to free the slot (and release a payment hold when applicable).</li>
  <li>Member and guest requests include a <strong>food safety waiver</strong> (setup location and typed signature). Use <strong>View waiver</strong> to print a copy. Staff-created bookings skip the waiver.</li>
</ol>

<h3 id="facilities-payments" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Paid bookings (authorization holds)</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>At checkout, payment is <strong>authorized</strong> (held), not always captured immediately.</li>
  <li>On <strong>approval</strong>, the hold is <strong>captured</strong>.</li>
  <li>On <strong>rejection or cancellation</strong>, the hold is <strong>released</strong>.</li>
  <li>Expired holds are cleaned up automatically by background jobs when cron is configured.</li>
</ol>
<div class="doc-callout doc-callout-warn">
  Paid facility bookings require Stripe. If approvals succeed but money never settles, check Settings → Payments and the Payments reconciliation tools.
</div>

<h3 id="facilities-calendars" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Calendars &amp; event blocking</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Use the per-facility calendar on facility details, or <strong>Facilities → Bookings Calendar</strong> for the whole organization.</li>
  <li>When you <strong>link a published event</strong> to a facility, that time is blocked so bookings do not conflict.</li>
</ul>

<a href="<?= e($docNav['facilities'] ?? ($docAdminBase . '/?page=facilities')) ?>" class="doc-goto page-header-btn-primary">All Facilities</a>
<a href="<?= e($docNav['facility-bookings'] ?? ($docAdminBase . '/?page=facility-bookings')) ?>" class="doc-goto page-header-btn-secondary">Bookings</a>
<a href="<?= e($docNav['facility-bookings-calendar'] ?? ($docAdminBase . '/?page=facility-bookings-calendar')) ?>" class="doc-goto page-header-btn-secondary">Bookings Calendar</a>
