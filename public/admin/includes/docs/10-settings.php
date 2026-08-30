<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Settings &amp; integrations</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  Settings controls organization identity, payments, email, team permissions, kiosk, WordPress/API, and maintenance tools. Access usually requires admin plus <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">settings.access</code>.
</p>

<h3 id="settings-org" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Organization &amp; branding</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Organization name, location, and timezone.</li>
  <li>Logo used in outgoing email and branded exports.</li>
  <li>Primary branding colors where supported.</li>
  <li>RSVP / program <strong>liability waiver</strong> text shown during registration.</li>
  <li>Facility booking <strong>food safety waiver</strong> (SOP-MAF-042) shown when members and guests book a space.</li>
</ul>

<h3 id="settings-stripe" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Stripe (payments)</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Enter publishable and secret API keys.</li>
  <li>Enter the <strong>webhook secret</strong> matching your Stripe endpoint.</li>
  <li>Without a working webhook, checkouts may succeed in Stripe but stay pending in Headcount — use Payments reconciliation if needed.</li>
</ol>

<h3 id="settings-email" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">SMTP2GO (email)</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Enter API key, from address, and from name.</li>
  <li>Send a test message to yourself.</li>
  <li>Confirm campaigns and transactional mail after the test succeeds.</li>
</ol>

<h3 id="settings-categories" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Event categories</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">
  Maintain event categories used when creating events and filtering lists/reports. Program categories are managed from the Programs area.
</p>

<h3 id="settings-team" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Team &amp; permissions</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Invite or create <strong>admin</strong> and <strong>coordinator</strong> accounts.</li>
  <li>Adjust <strong>role defaults</strong> and <strong>per-user overrides</strong> for capabilities such as events, check-in, members, payments, programs, facilities, campaigns, reports, and settings.</li>
  <li>Super admin / owner can transfer ownership when needed.</li>
</ol>

<h3 id="settings-kiosk" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Public kiosk</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Configure lobby display mode (board or slideshow) and refresh behavior.</li>
  <li>Use the public kiosk URL on a venue display (no staff login on the screen).</li>
</ul>

<h3 id="settings-wordpress" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">WordPress &amp; public API</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Generate an organization API key for the WordPress plugin / public event feeds.</li>
  <li>Follow the inline WordPress shortcode instructions in Settings to embed list, grid, or calendar views on your site.</li>
</ul>

<h3 id="settings-maintenance" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">System info, health &amp; backup</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Review PHP/database system information in Settings.</li>
  <li>Use the <strong>Health</strong> page (when available) for runtime, storage, database, and integration checks.</li>
  <li>Create a <strong>database backup</strong> before major changes.</li>
</ul>
<div class="doc-callout doc-callout-warn">
  Destructive tools that clear events or attendance data are irreversible. Only use them with a verified backup and clear organizational approval. Prefer archive/cancel for normal cleanup.
</div>

<a href="<?= e($docNav['settings'] ?? ($docAdminBase . '/?page=settings')) ?>" class="doc-goto page-header-btn-primary">Open Settings</a>
<a href="<?= e($docNav['health'] ?? ($docAdminBase . '/?page=health')) ?>" class="doc-goto page-header-btn-secondary">Health</a>
<a href="<?= e($docNav['activity-log'] ?? ($docAdminBase . '/?page=activity-log')) ?>" class="doc-goto page-header-btn-secondary">Activity Log</a>
