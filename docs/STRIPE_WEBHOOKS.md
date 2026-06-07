# Stripe webhooks for Headcount

## Endpoint URL (member portal / production)

Configure Stripe to send event notifications to your **public HTTPS** URL:

`https://<your-domain>/<app-base>/public/api/portal/payments/webhook`

Example (XAMPP local is not reachable by Stripe; use a tunnel such as ngrok, or your live host):

`https://example.org/Headcount/public/api/portal/payments/webhook`

The handler is implemented in `public/api/portal/payments.php` (action `webhook`) and delegates to `Headcount\Services\PortalPaymentService::handleWebhook`.

## Legacy API route

If Stripe was pointed at `.../api/payments/webhook` (Bootstrap API), `PaymentController::handleWebhook` now forwards to the same `PortalPaymentService` pipeline. Prefer the portal URL above so the path matches where checkout sessions are created.

## Signing secret

Use the **webhook signing secret** from the Stripe Dashboard for the endpoint that matches this URL. Store it per organization in Admin → Settings → Payments (encrypted `stripe_webhook_secret_encrypted`). The service selects the org Stripe client using `metadata.organization_id` on the Checkout Session and PaymentIntent.

## Events to enable

Enable at least:

- `checkout.session.completed` — marks `payments.status` as `paid`, applies RSVP and pending checkout JSON.
- `payment_intent.succeeded` — idempotent backup finalization if the session webhook is delayed or missing.
- `charge.refunded` — updates refund state on `payments`.

## After deploy: stuck `pending` rows

If Stripe shows **Succeeded** but Headcount still has `payments.status = pending`:

1. In Stripe Dashboard → Developers → Webhooks → your endpoint → **Resend** the relevant `checkout.session.completed` or `payment_intent.succeeded` event, or  
2. Ask the member to open their success link again (`portal/payment-success.php?session_id=...`); the page reconciles with Stripe when the row is still pending, or  
3. In **Admin → Payment Transfers**, use **Sync Stripe** on the event (re-queries each pending checkout session in Stripe and finalizes paid ones), or  
4. Manually fix in the database only after verifying the Stripe PaymentIntent / Checkout Session is paid (match `user_id`, `event_id`, and amount).

**Webhook signing:** If `organization_id` was left blank in older Checkout metadata, Headcount now resolves the organization from `event_id` so verification uses the same Stripe keys as checkout.

## Automatic reconciliation (schedule)

In addition to **Admin → Payment Transfers → Sync Stripe** (per event), you can run reconciliation on a timer so missed webhooks are corrected without manual clicks.

### Option A — CLI (recommended)

From the project root (same PHP that serves the site):

```bash
php scripts/stripe-reconcile-pending.php
```

Schedule it with **cron** (Linux) every **3 hours** (or every **6 hours** for about four runs per day), **Task Scheduler** (Windows), or your host’s “cron jobs” UI pointing at that command.

**Windows Task Scheduler:** Create a basic task → trigger **Daily** and **Repeat task every** `3 hours` for `1 day`, or use multiple daily triggers at fixed times. Action: **Start a program** → Program `php.exe` (full path to your PHP install, e.g. `C:\xampp\php\php.exe`) → Arguments: `scripts\stripe-reconcile-pending.php` → Start in: your Headcount project root (e.g. `C:\xampp\htdocs\Headcount`).

Output is one JSON line (`events_processed`, `updated`, `skipped_unpaid_session`, `errors`).

### Option B — HTTP URL (optional)

1. In `config/config.php`, set `cron.stripe_reconcile_secret` to a long random string (and deploy).
2. Call over HTTPS (GET or POST), for example every few hours:

`https://<your-domain>/<app-base>/public/api/cron-stripe-reconcile.php?key=<YOUR_SECRET>`

If the secret is left empty, the URL returns `503` and you should use the CLI script instead.

### Option C — Browser background (same org)

While an admin or coordinator is signed in, the app may call `reconcile_organization` in the background (throttled, **every 3 hours** per browser):

- On **any** admin page that loads the shared footer, a deferred request runs once per interval (localStorage key `headcount_stripe_admin_org_reconcile_ms`).
- On **Payment Transfers**, the same reconcile runs with key `headcount_stripe_bg_reconcile_ms` (same 3-hour interval).

These do **not** replace OS cron or the CLI script when no one opens the admin UI. Manual **Sync Stripe** on an event still runs immediately for that event.
