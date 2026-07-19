<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Troubleshooting</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Common issues and where to fix them. If a sidebar item is missing, start with permissions before assuming a product bug.
</p>

<h3 id="trouble-rsvp" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Event not visible / cannot RSVP</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Confirm the event is <strong>Published</strong>, not Draft or Cancelled.</li>
  <li>Check visibility: invite-only requires an invite; internal may hide from guests.</li>
  <li>Verify registration deadline and capacity have not closed out spots.</li>
  <li>For eligibility rules (age/gender), confirm the member profile has the required fields.</li>
</ul>

<h3 id="trouble-payment" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Payment stuck pending</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open <strong>Payments</strong> and locate the row.</li>
  <li>Use <strong>reconcile / sync</strong> if the webhook may have been missed.</li>
  <li>In Settings, verify Stripe keys and webhook secret.</li>
  <li>Ask your technical admin to confirm the Stripe webhook endpoint is receiving events.</li>
</ol>

<h3 id="trouble-email" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Email not sending</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Send a test email from Settings (SMTP2GO).</li>
  <li>Check spam folders and the sender address/domain reputation.</li>
  <li>Review the event email log or campaign history for failures.</li>
  <li>Automated reminders also need server cron jobs — ask your technical admin if schedules never fire.</li>
</ul>

<h3 id="trouble-checkin" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Check-in problems</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Confirm you selected the correct event/session and that the check-in window is open.</li>
  <li>Allow camera permissions for QR scanning (HTTPS is usually required in browsers).</li>
  <li>If offline, keep the tab open until the queue syncs after reconnect.</li>
  <li>Eligibility blocks may prevent check-in — verify member profile data or use an authorized override.</li>
</ul>

<h3 id="trouble-permissions" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Missing menu items</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  Coordinators and customized admin accounts only see links their capabilities allow. An admin with Settings access can grant events, programs, facilities, payments, campaigns, reports, or settings rights under Team &amp; permissions.
</p>

<h3 id="trouble-facility" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Facility booking conflicts</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Check operating hours and blocked periods on the facility.</li>
  <li>Look for published events linked to that facility in the same slot.</li>
  <li>Review pending bookings that may already hold the time.</li>
</ul>

<h3 id="trouble-more" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Still stuck?</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Check <strong>Activity Log</strong> for recent admin actions.</li>
  <li>Open <strong>Health</strong> for integration/runtime status.</li>
  <li>Contact your organization owner or technical administrator for server, cron, Stripe, or SMTP issues.</li>
</ul>

<a href="<?= e($docNav['payment-transfers'] ?? ($docAdminBase . '/?page=payment-transfers')) ?>" class="doc-goto page-header-btn-secondary">Payments</a>
<a href="<?= e($docNav['settings'] ?? ($docAdminBase . '/?page=settings')) ?>" class="doc-goto page-header-btn-secondary">Settings</a>
<a href="<?= e($docNav['activity-log'] ?? ($docAdminBase . '/?page=activity-log')) ?>" class="doc-goto page-header-btn-secondary">Activity Log</a>
<a href="<?= e($docNav['health'] ?? ($docAdminBase . '/?page=health')) ?>" class="doc-goto page-header-btn-secondary">Health</a>
