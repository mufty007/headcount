<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Glossary</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Short definitions for terms used across Headcount.
</p>

<dl class="mt-4 space-y-4 text-sm text-gray-700 dark:text-gray-300">
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">RSVP</dt>
    <dd class="mt-1">A registration response for an event (typically attending / not attending), optionally with guests, tickets, and answers to custom questions.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Series / recurring event</dt>
    <dd class="mt-1">An event pattern that generates multiple sessions (daily, weekly, monthly, etc.) with shared or per-session registration rules.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Invite-only</dt>
    <dd class="mt-1">Visibility mode where only invited members or emailed guests can register.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Ticket type</dt>
    <dd class="mt-1">A named paid option on an event (price, quantity, sale window). Package groups let registrants pick one option within a group.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Headcount-tier pricing</dt>
    <dd class="mt-1">Package pricing based on party size tiers. Named ticket types supersede this when configured.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Program</dt>
    <dd class="mt-1">An ongoing class or course with generated sessions, enrollment, and per-session attendance — distinct from a one-off event.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Selectable weeks</dt>
    <dd class="mt-1">Program enrollment mode where registrants choose specific weeks (with per-week or bundle pricing) instead of the whole program only.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Sponsored enrollment</dt>
    <dd class="mt-1">Staff-created program registration for a member or guest, often when payment is covered or waived by the organization.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Check-in</dt>
    <dd class="mt-1">Marking that someone arrived at an event (search or QR). Different from program session attendance statuses.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">No-show</dt>
    <dd class="mt-1">Someone who RSVP’d yes (or equivalent) but was not checked in — tracked in reports.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Authorization hold / capture / release</dt>
    <dd class="mt-1">Facility payment flow: funds are held at booking, captured on approval, or released on rejection/cancellation.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Reconcile</dt>
    <dd class="mt-1">Syncing Headcount payment rows with Stripe when webhooks were missed or status is out of date.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Merge tag</dt>
    <dd class="mt-1">A placeholder in an email template or campaign (for example a name field) replaced with each recipient’s data at send time.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Capability / permission</dt>
    <dd class="mt-1">A granular right (such as <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">events.manage</code>) granted by role default or per-user override.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Kiosk</dt>
    <dd class="mt-1">Public lobby display of upcoming events; not used for staff check-in.</dd>
  </div>
  <div>
    <dt class="font-semibold text-gray-900 dark:text-white">Prayer-relative time</dt>
    <dd class="mt-1">Scheduling start/end relative to a prayer time (Fajr, Dhuhr, Asr, Maghrib, Isha) plus an offset, using your org location.</dd>
  </div>
</dl>

<a href="#getting-started" class="doc-goto page-header-btn-secondary">Back to Getting started</a>
<a href="<?= e($docNav['documentation'] ?? ($docAdminBase . '/?page=documentation')) ?>" class="doc-goto page-header-btn-secondary">Documentation home</a>
