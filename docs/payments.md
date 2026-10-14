# QRaft — Plans & Payments (Stripe)

QRaft ships with a plan system and an optional Stripe integration. Without
Stripe keys the app runs on the **Free** plan for everyone; add keys to sell
upgrades.

## Plans

Three plans are seeded (edit in **Admin → Plans**):

| Plan | Price | Codes | Bio pages |
|------|-------|-------|-----------|
| Free | 0 | 5 | 1 |
| Pro | $9/mo | ∞ | 10 |
| Business | $29/mo | ∞ | ∞ |

`price` is stored in **minor units** (cents): `900` = $9.00. A blank limit means
unlimited. Limits are enforced when creating dynamic codes and bio pages.

## Connect Stripe

In **Admin → Settings → Payments (Stripe)**:

1. **Publishable key** — `pk_test_…` / `pk_live_…`
2. **Secret key** — `sk_test_…` / `sk_live_…`
3. **Currency** — e.g. `usd`, `eur`
4. **Webhook signing secret** — `whsec_…` (from the step below)

### Webhook

In the Stripe dashboard, add a webhook endpoint pointing to:

```
https://your-site/api/stripe-webhook.php
```

Subscribe to **`checkout.session.completed`**, copy the signing secret
(`whsec_…`) into the settings. On a completed payment the user's plan is
activated for its period (month / year / lifetime).

The endpoint answers `ok` (200) on success and a short plain-text reason (400) on
failure — bad signature, bad payload or no secret configured. Stripe sends no language
header, so the reason is shown in the site's default language (Settings → Default language)
and appears in the Stripe dashboard's delivery log.

## How checkout works

`checkout.php` creates a Stripe Checkout Session over the Stripe HTTP API (no
SDK) using one-time `price_data`, with the user and plan in metadata. On
success the webhook sets `users.plan_id` and `plan_expires`.

> **Recurring subscriptions:** this template uses one-time payments that grant a
> period of access (simple and dependency-free). For auto-renewing
> subscriptions, create recurring **Prices** in Stripe and switch the Checkout
> Session `mode` to `subscription` with the `price` id in `checkout.php`.

## Testing

Use Stripe **test** keys and card `4242 4242 4242 4242`, any future expiry and
CVC. Trigger the webhook from the Stripe CLI or dashboard to confirm the plan
activates.
