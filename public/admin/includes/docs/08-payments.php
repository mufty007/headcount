<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Payments &amp; refunds</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Stripe powers paid event registration, program payments/subscriptions, and paid facility bookings. Admins reconcile payments, issue refunds, record cash, and review attendee refund requests.
</p>

<h3 id="payments-setup" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Before you take payments</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>In <strong>Settings</strong>, enter Stripe API keys and the webhook secret.</li>
  <li>Confirm your technical admin has pointed Stripe webhooks at this installation.</li>
  <li>Send a test paid registration in a draft/test environment when possible.</li>
</ol>

<h3 id="payments-page" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Payments page</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Open <strong>Reports &amp; Finance → Payments</strong>.</li>
  <li>Switch among event, facility, and program payment views/tabs as available.</li>
  <li>Filter by status: collected, pending, failed, authorized, captured, subscription, etc.</li>
  <li>Use charts for payment and refund trends when shown.</li>
  <li><strong>Reconcile</strong> when a webhook was missed — sync status from Stripe for incomplete rows.</li>
  <li>Issue <strong>full or partial refunds</strong> from a payment when authorized.</li>
</ol>

<h3 id="payments-cash" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Cash payments</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  For events, record cash on the <strong>event details</strong> page against an attendee so reporting stays complete. Remove mistaken cash entries when your role allows.
</p>

<h3 id="payments-refund-requests" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Refund requests</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Paid attendees who did not check in may submit a refund request from the portal after the event.</li>
  <li>Open <strong>Reports &amp; Finance → Refund Requests</strong>.</li>
  <li>Read the request reason and payment details.</li>
  <li><strong>Approve</strong> (processes via Stripe) or <strong>deny</strong> with notes.</li>
  <li>The outcome is recorded and the member can be notified.</li>
</ol>
<div class="doc-callout doc-callout-warn">
  Refund processing typically requires <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">refunds.process</code>. Payment management uses <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">payments.manage</code>.
</div>

<a href="<?= e($docNav['payment-transfers'] ?? ($docAdminBase . '/?page=payment-transfers')) ?>" class="doc-goto page-header-btn-primary">Payments</a>
<a href="<?= e($docNav['refund-requests'] ?? ($docAdminBase . '/?page=refund-requests')) ?>" class="doc-goto page-header-btn-secondary">Refund Requests</a>
<a href="<?= e($docNav['settings'] ?? ($docAdminBase . '/?page=settings')) ?>" class="doc-goto page-header-btn-secondary">Settings (Stripe)</a>
