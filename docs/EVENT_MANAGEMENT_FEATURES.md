# Headcount — Event Management Features

A complete inventory of event management capabilities in the Headcount platform. This covers the admin interface, member portal, APIs, background jobs, and integrations.

---

## Table of Contents

1. [Event CRUD & Administration](#1-event-crud--administration)
2. [Event Configuration & Metadata](#2-event-configuration--metadata)
3. [Recurring Events & Series](#3-recurring-events--series)
4. [RSVP & Registration (Members)](#4-rsvp--registration-members)
5. [Guest RSVP (No Login)](#5-guest-rsvp-no-login)
6. [Family / Household RSVP](#6-family--household-rsvp)
7. [Custom Registration Questions](#7-custom-registration-questions)
8. [Eligibility, Restrictions & Waivers](#8-eligibility-restrictions--waivers)
9. [Visibility & Invites](#9-visibility--invites)
10. [Potluck / Food Signup](#10-potluck--food-signup)
11. [Ticketing & Pricing](#11-ticketing--pricing)
12. [Payments](#12-payments)
13. [Refunds](#13-refunds)
14. [Check-In & Attendance](#14-check-in--attendance)
15. [QR Codes](#15-qr-codes)
16. [Communications & Transactional Email](#16-communications--transactional-email)
17. [Email Campaigns (Marketing)](#17-email-campaigns-marketing)
18. [Reporting & Exports](#18-reporting--exports)
19. [Member Portal (Event-Facing)](#19-member-portal-event-facing)
20. [Kiosk / Digital Signage](#20-kiosk--digital-signage)
21. [Public APIs & Integrations](#21-public-apis--integrations)
22. [Organization Settings (Event-Related)](#22-organization-settings-event-related)
23. [Roles & Permissions](#23-roles--permissions)
24. [Background Jobs (Cron)](#24-background-jobs-cron)
25. [Related Domains](#25-related-domains)

---

## 1. Event CRUD & Administration

| Feature | Location | Description |
|---------|----------|-------------|
| **Events list** | `public/admin/events.php` | Event grid with status/category/search filters, pagination, series collapsing, coordinator vs. admin access; link to **Calendar** view |
| **Events calendar** | `public/admin/events-calendar.php`, `public/js/events-calendar.js` | FullCalendar month/week/day; status filters; click event → side panel with RSVP/check-in links; click date → create event |
| **Events calendar API** | `public/api/events.php?action=calendar` | Date-range feed of all session rows (FullCalendar JSON); optional `status` filter |
| **Event calendar service** | `src/Services/EventCalendarService.php` | Builds calendar payloads with RSVP head counts and facility names |
| **Create event (6-step wizard)** | `public/admin/event-create.php`, `public/admin/js/event-wizard-steps.js` | Multi-step create: Basics → Schedule → Registration → Options → Questions → Review |
| **Edit event (5-step wizard)** | `public/admin/event-edit.php`, `public/admin/js/event-wizard-steps.js` | Same wizard for updates; loads questions, ticket types, recurrence, potluck, people, etc. |
| **Event details / management hub** | `public/admin/event-details.php`, `public/admin/components/event-header.php` | Per-event admin page with tabs for details, RSVP report, email actions, invites, stats |
| **Events API** | `public/api/events.php` | CRUD plus `duplicate`, `delete`, `rsvps`, `delete-rsvp`, `announce`, `remind`, `resend-confirmations`, invite actions |
| **Event service** | `src/Services/EventService.php`, `src/Models/Event.php` | Validation, create/update/duplicate/delete, list/upcoming with pagination |
| **Activity logging** | `src/Services/ActivityLogger.php` | Logs admin actions including cash payments and check-in corrections |

---

## 2. Event Configuration & Metadata

| Feature | Location | Description |
|---------|----------|-------------|
| **Core fields** | Event wizard, `events` table | Title, description, date/time, location, capacity, status (draft/published/cancelled), registration required/deadline |
| **Banner image** | Migration `014_add_banner_image_to_events.sql` | Event banner; recurring instances can inherit parent banner |
| **Categories** | `database/migrations/003_create_event_categories_table.sql`, settings API | Many-to-many event ↔ category tagging |
| **Virtual events** | Migration `024_add_virtual_events.sql` | `is_virtual` flag plus virtual event details |
| **Extra details** | Event create/edit wizard | Rich-text extra details field |
| **Facility linkage** | Migration `063_events_facility_id.sql`, `FacilityService` | Optional link to org facility for location/booking context |
| **Event people (speakers/organisers)** | Migration `051_event_and_program_people.sql`, `src/Services/EventPeopleService.php` | Named speakers/organisers with title and photo |
| **Prayer-based scheduling** | Migration `046_prayer_scheduling.sql`, `PrayerTimesService` | Start time relative to prayer (e.g. after Maghrib + offset) using org city/country |
| **Check-in window** | Migration `005_add_checkin_window_to_events.sql` | `checkin_window_start` / `checkin_window_end` restrict when check-in is allowed |
| **Registration modes** | `events.registration_required`, `registration_deadline` | Online RSVP open/closed logic via `RSVPService::isRegistrationDeadlinePassed` |
| **Walk-ins** | `events.allow_walk_ins` | Allow door check-in without prior RSVP when online registration is closed |

---

## 3. Recurring Events & Series

| Feature | Location | Description |
|---------|----------|-------------|
| **Recurrence patterns** | `database/migrations/004_create_recurring_events_table.sql`, `public/admin/includes/event-recurrence-fields.php` | Daily/weekly/monthly/yearly/custom patterns; parent/child instance model |
| **Custom session dates** | Migration `037_recurring_events_custom_dates.sql` | JSON custom session dates for non-standard schedules |
| **Monthly weekday recurrence** | Migration `019_add_monthly_weekday_recurrence.sql` | e.g. "2nd Tuesday of each month" |
| **Recurring event service** | `src/Services/RecurringEventService.php` | `generateInstances`, `generateUpcomingInstances`, `deleteAllInstances` |
| **Admin recurrence service** | `src/Services/AdminEventRecurrenceService.php` | Parses admin POST input for recurrence settings |
| **Recurring events API** | `public/api/recurring-events.php` | `generate`, `delete_instances`, `get`, `generate_all` |
| **Cron: generate instances** | `cron/generate-recurring-events.php` | Scheduled generation of upcoming recurring instances |
| **Session registration modes** | Migration `036_add_session_registration_mode.sql`, `src/Services/EventSeriesHelper.php` | `independent` (per session), `choose_one`, `all_sessions` (one RSVP for whole series) |
| **Series helper** | `src/Services/EventSeriesHelper.php` | RSVP source event ID, preferred landing session, series session lists, clearing conflicting RSVPs |

---

## 4. RSVP & Registration (Members)

| Feature | Location | Description |
|---------|----------|-------------|
| **RSVP service** | `src/Services/RSVPService.php`, `src/Models/RSVP.php` | Create/update/cancel RSVP; capacity checks; guest count; family member RSVPs; duplicate email validation |
| **Portal RSVP API** | `public/api/portal/rsvps.php` | `GET my`, `GET event`, `POST` create, `PUT` update, `DELETE` cancel; potluck + waiver + question answers |
| **Portal event details + RSVP UI** | `public/portal/event-details.php` | Full RSVP/payment UX: capacity, series modes, ticket types, tier pricing, potluck, guest options, eligibility |
| **My RSVPs page** | `public/portal/my-rsvps.php` | Member RSVP history with status/date filters |
| **Admin RSVP list** | `public/admin/event-details.php`, `public/api/events.php` | Admin view of RSVPs with question answers, potluck data, family links, payments |
| **Admin delete RSVP** | `public/api/events.php` action `delete-rsvp` | Remove an attendee's RSVP from admin |
| **RSVP export (CSV)** | `public/api/event-rsvp-export.php` | Per-event CSV with guests, questions, potluck fields |
| **RSVP confirmation email** | `src/Services/PortalEmailService.php`, `templates/portal/rsvp-confirmation.html` | Confirmation on RSVP; guest variant with account-complete link |
| **Resend confirmations** | `public/api/events.php` action `resend-confirmations` | Bulk resend RSVP confirmation emails to "yes" RSVPs |

---

## 5. Guest RSVP (No Login)

| Feature | Location | Description |
|---------|----------|-------------|
| **Allow guest RSVP flag** | Migration `020_event_questions_guest_rsvp.sql` | `allow_guest_rsvp` on events |
| **Guest RSVP API** | `public/api/portal/guest-rsvp.php` | Public POST: creates guest user + RSVP; validates waiver, eligibility, custom questions, potluck |
| **Guest paid checkout** | `public/api/portal/guest-rsvp-checkout.php` | Stripe checkout for guests on paid/ticket-type events without portal login |
| **Bring additional guests** | Migration `045_allow_bring_guests.sql` | `allow_bring_guests` + `guest_count` on RSVPs for "+N guests" field |
| **Guest RSVP visibility** | `src/Services/EventVisibilityService.php` | Guest RSVP blocked for internal/invite-only visibility in some cases |

---

## 6. Family / Household RSVP

| Feature | Location | Description |
|---------|----------|-------------|
| **Family member RSVPs** | Migration `050_household_rsvp_restrictions.sql` (`rsvp_family_members`) | Link family members to a parent's RSVP |
| **Family portal API** | `public/api/portal/family.php` | Manage household members used during RSVP |
| **Family page** | `public/portal/family.php` | Portal UI for family management |
| **Per-family-member check-in** | Migration `050` (`attendance.family_member_id`) | Check in individual family slots under parent account |

---

## 7. Custom Registration Questions

| Feature | Location | Description |
|---------|----------|-------------|
| **Question tables** | Migrations `020`, `034` | `event_questions`, `event_question_options`, `rsvp_question_answers`; types: text, short_text, checkbox, number |
| **Conditional questions** | Migration `034_event_question_options_and_conditionals.sql` | `depends_on_question_id` + `depends_on_value` for show-if logic |
| **Question merge service** | `src/Services/EventQuestionMergeService.php` | Saves/replaces question set on event create/update |
| **Admin question UI** | `public/admin/js/event-custom-questions.js` | Drag/sort/add questions in wizard step 5 |
| **Answers in exports/reports** | `event-rsvp-export.php`, events API `rsvps` action | Question answers attached to RSVP rows |

---

## 8. Eligibility, Restrictions & Waivers

| Feature | Location | Description |
|---------|----------|-------------|
| **Age/gender restrictions** | Migration `050_household_rsvp_restrictions.sql`, `EventEligibilityService` | `min_age`, `max_age`, `gender_restriction`, `enforce_restrictions_at_checkin` |
| **Eligibility service** | `src/Services/EventEligibilityService.php` | Portal + guest validation; age-at-event calculation; guest DOB/gender persistence |
| **RSVP waiver (org-level)** | Migration `058_organization_rsvp_waiver.sql`, `settings.php` action `update_waiver` | Org checkbox label + full waiver text; `waiver_accepted_at` on `rsvps` |
| **Waiver in portal RSVP** | `guest-rsvp.php`, `rsvps.php`, `event-details.php` | Waiver required before RSVP when enabled |
| **Check-in eligibility enforcement** | `public/api/checkin.php`, `public/api/portal/checkin.php` | Blocks check-in when restrictions enforced and member ineligible |

---

## 9. Visibility & Invites

| Feature | Location | Description |
|---------|----------|-------------|
| **Visibility modes** | Migration `052_event_visibility_and_invites.sql`, `EventVisibilityService` | `public`, `internal` (members only), `invite_only` |
| **Event invites table** | Migration `052` (`event_invites`) | Per-user invite list for invite-only events |
| **Invite service** | `src/Services/EventInviteService.php` | List/add/remove invites; invite guest by email; storage on series parent for `all_sessions` |
| **Invite API actions** | `public/api/events.php` | `event-invites`, `add-event-invites`, `remove-event-invite`, `invite-guest-by-email` |
| **Invite UI** | `public/admin/event-details.php` | Manage invites tab |
| **Portal visibility gate** | `public/portal/events.php`, `public/api/portal/events.php` | Hides internal/invite-only events from unauthorized viewers |

---

## 10. Potluck / Food Signup

| Feature | Location | Description |
|---------|----------|-------------|
| **Potluck flag** | Migrations `049`, `055`, `056` | `is_potluck`, allowed category slugs, optional "bringing food?" prompt |
| **RSVP potluck fields** | Migrations `049`, `053` | Category, item note, quantity, serving side, party adults/children |
| **Potluck category service** | `src/Services/PotluckCategoryService.php` | Category catalog, validation, normalization, public signup list sorting |
| **Public potluck display** | `public/portal/event-details.php`, `public/api/portal/events.php` | Public list of who is bringing what |
| **Potluck on RSVP create/update** | `public/api/portal/rsvps.php`, `guest-rsvp.php` | Validates and stores potluck signup with RSVP |

---

## 11. Ticketing & Pricing

| Feature | Location | Description |
|---------|----------|-------------|
| **Simple ticket price** | `events.ticket_price` | Single flat price per event |
| **Multiple ticket types** | Migration `022_event_ticket_types.sql`, `EventTicketTypesPersistence` | Named types (Early bird, VIP, etc.) with price, quantity limit, sort order |
| **Sale windows & package groups** | Migration `057`, `EventTicketTypeRulesService` | `sale_starts_at`/`sale_ends_at`, `package_group` for mutually exclusive tiers; portal sale countdown |
| **Headcount tier pricing** | Migration `048`, `EventHeadcountPricingService` | `pricing_model`: `per_person` vs `headcount_tier` (bundle by party size); JSON tiers |
| **Pricing tabs UI** | `public/admin/includes/event-pricing-tabs.php`, `public/admin/js/event-pricing-tabs.js` | Admin UI toggling "group/tier" vs "ticket types" |
| **Portal ticket selection** | `public/portal/event-details.php`, `guest-rsvp-checkout.php`, `portal/payments.php` | Choose tickets, validate sale windows, show "from $X" pricing |

---

## 12. Payments

| Feature | Location | Description |
|---------|----------|-------------|
| **Stripe checkout (members)** | `public/api/portal/payments.php`, `PortalPaymentService` | Stripe session for flat price, tiers, or ticket types; stores pending checkout JSON |
| **Stripe webhook / reconcile** | `PortalPaymentService` | Completes RSVP after payment; reconciliation for stuck sessions |
| **Payment history & receipts** | `public/portal/payments.php`, `PortalPaymentService` | Member payment history and PDF receipts |
| **Payment success/cancel pages** | `public/portal/payment-success.php`, `payment-cancel.php` | Post-checkout landing pages |
| **Cash payments at check-in** | `public/api/cash-payment.php` | Record/update/delete cash payment during admin check-in |
| **Admin payment transfers** | `public/admin/payment-transfers.php`, `public/api/payment-transfers.php` | Event payment summaries; `reconcile_event`, Stripe refunds |
| **Checkout pending JSON** | Migration `047_payments_checkout_pending_json.sql` | Stores ticket/guest/potluck context pre-payment |

---

## 13. Refunds

| Feature | Location | Description |
|---------|----------|-------------|
| **Refund requests table** | Migration `026_create_refund_requests_table.sql` | User-initiated requests linked to event/payment |
| **Org refund settings** | Migration `029_organization_refund_settings.sql` | `refund_request_days_after_event` deadline |
| **Portal refund API** | `public/api/portal/refund-requests.php` | Member submits refund request with reason |
| **Admin refund API** | `public/api/refund-requests.php` | Approve (Stripe refund) or deny with admin notes |
| **Admin refund UI** | `public/admin/refund-requests.php` | Review queue for attendee refund requests |
| **Admin Stripe refund** | `payment-transfers.php` API `refund` action | Direct refund from payment transfers screen |

---

## 14. Check-In & Attendance

| Feature | Location | Description |
|---------|----------|-------------|
| **Admin check-in page** | `public/admin/checkin.php` | Full check-in UI: search, RSVP list, QR scan, walk-ins, cash payment, guest count at door, offline sync, correction mode |
| **Check-in API** | `public/api/checkin.php` | Record check-in for user (+ optional family member, guests checked in, client timestamp for offline) |
| **Undo check-in** | `public/api/undo-checkin.php` | Revert check-in; past-event undo needs `canCorrectCheckins` permission |
| **Check-in override** | `public/api/checkin-override.php` | Admin correction: `checkin`, `undo`, `update` checked-in time |
| **RSVP list for check-in** | `public/api/checkin-rsvps.php` | All RSVPs with payment + check-in status for an event session |
| **Check-in sync (offline batch)** | `public/api/checkin-sync.php` | Batch apply offline check-in/undo actions; returns refreshed list |
| **Attendance service** | `src/Services/AttendanceService.php` | Search members, record/bulk/undo check-in, get attendance |
| **Guests checked in** | Migration `035_attendance_guests_checked_in.sql` | Track how many +guests actually arrived |
| **Member search for check-in** | `public/api/search-members.php` | Search org members with event-scoped check-in/RSVP status |
| **Check-in correction permission** | Migration `030_organization_checkin_correction.sql` | `coordinators_can_correct_checkins` org setting |
| **Check-in export (CSV)** | `public/api/event-checkin-export.php` | Export checked-in attendees including walk-ins |
| **Check-in JSON list** | `public/api/event-checkins.php` | JSON attendance roster for an event |

---

## 15. QR Codes

| Feature | Location | Description |
|---------|----------|-------------|
| **Member QR code** | `src/Services/QRCodeService.php`, `public/api/portal/qr-code.php`, `public/portal/qr-code.php` | Generate/validate member QR for check-in; image endpoint |
| **QR check-in (portal API)** | `public/api/portal/checkin.php` | Admin/coordinator scans member QR to check in |
| **Event share QR (admin)** | `public/api/event-share-qr.php` | QR encoding public portal event URL for promotion |
| **Admin check-in QR scanner** | `public/admin/checkin.php` | Camera-based QR scanning |

---

## 16. Communications & Transactional Email

| Feature | Location | Description |
|---------|----------|-------------|
| **Event announcement** | `public/api/events.php` action `announce` | Email all members (respecting `event_announcements` preference) about an event |
| **Manual event reminder** | `public/api/events.php` action `remind` | Email RSVP "yes" attendees who have `event_reminders` enabled |
| **Email templates** | `public/admin/email-templates.php`, `public/api/email-templates.php` | Types: `announcement`, `reminder_1day`, `reminder_1week`, `confirmation`, `follow_up`, `custom` |
| **Bulk email** | `public/api/bulk-email.php` | Send to all members or event RSVPs |
| **Email logs & resend** | `public/api/email-logs.php`, admin email-campaigns log tab | Delivery log; resend failed messages |
| **SMTP2GO webhook** | `public/api/smtp2go-webhook.php` | Tracks opens/clicks/bounces for campaign analytics |
| **Portal email service** | `src/Services/PortalEmailService.php` | RSVP confirmation/cancellation, payment receipt, event invite, magic link, welcome |
| **Email automation settings** | `public/admin/email-campaigns.php`, `settings.php` | Toggle/configure automated reminder milestones |
| **In-app notifications** | `public/admin/notifications.php`, `public/api/notifications.php` | Admin notifications including event-related alerts |

---

## 17. Email Campaigns (Marketing)

| Feature | Location | Description |
|---------|----------|-------------|
| **Campaigns admin UI** | `public/admin/email-campaigns.php`, `public/admin/campaigns.php` | Compose, audience picker, schedule, history; tabs: Campaigns, Automation, Log |
| **Campaigns API** | `public/api/campaigns.php` | `list`, `get`, `detail`, `save_draft`, `schedule`, `send`, `count_recipients`, `cancel_scheduled`, `delete`, `duplicate` |
| **Audience types** | `campaigns.php` | `all_members`, `event` (RSVP yes), `event_member`, `single_member`, `segment` (group), `manual` emails |
| **Event merge fields** | Campaigns API | Injects `{event_name}`, `{event_date}`, `{event_location}`, etc. |
| **Campaign analytics** | Migrations `030`, `031`, `033` | Tracks delivered/opened/clicked/bounced/unsubscribed per campaign |
| **Unsubscribes** | Migration `032_email_unsubscribes.sql` | Campaign sends exclude unsubscribed addresses |
| **Campaign JS helpers** | `public/admin/js/campaign-email-helpers.js` | Frontend helpers for campaign composition |

---

## 18. Reporting & Exports

| Feature | Location | Description |
|---------|----------|-------------|
| **Reports hub** | `public/admin/reports.php` | Tabs: overview, events, members, RSVP, revenue, facilities, programs |
| **Events report tab** | `public/admin/includes/reports/tab-events.php` | Event performance metrics and charts |
| **RSVP report tab** | `public/admin/includes/reports/tab-rsvp.php` | RSVP breakdowns per event |
| **Admin report service** | `src/Services/AdminReportService.php` | Event performance, RSVP trends, no-show stats, revenue by event, export helpers |
| **PDF reports** | `public/api/export-report-pdf.php`, `src/Services/ReportPdfService.php` | PDF export of report data |
| **CSV export API** | `public/api/export-report.php` | Bulk CSV exports including RSVP/attendance/revenue slices |
| **Per-event CSV exports** | `event-rsvp-export.php`, `event-checkin-export.php` | Single-event RSVP and check-in CSV downloads |

---

## 19. Member Portal (Event-Facing)

| Feature | Location | Description |
|---------|----------|-------------|
| **Public events list** | `public/portal/events.php` | Browse published events; no login required to view |
| **Event details** | `public/portal/event-details.php` | Main event page: RSVP, pay, series picker, potluck, speakers, calendar add |
| **Portal events API** | `public/api/portal/events.php` | Single-event fetch with ticket types, potluck signups, eligibility, user RSVP, series sessions |
| **Dashboard upcoming/past** | `public/api/portal/dashboard.php`, `public/portal/dashboard.php` | Member home with event cards and stats |
| **My RSVPs** | `public/portal/my-rsvps.php` | RSVP history and management |
| **Event attendees (social)** | `public/portal/event-attendees.php`, `public/api/portal/social.php` | Who's attending (checked-in/RSVP-based list) |
| **Share event** | `public/api/portal/social.php` | Share URLs / invite others to event |
| **Add to calendar** | `public/api/portal/calendar.php` | Download `.ics`, Google Calendar, Apple Calendar links |
| **Event feedback** | `public/portal/feedback.php`, `public/api/portal/feedback.php`, `public/admin/event-details.php` (Feedback tab), `public/api/event-feedback.php` | 4-question 1–5 ratings + optional text; enabled per event via **Collect post-event feedback**; emailed to checked-in attendees 1 day after event end |
| **Portal auth** | `public/api/portal/auth.php` | Login, register, magic link — required for member RSVP on non-guest events |

---

## 20. Kiosk / Digital Signage

| Feature | Location | Description |
|---------|----------|-------------|
| **Kiosk display page** | `public/portal/kiosk.php`, `portal/includes/kiosk-data.php` | Full-screen public board/slideshow of upcoming events by org slug |
| **Kiosk events API** | `public/api/portal/kiosk-events.php` | JSON poll endpoint for kiosk displays |
| **Kiosk org settings** | Migration `069_add_kiosk_settings.sql`, `settings.php` action `update_kiosk` | `kiosk_enabled`, `kiosk_mode`, `kiosk_days`, `kiosk_interval` per org |
| **Admin settings UI** | `public/admin/settings.php` | Kiosk configuration under org settings |

---

## 21. Public APIs & Integrations

| Feature | Location | Description |
|---------|----------|-------------|
| **Public events API** | `public/api/public-events.php` | API-key auth; fetch published events for WordPress/external sites |
| **Public calendar feed** | `public/api/public-calendar-feed.php` | Combined events + program sessions JSON feed for date range |
| **WordPress plugin** | `headcount-wordpress-plugin/` | Shortcodes/widgets: events grid/list/calendar/search/categories, single event, RSVP form; Elementor loop widgets |
| **Download plugin** | `public/api/download-plugin.php` | Serves WordPress plugin zip from admin |
| **Org API keys** | `OrganizationApiKeyService`, migration `065_hash_api_keys.sql` | Authenticates public API consumers |
| **Image API** | `public/api/image.php` | Serves uploaded event/banner images |

---

## 22. Organization Settings (Event-Related)

| Feature | Location | Description |
|---------|----------|-------------|
| **Waiver settings** | `public/admin/settings.php`, `public/api/settings.php` action `update_waiver` | Enable/disable waiver, label, full text |
| **Kiosk settings** | `settings.php` action `update_kiosk` | Digital signage defaults |
| **Email automation** | `settings.php` `get_email_automation` / `update_email_automation` | Automated reminder toggles and schedules |
| **Stripe settings** | `settings.php` action `update_stripe` | Org Stripe keys for event payments |
| **Refund policy** | Migration `029` | Days after event to allow refund requests |
| **Check-in correction** | Migration `030` | Whether coordinators can correct past check-ins |
| **Categories** | `settings.php` category CRUD | Event category management |

---

## 23. Roles & Permissions

| Feature | Location | Description |
|---------|----------|-------------|
| **Admin role** | Auth middleware across admin/API | Full access to events, settings, refunds, campaigns |
| **Coordinator role** | `AuthMiddleware::requireAdminOrCoordinator()` | Check-in attendees, view attendance, RSVP/check-in exports; limited event edit access |
| **Permission tables** | Migration `067_create_permission_tables.sql` | Granular capabilities: `events.manage`, `campaigns.send`, `refunds.process`, etc. |
| **Coordinator refund toggle** | `settings.php` `coordinators_can_refund` | Optional coordinator access to process refunds |

---

## 24. Background Jobs (Cron)

| Job | Location | Description |
|-----|----------|-------------|
| **Generate recurring instances** | `cron/generate-recurring-events.php` | Creates upcoming recurring event sessions |
| **Admin reminders** | `cron/reminders.php` | 1-week and 1-day automated reminders to RSVP "yes" attendees |
| **Portal reminders** | `cron/portal-reminders.php` | Portal-side reminder automation |
| **Post-event follow-up** | `cron/post-event-followup.php` | Thank-you / follow-up emails after events end |
| **Post-event feedback request** | `cron/send-event-feedback.php` | Feedback form email to checked-in attendees (events with `collect_feedback`) one day after end |
| **Send emails** | `cron/send-emails.php` | General email queue processor |
| **Scheduled campaigns** | `cron/send-scheduled-campaigns.php` | Sends due scheduled email campaigns |

---

## 25. Related Domains

These share patterns with events but are separate product areas:

| Domain | Location | Relationship to Events |
|--------|----------|------------------------|
| **Programs** | `public/admin/programs.php`, `public/portal/programs.php` | Multi-session programs with registration, coupons, staff, attendance; included in public calendar feed |
| **Facilities** | `public/admin/facility-edit.php`, `public/portal/facilities.php` | Venues linkable to events via `facility_id` |
| **Tags & groups** | `public/api/tags.php`, `public/api/groups.php` | Member segmentation; groups used as campaign audience |

---

## Services Layer (Event-Related)

| Service | Path |
|---------|------|
| `EventService` | `src/Services/EventService.php` |
| `RSVPService` | `src/Services/RSVPService.php` |
| `AttendanceService` | `src/Services/AttendanceService.php` |
| `RecurringEventService` | `src/Services/RecurringEventService.php` |
| `AdminEventRecurrenceService` | `src/Services/AdminEventRecurrenceService.php` |
| `EventSeriesHelper` | `src/Services/EventSeriesHelper.php` |
| `EventEligibilityService` | `src/Services/EventEligibilityService.php` |
| `EventVisibilityService` | `src/Services/EventVisibilityService.php` |
| `EventInviteService` | `src/Services/EventInviteService.php` |
| `EventQuestionMergeService` | `src/Services/EventQuestionMergeService.php` |
| `EventPeopleService` | `src/Services/EventPeopleService.php` |
| `EventHeadcountPricingService` | `src/Services/EventHeadcountPricingService.php` |
| `EventTicketTypeRulesService` | `src/Services/EventTicketTypeRulesService.php` |
| `PotluckCategoryService` | `src/Services/PotluckCategoryService.php` |
| `PortalPaymentService` | `src/Services/PortalPaymentService.php` |
| `PortalEmailService` | `src/Services/PortalEmailService.php` |
| `QRCodeService` | `src/Services/QRCodeService.php` |
| `AdminReportService` | `src/Services/AdminReportService.php` |
| `PrayerTimesService` | Used in event create/edit |
| `EventTicketTypesPersistence` | `src/Helpers/EventTicketTypesPersistence.php` |

---

## Database Tables (Event Domain)

| Table / area | Key migrations |
|--------------|----------------|
| `events` core + extensions | `004`, `005`, `014`, `020`, `024`, `036`, `045`, `048`, `049`, `050`, `052`, `055`, `056`, `063` |
| `recurring_events` | `004`, `019`, `037` |
| `event_categories` | `003` |
| `event_questions`, `event_question_options`, `rsvp_question_answers` | `020`, `034` |
| `event_ticket_types` | `022`, `057` |
| `event_invites` | `052` |
| `event_people` | `051` |
| `event_feedback` | `011` |
| `rsvps` + potluck/waiver/guest | `020`, `049`, `053`, `058` |
| `rsvp_family_members` | `050` |
| `attendance` + family/guests | `050`, `035` |
| `payments` (event) | `012`, `025`, `047` |
| `refund_requests` | `026`, `029` |
| `email_campaigns`, `email_campaign_events` | `030`, `031`, `033` |
| `organizations` kiosk/waiver/refund | `029`, `058`, `069` |

---

*Generated from codebase analysis. For a shorter overview, see `old-plans/FEATURES.md`.*
