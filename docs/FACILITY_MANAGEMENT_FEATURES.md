# Headcount — Facility Management Features

A complete inventory of facility management capabilities in the Headcount platform. This covers rooms/spaces booking, admin configuration, member and guest portal flows, payments, reporting, event linkage, and integrations.

---

## Table of Contents

1. [Facility CRUD & Administration](#1-facility-crud--administration)
2. [Facility Configuration & Metadata](#2-facility-configuration--metadata)
3. [Availability & Scheduling Rules](#3-availability--scheduling-rules)
4. [Booking Workflow](#4-booking-workflow)
5. [Member Bookings (Portal)](#5-member-bookings-portal)
6. [Guest Bookings (No Login)](#6-guest-bookings-no-login)
7. [Staff / Admin Bookings](#7-staff--admin-bookings)
8. [Payments & Stripe Holds](#8-payments--stripe-holds)
9. [Facility Managers & Access Control](#9-facility-managers--access-control)
10. [Communications & Email](#10-communications--email)
11. [Reporting & Analytics](#11-reporting--analytics)
12. [Payment Transfers (Admin)](#12-payment-transfers-admin)
13. [Event–Facility Linkage](#13-eventfacility-linkage)
14. [Public APIs & Integrations](#14-public-apis--integrations)
15. [WordPress Plugin Embed](#15-wordpress-plugin-embed)
16. [Background Jobs (Cron)](#16-background-jobs-cron)
17. [Activity Logging](#17-activity-logging)
18. [Database Schema & Migrations](#18-database-schema--migrations)
19. [Services Layer](#19-services-layer)
20. [Implementation Notes](#20-implementation-notes)

---

## 1. Facility CRUD & Administration

| Feature | Location | Description |
|---------|----------|-------------|
| **Facilities list** | `public/admin/facilities.php` | Grid/list toggle, search, status filter, thumbnails, links to edit/details/booking queue; "New Facility" button |
| **Create/edit facility (4-step wizard)** | `public/admin/facility-edit.php` | Step 1: Basic Info → Step 2: Images & Pricing → Step 3: Availability → Step 4: Booking Rules |
| **Facility details hub** | `public/admin/facility-details.php` | Tabbed hub: **Calendar** (FullCalendar + click-to-block side panel), **Booking history** (quick actions, approve/reject), **Schedule blocks** (inline add/remove internal blocks), **Managers** (assign staff). Stat cards remain above tabs. |
| **Facilities API** | `public/api/facilities.php` | `list`, `get` (+ managers), `eligible-managers`, `save`, `delete`, **`add-block`**, **`remove-block`**, **`update-managers`** |
| **Shared admin calendar JS** | `public/js/admin-fullcalendar.js` | FullCalendar CDN loader, dark-mode hooks, color helpers for facility and events calendars |
| **Facility service** | `src/Services/FacilityService.php` | CRUD, slug management, image enrichment, pricing calculation, booking rule validation, availability blocks |
| **Admin routing** | `public/admin/includes/layout-vars.php` | Routes `?page=facilities`, `facility-edit`, `facility-details`, `facility-bookings` |
| **Admin nav** | `public/admin/includes/header.php` | Facilities dropdown (All Facilities + Bookings) for users with `facilities.manage` |

---

## 2. Facility Configuration & Metadata

| Feature | Location | Description |
|---------|----------|-------------|
| **Core fields** | `facilities` table, facility wizard step 1 | Name, slug, description (Quill rich text), location, capacity, status (`active`/`inactive`) |
| **Image gallery** | Migration `060_facilities_pricing_images.sql`, wizard step 2 | Multi-image upload; `images` JSON gallery; thumbnail via `headcount_facility_thumb_url` |
| **Legacy single image** | Migration `059_facilities_domain.sql` | `image` column (superseded by gallery but still supported) |
| **Paid facility toggle** | Wizard step 2 | `is_paid`, `hourly_rate`, `discount_percent`, `discount_label` |
| **Facility managers** | Migration `064_facility_managers.sql`, wizard step 1 | Assign admins/coordinators as per-facility managers for notifications and approval scope |
| **Slug management** | `FacilityService` | Auto-slugify, unique slug per organization |
| **Image serving** | `public/api/image.php` | Serves uploaded images from `facility-images/` path prefix |
| **Description display helper** | `src/helpers.php` — `headcount_facility_description_for_display` | Prepares Quill/encoded HTML for public display |

---

## 3. Availability & Scheduling Rules

| Feature | Location | Description |
|---------|----------|-------------|
| **Weekly operating hours** | Wizard step 3, `FacilityService::defaultOperatingHours` | Per-day open/close; presets; copy Monday to all weekdays; Sun/Sat closed by default |
| **Unified operating hours** | Migration `060` | `operating_hours` JSON (replaces separate member/guest hour columns in UI) |
| **Date-specific blocked times** | Migration `061` + `083`, wizard step 3, facility details | `blocked_times` JSON supports **once**, **date range**, and **weekly** (e.g. school hours Mon–Fri) with start/end time, reason, `block_member` / `block_guest` |
| **Member booking rules** | Wizard step 4 | `allow_member_booking`, `member_max_duration_minutes`, `member_advance_days` |
| **Guest booking rules** | Wizard step 4 | `allow_guest_booking`, `guest_max_duration_minutes`, `guest_advance_days` |
| **Staff booking rules** | `facilities` table | `staff_max_duration_minutes`, `staff_advance_days`; staff bypasses operating hours and manual blocks |
| **Duration constraints** | Wizard step 4 | `min_duration_minutes`, `buffer_minutes` (gap between bookings), `slot_increment_minutes` |
| **Overlap prevention** | `FacilityBookingService::hasOverlap` | Blocks overlapping pending/approved bookings; respects buffer on both sides |
| **Availability API** | `public/api/facility-bookings.php`, `public/api/portal/facility-bookings.php` | Returns approved bookings + blocked times + linked event blocks for a date range |
| **Slot block detection** | `FacilityService::getSlotBlockMessage` | Returns human-readable message when slot overlaps manual block or published event |
| **Client-side slot check** | `public/portal/includes/facility-book-slot-check.js.php` | Live availability fetch + overlap detection on booking forms |
| **Published event blocks** | `FacilityService::getPublishedEventBlocksForFacility` | Non-virtual published events with start/end times block member/guest bookings |
| **Schedule view (admin)** | `public/admin/facility-details.php` | Calendar tab + schedule blocks list use `FacilityBookingService::getAvailabilityForAdmin()` (bookings + manual blocks + linked published events, typed for UI) |

---

## 4. Booking Workflow

| Feature | Location | Description |
|---------|----------|-------------|
| **Booking statuses** | `facility_bookings.status` | `pending` → `approved` / `rejected` / `cancelled` |
| **Booked via tracking** | `facility_bookings.booked_via` | `guest`, `portal`, `admin` |
| **Create booking** | `FacilityBookingService::createBooking` | Validates rules, pricing, overlap; status `pending`; blocks direct create if Stripe checkout required |
| **Approve booking** | `FacilityBookingService::approveBooking` | Re-checks overlap; captures Stripe hold if authorized; sets reviewer + timestamp |
| **Reject booking** | `FacilityBookingService::rejectBooking` | Releases payment hold; stores rejection reason |
| **Cancel booking** | `FacilityBookingService::cancelBooking` | Members: pending only; staff: pending + approved; releases hold |
| **Booking queue (admin)** | `public/admin/facility-bookings.php` | Filter by status and facility; Approve/Reject/Cancel actions; payment status display; 7-day hold expiry notice |
| **Bookings calendar (org-wide)** | `public/admin/facility-bookings-calendar.php`, `public/js/facility-bookings-calendar.js` | FullCalendar across all facilities; filter by facility; color-coded bookings, blocks, and linked events; side panel with approve/reject for pending |
| **Bookings calendar API** | `public/api/facility-bookings.php?action=calendar` | Date-range feed via `FacilityBookingService::getOrgCalendarForAdmin()`; coordinator-scoped facility list |
| **Booking history** | `public/admin/facility-details.php` | Per-facility booking table with status and payment filters |
| **Purpose validation** | `src/helpers.php` — `headcount_validate_booking_purpose` | Validates booking purpose text (max words) |
| **Pricing snapshot** | Migration `060` | Stores `hours_booked`, `hourly_rate`, `subtotal_amount`, `total_amount` on booking at creation |

**Workflow summary:**

```
Request (member / guest / staff)
  → Validate rules + overlap + blocks
  → If paid: Stripe authorize hold (pending)
  → Else: pending booking
  → Email requester + facility managers/admins
  → Staff approve → capture payment (if authorized) → approved
  → Staff reject/cancel → release hold → rejected/cancelled
  → 7-day cron → auto-cancel expired authorized holds
```

---

## 5. Member Bookings (Portal)

| Feature | Location | Description |
|---------|----------|-------------|
| **Facilities browse** | `public/portal/facilities.php` | Public grid of bookable facilities; shows pricing, capacity, location; guest + member merge when logged in |
| **Facility detail page** | `public/portal/facility-details.php` | Image carousel, rich description, operating hours, capacity/price badges, book CTAs |
| **Member booking form** | `public/portal/facility-book.php` | Date/time, title, purpose; live price estimate; slot overlap check; free → API create; paid → Stripe checkout |
| **My bookings** | `public/portal/my-facility-bookings.php` | Member list of all bookings; cancel pending via API |
| **Portal bookings API** | `public/api/portal/facility-bookings.php` | Public: `facilities`, `availability`. Auth: `list`/`my`, `create`, `cancel` |
| **Member checkout** | `public/api/portal/facility-booking-checkout.php` | Starts Stripe checkout for paid member bookings |
| **Payment success** | `public/portal/facility-booking-success.php` | Finalizes Stripe session; confirms pending request |
| **Payment cancelled** | `public/portal/facility-booking-cancel.php` | Cancels abandoned checkout booking |
| **Portal nav** | `public/portal/includes/header.php` | "Facilities" and "My Facility Bookings" nav items |
| **Portal routing** | `public/portal/index.php` | Includes facility-related pages in allowed routes |

---

## 6. Guest Bookings (No Login)

| Feature | Location | Description |
|---------|----------|-------------|
| **Guest booking form** | `public/portal/facility-book-guest.php` | Guest contact fields + booking fields; slot check + optional Stripe checkout |
| **Guest booking API** | `public/api/portal/guest-facility-booking.php` | Creates free guest booking without authentication |
| **Guest checkout** | `public/api/portal/guest-facility-booking-checkout.php` | Resolves guest user + starts Stripe checkout for paid bookings |
| **Guest user resolution** | `FacilityBookingService::resolveGuestUser` | Find or create member user record for guest contact |
| **Guest booking creation** | `FacilityBookingService::createGuestBooking` | Full guest booking flow with `booked_via=guest` |
| **Role-based bookable list** | `FacilityService::listBookableForRole` | Filters active facilities by `allow_member_booking` / `allow_guest_booking` |
| **Guest account upsell** | `FacilityEmailService` | Member upgrade/register links in guest confirmation emails |

---

## 7. Staff / Admin Bookings

| Feature | Location | Description |
|---------|----------|-------------|
| **Staff booking API** | `public/api/facility-bookings.php` action `create` | Staff creates booking on behalf of a user with `booked_via=admin`; staff role rules apply |
| **Staff rule bypass** | `FacilityService::validateBookingRequest` | Staff role bypasses operating hours and manual block restrictions |
| **Booking list (scoped)** | `public/api/facility-bookings.php` action `list` | Admins see all; coordinators see only managed facilities |
| **Availability (admin)** | `public/api/facility-bookings.php` action `availability` | Admin availability check including optional pending bookings |

> **Note:** Staff booking creation exists in the admin API but there is no dedicated admin UI form for it in `facility-bookings.php`.

---

## 8. Payments & Stripe Holds

| Feature | Location | Description |
|---------|----------|-------------|
| **Paid facility pricing** | `FacilityService::calculateBookingPrice` | Hours × hourly rate minus discount percent |
| **Checkout requirement** | `FacilityPaymentService::requiresCheckout` | Paid facility + total ≥ $0.50 triggers Stripe checkout |
| **Stripe Checkout (manual capture)** | `FacilityPaymentService::startCheckout` | Creates pending booking; Stripe session with `checkout_type=facility_booking`; authorize-only hold |
| **Checkout finalization** | `FacilityPaymentService::finalizeCheckoutFromSession` | Completes booking after successful Stripe session |
| **Webhook routing** | `PortalPaymentService` | Routes `checkout_type=facility_booking` to `FacilityPaymentService::handleCheckoutSessionCompleted` |
| **Capture on approve** | `FacilityPaymentService::captureForBooking` | Captures authorized hold when admin approves booking |
| **Release on reject/cancel** | `FacilityPaymentService::releaseForBooking` | Cancels payment intent or refunds if already captured |
| **Expired hold processing** | `FacilityPaymentService::processExpiredHolds` | After ~7 days, releases authorization, cancels booking, emails requester |
| **Payment statuses** | Migration `062_facility_booking_stripe.sql` | `not_required`, `awaiting_checkout`, `authorized`, `captured`, `released`, `failed` |
| **Checkout pending JSON** | Migration `062` | `checkout_pending_json` stores booking context pre-payment |
| **Stripe manual capture** | `src/Integrations/StripeService.php` | Capture/cancel/refund support for facility payment holds |
| **Feature gate** | `FacilityPaymentService::facilityPaymentsEnabled` | Checks schema columns exist before enabling payment flows |

---

## 9. Facility Managers & Access Control

| Feature | Location | Description |
|---------|----------|-------------|
| **Permission capability** | `src/Helpers/Permissions.php` — `facilities.manage` | Default: admin only; label "Manage facilities & bookings" |
| **Facility managers table** | Migration `064_facility_managers.sql` | `facility_id` + `user_id` junction for per-facility scope |
| **Manager assignment** | `FacilityService::setManagers`, wizard step 1 | Assign eligible admins/coordinators as facility managers |
| **Eligible managers list** | `FacilityService::listEligibleManagers` | Active admins/coordinators eligible for assignment |
| **Manager scoping** | `FacilityService::userCanManageFacility` | Admins: all facilities; coordinators: assigned only |
| **Managed facility IDs** | `FacilityService::getManagedFacilityIds` | Returns facility IDs a coordinator manages |
| **Booking action guard** | `public/api/facility-bookings.php` | `userCanManageFacility` enforced on approve/reject/cancel |
| **Coordinator booking queue** | `public/admin/facility-bookings.php` | Coordinators see/manage only their assigned facilities |
| **Admin notification routing** | `FacilityEmailService::notifyAdminsPending` | Emails assigned managers first; falls back to all admins/coordinators if none assigned |

---

## 10. Communications & Email

| Feature | Location | Description |
|---------|----------|-------------|
| **Facility email service** | `src/Services/FacilityEmailService.php` | All facility booking transactional emails |
| **Pending confirmation (member)** | `sendPendingConfirmation` | Sent when member booking is created |
| **Pending confirmation (guest)** | `sendGuestPendingConfirmation` | Sent when guest booking is created |
| **Admin/manager alert** | `notifyAdminsPending` | New pending booking notification with review queue link |
| **Approved notification** | `sendApproved` | Sent when staff approves booking |
| **Rejected notification** | `sendRejected` | Sent when staff rejects booking |
| **Hold expired notification** | `sendHoldExpired` | Sent when 7-day payment hold expires via cron |
| **Payment context in emails** | `FacilityEmailService` | Authorization, captured, and released payment notes included in email body |

---

## 11. Reporting & Analytics

| Feature | Location | Description |
|---------|----------|-------------|
| **Reports hub — Facilities tab** | `public/admin/reports.php`, `public/admin/includes/reports/tab-facilities.php` | Booking counts by status, captured revenue, per-facility performance |
| **Facility report stats** | `src/Services/AdminReportService::getFacilityReportStats` | Total/pending/approved/rejected/cancelled bookings; captured revenue |
| **Facility performance list** | `AdminReportService::getFacilityPerformanceList` | Per-facility hours booked and revenue breakdown |
| **Facility filter** | `src/Services/ReportFilterSet.php`, `public/admin/includes/reports/filter-panel.php` | Optional `facility_id` filter on reports |
| **Bar chart** | `public/js/reports-charts.js` | `#facilityBookingsChart` bar chart rendering |
| **Booking share progress** | `tab-facilities.php` | Visual booking share per facility |

---

## 12. Payment Transfers (Admin)

| Feature | Location | Description |
|---------|----------|-------------|
| **Facilities payments tab** | `public/admin/payment-transfers.php` (tab `facilities`) | Lists facilities with paid bookings; search/filter; aggregate authorized/captured counts |
| **Per-booking payment modal** | `payment-transfers.php` | View individual booking payment details per facility |
| **Facility bookings lookup API** | `public/api/payment-transfers.php` action `get_facility_bookings` | Returns paid bookings for a facility |

---

## 13. Event–Facility Linkage

| Feature | Location | Description |
|---------|----------|-------------|
| **Event facility FK** | Migration `063_events_facility_id.sql` | `events.facility_id` optional FK to `facilities` |
| **Event edit UI** | `public/admin/event-edit.php`, `event-create.php` | Optional "Link to facility" dropdown; requires start/end time |
| **Event API support** | `public/api/events.php` | Create/update supports `facility_id`; propagates to recurring instances |
| **Published event blocks bookings** | `FacilityService::getPublishedEventBlocksForFacility` | Published non-virtual events with times become availability blocks |
| **Linked events list** | `FacilityService::listLinkedEventsForFacility` | Admin schedule view of events linked to a facility |
| **Facility ID resolution** | `src/helpers.php` — `headcount_resolve_event_facility_id` | Validates facility belongs to org |
| **Event facility time validation** | `headcount_validate_event_facility_times` | Requires start/end time when facility is linked |
| **Event API validation** | `headcount_event_facility_api_errors` | Validation errors for `facility_id` on event API payloads |
| **Recurring propagation** | `event-edit.php` | Updates `facility_id` on child recurring event instances |

---

## 14. Public APIs & Integrations

| Feature | Location | Description |
|---------|----------|-------------|
| **Public facilities API** | `public/api/public-facilities.php` | API-key auth; list bookable facilities by audience; get by id/slug for WordPress/external sites |
| **Public availability API** | `public/api/public-facility-availability.php` | Returns approved booking blocks + IMCA event blocks for calendar embed |
| **Org API keys** | `OrganizationApiKeyService`, migration `065_hash_api_keys.sql` | Authenticates public API consumers |
| **Payment hold cron endpoint** | `public/api/cron-facility-payment-holds.php` | CLI/cron: `processExpiredHolds` across all organizations |
| **Download plugin** | `public/api/download-plugin.php` | Serves WordPress plugin zip from admin (includes facility shortcodes) |

---

## 15. WordPress Plugin Embed

| Feature | Location | Description |
|---------|----------|-------------|
| **Facilities grid** | `[headcount_facilities]` — `headcount-wordpress-plugin/includes/Shortcodes.php`, `templates/frontend/facilities-grid.php` | API-key fetch + grid of bookable facilities |
| **Single facility** | `[headcount_facility id/slug]` — `templates/frontend/facility-single.php` | Detail view with book links to portal |
| **Facility calendar** | `[headcount_facility_calendar facility="slug"]` — `templates/frontend/facility-calendar.php` | Approved bookings + event blocks via public availability API |
| **Showcase tabs** | `[headcount_showcase facilities_limit="N"]` — `templates/frontend/showcase-tabs.php` | Events/programs/facilities tabbed showcase |
| **Custom facility loop** | `[headcount_facilities_loop]` — `includes/Core/FacilitiesLoopRenderer.php` | Custom card layouts with sort and render |
| **Field shortcodes** | `includes/Core/FacilityFieldShortcodes.php` | Granular fields: title, location, price, book links, image, slug, etc. |
| **API client** | `includes/Core/APIClient.php` | `get_facilities`, `get_facility`, `get_facility_availability` |
| **Presenter** | `includes/Core/FacilityPresenter.php` | Formats API data, builds portal URLs, hourly price display |
| **Loop context** | `includes/Core/FacilityLoopContext.php` | Context for field shortcodes inside loops |
| **Styles** | `headcount-wordpress-plugin/assets/css/headcount.css` | Facility grid, calendar, and pricing styles |

---

## 16. Background Jobs (Cron)

| Job | Location | Description |
|-----|----------|-------------|
| **Expired payment holds** | `public/api/cron-facility-payment-holds.php` | Releases Stripe authorizations older than ~7 days; cancels booking; sends hold-expired email |

---

## 17. Activity Logging

| Entity | Actions logged | Location |
|--------|----------------|----------|
| `facility` | Create, update, delete | `ActivityLogger` via `public/api/facilities.php` |
| `facility_booking` | Create (staff/member/guest), approve, reject, cancel | `ActivityLogger` via `public/api/facility-bookings.php` and portal APIs |

---

## 18. Database Schema & Migrations

| Table / area | Migration | Description |
|--------------|-----------|-------------|
| `facilities` core | `059_facilities_domain.sql` | Name, slug, description, location, capacity, image, status, member/guest/staff booking rules, operating hours, duration/buffer/slot settings |
| Pricing & images | `060_facilities_pricing_images.sql` | `is_paid`, `hourly_rate`, `discount_percent`, `discount_label`, `images` JSON, unified `operating_hours` |
| Blocked times | `061_facility_blocked_times.sql`, `083_facility_blocked_times_ranges.sql` | once / range / weekly rules (school hours, holiday spans) |
| Stripe payments | `062_facility_booking_stripe.sql` | Stripe session/intent IDs, `payment_status` enum, authorization/capture/release timestamps, `checkout_pending_json` |
| Event linkage | `063_events_facility_id.sql` | `events.facility_id` FK |
| Facility managers | `064_facility_managers.sql` | `facility_managers` junction table |
| `facility_bookings` core | `059_facilities_domain.sql` | Title, purpose, datetime range, status workflow, `booked_via`, reviewer fields |
| Booking pricing snapshot | `060_facilities_pricing_images.sql` | `hours_booked`, `hourly_rate`, `subtotal_amount`, `total_amount` |

### Key enums

| Column | Values |
|--------|--------|
| `facilities.status` | `active`, `inactive` |
| `facility_bookings.status` | `pending`, `approved`, `rejected`, `cancelled` |
| `facility_bookings.booked_via` | `guest`, `portal`, `admin` |
| `facility_bookings.payment_status` | `not_required`, `awaiting_checkout`, `authorized`, `captured`, `released`, `failed` |

---

## 19. Services Layer

| Service | Path | Role |
|---------|------|------|
| `FacilityService` | `src/Services/FacilityService.php` | CRUD, rules, availability, managers, pricing, event blocks |
| `FacilityBookingService` | `src/Services/FacilityBookingService.php` | Booking lifecycle, overlap, guest resolution, approval workflow |
| `FacilityPaymentService` | `src/Services/FacilityPaymentService.php` | Stripe checkout, capture, release, expired holds |
| `FacilityEmailService` | `src/Services/FacilityEmailService.php` | All facility booking transactional emails |
| `AdminReportService` | `src/Services/AdminReportService.php` | Facility report stats and performance list |
| `ReportFilterSet` | `src/Services/ReportFilterSet.php` | Optional facility filter on reports |
| `PortalPaymentService` | `src/Services/PortalPaymentService.php` | Webhook routing for `checkout_type=facility_booking` |
| `StripeService` | `src/Integrations/StripeService.php` | Manual capture, cancel, refund for facility holds |

### Helper functions (`src/helpers.php`)

| Function | Purpose |
|----------|---------|
| `headcount_facility_description_for_display` | Prepare facility description HTML for display |
| `headcount_validate_booking_purpose` | Validate booking purpose text |
| `headcount_resolve_event_facility_id` | Resolve/validate facility_id for events |
| `headcount_event_facility_id_from_post` | Resolve facility_id from event admin form POST |
| `headcount_validate_event_facility_times` | Require times when facility is linked to event |
| `headcount_event_facility_api_errors` | Validation errors for facility_id on event API |
| `headcount_facility_thumb_url` | Resolve thumbnail URL for facility lists |

---

## 20. Implementation Notes

1. **Staff booking UI gap** — Staff booking creation exists in the admin API (`facility-bookings.php` `create`) but there is no dedicated admin UI form for it.
2. **Legacy DB columns** — `member_operating_hours` and `guest_operating_hours` exist from migration 059; the current admin UI uses unified `operating_hours` (migration 060).
3. **Coordinator access** — Coordinators without `facilities.manage` can still act as assigned facility managers for approve/reject on their facilities.
4. **Minimum payment** — Paid bookings require total ≥ $0.50 to trigger Stripe checkout (`requiresCheckout`).
5. **Hold expiry** — Authorized Stripe holds expire after ~7 days; cron auto-cancels and notifies the requester.
6. **Delete protection** — Facilities cannot be deleted if pending or approved bookings exist.

---

*Generated from codebase analysis. For event management features, see `docs/EVENT_MANAGEMENT_FEATURES.md`.*
