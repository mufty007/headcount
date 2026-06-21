# Headcount — Email Campaigns & Templates Features

A complete inventory of email marketing campaigns, template library, automation, delivery logging, and analytics capabilities in the Headcount platform. This covers the admin Email Center, APIs, cron jobs, SMTP2GO integration, and supporting infrastructure.

---

## Table of Contents

1. [Email Center & Admin UI](#1-email-center--admin-ui)
2. [Email Templates Library](#2-email-templates-library)
3. [Campaign Composer & Lifecycle](#3-campaign-composer--lifecycle)
4. [Audience Types & Recipient Resolution](#4-audience-types--recipient-resolution)
5. [Merge Fields & Personalization](#5-merge-fields--personalization)
6. [Scheduling & Sending](#6-scheduling--sending)
7. [Email Automation (Event Reminders)](#7-email-automation-event-reminders)
8. [Analytics & Tracking](#8-analytics--tracking)
9. [Unsubscribes & Compliance](#9-unsubscribes--compliance)
10. [Delivery Logs & Resend](#10-delivery-logs--resend)
11. [Bulk Email](#11-bulk-email)
12. [SMTP & Email Branding Settings](#12-smtp--email-branding-settings)
13. [API Reference](#13-api-reference)
14. [Background Jobs (Cron)](#14-background-jobs-cron)
15. [Services & Infrastructure](#15-services--infrastructure)
16. [JavaScript Helpers](#16-javascript-helpers)
17. [Database Schema & Migrations](#17-database-schema--migrations)
18. [Roles & Permissions](#18-roles--permissions)
19. [Related Features (Shared Infrastructure)](#19-related-features-shared-infrastructure)
20. [Implementation Notes & Gaps](#20-implementation-notes--gaps)

---

## 1. Email Center & Admin UI

| Feature | Location | Description |
|---------|----------|-------------|
| **Email & Campaigns hub** | `public/admin/email-campaigns.php` | Main "Email Center" with 3 tabs: Campaigns, Automation, Analytics & logs; requires `campaigns.send` |
| **Campaigns tab** | `email-campaigns.php` | GrapesJS drag-and-drop composer, audience picker, schedule toggle, merge-tag inserter, preview, campaign history |
| **Automation tab** | `email-campaigns.php` | Master reminder toggle; 1-week / 1-day / 2-hour checkboxes; custom reminder sequence editor |
| **Analytics & logs tab** | `email-campaigns.php` | Delivery log from `email_logs` with status filter and resend; note about SMTP2GO handoff vs inbox delivery |
| **Email Templates library** | `public/admin/email-templates.php` | Left-rail template list + Quill WYSIWYG editor; create/edit/delete/duplicate; preview; send test email |
| **Legacy campaigns redirect** | `public/admin/campaigns.php` | 302 redirect to `/?page=email-campaigns` for old bookmarks |
| **Members bulk email** | `public/admin/members.php` | Bulk-email modal: pick template, edit subject/body, send to selected members |
| **Event email composers** | `public/admin/events.php`, `event-details.php` | Load templates from email-templates API for announcement/reminder composition |
| **Dashboard quick action** | `public/admin/dashboard.php` | "New campaign" link to email-campaigns |
| **Admin navigation** | `public/admin/includes/header.php` | Sidebar: Email Templates + Email & Campaigns (permission-gated) |
| **Template redirect** | `email-templates.php` | Redirects `?tab=` query params to email-campaigns hub |

---

## 2. Email Templates Library

| Feature | Location | Description |
|---------|----------|-------------|
| **Template CRUD** | `public/api/email-templates.php` | `list`, `get`, `create`, `update`, `delete`, `duplicate` |
| **Quill WYSIWYG editor** | `public/admin/email-templates.php` | Rich-text editing for `body_html`; Ctrl/Cmd+S save shortcut |
| **Template types** | `email_templates.template_type` | See [Template Types](#template-types) below |
| **One per type rule** | `email-templates.php` API | Non-`custom` types: one template per org; multiple `custom` templates allowed |
| **Body blocks** | Migration `027_email_templates_body_blocks.sql` | `body_blocks` JSON for block editor support |
| **Design JSON** | Migration `033_email_campaigns_alter_logs_templates.sql` | `design_json` column for visual editor state (GrapesJS/Unlayer) |
| **Thumbnail** | Migration `033` | `thumbnail_path` for template library previews |
| **Template preview** | API `preview` | Renders template with sample merge data |
| **Send test email** | API `send_test` | Emails rendered template to logged-in admin via SMTP2GO |
| **Default template seeding** | `email-templates.php` | Auto-seeds default templates on first visit if org has none |
| **Template cache** | `email-templates.php` API | Clears `email_templates_{orgId}_all` / `_custom` cache on CRUD |
| **Variable groups UI** | `email-templates.php` | Attendee, Event, Links, Payment, Org variable groups for inserter |

### Template Types

| Type | Typical use |
|------|-------------|
| `announcement` | Event invites / broadcasts |
| `reminder_1week` | Automated 7-day event reminder |
| `reminder_1day` | Automated 24-hour event reminder |
| `reminder_2hours` | Automated 2-hour event reminder |
| `confirmation` | RSVP confirmation |
| `receipt` | Payment receipt |
| `cancellation` | Cancellation notice |
| `follow_up` | Post-event thank-you (`cron/post-event-followup.php`) |
| `custom` | Multiple allowed per org; campaign-saved templates |

**Editors:**
- **Templates page:** Quill WYSIWYG (`body_html`)
- **Campaigns page:** GrapesJS newsletter preset (`body_html` + `design_json`)

---

## 3. Campaign Composer & Lifecycle

| Feature | Location | Description |
|---------|----------|-------------|
| **GrapesJS editor** | `public/admin/email-campaigns.php` | `grapesjs-preset-newsletter` drag-and-drop email builder |
| **Subject line** | Campaign composer | Required for send/schedule; optional for draft |
| **Audience picker** | Campaign composer | Select audience type + config (event, member, group, manual emails) |
| **Recipient count preview** | Campaign composer + API `count_recipients` | "Will reach N recipients" widget before send |
| **Schedule toggle** | Campaign composer | `datetime-local` + "Schedule for later" → `schedule` action |
| **Preview modal** | Campaign composer | Mobile/desktop preview via `campaign-email-helpers.js` |
| **Save draft** | API `save_draft` | Create/update campaign with `status=draft` |
| **Send now** | API `send` | Resolve audience, merge fields, append unsubscribe footer + branding, send via `EmailService` |
| **Schedule** | API `schedule` | Set `status=scheduled`, `scheduled_at` |
| **Cancel scheduled** | API `cancel_scheduled` | Revert scheduled → draft |
| **Duplicate** | API `duplicate` | Clone campaign as new draft |
| **Delete** | API `delete` | Delete draft/scheduled; cleans `email_campaign_events`, nulls `email_logs.campaign_id` |
| **Save as library template** | Campaign composer | Save campaign design as custom template in library |
| **Campaign history table** | Campaigns tab | Lists past campaigns with status and timestamps |
| **Image upload** | `public/api/upload-email-image.php` | Asset upload for GrapesJS editor; returns `{ success, url }` |
| **Video upload** | `public/api/upload-email-video.php` | Short video upload for HTML5 video embeds in campaign HTML |
| **Campaign statuses** | `email_campaigns.status` | `draft`, `scheduled`, `sending`, `sent` |

---

## 4. Audience Types & Recipient Resolution

| Type | Where used | Resolution logic |
|------|------------|------------------|
| `all_members` | Campaign UI + API | Active members with email; excludes `email_unsubscribes` |
| `single_member` | Campaign UI + API | One user by `audience_config.user_id` |
| `event` | Campaign UI + API | RSVP "yes" attendees for `audience_config.event_id` (series-aware via `EventSeriesHelper`) |
| `event_member` | Campaign UI + API | Single RSVP-yes attendee (`event_id` + `event_user_id`) |
| `manual` | Campaign UI + API | Parsed email list in `audience_config.manual_emails[]` |
| `segment` | Campaign UI + API | Members of `audience_config.group_id` via `group_members` |
| `all` / `event` / `members` | `bulk-email.php` | Legacy bulk send: all members, event RSVPs, or explicit `user_ids` |

**Recipient preview:** `count_recipients` action mirrors send-time resolution and excludes unsubscribed addresses.

**Note:** Migration `030` defines `audience_type` ENUM as `all_members|event|manual|segment`. Runtime also supports `event_member` and `single_member` via API inference from `audience_config`.

---

## 5. Merge Fields & Personalization

### Campaign UI insert buttons

`{first_name}`, `{last_name}`, `{name}`, `{email}`, `{organization_name}`, `{event_name}`, `{event_day}`, `{event_date}`, `{event_time}`, `{event_location}`

### EmailService::processTemplate()

`{first_name}`, `{last_name}`, `{full_name}`, `{name}`, `{email}`, `{phone}`, `{event_name}`, `{event_date}`, `{event_day}`, `{event_day_name}`, `{event_time}`, `{event_location}`, `{location}`, `{join_link}`, `{event_description}`, `{organization_name}`, `{program_name}`, `{program_description}`, `{next_session_date}`

### Template library UI variable groups

| Group | Variables |
|-------|-----------|
| Attendee | `first_name`, `last_name`, `email` |
| Event | `event_name`, `event_date`, `event_time`, `location`, `event_description` |
| Links | `rsvp_link`, `event_link`, `unsubscribe_link` |
| Payment | `amount`, `payment_id`, `payment_date` |
| Org | `organization_name` |

### Campaign-specific

- `{unsubscribe_link}` / `{{unsubscribe_link}}` — replaced at send time
- Event merge data injected when `audience_config.event_id` is set

### Email branding

| Feature | Location | Description |
|---------|----------|-------------|
| **Branding wrapper** | `src/helpers.php` — `wrapEmailWithBranding()` | Injects org logo/name header into HTML fragments or full documents |
| **Logo URL builder** | `buildLogoUrlForEmail()` | Absolute logo URLs for email headers |
| **Org logo** | `public/admin/settings.php` | Logo upload used in campaign sends and previews |

---

## 6. Scheduling & Sending

| Feature | Location | Description |
|---------|----------|-------------|
| **Schedule in UI** | `email-campaigns.php` | Datetime picker + toggle → `campaigns.php?action=schedule` |
| **Scheduled send cron** | `cron/send-scheduled-campaigns.php` | Picks `status=scheduled` and `scheduled_at <= NOW()`; mirrors send logic; sets `status=sent` |
| **Send pipeline** | `public/api/campaigns.php` `send` | Resolve audience → per-recipient merge → unsubscribe footer → branding → `EmailService::sendBulk` |
| **Rate limiting** | `EmailService` | Retry logic for SMTP2GO rate limits |
| **SMTP2GO delivery** | `EmailService::sendViaSmtp2Go()` | HTTP API send; stores `smtp_message_id` on `email_logs` for webhook correlation |

---

## 7. Email Automation (Event Reminders)

| Feature | Location | Description |
|---------|----------|-------------|
| **Automation UI** | `email-campaigns.php` Automation tab | Master toggle `email_reminders_enabled`; checkboxes for `reminder_1week`, `reminder_1day`, `reminder_2hours` |
| **Custom reminder schedule** | Automation tab + `organizations.reminder_custom_schedule` | JSON array of `{value, unit}` steps (days/hours before event) |
| **Automation API** | `public/api/settings.php` | `get_email_automation` / `update_email_automation` |
| **Portal reminders cron** | `cron/portal-reminders.php` | Primary automated reminder cron: 1-week, 1-day, 2-hour windows + custom schedule |
| **Admin reminders cron** | `cron/reminders.php` | Alternate 1-week/1-day sender via `EmailService::sendEventReminder` |
| **Member preferences** | `email_preferences.event_reminders` | Portal reminders respect member opt-out for event reminders |
| **Template usage** | `portal-reminders.php` | Uses `email_templates` types `reminder_1week`, `reminder_1day`, `reminder_2hours` |
| **Dedup** | `reminders` table | Prevents duplicate reminder sends per event/user/type |
| **Post-event follow-up** | `cron/post-event-followup.php` | Sends `follow_up` template to RSVP-yes attendees after events end |
| **Org automation columns** | Migration `021_add_email_automation_to_organizations.sql` | `email_reminders_enabled`, `reminder_1week`, `reminder_1day`, `reminder_2hours` |
| **Custom schedule column** | Migration `022_add_reminder_custom_schedule.sql` | `reminder_custom_schedule` JSON on `organizations` |

---

## 8. Analytics & Tracking

| Feature | Location | Description |
|---------|----------|-------------|
| **Event storage** | `email_campaign_events` table | Per-event rows: `delivered`, `opened`, `clicked`, `bounced`, `unsubscribed`; optional `link_url` |
| **Webhook ingestion** | `public/api/smtp2go-webhook.php` | SMTP2GO events → `email_campaign_events`; correlates via `email_logs.smtp_message_id` |
| **Campaign list stats** | `campaigns.php?action=list` | Aggregates `opened`, `clicked`, `bounced`, `unsubscribed` + `open_rate` / `click_rate` per campaign |
| **Campaign detail stats** | `campaigns.php?action=detail` | Per-recipient open/click/bounce/unsubscribe flags |
| **Delivery log UI** | `email-campaigns.php` Log tab | Shows sent/failed/queued from `email_logs` — delivery status, not engagement rates |
| **Campaign history** | Campaigns tab | Status and timestamps; engagement metrics not surfaced in UI tables |

### Webhook event mapping

| SMTP2GO event | Stored as |
|---------------|-----------|
| `open` | `opened` |
| `click` | `clicked` |
| `bounce` | `bounced` |
| `unsubscribe` / `spam` | `unsubscribed` |

---

## 9. Unsubscribes & Compliance

| Feature | Location | Description |
|---------|----------|-------------|
| **Unsubscribe table** | Migration `032_email_unsubscribes.sql` | Per-org email opt-outs; unique `(organization_id, email)` |
| **Unsubscribe URL** | `src/helpers.php` — `generateUnsubscribeUrl()`, `verifyUnsubscribeToken()` | HMAC-signed unsubscribe links |
| **Unsubscribe footer** | `appendUnsubscribeFooter()` | CAN-SPAM footer; supports `{unsubscribe_link}` / `{{unsubscribe_link}}` |
| **Public unsubscribe page** | `public/unsubscribe.php` | Signed HMAC link handler; inserts into `email_unsubscribes` |
| **Audience exclusion** | `campaigns.php` send + `count_recipients` | Excludes addresses in `email_unsubscribes` |
| **Webhook unsubscribe** | `smtp2go-webhook.php` | Auto-inserts `email_unsubscribes` on SMTP2GO unsubscribe events |

---

## 10. Delivery Logs & Resend

| Feature | Location | Description |
|---------|----------|-------------|
| **Email logs table** | `email_logs` | Recipient, subject, status, `email_type`, `event_id`, `program_id`, `campaign_id`, `smtp_message_id` |
| **Email logs API** | `public/api/email-logs.php` | GET list (paginated, filter by `status`, `event_id`); POST `resend` by log id |
| **Log tab UI** | `email-campaigns.php` | Filter by sent/failed/queued; recipient, subject, type; resend button |
| **Resend** | `EmailService::resendToLog()` | Re-sends failed/queued email from log entry |
| **EmailLog model** | `src/Models/EmailLog.php` | CRUD for logs; `updateSmtpMessageId()` for webhook correlation |

---

## 11. Bulk Email

| Feature | Location | Description |
|---------|----------|-------------|
| **Bulk email API** | `public/api/bulk-email.php` | POST: send to `user_ids`, or `send_to=all|event|members`; optional `template_id` |
| **Bulk send service** | `EmailService::sendBulk()` | Iterates recipients with merge and logging |
| **Members UI** | `public/admin/members.php` | Modal to pick template, edit subject/body, send to selected members |
| **Event announce** | `public/api/events.php` action `announce` | Uses `EmailService::sendEventAnnouncement()` with optional template |
| **Manual event reminder** | `events.php` action `remind` | Uses `EmailService::sendEventReminder()` |

---

## 12. SMTP & Email Branding Settings

| Feature | Location | Description |
|---------|----------|-------------|
| **SMTP configuration** | `public/admin/settings.php` Email tab | SMTP2GO API key, from email/name, reply-to |
| **SMTP API** | `public/api/settings.php` action `update_email` | Stores org SMTP2GO credentials (plain or encrypted) |
| **Email branding** | Settings Email tab | Org logo upload for `wrapEmailWithBranding()` |
| **Email preview** | `settings.php` | Preview branded email header |
| **Required for** | All email features | Campaigns, template test send, automation crons, transactional emails |

---

## 13. API Reference

### Campaigns API (`public/api/campaigns.php`)

Requires `campaigns.send` permission.

| Action | Method | Purpose |
|--------|--------|---------|
| `list` | GET | All campaigns with recipient/sent counts and engagement stats |
| `get` | GET | Single campaign by id (incl. `audience_config` JSON) |
| `detail` | GET | Campaign + per-recipient log rows with open/click/bounce counts |
| `save_draft` | POST | Create/update draft |
| `schedule` | POST | Set `status=scheduled`, `scheduled_at` |
| `send` | POST | Resolve audience, merge, send, set `status=sent` |
| `count_recipients` | POST | Preview recipient count (excludes unsubscribes) |
| `cancel_scheduled` | POST | Revert scheduled → draft |
| `delete` | POST | Delete draft/scheduled campaign |
| `duplicate` | POST | Clone campaign as new draft |

### Email Templates API (`public/api/email-templates.php`)

| Action | Method | Purpose |
|--------|--------|---------|
| `list` | GET | Cached list; optional `template_type=custom` filter |
| `get` | GET | Full template row |
| `create` | POST | New template (`body_html`, `body_blocks`, `design_json`) |
| `update` | POST | Update template |
| `delete` | POST | Hard delete (blocks default system templates) |
| `duplicate` | POST | Clone as custom copy |
| `preview` | GET | Render with sample merge data |
| `send_test` | POST | Email rendered template to logged-in admin |

### Other email APIs

| Endpoint | Purpose |
|----------|---------|
| `public/api/email-logs.php` | List logs; resend |
| `public/api/bulk-email.php` | Bulk send to members/event/all |
| `public/api/smtp2go-webhook.php` | Ingest opens/clicks/bounces/unsubscribes |
| `public/api/upload-email-image.php` | Campaign editor image upload |
| `public/api/upload-email-video.php` | Campaign editor video upload |
| `public/api/settings.php` | `get_email_automation`, `update_email_automation`, `update_email` |

---

## 14. Background Jobs (Cron)

| Job | Location | Description |
|-----|----------|-------------|
| **Scheduled campaigns** | `cron/send-scheduled-campaigns.php` | Sends due scheduled campaigns; run every 5–15 min |
| **Portal reminders** | `cron/portal-reminders.php` | Primary event reminder automation (1-week, 1-day, 2-hour, custom) |
| **Admin reminders** | `cron/reminders.php` | Alternate 1-week/1-day reminder sender |
| **Post-event follow-up** | `cron/post-event-followup.php` | Thank-you emails using `follow_up` template |
| **Email queue (legacy)** | `cron/send-emails.php` | Generic `email_queue` table processor; **not** wired to campaign system |
| **Cron documentation** | `cron/README.md` | Documents portal-reminders, reminders, post-event-followup |

---

## 15. Services & Infrastructure

| Service | Path | Role |
|---------|------|------|
| `EmailService` | `src/Services/EmailService.php` | Core send path: SMTP2GO, `email_logs`, merge tags, bulk send, resend, event/program announcements and reminders |
| `EmailLog` | `src/Models/EmailLog.php` | Log CRUD; `campaign_id` + `smtp_message_id` support |
| `SMTP2GOService` | `src/Integrations/SMTP2GOService.php` | Lower-level SMTP2GO HTTP client; template test send |
| **No CampaignService** | — | Campaign logic inline in `campaigns.php` API and `send-scheduled-campaigns.php` cron |

### EmailService key methods

| Method | Purpose |
|--------|---------|
| `sendEmail()` | Single email with logging |
| `sendBulk()` | Multi-recipient send with merge |
| `processTemplate()` | Merge tag replacement |
| `resendToLog()` | Resend from log entry |
| `sendEventAnnouncement()` | Event broadcast to members/RSVPs |
| `sendEventReminder()` | Manual/automated event reminder |
| `sendProgramAnnouncement()` | Program broadcast to registrants |
| `sendProgramSessionReminderEmail()` | Program session reminder |

### Out of campaigns/templates domain (separate builders)

| Service | Notes |
|---------|-------|
| `PortalEmailService` | RSVP confirmation, receipts, magic links — does not read `email_templates` |
| `FacilityEmailService` | Facility booking emails — separate HTML builders |

---

## 16. JavaScript Helpers

| Feature | Location | Description |
|---------|----------|-------------|
| **Campaign email helpers** | `public/admin/js/campaign-email-helpers.js` | `headcountExtractBodyFromCampaignHtml()` — strip full HTML doc to body fragment; `headcountBuildCampaignPreviewHtml()` — branded preview shell |
| **Email campaigns app** | Inline in `email-campaigns.php` | Alpine.js `emailCampaignsApp()`: tabs, GrapesJS init, audience/recipient count, campaign CRUD, automation save, log load/resend |
| **Email templates app** | Inline in `email-templates.php` | Alpine.js `emailTemplatesApp()`: Quill sync, variable groups, preview, send test |
| **GrapesJS isolation** | `email-campaigns.php` | GrapesJS instance kept outside Alpine reactive scope to avoid DOM/model conflicts |

---

## 17. Database Schema & Migrations

| Table / area | Migration | Description |
|--------------|-----------|-------------|
| `email_templates` (base) | `database/schema.sql` | `template_type` enum, `subject`, `body_html`, per-org unique type constraint (later relaxed) |
| Email automation | `021_add_email_automation_to_organizations.sql` | `email_reminders_enabled`, `reminder_1week`, `reminder_1day`, `reminder_2hours` |
| Custom reminder schedule | `022_add_reminder_custom_schedule.sql` | `reminder_custom_schedule` JSON |
| Template body blocks | `027_email_templates_body_blocks.sql` | `body_blocks` JSON |
| Multiple custom templates | `028_email_templates_allow_multiple_custom.sql` | Drops `unique_org_template`; app enforces one-per-type for non-custom |
| `email_campaigns` | `030_email_campaigns.sql` | Campaign records: subject, body, design JSON, status, schedule, audience |
| `email_campaign_events` | `031_email_campaign_events.sql` | Webhook analytics per campaign/recipient |
| `email_unsubscribes` | `032_email_unsubscribes.sql` | Per-org email opt-outs |
| Logs + template extensions | `033_email_campaigns_alter_logs_templates.sql` | `email_logs.campaign_id`, `smtp_message_id`; `email_templates.name`, `thumbnail_path`, `design_json` |
| `reminders` | `database/schema.sql` | Dedup table for automated reminder sends |
| `email_logs` | `database/schema.sql` | Delivery log; extended with `campaign_id`, `program_id` in later migrations |

### Key enums

| Column | Values |
|--------|--------|
| `email_campaigns.status` | `draft`, `scheduled`, `sending`, `sent` |
| `email_campaigns.audience_type` (DB) | `all_members`, `event`, `manual`, `segment` (+ runtime: `event_member`, `single_member`) |
| `email_templates.template_type` | `announcement`, `reminder_1week`, `reminder_1day`, `reminder_2hours`, `confirmation`, `receipt`, `cancellation`, `custom` (+ `follow_up` used in app) |
| `email_campaign_events.event_type` | `delivered`, `opened`, `clicked`, `bounced`, `unsubscribed` |

---

## 18. Roles & Permissions

| Feature | Location | Description |
|---------|----------|-------------|
| **Permission capability** | `src/Helpers/Permissions.php` — `campaigns.send` | Default: admin only; label "Send email campaigns & templates" |
| **Campaigns API gate** | `public/api/campaigns.php` | Requires `campaigns.send` |
| **Templates API gate** | `public/api/email-templates.php` | Requires `campaigns.send` |
| **Upload endpoints** | `upload-email-image.php`, `upload-email-video.php` | Admin or coordinator |

---

## 19. Related Features (Shared Infrastructure)

These use `email_templates` or `EmailService` but are transactional/automation flows, not marketing broadcast campaigns:

| Feature | Location |
|---------|----------|
| Event announce | `public/api/events.php` (`announce`) |
| Manual event reminder | `public/api/events.php` (`remind`) |
| Resend RSVP confirmations | `events.php` (`resend-confirmations`) |
| Members bulk email | `members.php` + `bulk-email.php` |
| Automated event reminders | `cron/portal-reminders.php`, `cron/reminders.php` |
| Post-event follow-up | `cron/post-event-followup.php` |
| Program announcements | `programs.php` API `announce` |
| Program session reminders | `cron/program-reminders.php` |
| Portal transactional emails | `PortalEmailService` (RSVP, receipts, invites — separate HTML, shared SMTP/logs) |

---

## 20. Implementation Notes & Gaps

| Area | Status |
|------|--------|
| **Engagement UI** | `open_rate`/`click_rate` returned by API but not surfaced in admin campaign tables |
| **Cancel scheduled / duplicate / delete** | API exists; limited or no UI buttons in current campaign composer |
| **`audience_type` ENUM** | Migration 030 narrower than runtime types (`event_member`, `single_member`) |
| **`send-emails.php`** | Legacy `email_queue` processor — separate from campaign pipeline |
| **`follow_up` template type** | Used in app and seeded defaults; may not be in base schema ENUM |
| **Settings JS legacy** | `public/js/settings.js` still references reminder toggles; automation UI moved to email-campaigns |
| **Cron README** | Does not yet document `send-scheduled-campaigns.php` |
| **Coordinator default** | `campaigns.send` is admin-only by default; coordinators need explicit permission grant |

---

*Generated from codebase analysis. For related domain features, see `docs/EVENT_MANAGEMENT_FEATURES.md` and `docs/PROGRAM_MANAGEMENT_FEATURES.md`.*
