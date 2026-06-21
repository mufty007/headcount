# Cron Jobs – Automated Event Emails

These scripts send **automated event emails** (reminders and follow-ups). Configure your system cron to run them on a schedule.

## Quick setup

1. **SMTP**: Configure **Admin → Settings → Email (SMTP)** per organization, or set global `smtp2go` in `config/config.php`.
2. **Templates**: Customize **Admin → Email → Templates** (Reminder 1 Week, Reminder 1 Day, etc.). Those templates are used for automated reminders.
3. **Automation**: In **Admin → Email**, use the **Automated reminder emails** section to turn automation on/off and choose which reminders to send (1 week, 1 day, 2 hours before). Cron jobs respect these settings.
4. **Database**: If you use the automation toggles, run migration `database/migrations/021_add_email_automation_to_organizations.sql` so the organization table has the automation columns.
5. **Schedule** the scripts below (e.g. daily at 8:00 AM).

## Scripts

| Script | Purpose | Suggested schedule |
|--------|---------|--------------------|
| **portal-reminders.php** | Sends **1-week** and **1-day** reminders to members who RSVP’d “yes”, using your Email templates. Respects member “Event reminders” preference. | Daily, e.g. `0 8 * * *` (8:00 AM) |
| **reminders.php** | Same idea as portal reminders (1-week / 1-day), for consistency. Run one of the two. | Daily, e.g. `0 9 * * *` (9:00 AM) |
| **post-event-followup.php** | Sends follow-up emails after events (uses “Follow up” template). | Daily |
| **send-event-feedback.php** | Sends feedback request emails to checked-in attendees (uses “Event feedback request” template) one day after events with feedback collection enabled. | Daily, e.g. `0 9 * * *` (9:00 AM) |
| **send-emails.php** | Processes queued emails (if you use a queue). | Every 5–15 min |
| **generate-recurring-events.php** | Creates instances of recurring events. | Daily |
| **cleanup-logs.php** | Log rotation/cleanup. | Weekly |
| **../scripts/stripe-reconcile-pending.php** | Reconciles **pending** Stripe checkouts org-wide (missed webhooks). See `docs/STRIPE_WEBHOOKS.md`. | Every **3 hours** (or every **6 hours** for ~4×/day) |

## HTTP cron URLs (Hostinger / shared hosting)

When CLI cron is unavailable, use HTTPS URLs with `?key=` (set `cron.http_secret` in `config/config.php`).

**If your site docroot is the project root** (URLs include `/public/`):

| Job | URL |
|-----|-----|
| Stripe reconcile | `https://YOUR-DOMAIN/public/api/cron-stripe-reconcile.php?key=SECRET` |
| Event feedback | `https://YOUR-DOMAIN/public/api/cron-event-feedback.php?key=SECRET` |
| Any job (dispatcher) | `https://YOUR-DOMAIN/public/api/cron-run.php?job=JOB&key=SECRET` |

**If docroot is `public/`** (no `/public` in URL):

| Job | URL |
|-----|-----|
| Stripe reconcile | `https://YOUR-DOMAIN/api/cron-stripe-reconcile.php?key=SECRET` |
| Event feedback | `https://YOUR-DOMAIN/api/cron-event-feedback.php?key=SECRET` |
| Dispatcher | `https://YOUR-DOMAIN/api/cron-run.php?job=JOB&key=SECRET` |

**Dispatcher jobs** (`job=`): `stripe-reconcile`, `event-feedback`, `portal-reminders`, `post-event-followup`, `send-campaigns`, `recurring-events`, `facility-holds`, `program-reminders`

Suggested Hostinger schedules:

| Job | Cron expression | Example |
|-----|-----------------|---------|
| Event feedback | Daily 9 AM | `0 9 * * *` wget/curl URL |
| Portal reminders | Daily 8 AM | `0 8 * * *` |
| Stripe reconcile | Every 3 hours | `0 */3 * * *` |
| Send campaigns | Every 15 min | `*/15 * * * *` |

Auth also accepts header `X-Cron-Secret: SECRET` instead of `?key=`.

## Example crontab (Linux / macOS)

Run from the project root (or use full paths):

```bash
# Automated event reminder emails (run once per day)
0 8 * * * cd /path/to/Headcount && php cron/portal-reminders.php >> logs/cron-reminders.log 2>&1

# Stripe pending checkout reconciliation (e.g. every 3 hours)
0 */3 * * * cd /path/to/Headcount && php scripts/stripe-reconcile-pending.php >> logs/cron-stripe-reconcile.log 2>&1
```

## How it works

- **Reminders**: Events with date = *today + 7 days* get the **Reminder (1 Week)** template; events with date = *today + 1 day* get the **Reminder (1 Day)** template. Only members with RSVP “yes” and (for portal) “Event reminders” enabled receive them. Each event+type is sent only once (tracked in the `reminders` table).
- **Templates**: Subject and body come from **Admin → Email → Templates**. You can use variables like `{first_name}`, `{event_name}`, `{event_date}`, `{event_time}`, `{location}`.

## Notes

- Organizations can use their own SMTP (Settings → Email). If not set, the global `smtp2go` config is used.
- Ensure the `reminders` table exists (see `database/schema.sql`). If it’s missing, reminder scripts will still send but won’t record sent reminders (risk of duplicates if you run multiple times per day).
