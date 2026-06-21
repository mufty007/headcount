# Headcount — Calendar Views Features

A complete inventory of calendar views and calendar-related UI in the Headcount platform. This covers admin FullCalendar views, facility scheduling calendars, public/WordPress embeds, API feeds, and member “add to calendar” integrations.

---

## Table of Contents

1. [Shared Calendar Infrastructure](#1-shared-calendar-infrastructure)
2. [Admin — Events Calendar](#2-admin--events-calendar)
3. [Admin — Facility Bookings Calendar (Org-Wide)](#3-admin--facility-bookings-calendar-org-wide)
4. [Admin — Single Facility Calendar](#4-admin--single-facility-calendar)
5. [WordPress — Public Event Calendar](#5-wordpress--public-event-calendar)
6. [WordPress — Facility Schedule Calendar](#6-wordpress--facility-schedule-calendar)
7. [Public API Calendar Feeds](#7-public-api-calendar-feeds)
8. [Member Portal — Add to Calendar](#8-member-portal--add-to-calendar)
9. [Color Coding & Legends](#9-color-coding--legends)
10. [Access Control & Scoping](#10-access-control--scoping)
11. [Navigation & Routing](#11-navigation--routing)
12. [Services & Data Layer](#12-services--data-layer)
13. [Related Documentation](#13-related-documentation)

---

## 1. Shared Calendar Infrastructure

| Feature | Location | Description |
|---------|----------|-------------|
| **FullCalendar loader** | `public/js/admin-fullcalendar.js` | Lazy-loads FullCalendar **v6.1.15** from jsDelivr CDN (CSS + JS); singleton promise prevents duplicate loads |
| **Brand theme injection** | `admin-fullcalendar.js` → `injectBrandTheme()` | Scoped `.hc-admin-calendar-root` styles: Outfit font, brand toolbar buttons, today highlight, dark-mode palette, rounded event pills |
| **Default calendar options** | `HeadcountAdminCalendar.create()` | Month / week / day toolbar; `nowIndicator`; 06:00–22:00 slot range; `dayMaxEvents`; `fixedWeekCount: false`; 12-hour time format |
| **Event status colors** | `HeadcountAdminCalendar.statusColor()` | Published (green), draft (gray), scheduled (amber), cancelled (red) — used by events calendar |
| **Facility block colors** | `HeadcountAdminCalendar.blockToFcEvent()` | Approved booking (blue), pending (amber), manual block (slate pastel), Headcount event (violet) |
| **Dark mode** | Admin header theme + `.dark` class | FullCalendar chrome adapts; per-event pill colors stay semantic for legend consistency |
| **Alpine.js page controllers** | `events-calendar.js`, `facility-bookings-calendar.js`, `facility-details.js` | Each admin calendar page owns init, filters, side panels, and API refetch logic |

Admin calendars all use **FullCalendar**. The WordPress public event calendar uses a **custom month-grid** (table layout) instead of FullCalendar, optimized for theme compatibility.

---

## 2. Admin — Events Calendar

| Feature | Location | Description |
|---------|----------|-------------|
| **Page** | `public/admin/events-calendar.php` | Full-width admin calendar for all organization events |
| **Client JS** | `public/js/events-calendar.js` | Alpine component `eventsCalendarPage()` |
| **API feed** | `GET public/api/events.php?action=calendar&start=&end=` | Optional `status`, `category_id` filters |
| **Service** | `src/Services/EventCalendarService.php` | Builds FullCalendar JSON with RSVP head counts, facility names, recurring-child flags |
| **Views** | FullCalendar toolbar | **Month**, **Week**, **Day** |
| **Status filter** | Page toolbar buttons | All · Published · Draft · Scheduled — persists in URL (`?status=`) and refetches events |
| **Color legend** | Below filters | Published, draft, scheduled, cancelled swatches |
| **Click event** | Side panel (slide-over) | Title, status, when, location, facility, RSVP yes (heads), checked-in count |
| **Panel actions** | Side panel links | View details · Edit event · Start check-in |
| **Click empty date** | `dateClick` handler | Navigates to event create wizard with `event_date` pre-filled |
| **List view link** | Page header | “List view” → `events.php` |
| **Create event** | Page header | Primary CTA → `event-create.php` |
| **Nav entry** | `public/admin/includes/header.php` | Events dropdown → **Calendar** |
| **List page link** | `public/admin/events.php` | “Calendar” button in events list header |

### Event calendar payload (`extendedProps`)

Each event in the feed includes: `status`, `location`, `facility_id`, `facility_name`, `rsvp_yes_heads`, `checked_in`, `is_recurring_child`, `parent_event_id`, `banner_image`.

Cancelled and deleted events are excluded from the default “All” feed; explicit status filter can include cancelled when needed.

---

## 3. Admin — Facility Bookings Calendar (Org-Wide)

| Feature | Location | Description |
|---------|----------|-------------|
| **Page** | `public/admin/facility-bookings-calendar.php` | Cross-facility schedule view |
| **Client JS** | `public/js/facility-bookings-calendar.js` | Alpine component `facilityBookingsCalendarPage()` |
| **API feed** | `GET public/api/facility-bookings.php?action=calendar&start=&end=` | Optional `facility_id` filter |
| **Service** | `FacilityBookingService::getOrgCalendarForAdmin()` | Merges availability blocks from all (or filtered) facilities into one FullCalendar feed |
| **Views** | FullCalendar toolbar | **Month**, **Week**, **Day** |
| **Facility filter** | `<select>` dropdown | All facilities or single facility — URL param `facility_id`, refetches on change |
| **Coordinator scope** | API + page facility list | Coordinators only see facilities they manage |
| **Event types shown** | Merged blocks | Approved bookings, pending bookings, internal manual blocks, linked Headcount events |
| **Click item** | Side panel | Facility name, type, title, when, status (for bookings) |
| **Panel actions** | Contextual | Open facility calendar · Edit Headcount event · Approve/reject pending · View booking queue |
| **Inline approve/reject** | Side panel (pending only) | POST to `facility-bookings.php?action=approve|reject` with CSRF |
| **Queue link** | Page header | “Booking queue” → `facility-bookings.php` |
| **Facilities link** | Page header | “All facilities” → `facilities.php` |
| **Nav entry** | Admin header | Facilities dropdown → **Bookings calendar** |
| **Queue page link** | `public/admin/facility-bookings.php` | “Calendar” button (preserves active facility filter) |

Calendar event titles are prefixed with facility name (e.g. `Main Hall: Board meeting`).

---

## 4. Admin — Single Facility Calendar

| Feature | Location | Description |
|---------|----------|-------------|
| **Page tab** | `public/admin/facility-details.php` → **Calendar** tab (default) | Per-facility FullCalendar inside facility hub |
| **Client JS** | `public/js/facility-details.js` | Alpine component `facilityDetailsPage()` — calendar init on tab activate |
| **API feed** | `GET public/api/facility-bookings.php?action=availability&facility_id=&start=&end=` | Returns typed blocks via `getAvailabilityForAdmin()` |
| **Operating hours slots** | PHP in `facility-details.php` | `slotMinTime` / `slotMaxTime` derived from facility `operating_hours` (fallback 06:00–22:00) |
| **Views** | FullCalendar toolbar | **Month**, **Week**, **Day** |
| **Click/drag to block** | Admin only | `selectable` + `dateClick` opens side panel in **block** mode with pre-filled date/time range |
| **Click existing item** | Side panel **view** mode | Type, title, when, status; approve/reject for pending; remove for editable manual blocks |
| **Save internal block** | Side panel or Schedule blocks tab | POST `facilities.php?action=add-block` |
| **Remove block** | Side panel or blocks list | POST `facilities.php?action=remove-block` by block index |
| **Schedule blocks tab** | Same page | List view of next 120 days (bookings + events + manual blocks) with inline remove/edit-event links |
| **Refresh after changes** | `refreshSchedule()` | Refetches availability API and reloads calendar events |
| **Deep link** | `?page=facility-details&id={id}&tab=calendar` | Used from org-wide bookings calendar side panel |

Non-admin coordinators can view the calendar but cannot create or remove internal blocks (`selectable: false`).

---

## 5. WordPress — Public Event Calendar

| Feature | Location | Description |
|---------|----------|-------------|
| **Shortcode** | `[headcount_calendar]` | Registered in `headcount-wordpress-plugin/includes/Core/Shortcodes.php` |
| **Template** | `headcount-wordpress-plugin/templates/frontend/event-calendar.php` | Custom month-grid (not FullCalendar) |
| **Attributes** | Shortcode | `view="month"`, `height="600px"`, `theme` (light/dark from plugin settings) |
| **Data source (primary)** | `public/api/public-calendar-feed.php` via API client | Combined **events + program sessions** for date range (−12 / +18 months) |
| **Data source (fallback)** | Events list API | Used when calendar feed unavailable |
| **Month navigation** | Inline JS | Previous / next month, **Today** button |
| **Today highlight** | CSS | Circular indigo badge on current day |
| **Event pills** | Per-day cells | Click opens preview dialog (not direct navigation) |
| **Preview dialog** | Modal overlay | Banner image, category, title, date/time/location meta, excerpt, CTA link |
| **Program vs event links** | `eventHref()` | Programs → portal `program-details.php`; events → configured event details URL or query param |
| **Empty state** | Banner above grid | Friendly message when no items loaded; grid still navigable |
| **Theme-safe CSS** | Embedded critical CSS | High-specificity rules so WordPress themes cannot collapse table layout |
| **Mobile** | Responsive CSS | Smaller cells and pill text below 640px |

Programs appear with category label “Program”; events use their event category. Items are distinguished internally by `calendar_item_type` (`event` | `program`).

---

## 6. WordPress — Facility Schedule Calendar

| Feature | Location | Description |
|---------|----------|-------------|
| **Shortcode** | `[headcount_facility_calendar facility="hall-a"]` | Requires facility slug |
| **Template** | `headcount-wordpress-plugin/templates/frontend/facility-calendar.php` | List-based schedule (not interactive FullCalendar grid) |
| **Attributes** | Shortcode | `facility` (required slug), `height="500px"` |
| **Data source** | `public/api/public-facility-availability.php` via API client | Approved bookings + linked event blocks for current month + 3 months |
| **Display** | Chronological list | Each row: title, date, start–end time |
| **Guest booking CTA** | Header link | “Request a booking” → portal guest booking URL when portal base configured |
| **Empty state** | Template | “No scheduled bookings in this period.” |
| **Privacy** | Public API | Shows **approved** bookings only (`includePending: false`); internal manual blocks and pending requests are not exposed |

This is a **read-only availability** view for public websites, not an admin scheduling tool.

---

## 7. Public API Calendar Feeds

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `GET public/api/public-calendar-feed.php` | Organization API key (`X-API-Key` or `?api_key=`) | Combined feed: published events + published program sessions (`show_on_public_site=1`); query `start`, `end` (YYYY-MM-DD) |
| `GET public/api/public-facility-availability.php` | Organization API key | Per-facility busy blocks; `facility_id` or `facility` slug; query `start`, `end` |

Both endpoints return JSON with CORS headers for external/WordPress consumption.

### Admin-only API feeds (session auth)

| Endpoint | Used by |
|----------|---------|
| `GET public/api/events.php?action=calendar` | Admin events calendar |
| `GET public/api/facility-bookings.php?action=calendar` | Org-wide facility bookings calendar |
| `GET public/api/facility-bookings.php?action=availability` | Single-facility calendar + schedule blocks tab |

---

## 8. Member Portal — Add to Calendar

These are not calendar *views*, but calendar *export* features tied to events:

| Feature | Location | Description |
|---------|----------|-------------|
| **ICS download** | `GET public/api/portal/calendar/event/{id}.ics` | Generates `.ics` via `CalendarHelper::generateICS()` (1-hour reminder alarm) |
| **Google Calendar** | `GET public/api/portal/calendar/google/{id}` | Redirect to Google Calendar add-event URL |
| **Apple Calendar** | `GET public/api/portal/calendar/apple/{id}` | Redirect via webcal / ICS link |
| **Portal UI — event details** | `public/portal/event-details.php` | “Add to Google Calendar” and “Download .ics” buttons |
| **Portal UI — my RSVPs** | `public/portal/my-rsvps.php` | Download ICS per RSVP’d event |

Published events only for ICS/Google/Apple endpoints.

---

## 9. Color Coding & Legends

### Events calendar (by event status)

| Status | Color | Hex (approx.) |
|--------|-------|---------------|
| Published | Green | `#059669` |
| Draft | Gray | `#6b7280` |
| Scheduled | Amber | `#d97706` |
| Cancelled | Red | `#dc2626` |

### Facility calendars (by block type)

| Type | Color | Meaning |
|------|-------|---------|
| `booking_approved` | Blue | Confirmed facility booking |
| `booking_pending` | Amber | Awaiting staff approval |
| `manual_block` | Slate pastel | Internal block (maintenance, off-platform use) |
| `headcount_event` | Violet | Published Headcount event linked to facility |

Manual blocks use dark text on light background so they read as “unavailable / muted” rather than active bookings.

### WordPress public calendar

| Item type | Visual |
|-----------|--------|
| Event | Indigo pill (`#eef2ff` background) |
| Program | Same grid; category labeled “Program”; CTA “Learn more” |

Public combined feed uses `color_hint`: events `#4f46e5`, programs `#059669` (metadata for integrators).

---

## 10. Access Control & Scoping

| Context | Who can access | Notes |
|---------|----------------|-------|
| Admin events calendar | Admin, coordinator | Read-only calendar action on events API |
| Admin facility calendars | Admin, coordinator | Coordinator facility list and API feeds scoped to **managed facilities** |
| Facility block create/remove | Admin only | Coordinators can view and approve bookings but not add manual blocks |
| Pending booking approve/reject | Admin; coordinator for managed facilities | From side panels on org-wide and single-facility calendars |
| Public API feeds | API key holders | No session; org-scoped |
| WordPress shortcodes | Public site visitors | Data limited to published content / approved bookings |
| Portal add-to-calendar | Anyone with event link | Published events only |

---

## 11. Navigation & Routing

| Route | Page file | Nav label |
|-------|-----------|-----------|
| `?page=events-calendar` | `events-calendar.php` | Events → Calendar |
| `?page=facility-bookings-calendar` | `facility-bookings-calendar.php` | Facilities → Bookings calendar |
| `?page=facility-details&id={id}&tab=calendar` | `facility-details.php` | Facility hub → Calendar tab (default) |

URLs registered in `public/admin/includes/layout-vars.php` as `$navUrls['events-calendar']` and `$navUrls['facility-bookings-calendar']`.

Cross-links:

- Events list ↔ Events calendar
- Facility booking queue ↔ Bookings calendar (facility filter preserved)
- Org-wide bookings calendar ↔ Single facility calendar (`tab=calendar`)
- Facility bookings calendar ↔ Event edit (for linked Headcount events)

---

## 12. Services & Data Layer

| Service / helper | Role |
|------------------|------|
| `EventCalendarService` | Admin events FullCalendar feed; RSVP/attendance counts; all-day detection when start/end times missing |
| `FacilityBookingService::getAvailability()` | Raw merged bookings + manual blocks + linked published events |
| `FacilityBookingService::getAvailabilityForAdmin()` | Adds `type`, `editable`, `source_id`, `block_index` for UI |
| `FacilityBookingService::getOrgCalendarForAdmin()` | Multi-facility FullCalendar feed with facility-prefixed titles |
| `FacilityService::getBlockedTimesInRange()` | Manual blocks from `blocked_times` JSON |
| `FacilityService::getPublishedEventBlocksForFacility()` | Non-virtual published events blocking facility time |
| `CalendarHelper` | ICS generation, Google/Apple calendar URL builders |
| `OrganizationApiKeyService` | Validates API keys for public feeds |

### Block type resolution (`getAvailabilityForAdmin`)

| Source ID pattern | `type` | Editable |
|-------------------|--------|----------|
| Numeric booking ID | `booking_approved` / `booking_pending` | No |
| `blocked-{index}` | `manual_block` | Yes (admin) |
| `event-{eventId}` | `headcount_event` | No (edit via event admin) |

---

## 13. Related Documentation

- [Event Management Features](./EVENT_MANAGEMENT_FEATURES.md) — event CRUD, recurrence, RSVP, check-in
- [Facility Management Features](./FACILITY_MANAGEMENT_FEATURES.md) — booking workflow, availability rules, payments
- [Program Management Features](./PROGRAM_MANAGEMENT_FEATURES.md) — program sessions in public calendar feed
- WordPress plugin readme — shortcode reference (`headcount-wordpress-plugin/readme.txt`)

---

## Summary Matrix

| View | UI engine | Scope | Interactive actions |
|------|-----------|-------|---------------------|
| Admin events calendar | FullCalendar v6 | All org events | Filter, side panel, create on date click |
| Admin facility bookings calendar | FullCalendar v6 | All facilities (filterable) | Filter, side panel, approve/reject |
| Admin facility details calendar | FullCalendar v6 | Single facility | View, block time (admin), approve/reject |
| WordPress `[headcount_calendar]` | Custom month grid | Public events + programs | Month nav, preview modal |
| WordPress `[headcount_facility_calendar]` | List layout | Public facility busy times | Link to guest booking |
| Portal add-to-calendar | Links / ICS | Single event | Export only |
