# Headcount — Program Management Features

A complete inventory of program management capabilities in the Headcount platform. Programs are ongoing classes/halaqahs with member-only portal registration, recurring sessions, attendance tracking, optional Stripe payments, and coupons.

---

## Table of Contents

1. [Program CRUD & Administration](#1-program-crud--administration)
2. [Program Configuration & Metadata](#2-program-configuration--metadata)
3. [Program Categories](#3-program-categories)
4. [Scheduling & Sessions](#4-scheduling--sessions)
5. [Presenters & Staff](#5-presenters--staff)
6. [Registration & Enrollment](#6-registration--enrollment)
7. [Custom Registration Questions](#7-custom-registration-questions)
8. [Liability Waiver](#8-liability-waiver)
9. [Payments & Stripe](#9-payments--stripe)
10. [Coupons & Discounts](#10-coupons--discounts)
11. [Attendance](#11-attendance)
12. [Communications & Email](#12-communications--email)
13. [Reporting & Analytics](#13-reporting--analytics)
14. [Member Portal](#14-member-portal)
15. [Public APIs & Integrations](#15-public-apis--integrations)
16. [WordPress Plugin Embed](#16-wordpress-plugin-embed)
17. [Roles & Permissions](#17-roles--permissions)
18. [Background Jobs (Cron)](#18-background-jobs-cron)
19. [Database Schema & Migrations](#19-database-schema--migrations)
20. [Services Layer](#20-services-layer)
21. [Implementation Notes & Gaps](#21-implementation-notes--gaps)

---

## 1. Program CRUD & Administration

| Feature | Location | Description |
|---------|----------|-------------|
| **Programs list** | `public/admin/programs.php` | Lists all org programs; card/table views; filter by status, category, search; links to Details/Edit/Attendance; migration install warning |
| **Create/edit program (3-step wizard)** | `public/admin/program-edit.php` | Step 1: Basic Info → Step 2: Schedule & Pricing → Step 3: Questions & Save |
| **Program hub / details** | `public/admin/program-details.php` | Tabs: Overview (stats), Registrants, Sessions & attendance, Share (QR + portal link) |
| **Dedicated attendance page** | `public/admin/program-attendance.php` | Program → session picker → mark Present/Absent/Excused for active registrants |
| **Programs API** | `public/api/programs.php` | Full admin/coordinator API (see [Admin API](#admin-api-publicapiprogramsphp)) |
| **Program service** | `src/Services/ProgramService.php` | All program domain logic: CRUD, categories, questions, registration, sessions, attendance, coupons, presenters, staff |
| **Soft delete (archive)** | API `delete` action, programs list & program details UI | Sets status `archived`; hidden from default list and portal; view via Status → Archived |
| **Admin navigation** | `public/admin/includes/header.php`, `layout-vars.php` | "Programs" menu: All Programs, Attendance |
| **Admin routing** | `public/admin/includes/layout-vars.php` | Routes `?page=programs`, `program-edit`, `program-details`, `program-attendance` |

---

## 2. Program Configuration & Metadata

| Feature | Location | Description |
|---------|----------|-------------|
| **Core fields** | Wizard step 1, `programs` table | Title, WYSIWYG description, location, virtual flag, banner image upload, status, category |
| **Banner image** | `programs.banner_image` | Uploaded via program save API; displayed on portal and public feed |
| **Status lifecycle** | `programs.status` | `draft`, `published`, `cancelled`, `archived` |
| **Show on public site** | Wizard step 2 | `show_on_public_site` flag controls visibility in public API and WordPress embed |
| **Virtual programs** | Wizard step 1 | `is_virtual` flag for online classes |
| **Capacity** | Wizard step 2 | Optional max registrants; counts `active` + `pending` against limit |
| **Location** | Wizard step 1 | Physical or virtual location string |
| **Created by** | `programs.created_by` | Tracks admin user who created the program |

---

## 3. Program Categories

| Feature | Location | Description |
|---------|----------|-------------|
| **Category table** | Migration `039_programs_domain.sql` — `program_categories` | Name, slug, sort order per organization |
| **Category modal on list page** | `public/admin/programs.php` | CRUD for categories via API |
| **Category on program form** | `public/admin/program-edit.php` | Assign category when creating/editing |
| **Category service** | `ProgramService::listCategories`, `saveCategory`, `deleteCategory` | Prevents delete if category is in use |
| **Category API** | `public/api/programs.php` — `categories`, `save_category`, `delete_category` | Admin/coordinator access |
| **Report filter** | `public/admin/includes/reports/filter-panel.php` | Filter reports by `program_category_id` |

---

## 4. Scheduling & Sessions

| Feature | Location | Description |
|---------|----------|-------------|
| **Recurrence config** | Wizard step 2, `ProgramService::saveProgram()` | Weekly / bi-weekly / monthly / none; `starts_on` / `ends_on`; weekday chips (JSON `session_days_of_week`) |
| **Fixed clock times** | Wizard step 2 | `session_start_time`, `session_end_time` |
| **Prayer-based start times** | Migration `046_prayer_scheduling.sql`, `PrayerTimesService` | Session start = org city/country prayer time + offset (Aladhan API) |
| **Prayer-based end times** | Migration `075_program_weeks_enrollment.sql`, `ProgramService::computeSessionTimesForDate()` | Session end = daily prayer (e.g. Maghrib) + offset when `session_end_time_mode = prayer` |
| **Daily break window** | Migration `075`, wizard step 2 | Optional `break_start_time` / `break_end_time` copied to each generated session (e.g. 6:00–6:30 PM) |
| **Program weeks** | Migration `075` — `program_weeks`, `program_sessions.week_id` | Admin-defined enrollment units with title, price, capacity, and explicit session dates |
| **Select-weeks registration** | `programs.registration_mode = select_weeks` | Members enroll in chosen weeks only; legacy `whole_program` keeps full access |
| **Bundle pricing** | `programs.bundle_all_weeks_price`, `ProgramPricingService::quote()` | When all weeks selected, charge bundle price instead of sum |
| **Generate sessions** | Wizard step 2 (button), API `generate_sessions`, `ProgramService::generateSessions()` | Creates `program_sessions` rows up to N months ahead; idempotent per date; updates `sessions_generated_until`; supports prayer end + break |
| **List sessions** | API `sessions`, `ProgramService::listSessions()` | Date-range filtered session list for admin |
| **Next session display** | `ProgramService::getNextSessionDate()` | Used on portal cards, public API, WordPress plugin |
| **Session statuses** | `program_sessions.status` | `scheduled`, `cancelled`, `completed` (generation always creates `scheduled`) |
| **Session table** | `program_sessions` | Per-date sessions with start/end time, `generated` flag |
| **Sessions & attendance tab** | `public/admin/program-details.php` | Session list with inline attendance roster |

---

## 5. Presenters & Staff

| Feature | Location | Description |
|---------|----------|-------------|
| **Presenters (public-facing)** | Migration `051_event_and_program_people.sql`, wizard step 2 | `program_presenters` — display name, title, image upload; shown on portal program page |
| **Presenter management** | `ProgramService::listPresenters`, `replacePresentersFromAdminInput`, `listPresentersForPrograms` | CRUD via program save; image upload handling |
| **Program staff (lead/coordinator)** | `program_staff` table, `ProgramService::listStaff`, `setStaff` | Per-program staff assignments with `lead` or `coordinator` role |
| **Staff API** | `public/api/programs.php` action `staff` | Assign coordinators/leads | **No admin UI** — API/backend only |
| **Staff-based announce permission** | `programs.php` `announce` action | Non-admin staff on `program_staff` can send announcements if `userCanManageProgram` passes |
| **Manage program check** | `ProgramService::userCanManageProgram` | Admins: all programs; staff: assigned programs only |

---

## 6. Registration & Enrollment

| Feature | Location | Description |
|---------|----------|-------------|
| **Member-only registration** | `public/api/portal/programs.php` | All program discovery and registration requires logged-in portal member |
| **Registration table** | `program_registrations` | Links user to program with status, Stripe IDs, coupon code, waiver timestamp |
| **Registration statuses** | `program_registrations.status` | `pending`, `active`, `cancelled`, `waitlist` |
| **Free registration** | Portal API `register_free`, `ProgramService::registerFree()` | Immediate `active` registration + answers; validates published status, pricing=free, capacity, enrollment window |
| **Paid registration (pending → active)** | Portal API `checkout`, `ProgramService::createPendingRegistration()` | Creates `pending` row, then Stripe checkout |
| **Capacity limits** | `ProgramService` | Counts `active` + `pending`; returns "Program is full" when at capacity |
| **Enrollment window** | `enrollment_starts_at` / `enrollment_ends_at` | Enforced in `registerFree()` and `createPendingRegistration()` | **No admin UI fields** in wizard |
| **Re-registration after cancel** | `registerFree()` | Reactivates cancelled registration to `active` |
| **View registrants** | `program-details.php`, API `registrants`, `listActiveRegistrantsWithWeeks()` | Active members with name/email/join date and enrolled weeks (select-weeks mode) |
| **Week enrollment junction** | `program_registration_weeks` | Links registration to selected `program_weeks`; empty = full program access (legacy) |
| **Guest registration** | `allow_guest_registration`, `public/portal/guest-program-register.php`, `public/api/portal/guest-program-register.php` | Non-members register and pay without portal login; account-completion email for new users |
| **My programs** | `public/portal/my-programs.php` | Member's registrations with status badges, next session (scoped to enrolled weeks), link to detail |
| **Waitlist** | DB enum `waitlist` | Schema exists; registration rejects full programs instead of waitlisting; portal has waitlist label styling only |

---

## 7. Custom Registration Questions

| Feature | Location | Description |
|---------|----------|-------------|
| **Question table** | `program_questions` | Question text, type, required flag, sort order |
| **Question types** | Wizard step 3 | `text`, `short_text`, `number`, `checkbox`, `radio`, `dropdown`, `multi_checkbox` |
| **Question options** | Migration `040_program_question_options.sql` | `program_question_options` for radio/dropdown/multi-checkbox |
| **Answer storage** | `program_registration_answers` | Answers linked to registration + question |
| **Question CRUD** | `ProgramService::getQuestions`, `saveQuestions`, `saveAnswers` | Saved on program create/update; answers saved on registration |
| **Admin question UI** | `public/admin/program-edit.php` step 3 | Add/edit/remove questions in wizard |

---

## 8. Liability Waiver

| Feature | Location | Description |
|---------|----------|-------------|
| **Org-level waiver** | Migration `058_organization_rsvp_waiver.sql`, `public/admin/settings.php` | Shared waiver settings with events; checkbox label + full waiver text |
| **Waiver on registration** | `waiver_accepted_at` on `program_registrations` | Timestamp when member accepted waiver |
| **Waiver validation** | `src/helpers.php` — `headcount_waiver_validation_error`, `headcount_mark_waiver_accepted` | Required on program registration when org waiver is enabled |
| **Portal waiver UI** | `public/portal/program-details.php` | Waiver checkbox shown before registration when enabled |

---

## 9. Payments & Stripe

| Feature | Location | Description |
|---------|----------|-------------|
| **Pricing models** | Wizard step 2, `programs.pricing_type` | `free`, `one_time`, `recurring` (weekly/bi-weekly/monthly Stripe subscriptions) |
| **Price amount** | `programs.price_amount` | Decimal price for whole-program paid registration |
| **Per-week pricing** | `program_weeks.price_amount`, `ProgramPricingService::quote()` | Sum of selected week prices; bundle override when all weeks selected |
| **Stripe checkout** | `src/Services/ProgramPaymentService.php` — `createCheckoutSession()` | Quoted total from selected weeks; coupon discount; metadata includes `week_ids` JSON |
| **Webhook completion** | `ProgramPaymentService::handleCheckoutSessionCompleted()` | Activates registration, stores payment/subscription IDs, increments coupon redemption |
| **Webhook routing** | `PortalPaymentService` | Delegates `checkout_type=program` to `ProgramPaymentService` |
| **Subscription cancellation** | `ProgramPaymentService::handleSubscriptionDeleted()` | Handles `customer.subscription.deleted` webhook; sets registration `cancelled` |
| **Payment success page** | `public/portal/payment-success.php` | Confirms program registration after Stripe (`?type=program`) |
| **Stripe org config** | Per-org encrypted Stripe keys in `organizations` table | Same pattern as events and facilities |
| **Registration Stripe fields** | `program_registrations` | `stripe_checkout_session_id`, `stripe_payment_intent_id`, `stripe_subscription_id` |

---

## 10. Coupons & Discounts

| Feature | Location | Description |
|---------|----------|-------------|
| **Coupon table** | `program_coupons` | Org- or program-scoped codes; percent or amount off; validity dates; max redemptions |
| **Coupon validation** | `ProgramService::validateCoupon()` | Checks code, date range, max redemptions, program scope |
| **Coupon CRUD (API)** | `programs.php` — `coupons`, `save_coupon`; `listCoupons`, `saveCoupon` | Org-wide or per-program coupons | **No admin UI** — API only |
| **Portal coupon entry** | `public/portal/program-details.php` | Member enters coupon code at checkout |
| **Redemption tracking** | `ProgramService::incrementCouponRedemption()` | Increments `redemptions_count` on successful paid registration |
| **Stripe coupon ID** | `program_coupons.stripe_coupon_id` | Optional linked Stripe coupon |

---

## 11. Attendance

| Feature | Location | Description |
|---------|----------|-------------|
| **Attendance table** | `program_session_attendance` | Per-session marks: `present`, `absent`, `excused`; `recorded_by`, `recorded_at`, optional `notes` |
| **Session attendance roster** | API `attendance_roster`, `ProgramService::getSessionAttendanceRoster()` | Active registrants + current attendance status for a session |
| **Record attendance** | API `attendance`, `ProgramService::recordAttendance()` | Upsert present/absent/excused; requires active registration; records `recorded_by` |
| **Admin attendance UI** | `program-attendance.php`, `program-details.php` (Sessions tab) | Two UIs for the same attendance API |
| **Attendance requirement** | `recordAttendance()` | Only users with `active` registration can be marked |

---

## 12. Communications & Email

| Feature | Location | Description |
|---------|----------|-------------|
| **Manual program announcement** | `program-edit.php`, API `announce`, `EmailService::sendProgramAnnouncement()` | Email all active registrants; merge tags `{first_name}`, `{program_name}`, `{program_description}`, `{next_session_date}` |
| **24-hour session reminders** | `public/cron/program-reminders.php`, `EmailService::sendProgramSessionReminderEmail()` | Daily cron emails active registrants for tomorrow's scheduled sessions |
| **Email logging** | `email_logs.program_id` | Program-related sends tied to a program in email logs |
| **Announcement panel** | `public/admin/program-edit.php` | Send announcement email when editing an existing program |
| **Configurable email rules** | `program_email_rules` table | Schema exists for per-program automation hooks | **Not implemented** in PHP — only hardcoded cron reminders |

---

## 13. Reporting & Analytics

| Feature | Location | Description |
|---------|----------|-------------|
| **Programs report tab** | `public/admin/reports.php`, `public/admin/includes/reports/tab-programs.php` | Metrics: published programs, registrations, sessions held, attendance records, registration revenue |
| **Per-program breakdown** | `tab-programs.php` | Per-program performance with attendance rate % |
| **Report service** | `AdminReportService::getProgramReportStats()`, `getProgramPerformanceList()` | Date-range scoped; revenue approximated from `price_amount` × paid active registrations |
| **Report filters** | `includes/reports/filter-panel.php`, `ReportFilterSet.php` | Filter by `program_id`, `program_category_id`, date range |
| **Chart rendering** | `public/js/reports-charts.js` | Program-related charts on reports tab |

---

## 14. Member Portal

| Feature | Location | Description |
|---------|----------|-------------|
| **Browse programs** | `public/portal/programs.php` | Grid of published programs; category/search via API; shows next session, pricing, presenters, registration status |
| **Program detail & register** | `public/portal/program-details.php` | Full detail, week picker (select-weeks mode), live price quote, presenters, custom questions, waiver, free register or paid checkout |
| **Guest program register** | `public/portal/guest-program-register.php` | Public page when `allow_guest_registration`; no login required |
| **My programs** | `public/portal/my-programs.php` | Member's registrations with status badges (including waitlist styling), next session scoped to enrolled weeks |
| **Portal navigation** | `public/portal/includes/header.php`, `index.php` | Programs + My Programs nav items |
| **Portal API** | `public/api/portal/programs.php` | `GET` list, `GET?id=` detail, `GET?action=quote`, `GET?action=mine`, `POST register_free`, `POST checkout` (supports `week_ids[]`) |
| **Guest program API** | `public/api/portal/guest-program-register.php`, `guest-program-register-checkout.php` | Public GET program, POST free register, POST paid checkout |
| **Portal auth required** | `PortalAuthMiddleware` on portal programs API | Member flows require login; guest flows use dedicated public endpoints |

---

## 15. Public APIs & Integrations

| Feature | Location | Description |
|---------|----------|-------------|
| **Public programs feed** | `public/api/public-programs.php` | API-key auth; returns published + `show_on_public_site` programs with banner URLs, next session, presenters |
| **Public calendar feed** | `public/api/public-calendar-feed.php` | Merges events + program sessions for external calendars |
| **Program share QR** | `public/api/program-share-qr.php`, `headcount_program_portal_url()`, `headcount_program_guest_register_url()` in `src/helpers.php` | PNG/SVG QR for portal program URL; guest register link when enabled |
| **Org API keys** | `OrganizationApiKeyService` | Authenticates public API consumers |
| **Image API** | `public/api/image.php` | Serves uploaded program banner images |

---

## 16. WordPress Plugin Embed

| Feature | Location | Description |
|---------|----------|-------------|
| **Programs grid** | `[headcount_programs]` — `headcount-wordpress-plugin/includes/Core/Shortcodes.php`, `templates/frontend/programs-grid.php` | API-key fetch + grid of published programs |
| **Showcase tabs** | `[headcount_showcase programs_limit="N"]` — `templates/frontend/showcase-tabs.php` | Events/programs/facilities tabbed showcase |
| **Calendar with programs** | Shortcodes calendar integration | Program sessions included alongside events in calendar views |
| **API client** | `includes/Core/APIClient.php` — `get_programs()` | Fetches programs from public API |
| **Presenter** | `includes/Core/Presenter.php` | Formats API data, resolves banner URLs, builds portal URLs |
| **Plugin settings docs** | `headcount-wordpress-plugin/templates/admin/settings.php` | Documents program shortcodes and showcase usage |

---

## 17. Roles & Permissions

| Feature | Location | Description |
|---------|----------|-------------|
| **Permission capability** | `src/Helpers/Permissions.php` — `programs.manage` | Default: admin only; label "Manage programs" |
| **Coordinator access** | `AuthMiddleware::requireAdminOrCoordinator()` on APIs/pages | Coordinators can access program admin pages and APIs |
| **Program staff scoping** | `ProgramService::userCanManageProgram` | Program-assigned staff can manage their programs (e.g. send announcements) |
| **Share QR access** | `public/api/program-share-qr.php` | Admin or coordinator only |

---

## 18. Background Jobs (Cron)

| Job | Location | Description |
|-----|----------|-------------|
| **24-hour session reminders** | `public/cron/program-reminders.php` | Daily job: finds tomorrow's scheduled sessions for published programs; emails active registrants |

---

## Admin API (`public/api/programs.php`)

Single authenticated admin/coordinator API:

| Action | Method | Purpose |
|--------|--------|---------|
| `list` | GET | List programs (status/search filters) |
| `get` | GET | Program + questions, staff, presenters |
| `save` | POST | Create/update program, banner upload, questions, presenters |
| `delete` | POST | Soft-archive program (status → `archived`) |
| `categories` | GET | List program categories |
| `save_category` | POST | Create/update category |
| `delete_category` | POST | Delete category (blocked if in use) |
| `generate_sessions` | POST | Generate session rows |
| `sessions` | GET | List sessions in date range |
| `registrants` | GET | Active registrants |
| `attendance_roster` | GET | Session attendance roster |
| `attendance` | POST | Record attendance (present/absent/excused) |
| `staff` | POST | Assign coordinators/leads |
| `coupons` | GET | List coupons |
| `save_coupon` | POST | Create/update coupon |
| `announce` | POST | Email active registrants |

---

## 19. Database Schema & Migrations

| Table / area | Migration | Description |
|--------------|-----------|-------------|
| `program_categories` | `039_programs_domain.sql` | Org-scoped categories with slug and sort order |
| `programs` core | `039_programs_domain.sql` | Title, description, banner, status, location, virtual, capacity, pricing, recurrence, session times/days, date range, enrollment window |
| `program_sessions` | `039_programs_domain.sql` | Per-date sessions with start/end time and status |
| `program_registrations` | `039_programs_domain.sql` | Member enrollment with Stripe IDs, coupon, waiver |
| `program_questions` | `039_programs_domain.sql` | Custom registration questions |
| `program_registration_answers` | `039_programs_domain.sql` | Answers per registration |
| `program_session_attendance` | `039_programs_domain.sql` | Per-session attendance marks |
| `program_staff` | `039_programs_domain.sql` | Lead/coordinator assignments |
| `program_coupons` | `039_programs_domain.sql` | Discount codes |
| `program_email_rules` | `039_programs_domain.sql` | Automation hooks (schema only) |
| `program_question_options` | `040_program_question_options.sql` | Options for radio/dropdown/multi-checkbox questions |
| `program_presenters` | `051_event_and_program_people.sql` | Display name, title, image |
| Prayer scheduling | `046_prayer_scheduling.sql` | `prayer_name`, `prayer_offset` on `programs` |
| Registration waiver | `058_organization_rsvp_waiver.sql` | `waiver_accepted_at` on `program_registrations` |
| Email logs | `039_programs_domain.sql` | `email_logs.program_id` FK |

### Key enums

| Column | Values |
|--------|--------|
| `programs.status` | `draft`, `published`, `cancelled`, `archived` |
| `programs.pricing_type` | `free`, `one_time`, `recurring` |
| `programs.billing_interval` | `once`, `week`, `week_2`, `month` |
| `programs.recurrence_type` | `none`, `weekly`, `biweekly`, `monthly` |
| `program_sessions.status` | `scheduled`, `cancelled`, `completed` |
| `program_registrations.status` | `pending`, `active`, `cancelled`, `waitlist` |
| `program_session_attendance.status` | `present`, `absent`, `excused` |
| `program_staff.role` | `lead`, `coordinator` |
| `program_questions.question_type` | `text`, `short_text`, `checkbox`, `number`, `radio`, `dropdown`, `multi_checkbox` |

---

## 20. Services Layer

| Service | Path | Role |
|---------|------|------|
| `ProgramService` | `src/Services/ProgramService.php` | CRUD, categories, questions, registration, sessions, attendance, coupons, presenters, staff, weeks |
| `ProgramPricingService` | `src/Services/ProgramPricingService.php` | Per-week sum vs bundle quote; coupon discount helpers |
| `ProgramPaymentService` | `src/Services/ProgramPaymentService.php` | Stripe checkout + webhook handlers for programs |
| `PrayerTimesService` | `src/Services/PrayerTimesService.php` | Prayer time lookup for session generation |
| `EmailService` | `src/Services/EmailService.php` | Program announcements + session reminders |
| `AdminReportService` | `src/Services/AdminReportService.php` | Program report stats and performance list |
| `PortalPaymentService` | `src/Services/PortalPaymentService.php` | Stripe webhook router delegating to `ProgramPaymentService` |
| `ReportFilterSet` | `src/Services/ReportFilterSet.php` | Program/category filter state for reports |

### Helper functions (`src/helpers.php`)

| Function | Purpose |
|----------|---------|
| `headcount_waiver_validation_error` | Validate waiver acceptance on registration |
| `headcount_mark_waiver_accepted` | Record waiver timestamp on registration |
| `headcount_program_portal_url` | Build portal URL for a program |

---

## 21. Implementation Notes & Gaps

| Area | Status |
|------|--------|
| **Certificates** | Not implemented |
| **Waitlist workflow** | DB enum exists; registration rejects "full" instead of waitlisting |
| **Enrollment date UI** | DB + backend validation only; no admin form fields in wizard |
| **Coupon admin UI** | API/backend only; members enter codes at checkout |
| **Staff assignment UI** | API/backend only |
| **Registration answers in admin** | Answers stored; not displayed in registrants UI |
| **`program_email_rules`** | Table created; no application logic reads/writes it |
| **Attendance notes UI** | Column exists; not exposed in admin UI |
| **Activity logging** | Not implemented for program actions |
| **Payment transfers tab** | No dedicated programs tab in payment-transfers (unlike facilities) |

---

## Example: Summer Dawrah Ilmiyyah setup

Use **select weeks** mode for a multi-week summer program:

| Week | Session dates | Week price |
|------|---------------|------------|
| Week 1 — Tuhfat al-Atfal | Jul 5 | $15 |
| Week 2 — Lamiyyah / Usul / Qawa'id | Jul 11, 12 | $15 |
| Week 3 — Bayquniyyah | Jul 18, 19 | $15 |
| Week 4 — Ibn Sa'di | Jul 25, 26 | $15 |

**Admin setup (program-edit step 2):**

1. Set **Registration mode** → Select weeks.
2. Add four weeks with titles, prices, and session dates (one date per line).
3. Set **Bundle price when all weeks selected** → `50.00`.
4. Session start → fixed `17:00` (5:00 PM) or prayer-based start.
5. Session end → **At prayer time** → Maghrib, 0 minutes after.
6. Daily break → `18:00` – `18:30`.
7. Enable **Allow guest registration** for public sign-up without portal login.
8. Save, then use **Generate Sessions** for recurrence-based weeks or rely on week session dates from `syncWeekSessions()`.

**Member experience:** Pick any weeks → pay sum ($15–$60). Pick all four → pay **$50** bundle. Sessions, attendance, and reminders only cover enrolled weeks.

---

*Generated from codebase analysis. For related features, see `docs/EVENT_MANAGEMENT_FEATURES.md` and `docs/FACILITY_MANAGEMENT_FEATURES.md`.*
