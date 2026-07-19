<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Check-in</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Use the dedicated Check-In page on event day to mark attendance quickly by search or QR scan. It supports guest counts, walk-up member creation, undo, and offline sync.
</p>

<h3 id="checkin-start" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Start check-in</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open <strong>Check-In</strong> from the sidebar, or choose <strong>Check-In</strong> on an event from All Events / event details.</li>
  <li>Select the event (and series session if prompted).</li>
  <li>Confirm live counts: RSVPs, people expected, and already checked in.</li>
</ol>
<div class="doc-callout doc-callout-info">
  Check-in open/close windows can be customized on the event. If the button is disabled, verify the event schedule and check-in window.
</div>

<h3 id="checkin-search" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Method 1 — Search</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Type a name or email in the search box.</li>
  <li>Select the person from the RSVP roster or results.</li>
  <li>Enter accompanying <strong>guest count</strong> when applicable.</li>
  <li>Confirm check-in. Switch between list and card views as you prefer.</li>
</ol>

<h3 id="checkin-qr" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Method 2 — QR scan</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open the QR scanner on the Check-In page.</li>
  <li>Allow camera access in the browser when prompted.</li>
  <li>Point at the member’s portal QR code (members can open/print it from their portal).</li>
  <li>The attendee is checked in automatically when the code is recognized.</li>
</ol>

<h3 id="checkin-extras" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Add member, undo, eligibility</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li><strong>Add a new member</strong> during check-in when a walk-up is not yet in the directory (subject to permissions).</li>
  <li><strong>Undo check-in</strong> if someone was marked by mistake.</li>
  <li>If the event enforces <strong>age/gender eligibility</strong>, ineligible people may be blocked — use authorized overrides only when your role allows.</li>
</ul>

<h3 id="checkin-offline" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Offline mode</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  The Check-In page caches the roster and queues actions when connectivity drops. When the network returns, queued check-ins sync automatically. Keep the tab open until sync finishes after reconnecting.
</p>
<div class="doc-callout doc-callout-tip">
  For venue lobbies, pair check-in with the public <strong>kiosk</strong> display (Settings → Kiosk) so guests can see today’s events while staff check people in.
</div>

<h3 id="checkin-vs-program" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Events vs program attendance</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  Event check-in is for events. Program session attendance (present / absent / excused) is recorded under <strong>Programs → Attendance</strong> or on the program details page — see the Programs section.
</p>

<a href="<?= e($docNav['checkin'] ?? ($docAdminBase . '/?page=checkin')) ?>" class="doc-goto page-header-btn-primary">Open Check-In</a>
<a href="<?= e($docNav['events'] ?? ($docAdminBase . '/?page=events')) ?>" class="doc-goto page-header-btn-secondary">All Events</a>
<a href="<?= e($docNav['program-attendance'] ?? ($docAdminBase . '/?page=program-attendance')) ?>" class="doc-goto page-header-btn-secondary">Program Attendance</a>
