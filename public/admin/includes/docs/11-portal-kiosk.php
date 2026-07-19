<?php
/** @var array $docNav */
/** @var string $docAdminBase */
?>
<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Member portal &amp; kiosk</h2>
<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
  The member portal is what participants use. The public kiosk is a lobby display. Understanding both helps you publish events and programs correctly.
</p>

<h3 id="portal-members" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">What members can do</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Browse eligible public or invited events and RSVP (including ticket selection and payment).</li>
  <li>Add events to Google Calendar or download ICS files.</li>
  <li>Register for programs, select weeks, and apply coupons when offered.</li>
  <li>View payments and printable receipts; request refunds when eligible.</li>
  <li>Maintain profile, photo, DOB, gender, and communication preferences.</li>
  <li>Manage family members.</li>
  <li>Display, download, or print their <strong>check-in QR code</strong>.</li>
  <li>View upcoming events, past attendance, RSVPs, and no-shows.</li>
  <li>Browse and request facility bookings.</li>
  <li>Submit post-event feedback when enabled.</li>
</ul>

<h3 id="portal-guests" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Guest flows (no login)</h3>
<p class="text-sm text-gray-700 dark:text-gray-300">When you enable guest options on an event, program, or facility:</p>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Guests can view public event details and RSVP/pay.</li>
  <li>Guests can register for programs or request facility bookings where allowed.</li>
  <li>They may receive email to complete an account later.</li>
  <li><strong>Invite-only</strong> events remain restricted to invited identities even if guest RSVP exists elsewhere.</li>
</ul>

<h3 id="portal-visibility" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Publishing checklist</h3>
<ol class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Set status to <strong>Published</strong> (draft items stay hidden).</li>
  <li>Confirm visibility (public / internal / invite-only) matches your intent.</li>
  <li>For invite-only, add invites before sharing widely.</li>
  <li>Share the portal link or QR from the event/program details page.</li>
  <li>Verify a test account can see and complete registration.</li>
</ol>

<h3 id="portal-kiosk" class="mt-6 text-base font-semibold text-gray-800 dark:text-gray-100">Lobby kiosk</h3>
<ul class="mt-2 text-sm text-gray-700 dark:text-gray-300">
  <li>Configure display options under <strong>Settings → Kiosk</strong>.</li>
  <li>Modes typically include a board view and a slideshow of upcoming events.</li>
  <li>Open the public kiosk URL on a tablet or TV in the lobby; it refreshes automatically.</li>
  <li>Kiosk is read-only public display — staff still use admin Check-In for attendance.</li>
</ul>

<div class="doc-callout doc-callout-tip">
  Members should keep their QR code handy (phone or printout) for the fastest check-in line. Staff can still search by name if someone forgets their code.
</div>

<a href="<?= e($docNav['settings'] ?? ($docAdminBase . '/?page=settings')) ?>" class="doc-goto page-header-btn-primary">Settings (Kiosk)</a>
<a href="<?= e($docNav['events'] ?? ($docAdminBase . '/?page=events')) ?>" class="doc-goto page-header-btn-secondary">Events</a>
<a href="<?= e($docNav['checkin'] ?? ($docAdminBase . '/?page=checkin')) ?>" class="doc-goto page-header-btn-secondary">Check-In</a>
